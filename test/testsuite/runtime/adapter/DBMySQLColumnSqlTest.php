<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;

/**
 * DBMySQL's column-SQL-rewriting hooks -- the MariaDB 11.7+ native `VECTOR(n)`
 * conversion functions that {@see \Propulsion\Adapter\DBAdapter::getColumnBindExpression()}
 * exists for.
 *
 * A separate class from DBMySQLTest rather than more methods on it: that one
 * extends BookstoreTestBase and so skips itself wholesale without a live
 * database, whereas none of this needs one -- these are string-shape
 * assertions, and MariaDB 11.7 is not something CI has to hand anyway.
 */
class DBMySQLColumnSqlTest extends TestCase
{
	private function makeColumn(bool $nativeVector): ColumnMap
	{
		$dbMap = new DatabaseMap('dbmysql_column_sql_test');
		$column = $dbMap->addTable('doc')->addColumn('EMBEDDING', 'Embedding', 'VECTOR', false, 3);
		$column->setNativeVector($nativeVector);

		return $column;
	}

	public function testUsesColumnSqlRewriting()
	{
		$this->assertTrue((new DBMySQL())->usesColumnSqlRewriting(), 'because of nativeVector columns');
	}

	public function testNativeVectorColumnIsWrittenThroughVecFromText()
	{
		$db = new DBMySQL();
		$this->assertEquals('VEC_FromText(:p1)', $db->getColumnBindExpression($this->makeColumn(true), ':p1'));
	}

	public function testNativeVectorColumnIsReadThroughVecToText()
	{
		$db = new DBMySQL();
		$this->assertEquals('VEC_ToText(book.EMBEDDING)', $db->getColumnSelectExpression($this->makeColumn(true), 'book.EMBEDDING'));
	}

	public function testEmulatedVectorColumnIsLeftAlone()
	{
		$db = new DBMySQL();
		$column = $this->makeColumn(false);
		$this->assertEquals(':p1', $db->getColumnBindExpression($column, ':p1'));
		$this->assertEquals('book.EMBEDDING', $db->getColumnSelectExpression($column, 'book.EMBEDDING'));
	}

	public function testOtherAdaptersDoNotRewriteAnything()
	{
		$column = $this->makeColumn(true);
		foreach (array(new \Propulsion\Adapter\DBPostgres(), new \Propulsion\Adapter\DBSQLite(), new \Propulsion\Adapter\DBMSSQL(), new \Propulsion\Adapter\DBOracle()) as $db) {
			$this->assertFalse($db->usesColumnSqlRewriting(), get_class($db));
			$this->assertEquals(':p1', $db->getColumnBindExpression($column, ':p1'));
			$this->assertEquals('book.EMBEDDING', $db->getColumnSelectExpression($column, 'book.EMBEDDING'));
		}
	}
}
