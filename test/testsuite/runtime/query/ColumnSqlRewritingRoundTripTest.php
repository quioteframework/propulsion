<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Map\ColumnMap;
use Propulsion\Propulsion;
use Propulsion\Query\Criteria;
use Propulsion\Util\BasePeer;

/**
 * The same hooks again, but with conversion functions a real database actually
 * has (`upper()` in, `lower()` out) against a real `sqlite::memory:`
 * connection, so the INSERT/UPDATE/SELECT wiring is exercised end to end
 * rather than only as a generated string. This is the shape that catches a
 * placeholder wrapped in the SQL but not bound (or bound twice) -- the failure
 * mode a string assertion cannot see.
 */
class RoundTripRewritingAdapter extends DBSQLite
{
	public function usesColumnSqlRewriting(): bool
	{
		return true;
	}

	public function getColumnBindExpression(ColumnMap $cMap, string $placeholder): string
	{
		return $cMap->isNativeVector() ? 'upper(' . $placeholder . ')' : $placeholder;
	}

	public function getColumnSelectExpression(ColumnMap $cMap, string $columnExpression): string
	{
		return $cMap->isNativeVector() ? 'lower(' . $columnExpression . ')' : $columnExpression;
	}
}

class ColumnSqlRewritingRoundTripTest extends TestCase
{
	private const DATASOURCE = 'column_sql_rewriting_roundtrip_test';

	private \Propulsion\Connection\PropulsionPDO $pdo;

	protected function setUp(): void
	{
		parent::setUp();

		$this->pdo = new \Propulsion\Adapter\Sqlite\SqlitePropulsionPDO('sqlite::memory:');
		$this->pdo->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
		$this->pdo->exec('CREATE TABLE doc (ID INTEGER PRIMARY KEY, TITLE TEXT, EMBEDDING TEXT)');

		Propulsion::setDB(self::DATASOURCE, new RoundTripRewritingAdapter());
		Propulsion::setConnection(self::DATASOURCE, $this->pdo, Propulsion::CONNECTION_WRITE);

		$dbMap = Propulsion::getDatabaseMap(self::DATASOURCE);
		if (!$dbMap->hasTable('doc')) {
			$table = $dbMap->addTable('doc');
			$table->setClassname('RoundTripDoc');
			$table->addColumn('ID', 'Id', 'INTEGER', true, null, null, true);
			$table->addColumn('TITLE', 'Title', 'VARCHAR', false, 255);
			$table->addColumn('EMBEDDING', 'Embedding', 'VECTOR', false, 3)->setNativeVector(true);
		}
	}

	protected function tearDown(): void
	{
		Propulsion::discardConnection($this->pdo);
		parent::tearDown();
	}

	private function storedEmbedding(): string
	{
		$stmt = $this->pdo->query('SELECT EMBEDDING FROM doc');
		$this->assertNotFalse($stmt);
		$value = $stmt->fetchColumn();

		return is_string($value) ? $value : '';
	}

	public function testInsertWritesThroughTheConversionFunction()
	{
		$c = new Criteria(self::DATASOURCE);
		$c->add('doc.TITLE', 'a title');
		$c->add('doc.EMBEDDING', 'abc');
		BasePeer::doInsert($c, $this->pdo);

		$this->assertSame('ABC', $this->storedEmbedding(), 'the bind expression ran server-side');
	}

	public function testSelectReadsBackThroughTheConversionFunction()
	{
		$this->pdo->exec("INSERT INTO doc (TITLE, EMBEDDING) VALUES ('t', 'ABC')");

		$c = new Criteria(self::DATASOURCE);
		$c->setPrimaryTableName('doc');
		$c->addSelectColumn('doc.EMBEDDING');
		$stmt = BasePeer::doSelect($c, $this->pdo);

		$this->assertSame('abc', $stmt->fetchColumn());
	}

	public function testUpdateWritesThroughTheConversionFunction()
	{
		$this->pdo->exec("INSERT INTO doc (ID, TITLE, EMBEDDING) VALUES (1, 't', 'ABC')");

		$select = new Criteria(self::DATASOURCE);
		$select->add('doc.ID', 1);
		$update = new Criteria(self::DATASOURCE);
		$update->add('doc.EMBEDDING', 'xyz');
		$this->assertSame(1, BasePeer::doUpdate($select, $update, $this->pdo));

		$this->assertSame('XYZ', $this->storedEmbedding());
	}

	public function testWhereClauseMatchesThroughTheConversionFunction()
	{
		$this->pdo->exec("INSERT INTO doc (ID, TITLE, EMBEDDING) VALUES (1, 't', 'ABC')");

		$c = new Criteria(self::DATASOURCE);
		$c->setPrimaryTableName('doc');
		$c->addSelectColumn('doc.ID');
		// Stored uppercased; the WHERE value is uppercased by the same bind
		// expression, so a lowercase input still finds the row.
		$c->add('doc.EMBEDDING', 'abc');

		$this->assertSame('1', (string) BasePeer::doSelect($c, $this->pdo)->fetchColumn());
	}
}
