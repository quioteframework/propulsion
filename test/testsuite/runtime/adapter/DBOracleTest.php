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
class DBOracleTest extends BookstoreTestBase
{
	public function testApplyLimitSimple()
	{
		Propulsion::setDb('oracle', new DBOracle());
		$c = new Criteria();
		$c->setDbName('oracle');
		BookPeer::addSelectColumns($c);
		$c->setLimit(1);
		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT B.ID, B.TITLE, B.ISBN, B.PRICE, B.PUBLISHER_ID, B.AUTHOR_ID FROM (SELECT A.*, rownum AS PROPEL_ROWNUM FROM (SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID FROM book) A ) B WHERE  B.PROPEL_ROWNUM <= 1', $sql, 'applyLimit() creates a subselect with the original column names by default');
	}

	public function testApplyLimitDuplicateColumnName()
	{
		Propulsion::setDb('oracle', new DBOracle());
		$c = new Criteria();
		$c->setDbName('oracle');
		BookPeer::addSelectColumns($c);
		AuthorPeer::addSelectColumns($c);
		$c->setLimit(1);
		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT B.ORA_COL_ALIAS_0, B.ORA_COL_ALIAS_1, B.ORA_COL_ALIAS_2, B.ORA_COL_ALIAS_3, B.ORA_COL_ALIAS_4, B.ORA_COL_ALIAS_5, B.ORA_COL_ALIAS_6, B.ORA_COL_ALIAS_7, B.ORA_COL_ALIAS_8, B.ORA_COL_ALIAS_9, B.ORA_COL_ALIAS_10 FROM (SELECT A.*, rownum AS PROPEL_ROWNUM FROM (SELECT book.ID AS ORA_COL_ALIAS_0, book.TITLE AS ORA_COL_ALIAS_1, book.ISBN AS ORA_COL_ALIAS_2, book.PRICE AS ORA_COL_ALIAS_3, book.PUBLISHER_ID AS ORA_COL_ALIAS_4, book.AUTHOR_ID AS ORA_COL_ALIAS_5, author.ID AS ORA_COL_ALIAS_6, author.FIRST_NAME AS ORA_COL_ALIAS_7, author.LAST_NAME AS ORA_COL_ALIAS_8, author.EMAIL AS ORA_COL_ALIAS_9, author.AGE AS ORA_COL_ALIAS_10 FROM book, author) A ) B WHERE  B.PROPEL_ROWNUM <= 1', $sql, 'applyLimit() creates a subselect with aliased column names when a duplicate column name is found');
	}

	public function testApplyLimitDuplicateColumnNameWithColumn()
	{
		Propulsion::setDb('oracle', new DBOracle());
		$c = new Criteria();
		$c->setDbName('oracle');
		BookPeer::addSelectColumns($c);
		AuthorPeer::addSelectColumns($c);
		$c->addAsColumn('BOOK_PRICE', BookPeer::PRICE);
		$c->setLimit(1);
		$params = array();
		$asColumns = $c->getAsColumns();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT B.ORA_COL_ALIAS_0, B.ORA_COL_ALIAS_1, B.ORA_COL_ALIAS_2, B.ORA_COL_ALIAS_3, B.ORA_COL_ALIAS_4, B.ORA_COL_ALIAS_5, B.ORA_COL_ALIAS_6, B.ORA_COL_ALIAS_7, B.ORA_COL_ALIAS_8, B.ORA_COL_ALIAS_9, B.ORA_COL_ALIAS_10, B.BOOK_PRICE FROM (SELECT A.*, rownum AS PROPEL_ROWNUM FROM (SELECT book.ID AS ORA_COL_ALIAS_0, book.TITLE AS ORA_COL_ALIAS_1, book.ISBN AS ORA_COL_ALIAS_2, book.PRICE AS ORA_COL_ALIAS_3, book.PUBLISHER_ID AS ORA_COL_ALIAS_4, book.AUTHOR_ID AS ORA_COL_ALIAS_5, author.ID AS ORA_COL_ALIAS_6, author.FIRST_NAME AS ORA_COL_ALIAS_7, author.LAST_NAME AS ORA_COL_ALIAS_8, author.EMAIL AS ORA_COL_ALIAS_9, author.AGE AS ORA_COL_ALIAS_10, book.PRICE AS BOOK_PRICE FROM book, author) A ) B WHERE  B.PROPEL_ROWNUM <= 1', $sql, 'applyLimit() creates a subselect with aliased column names when a duplicate column name is found');
		$this->assertEquals($asColumns, $c->getAsColumns(), 'createSelectSql supplementary add alias column');
	}

