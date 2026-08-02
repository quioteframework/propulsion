<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Util\StatementRows;

/**
 * DB-independent coverage for {@see \Propulsion\Util\StatementRows}, driven by a
 * mocked PDOStatement.
 *
 * The interesting property here is *when the cursor gets closed*. On a
 * connection without MARS (FreeTDS/pdo_dblib, i.e. MSSQL) an abandoned open
 * result set makes the next, unrelated statement on that connection fail with
 * "Attempt to initiate a new Adaptive Server operation with results pending"
 * -- long before PHP's GC would have destructed the statement. Routing every
 * formatter's row loop through this class is what keeps that hazard handled in
 * one place, so "closeCursor() was called" is the contract worth pinning down,
 * including for the consumer that stops reading early.
 */
class StatementRowsTest extends TestCase
{
	/**
	 * Wires $stmt->fetch() to hand back $rows one at a time, then false --
	 * PDOStatement::fetch()'s own end-of-result-set contract.
	 *
	 * @param list<array<array-key, mixed>> $rows
	 */
	private function queueRows(PDOStatement $stmt, array $rows): void
	{
		$queue = $rows;
		$stmt->method('fetch')->willReturnCallback(static function () use (&$queue) {
			if ($queue === array()) {
				return false;
			}
			return array_shift($queue);
		});
	}

	/**
	 * A stub, for the tests that only care what comes out of the generator.
	 *
	 * @param list<array<array-key, mixed>> $rows
	 */
	private function statementYielding(array $rows): PDOStatement
	{
		$stmt = $this->createStub(PDOStatement::class);
		$this->queueRows($stmt, $rows);

		return $stmt;
	}

	/**
	 * A mock, for the tests that assert closeCursor() was actually called.
	 *
	 * @param list<array<array-key, mixed>> $rows
	 */
	private function statementExpectingOneCloseCursor(array $rows): PDOStatement
	{
		$stmt = $this->createMock(PDOStatement::class);
		$this->queueRows($stmt, $rows);
		$stmt->expects($this->once())->method('closeCursor');

		return $stmt;
	}

	public function testIterateYieldsEveryRow(): void
	{
		$stmt = $this->statementYielding(array(array(1, 'a'), array(2, 'b')));

		$this->assertSame(
			array(array(1, 'a'), array(2, 'b')),
			iterator_to_array(StatementRows::iterate($stmt), false)
		);
	}

	public function testIterateClosesTheCursorWhenDrained(): void
	{
		$stmt = $this->statementExpectingOneCloseCursor(array(array(1, 'a')));

		iterator_to_array(StatementRows::iterate($stmt), false);
	}

	public function testIterateClosesTheCursorWhenTheConsumerStopsEarly(): void
	{
		// The regression: ModelCriteria::countFromRows() returns from inside
		// its foreach as soon as it has the scalar, which leaves the generator
		// suspended rather than exhausted. With closeCursor() sitting after
		// the loop instead of in a finally, it never ran at all.
		$stmt = $this->statementExpectingOneCloseCursor(array(array(7), array(8), array(9)));

		$rows = StatementRows::iterate($stmt);
		$first = null;
		foreach ($rows as $row) {
			$first = $row;
			break;
		}
		$this->assertSame(array(7), $first);

		// Dropping the last reference destroys the suspended generator, which
		// is the point at which PHP runs its finally block.
		unset($rows);
	}

	public function testIterateClosesTheCursorWhenTheConsumerThrows(): void
	{
		$stmt = $this->statementExpectingOneCloseCursor(array(array(1), array(2)));

		$this->expectException(RuntimeException::class);
		foreach (StatementRows::iterate($stmt) as $row) {
			throw new RuntimeException('consumer blew up');
		}
	}

	public function testIterateSkipsNonListRows(): void
	{
		$stmt = $this->statementYielding(array(array(1), array('assoc' => 'not a list'), array(2)));

		$this->assertSame(
			array(array(1), array(2)),
			iterator_to_array(StatementRows::iterate($stmt), false)
		);
	}

	public function testAllMaterialisesRowsAsAList(): void
	{
		$stmt = $this->statementExpectingOneCloseCursor(array(array(1, 'a'), array(2, 'b')));

		$this->assertSame(array(array(1, 'a'), array(2, 'b')), StatementRows::all($stmt));
	}
}
