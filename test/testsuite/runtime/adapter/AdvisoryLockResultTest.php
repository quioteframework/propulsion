<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Connection\PropulsionPDO;

/**
 * A PDOStatement standing in for one that returns several result sets, which
 * is what a T-SQL batch calling a stored procedure produces over pdo_dblib.
 *
 * @param array<int, array{columns: int, value: mixed}> $rowsets
 */
class ScriptedRowsetStatement extends \PDOStatement
{
	public int $closeCursorCalls = 0;

	/** @var array<string, mixed> Values bound by the adapter, for asserting on. */
	public array $boundValues = array();

	private int $index = 0;

	/** @param array<int, array{columns: int, value: mixed}> $rowsets */
	public function __construct(private readonly array $rowsets)
	{
	}

	public function execute(?array $params = null): bool
	{
		return true;
	}

	public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
	{
		$this->boundValues[(string) $param] = $value;

		return true;
	}

	public function columnCount(): int
	{
		return $this->rowsets[$this->index]['columns'] ?? 0;
	}

	public function fetchColumn(int $column = 0): mixed
	{
		// array_key_exists, not ??: a rowset whose value is genuinely null
		// must come back as null, which is the case the "don't skip a falsy
		// value" test below is about.
		return array_key_exists($this->index, $this->rowsets) ? $this->rowsets[$this->index]['value'] : false;
	}

	public function nextRowset(): bool
	{
		if (!isset($this->rowsets[$this->index + 1])) {
			return false;
		}
		$this->index++;

		return true;
	}

	public function closeCursor(): bool
	{
		$this->closeCursorCalls++;

		return true;
	}
}

/**
 * Regression coverage for {@see \Propulsion\Adapter\DBAdapter::fetchAdvisoryLockResult()}'s
 * result-set handling.
 *
 * This exists because of a real bug, found only by running the advisory-lock
 * code against a live SQL Server: `sp_getapplock` is a stored procedure, so
 * the batch that calls it and selects its return code yields *two* result sets
 * over pdo_dblib -- an empty one from the `EXEC`, then the `SELECT`. Reading
 * the first gave `false`, which the caller read as "lock not acquired", so
 * every acquisition silently failed. Leaving the remaining rowsets unconsumed
 * additionally broke the *next* statement on that connection with dblib's
 * "results pending" error.
 *
 * Driven through a scripted statement rather than a live server so the
 * regression stays covered on every run, including the no-Docker tier.
 */
class AdvisoryLockResultTest extends TestCase
{
	/**
	 * @param array<int, array{columns: int, value: mixed}> $rowsets
	 */
	private function runAgainst(array $rowsets, ?ScriptedRowsetStatement &$stmt = null): mixed
	{
		$stmt = new ScriptedRowsetStatement($rowsets);
		$con = $this->createStub(PropulsionPDO::class);
		$con->method('prepare')->willReturn($stmt);

		$adapter = new class extends DBMSSQL {
			/** @param array<int, mixed> $params */
			public function readResult(PropulsionPDO $con, string $sql, array $params = array()): mixed
			{
				return $this->fetchAdvisoryLockResult($con, $sql, $params);
			}
		};

		return $adapter->readResult($con, 'irrelevant', array('name', 0));
	}

	public function testASingleRowsetIsReadAsBefore()
	{
		$this->assertSame(0, $this->runAgainst(array(array('columns' => 1, 'value' => 0))));
	}

	public function testALeadingEmptyRowsetIsSkipped()
	{
		// The MSSQL shape: EXEC produces a columnless rowset, then SELECT
		// produces the return code. Reading the first one is what silently
		// broke every sp_getapplock acquisition.
		$this->assertSame(0, $this->runAgainst(array(
			array('columns' => 0, 'value' => false),
			array('columns' => 1, 'value' => 0),
		)));
	}

	public function testSeveralLeadingEmptyRowsetsAreSkipped()
	{
		$this->assertSame(-1, $this->runAgainst(array(
			array('columns' => 0, 'value' => false),
			array('columns' => 0, 'value' => false),
			array('columns' => 1, 'value' => -1),
		)));
	}

	public function testAFalsyValueInARealRowsetIsNotSkippedPast()
	{
		// columnCount() decides, not the value: a rowset that genuinely
		// answers false/null must be returned rather than searched past in
		// hope of a later one.
		$this->assertNull($this->runAgainst(array(
			array('columns' => 1, 'value' => null),
			array('columns' => 1, 'value' => 99),
		)));
	}

	public function testNoUsableRowsetYieldsFalse()
	{
		$this->assertFalse($this->runAgainst(array(array('columns' => 0, 'value' => false))));
	}

	/**
	 * "Wait forever" must not be MySQL's negative-timeout spelling, which
	 * MariaDB rejects outright ("Incorrect timeout value") and answers NULL to
	 * -- i.e. never acquires. Since null is withAdvisoryLock()'s *default*
	 * timeout, that made its commonest call shape fail silently on every
	 * MariaDB server until a live run caught it.
	 *
	 * Asserted as a property of the emitted value rather than per server
	 * version on purpose: the fix is a single timeout that both engines
	 * accept, so there is deliberately no version probe left in this path for
	 * a test to have to mirror. Pinning example versions here would imply a
	 * dependence that no longer exists -- and would have to be revisited on
	 * every server release for no reason.
	 */
	public function testWaitForeverUsesATimeoutBothEnginesAccept()
	{
		$this->assertGreaterThan(
			0,
			$this->timeoutBoundFor(null),
			'a negative GET_LOCK timeout means "forever" only to MySQL; MariaDB rejects it'
		);
		// Large enough that it cannot be reached in practice, so it is
		// indistinguishable from an infinite wait.
		$this->assertGreaterThanOrEqual(86400 * 365, $this->timeoutBoundFor(null));
	}

	public function testAFiniteTimeoutIsRoundedUp()
	{
		// Rounding down would turn "wait briefly" into "don't wait", which is
		// a different operation. GET_LOCK takes whole seconds.
		$this->assertSame(1, $this->timeoutBoundFor(0.4));
		$this->assertSame(1, $this->timeoutBoundFor(1.0));
		$this->assertSame(2, $this->timeoutBoundFor(1.2));
	}

	public function testAZeroTimeoutStaysZero()
	{
		$this->assertSame(0, $this->timeoutBoundFor(0.0));
	}

	/** The seconds DBMySQL binds to GET_LOCK's second argument for $timeout. */
	private function timeoutBoundFor(?float $timeout): int
	{
		$stmt = new ScriptedRowsetStatement(array(array('columns' => 1, 'value' => 1)));
		$con = $this->createStub(PropulsionPDO::class);
		$con->method('prepare')->willReturn($stmt);

		$adapter = new \Propulsion\Adapter\DBMySQL();
		$adapter->acquireAdvisoryLock($con, 'x', $timeout);

		$bound = $stmt->boundValues[':p2'] ?? null;
		$this->assertIsInt($bound);

		return $bound;
	}

	public function testTheCursorIsAlwaysClosed()
	{
		// Unconsumed rowsets make the *next* statement on a dblib connection
		// fail with "Attempt to initiate a new Adaptive Server operation with
		// results pending".
		$stmt = null;
		$this->runAgainst(array(array('columns' => 0, 'value' => false), array('columns' => 1, 'value' => 0)), $stmt);
		$this->assertNotNull($stmt);
		$this->assertSame(1, $stmt->closeCursorCalls);
	}
}