	public function testCreateSelectSqlPart()
	{
		Propulsion::setDb('oracle', new DBOracle());
		$db = Propulsion::getDB();
		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addAsColumn('book_ID', BookPeer::ID);
		$fromClause = array();
		$selectSql = $db->createSelectSqlPart($c, $fromClause);
		$this->assertEquals('SELECT book.ID, book.ID AS book_ID', $selectSql, 'createSelectSqlPart() returns a SQL SELECT clause with both select and as columns');
		$this->assertEquals(array('book'), $fromClause, 'createSelectSqlPart() adds the tables from the select columns to the from clause');
	}

	public function testToUpperCase()
	{
		$db = new DBOracle();
		$this->assertEquals('UPPER(foo)', $db->toUpperCase('foo'));
	}

	public function testIgnoreCase()
	{
		$db = new DBOracle();
		$this->assertEquals('UPPER(foo)', $db->ignoreCase('foo'));
	}

	public function testConcatString()
	{
		$db = new DBOracle();
		$this->assertEquals('CONCAT(foo, bar)', $db->concatString('foo', 'bar'));
	}

	public function testSubString()
	{
		$db = new DBOracle();
		$this->assertEquals('SUBSTR(foo, 1, 3)', $db->subString('foo', 1, 3));
	}

	public function testStrLength()
	{
		$db = new DBOracle();
		$this->assertEquals('LENGTH(foo)', $db->strLength('foo'));
	}

	public function testRandom()
	{
		$db = new DBOracle();
		$this->assertEquals('dbms_random.value', $db->random());
	}

	public function testGetIdThrowsWithoutSequenceName()
	{
		$db = new DBOracle();
		$this->expectException(PropulsionException::class);
		$db->getId($this->con);
	}

	public function testTurnSelectColumnsToAliases()
	{
		$db = new DBOracle();
		$c = new Criteria();
		BookPeer::addSelectColumns($c);
		$db->turnSelectColumnsToAliases($c);
		$asColumns = $c->getAsColumns();
		$this->assertEmpty($c->getSelectColumns(), 'turnSelectColumnsToAliases() clears the original select columns');
		$this->assertNotEmpty($asColumns, 'turnSelectColumnsToAliases() replaces them with aliased columns');
		foreach (array_keys($asColumns) as $alias) {
			$this->assertStringStartsWith('ORA_COL_ALIAS_', $alias);
		}
	}

	public function testSupportsUpsertAndUsesMerge()
	{
		$db = new DBOracle();
		$this->assertTrue($db->supportsUpsert());
		$this->assertTrue($db->usesMergeUpsert());
	}

	public function testGetMergeUpsertSql()
	{
		$db = new DBOracle();
		$sql = $db->getMergeUpsertSql('book', array('ID', 'TITLE', 'ISBN'), array('ID'), 'TITLE = :p4');
		$this->assertEquals(
			'MERGE INTO book USING (SELECT :p1 AS ID, :p2 AS TITLE, :p3 AS ISBN FROM dual) s ON (book.ID = s.ID) WHEN MATCHED THEN UPDATE SET TITLE = :p4 WHEN NOT MATCHED THEN INSERT (ID, TITLE, ISBN) VALUES (s.ID, s.TITLE, s.ISBN)',
			$sql,
			'no trailing semicolon (unlike MSSQL) -- executing one directly via OCI is a syntax error'
		);
	}

	public function testGetMergeUpsertSqlWithEmptySetClauseOmitsWhenMatched()
	{
		$db = new DBOracle();
		$sql = $db->getMergeUpsertSql('book', array('ID', 'TITLE'), array('ID'), '');
		$this->assertStringNotContainsString('WHEN MATCHED', $sql, 'an empty $setClause means "do nothing on conflict" -- no WHEN MATCHED clause at all');
		$this->assertStringContainsString('WHEN NOT MATCHED THEN INSERT (ID, TITLE) VALUES (s.ID, s.TITLE)', $sql);
	}

	public function testGetMergeUpsertSqlThrowsWithoutConflictColumns()
	{
		$db = new DBOracle();
		$this->expectException(PropulsionException::class);
		$db->getMergeUpsertSql('book', array('ID', 'TITLE'), array(), 'TITLE = :p3');
	}

}