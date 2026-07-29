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
		$this->assertEquals('SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID FROM book ORDER BY (SELECT NULL FROM dual) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY', $sql, 'applyLimit() appends the native OFFSET/FETCH clause with no subquery wrapping');
	}

	public function testApplyLimitWithOffset()
	{
		Propulsion::setDb('oracle', new DBOracle());
		$c = new Criteria();
		$c->setDbName('oracle');
		BookPeer::addSelectColumns($c);
		$c->setOffset(5);
		$c->setLimit(10);
		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID FROM book ORDER BY (SELECT NULL FROM dual) OFFSET 5 ROWS FETCH NEXT 10 ROWS ONLY', $sql, 'applyLimit() honors both offset and limit via native OFFSET/FETCH');
	}

	public function testApplyLimitDuplicateColumnNameNoLongerNeedsAliasing()
	{
		// Duplicate select-column names across joined tables no longer need
		// pre-aliasing for pagination now that applyLimit() appends a native
		// OFFSET/FETCH clause instead of wrapping the query in nested
		// subqueries -- see DBOracle::applyLimit()'s own doc comment.
		Propulsion::setDb('oracle', new DBOracle());
		$c = new Criteria();
		$c->setDbName('oracle');
		BookPeer::addSelectColumns($c);
		AuthorPeer::addSelectColumns($c);
		$c->setLimit(1);
		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals('SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID, author.ID, author.FIRST_NAME, author.LAST_NAME, author.EMAIL, author.AGE FROM book, author ORDER BY (SELECT NULL FROM dual) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY', $sql);
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