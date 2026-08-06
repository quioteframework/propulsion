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

	public function testNativeVectorOnMariaDbUsesVecFromTextAndVecToText()
	{
		$db = new DBMySQL();
		$db->setServerFlavor(true);
		$column = $this->makeColumn(true);

		$this->assertEquals('VEC_FromText(:p1)', $db->getColumnBindExpression($column, ':p1'));
		$this->assertEquals('VEC_ToText(book.EMBEDDING)', $db->getColumnSelectExpression($column, 'book.EMBEDDING'));
	}

	public function testNativeVectorOnMysqlUsesStringToVectorAndVectorToString()
	{
		// MySQL 9.0+ has the same VECTOR(n) column type but named its
		// conversion functions differently, and neither engine recognises the
		// other's -- so this is not cosmetic, it is the difference between a
		// working column and a "FUNCTION does not exist" error.
		$db = new DBMySQL();
		$db->setServerFlavor(false);
		$column = $this->makeColumn(true);

		$this->assertEquals('STRING_TO_VECTOR(:p1)', $db->getColumnBindExpression($column, ':p1'));
		$this->assertEquals('VECTOR_TO_STRING(book.EMBEDDING)', $db->getColumnSelectExpression($column, 'book.EMBEDDING'));
	}

	public function testUndetectedServerFlavorDefaultsToMariaDb()
	{
		// Only reachable for a connection the application built itself and
		// handed to setConnection(), since initConnection() primes the probe
		// otherwise. A wrong guess here fails loudly at the server rather than
		// corrupting anything, and setServerFlavor() is the one-line fix.
		$db = new DBMySQL();
		$this->assertEquals('VEC_FromText(:p1)', $db->getColumnBindExpression($this->makeColumn(true), ':p1'));
	}

	public function testServerFlavorCanBeResetToDetection()
	{
		$db = new DBMySQL();
		$db->setServerFlavor(false);
		$this->assertEquals('STRING_TO_VECTOR(:p1)', $db->getColumnBindExpression($this->makeColumn(true), ':p1'));

		$db->setServerFlavor(null);
		$this->assertEquals('VEC_FromText(:p1)', $db->getColumnBindExpression($this->makeColumn(true), ':p1'));
	}

	public function testInitConnectionPrimesTheServerFlavorFromTheVersionString()
	{
		// This is the mechanism the column hooks rely on: they have no
		// connection to ask, so the answer must already be cached by the time
		// SQL is built -- which it is, because opening the connection comes
		// first.
		$mysql = new DBMySQL();
		$mysql->initConnection($this->fakeConnection('9.0.1'), array());
		$this->assertEquals('STRING_TO_VECTOR(:p1)', $mysql->getColumnBindExpression($this->makeColumn(true), ':p1'));

		$mariadb = new DBMySQL();
		$mariadb->initConnection($this->fakeConnection('11.8.2-MariaDB-ubu2404'), array());
		$this->assertEquals('VEC_FromText(:p1)', $mariadb->getColumnBindExpression($this->makeColumn(true), ':p1'));

		// MariaDB's fake "5.5.5-" backward-compatibility prefix must not fool it.
		$prefixed = new DBMySQL();
		$prefixed->initConnection($this->fakeConnection('5.5.5-10.11.6-MariaDB'), array());
		$this->assertEquals('VEC_FromText(:p1)', $prefixed->getColumnBindExpression($this->makeColumn(true), ':p1'));
	}

	/**
	 * A PDO that answers ATTR_SERVER_VERSION and nothing else -- enough for
	 * initConnection()'s probe, and no server required to run it.
	 */
	private function fakeConnection(string $serverVersion): PDO
	{
		return new class($serverVersion) extends PDO {
			public function __construct(private readonly string $serverVersion)
			{
			}

			public function getAttribute(int $attribute): mixed
			{
				return $attribute === PDO::ATTR_SERVER_VERSION ? $this->serverVersion : null;
			}

			public function exec(string $statement): int|false
			{
				return 0;
			}
		};
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
