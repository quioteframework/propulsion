<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the DbOracle adapter
 *
 * @see        BookstoreDataPopulator
 * @author     Francois EZaninotto
 */
class DBAdapterTest extends BookstoreTestBase
{

	public function testTurnSelectColumnsToAliases()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c1 = new Criteria();
		$c1->addSelectColumn(BookPeer::ID);
		$db->turnSelectColumnsToAliases($c1);

		$c2 = new Criteria();
		$c2->addAsColumn('book_ID', BookPeer::ID);
		$this->assertTrue($c1->equals($c2));
	}

	public function testTurnSelectColumnsToAliasesPreservesAliases()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c1 = new Criteria();
		$c1->addSelectColumn(BookPeer::ID);
		$c1->addAsColumn('foo', BookPeer::TITLE);
		$db->turnSelectColumnsToAliases($c1);

		$c2 = new Criteria();
		$c2->addAsColumn('book_ID', BookPeer::ID);
		$c2->addAsColumn('foo', BookPeer::TITLE);
		$this->assertTrue($c1->equals($c2));
	}

	public function testTurnSelectColumnsToAliasesExisting()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c1 = new Criteria();
		$c1->addSelectColumn(BookPeer::ID);
		$c1->addAsColumn('book_ID', BookPeer::ID);
		$db->turnSelectColumnsToAliases($c1);

		$c2 = new Criteria();
		$c2->addAsColumn('book_ID_1', BookPeer::ID);
		$c2->addAsColumn('book_ID', BookPeer::ID);
		$this->assertTrue($c1->equals($c2));
	}

	public function testTurnSelectColumnsToAliasesDuplicate()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c1 = new Criteria();
		$c1->addSelectColumn(BookPeer::ID);
		$c1->addSelectColumn(BookPeer::ID);
		$db->turnSelectColumnsToAliases($c1);

		$c2 = new Criteria();
		$c2->addAsColumn('book_ID', BookPeer::ID);
		$c2->addAsColumn('book_ID_1', BookPeer::ID);
		$this->assertTrue($c1->equals($c2));
	}

	public function testCreateSelectSqlPart()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addAsColumn('book_ID', BookPeer::ID);
		$fromClause = array();
		$selectSql = $db->createSelectSqlPart($c, $fromClause);
		$this->assertEquals('SELECT book.ID, book.ID AS book_ID', $selectSql, 'createSelectSqlPart() returns a SQL SELECT clause with both select and as columns');
		$this->assertEquals(array('book'), $fromClause, 'createSelectSqlPart() adds the tables from the select columns to the from clause');
	}

	public function testCreateSelectSqlPartSelectModifier()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addAsColumn('book_ID', BookPeer::ID);
		$c->setDistinct();
		$fromClause = array();
		$selectSql = $db->createSelectSqlPart($c, $fromClause);
		$this->assertEquals('SELECT DISTINCT book.ID, book.ID AS book_ID', $selectSql, 'createSelectSqlPart() includes the select modifiers in the SELECT clause');
		$this->assertEquals(array('book'), $fromClause, 'createSelectSqlPart() adds the tables from the select columns to the from clause');
	}

	public function testCreateSelectSqlPartAliasAll()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addAsColumn('book_ID', BookPeer::ID);
		$fromClause = array();
		$selectSql = $db->createSelectSqlPart($c, $fromClause, true);
		$this->assertEquals('SELECT book.ID AS book_ID_1, book.ID AS book_ID', $selectSql, 'createSelectSqlPart() aliases all columns if passed true as last parameter');
		$this->assertEquals(array(), $fromClause, 'createSelectSqlPart() does not add the tables from an all-aliased list of select columns');
	}

	public function testDefaultAdapterDoesNotSupportInsertReturning()
	{
		// DBSQLite overrides this to true (SQLite 3.35+ supports RETURNING); use an
		// adapter that still relies on the DBAdapter default of false.
		$db = new DBMySQL();
		$this->assertFalse($db->supportsInsertReturning(), 'the default DBAdapter::supportsInsertReturning() is false');
	}

	public function testMssqlSupportsInsertReturning()
	{
		$db = new DBMSSQL();
		$this->assertTrue($db->supportsInsertReturning());
	}

	public function testMssqlGetInsertReturningSql()
	{
		$db = new DBMSSQL();
		$sql = 'INSERT INTO book (TITLE,ISBN) VALUES (:p1,:p2)';
		$expected = 'INSERT INTO book (TITLE,ISBN) OUTPUT INSERTED.ID VALUES (:p1,:p2)';
		$this->assertEquals($expected, $db->getInsertReturningSql($sql, 'ID'));
	}

	public function testMssqlGetInsertReturningSqlThrowsWithoutValuesClause()
	{
		$db = new DBMSSQL();
		$this->expectException(PropulsionException::class);
		$db->getInsertReturningSql('not an insert statement', 'ID');
	}

}