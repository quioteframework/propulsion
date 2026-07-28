<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the BasePeer classes.
 *
 * @see        BookstoreDataPopulator
 * @author     Hans Lellelid <hans@xmpl.org>
 */
class BasePeerTest extends BookstoreTestBase
{

	public function testMultipleFunctionInCriteria()
	{
		$this->expectNotToPerformAssertions();
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		try {
			$c = new Criteria();
			$c->setDistinct();
			if ($db instanceof DBPostgres) {
				$c->addSelectColumn("substring(".BookPeer::TITLE." from position('Potter' in ".BookPeer::TITLE.")) AS col");
			} else {
				$this->markTestSkipped();
			}
			$stmt = BookPeer::doSelectStmt( $c );
		} catch (PropulsionException $x) {
			$this->fail("Paring of nested functions failed: " . $x->getMessage());
		}
	}

	public function testNeedsSelectAliases()
	{
		$c = new Criteria();
		$this->assertFalse(BasePeer::needsSelectAliases($c), 'Empty Criterias dont need aliases');

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);
		$this->assertFalse(BasePeer::needsSelectAliases($c), 'Criterias with distinct column names dont need aliases');

		$c = new Criteria();
		BookPeer::addSelectColumns($c);
		$this->assertFalse(BasePeer::needsSelectAliases($c), 'Criterias with only the columns of a model dont need aliases');

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(AuthorPeer::ID);
		$this->assertTrue(BasePeer::needsSelectAliases($c), 'Criterias with common column names do need aliases');
	}

	public function testDoCountDuplicateColumnName()
	{
		$con = Propulsion::getConnection();
		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addJoin(BookPeer::AUTHOR_ID, AuthorPeer::ID);
		$c->addSelectColumn(AuthorPeer::ID);
		$c->setLimit(3);
		try {
			$count = BasePeer::doCount($c, $con);
		} catch (Exception $e) {
			$this->fail('doCount() cannot deal with a criteria selecting duplicate column names ');
		}
		$this->assertInstanceOf('PDOStatement', $count, 'doCount() should return a statement even when the criteria selects duplicate column names');
	}

	public function testBigIntIgnoreCaseOrderBy()
	{
		BookstorePeer::doDeleteAll();

		// Some sample data
		$b = new Bookstore();
		$b->setStoreName("SortTest1")->setPopulationServed(2000)->save();

		$b = new Bookstore();
		$b->setStoreName("SortTest2")->setPopulationServed(201)->save();

		$b = new Bookstore();
		$b->setStoreName("SortTest3")->setPopulationServed(302)->save();

		$b = new Bookstore();
		$b->setStoreName("SortTest4")->setPopulationServed(10000000)->save();

		$c = new Criteria();
		$c->setIgnoreCase(true);
		$c->add(BookstorePeer::STORE_NAME, 'SortTest%', Criteria::LIKE);
		$c->addAscendingOrderByColumn(BookstorePeer::POPULATION_SERVED);

		$rows = BookstorePeer::doSelect($c);
		$this->assertEquals('SortTest2', $rows[0]->getStoreName());
		$this->assertEquals('SortTest3', $rows[1]->getStoreName());
		$this->assertEquals('SortTest1', $rows[2]->getStoreName());
		$this->assertEquals('SortTest4', $rows[3]->getStoreName());
	}

	public function testMixedJoinOrder()
	{
		$this->markTestSkipped('Famous cross join problem, to be solved one day');
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);

		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);
		$c->addJoin(BookPeer::AUTHOR_ID, AuthorPeer::ID);

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$expectedSql = "SELECT book.ID, book.TITLE FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID), author WHERE book.AUTHOR_ID=author.ID";
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlApplyLimitNoOffset()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if(! ($db instanceof DBMSSQL))
		{
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);
		$c->addSelectColumn(PublisherPeer::NAME);
		$c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');

		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);

		$c->setOffset(0);
		$c->setLimit(20);

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$expectedSql = "SELECT TOP 20 book.ID, book.TITLE, publisher.NAME, (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS PublisherName FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)";
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlApplyLimitWithOffset()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if(! ($db instanceof DBMSSQL))
		{
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);
		$c->addSelectColumn(PublisherPeer::NAME);
		$c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);
		$c->setOffset(20);
		$c->setLimit(20);

		$params = array();

		$expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY book.ID) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlApplyLimitWithOffsetOrderByAggregate()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if(! ($db instanceof DBMSSQL))
		{
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);
		$c->addSelectColumn(PublisherPeer::NAME);
		$c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);
		$c->addDescendingOrderByColumn('PublisherName');
		$c->setOffset(20);
		$c->setLimit(20);

		$params = array();

		$expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) DESC) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlApplyLimitWithOffsetMultipleOrderBy()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if(! ($db instanceof DBMSSQL))
		{
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(BookPeer::TITLE);
		$c->addSelectColumn(PublisherPeer::NAME);
		$c->addAsColumn('PublisherName','(SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID)');
		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);
		$c->addDescendingOrderByColumn('PublisherName');
		$c->addAscendingOrderByColumn(BookPeer::TITLE);
		$c->setOffset(20);
		$c->setLimit(20);

		$params = array();

		$expectedSql = "SELECT [book.ID], [book.TITLE], [publisher.NAME], [PublisherName] FROM (SELECT ROW_NUMBER() OVER(ORDER BY (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) DESC, book.TITLE ASC) AS [RowNumber], book.ID AS [book.ID], book.TITLE AS [book.TITLE], publisher.NAME AS [publisher.NAME], (SELECT MAX(publisher.NAME) FROM publisher WHERE publisher.ID = book.PUBLISHER_ID) AS [PublisherName] FROM book LEFT JOIN publisher ON (book.PUBLISHER_ID=publisher.ID)) AS derivedb WHERE RowNumber BETWEEN 21 AND 40";
		$sql = BasePeer::createSelectSql($c, $params);
		$this->assertEquals($expectedSql, $sql);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testDoDeleteNoCondition()
	{
		$this->expectException(PropulsionException::class);
		$con = Propulsion::getConnection();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		BasePeer::doDelete($c, $con);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testDoDeleteJoin()
	{
		$this->expectException(PropulsionException::class);
		$con = Propulsion::getConnection();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->add(BookPeer::TITLE, 'War And Peace');
		$c->addJoin(BookPeer::AUTHOR_ID, AuthorPeer::ID);
		BasePeer::doDelete($c, $con);
	}

	public function testDoDeleteSimpleCondition()
	{
		$con = Propulsion::getConnection();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->add(BookPeer::TITLE, 'War And Peace');
		BasePeer::doDelete($c, $con);
		$expectedSQL = "DELETE FROM book WHERE book.TITLE='War And Peace'";
		$this->assertEquals($expectedSQL, normalizeGeneratedSql($con->getLastExecutedQuery()), 'doDelete() translates a contition into a WHERE');
	}

	public function testDoDeleteSeveralConditions()
	{
		$con = Propulsion::getConnection();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->add(BookPeer::TITLE, 'War And Peace');
		$c->add(BookPeer::ID, 12);
		BasePeer::doDelete($c, $con);
		$expectedSQL = "DELETE FROM book WHERE book.TITLE='War And Peace' AND book.ID=12";
		$this->assertEquals($expectedSQL, normalizeGeneratedSql($con->getLastExecutedQuery()), 'doDelete() combines conditions in WHERE whith an AND');
	}

	public function testDoDeleteTableAlias()
	{
		$con = Propulsion::getConnection();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addAlias('b', BookPeer::TABLE_NAME);
		$c->add('b.TITLE', 'War And Peace');
		BasePeer::doDelete($c, $con);
		$expectedSQL = "DELETE FROM book AS b WHERE b.TITLE='War And Peace'";
		$this->assertEquals($expectedSQL, normalizeGeneratedSql($con->getLastExecutedQuery()), 'doDelete() accepts a Criteria with a table alias');
	}

	/**
	 * Not documented anywhere, and probably wrong
	 * @see http://www.propelorm.org/ticket/952
	 */
	public function testDoDeleteSeveralTables()
	{
		$con = Propulsion::getConnection();
		$count = $con->getQueryCount();
		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->add(BookPeer::TITLE, 'War And Peace');
		$c->add(AuthorPeer::FIRST_NAME, 'Leo');
		BasePeer::doDelete($c, $con);
		$expectedSQL = "DELETE FROM author WHERE author.FIRST_NAME='Leo'";
		$this->assertEquals($expectedSQL, normalizeGeneratedSql($con->getLastExecutedQuery()), 'doDelete() issues two DELETE queries when passed conditions on two tables');
		$this->assertEquals($count + 2, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->add(AuthorPeer::FIRST_NAME, 'Leo');
		$c->add(BookPeer::TITLE, 'War And Peace');
		BasePeer::doDelete($c, $con);
		$expectedSQL = "DELETE FROM book WHERE book.TITLE='War And Peace'";
		$this->assertEquals($expectedSQL, normalizeGeneratedSql($con->getLastExecutedQuery()), 'doDelete() issues two DELETE queries when passed conditions on two tables');
		$this->assertEquals($count + 4, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
	}

	public function testCommentDoSelect()
	{
		$c = new Criteria();
		$c->setComment('Foo');
		$c->addSelectColumn(BookPeer::ID);
		$expected = 'SELECT /* Foo */ book.ID FROM book';
		$params = array();
		$this->assertEquals($expected, normalizeGeneratedSql(BasePeer::createSelectSQL($c, $params)), 'Criteria::setComment() adds a comment to select queries');
	}

	public function testForUpdateDoSelect()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBSQLite) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForUpdate();
		$expected = 'SELECT book.ID FROM book FOR UPDATE';
		$params = array();
		$this->assertEquals($expected, normalizeGeneratedSql(BasePeer::createSelectSQL($c, $params)), 'Criteria::setLockForUpdate() adds a trailing FOR UPDATE clause');
	}

	public function testForUpdateSkipLockedDoSelect()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBSQLite) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForUpdate(true);
		$expected = 'SELECT book.ID FROM book FOR UPDATE SKIP LOCKED';
		$params = array();
		$this->assertEquals($expected, normalizeGeneratedSql(BasePeer::createSelectSQL($c, $params)), 'Criteria::setLockForUpdate(true) appends SKIP LOCKED');
	}

	public function testForShareDoSelect()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBSQLite || $db instanceof DBOracle) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForShare();
		$expected = 'SELECT book.ID FROM book FOR SHARE';
		$params = array();
		$this->assertEquals($expected, normalizeGeneratedSql(BasePeer::createSelectSQL($c, $params)), 'Criteria::setLockForShare() adds a trailing FOR SHARE clause');
	}

	public function testForUpdateWithLimitDoSelect()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBSQLite) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLimit(10);
		$c->setLockForUpdate();
		$params = array();
		$sql = BasePeer::createSelectSQL($c, $params);
		$this->assertStringEndsWith('FOR UPDATE', $sql, 'the lock clause trails LIMIT/OFFSET');
	}

	public function testForUpdateUnsupportedOnSqlite()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBSQLite)) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForUpdate();
		$params = array();
		$this->expectException(PropulsionException::class);
		BasePeer::createSelectSQL($c, $params);
	}

	public function testForShareUnsupportedOnOracle()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBOracle)) {
			$this->markTestSkipped();
		}

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForShare();
		$params = array();
		$this->expectException(PropulsionException::class);
		BasePeer::createSelectSQL($c, $params);
	}

	public function testMssqlForUpdateAddsTableHints()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL)) {
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->addSelectColumn(PublisherPeer::NAME);
		$c->addJoin(BookPeer::PUBLISHER_ID, PublisherPeer::ID, Criteria::LEFT_JOIN);
		$c->setLockForUpdate();

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$expectedSql = "SELECT book.ID, publisher.NAME FROM book WITH (UPDLOCK, ROWLOCK) LEFT JOIN publisher WITH (UPDLOCK, ROWLOCK) ON (book.PUBLISHER_ID=publisher.ID)";
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlForShareAddsHoldlockHint()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL)) {
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForShare();

		$params = array();
		$sql = BasePeer::createSelectSql($c, $params);

		$expectedSql = "SELECT book.ID FROM book WITH (HOLDLOCK, ROWLOCK)";
		$this->assertEquals($expectedSql, $sql);
	}

	public function testMssqlNoWaitUnsupported()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL)) {
			$this->markTestSkipped();
		}

		$c = new Criteria(BookPeer::DATABASE_NAME);
		$c->addSelectColumn(BookPeer::ID);
		$c->setLockForUpdate(false, true);

		$params = array();
		$this->expectException(PropulsionException::class);
		BasePeer::createSelectSql($c, $params);
	}

	public function testCommentDoUpdate()
	{
		$c1 = new Criteria();
		$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
		$c1->setComment('Foo');
		$c2 = new Criteria();
		$c2->add(BookPeer::TITLE, 'Updated Title');
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BasePeer::doUpdate($c1, $c2, $con);
		$expected = 'UPDATE /* Foo */ book SET TITLE=\'Updated Title\'';
		$this->assertEquals($expected, normalizeGeneratedSql($con->getLastExecutedQuery()), 'Criteria::setComment() adds a comment to update queries');
	}

	public function testRawExpressionDoUpdate()
	{
		$c1 = new Criteria();
		$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
		$c2 = new Criteria();
		$c2->add(BookPeer::PRICE, array('raw' => BookPeer::PRICE . ' + ?', 'value' => 1), Criteria::CUSTOM_EQUAL);
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BasePeer::doUpdate($c1, $c2, $con);
		$expected = 'UPDATE book SET PRICE = book.PRICE + 1';
		$this->assertEquals($expected, normalizeGeneratedSql($con->getLastExecutedQuery()), 'a raw update expression with a "?" placeholder binds its value');
	}

	public function testRawExpressionDoUpdateWithoutValue()
	{
		$c1 = new Criteria();
		$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
		$c2 = new Criteria();
		$c2->add(BookPeer::TITLE, array('raw' => "'literal'"), Criteria::CUSTOM_EQUAL);
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BasePeer::doUpdate($c1, $c2, $con);
		$expected = "UPDATE book SET TITLE = 'literal'";
		$this->assertEquals($expected, normalizeGeneratedSql($con->getLastExecutedQuery()), 'a raw update expression without a "?" placeholder needs no bound value');
	}

	public function testRawExpressionDoUpdateWithoutValueDoesNotMisalignFollowingParams()
	{
		// A no-placeholder raw expression on one column must not shift the bound
		// parameter position for a normal column updated in the same statement.
		$c1 = new Criteria();
		$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
		$c2 = new Criteria();
		$c2->add(BookPeer::TITLE, array('raw' => "'literal'"), Criteria::CUSTOM_EQUAL);
		$c2->add(BookPeer::ISBN, '1234567890');
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BasePeer::doUpdate($c1, $c2, $con);
		$expected = "UPDATE book SET TITLE = 'literal', ISBN='1234567890'";
		$this->assertEquals($expected, normalizeGeneratedSql($con->getLastExecutedQuery()));
	}

	public function testRawExpressionDoUpdateMissingValueThrows()
	{
		$c1 = new Criteria();
		$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
		$c2 = new Criteria();
		$c2->add(BookPeer::TITLE, array('raw' => BookPeer::TITLE . ' + ?'), Criteria::CUSTOM_EQUAL);
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$this->expectException(PropulsionException::class);
		BasePeer::doUpdate($c1, $c2, $con);
	}

	public function testMssqlDoInsertUsesOutputClause()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL)) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->add(BookPeer::TITLE, 'A Brief History of MSSQL');
		$c->add(BookPeer::ISBN, '9999999999');
		$id = BasePeer::doInsert($c, $con);

		$this->assertNotNull($id, 'doInsert() returns the OUTPUT-generated id, without a separate lastInsertId() round trip');
		$this->assertStringContainsString('OUTPUT INSERTED.ID', $con->getLastExecutedQuery(), 'the INSERT statement folds id retrieval in via an OUTPUT clause');
	}

	public function testUpsertInsertsWhenNoConflict()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$countBefore = BookQuery::create()->count();

		$c = new Criteria();
		$c->add(BookPeer::ID, 999901);
		$c->add(BookPeer::TITLE, 'Upserted Book');
		$c->add(BookPeer::ISBN, '0000000001');
		$updateValues = new Criteria();
		$updateValues->add(BookPeer::TITLE, 'Should not be used');

		$affected = BasePeer::doUpsert($c, $updateValues, $con);

		$this->assertEquals(1, $affected);
		$this->assertEquals($countBefore + 1, BookQuery::create()->count(), 'a non-conflicting upsert inserts a new row');
		$book = BookQuery::create()->findPk(999901);
		$this->assertEquals('Upserted Book', $book->getTitle());
	}

	public function testUpsertUpdatesOnConflict()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$c = new Criteria();
		$c->add(BookPeer::ID, 999902);
		$c->add(BookPeer::TITLE, 'Original Title');
		$c->add(BookPeer::ISBN, '0000000002');
		// Plain insert to seed the row this test's actual upsert-on-conflict call
		// conflicts with -- MySQL's ON DUPLICATE KEY UPDATE has no "do nothing" form,
		// so doUpsert() with an empty update-values Criteria (which this insert never
		// needs, since ID 999902 doesn't exist yet) would throw there.
		BasePeer::doInsert($c, $con);

		$countBefore = BookQuery::create()->count();

		$c2 = new Criteria();
		$c2->add(BookPeer::ID, 999902);
		$c2->add(BookPeer::TITLE, 'Should not be used as the insert value');
		$c2->add(BookPeer::ISBN, '0000000002');
		$updateValues = new Criteria();
		$updateValues->add(BookPeer::TITLE, 'Updated Title');

		$affected = BasePeer::doUpsert($c2, $updateValues, $con);

		// MySQL's own C API reports affected-rows as 2 (not 1) for a row updated via
		// ON DUPLICATE KEY UPDATE -- 1 means "inserted as a new row", 2 means "an
		// existing row was updated". A real, documented MySQL quirk, not a bug in
		// doUpsert() -- see its docblock.
		$this->assertEquals($db instanceof DBMySQL ? 2 : 1, $affected);
		$this->assertEquals($countBefore, BookQuery::create()->count(), 'a conflicting upsert does not insert a new row');
		$book = BookQuery::create()->findPk(999902);
		$this->assertEquals('Updated Title', $book->getTitle());
	}

	public function testUpsertColumnExpressionOnConflict()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$c = new Criteria();
		$c->add(BookPeer::ID, 999903);
		$c->add(BookPeer::TITLE, 'Book');
		$c->add(BookPeer::ISBN, '0000000003');
		$c->add(BookPeer::PRICE, 10);
		// See the comment in testUpsertUpdatesOnConflict() for why this is a plain
		// insert rather than an upsert with an empty update-values Criteria.
		BasePeer::doInsert($c, $con);

		$c2 = new Criteria();
		$c2->add(BookPeer::ID, 999903);
		$c2->add(BookPeer::TITLE, 'Book');
		$c2->add(BookPeer::ISBN, '0000000003');
		$c2->add(BookPeer::PRICE, 10);
		$updateValues = new Criteria();
		$updateValues->add(BookPeer::PRICE, array('raw' => BookPeer::PRICE . ' + ?', 'value' => 5), Criteria::CUSTOM_EQUAL);

		BasePeer::doUpsert($c2, $updateValues, $con);

		$book = BookQuery::create()->findPk(999903);
		$this->assertEquals(15, $book->getPrice(), 'a ColumnExpression update value on conflict is spliced in as a raw SQL expression');
	}

	public function testUpsertDoesNothingWhenUpdateValuesEmpty()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMySQL || $db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$c = new Criteria();
		$c->add(BookPeer::ID, 999904);
		$c->add(BookPeer::TITLE, 'Original Title');
		$c->add(BookPeer::ISBN, '0000000004');
		BasePeer::doUpsert($c, new Criteria(), $con);

		$c2 = new Criteria();
		$c2->add(BookPeer::ID, 999904);
		$c2->add(BookPeer::TITLE, 'Should be ignored');
		$c2->add(BookPeer::ISBN, '0000000004');
		BasePeer::doUpsert($c2, new Criteria(), $con);

		$book = BookQuery::create()->findPk(999904);
		$this->assertEquals('Original Title', $book->getTitle(), 'an empty update-values Criteria means DO NOTHING on conflict');
	}

	public function testUpsertMysqlThrowsWhenUpdateValuesEmpty()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMySQL)) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->add(BookPeer::ID, 999905);
		$c->add(BookPeer::TITLE, 'Book');
		$this->expectException(PropulsionException::class);
		BasePeer::doUpsert($c, new Criteria(), $con);
	}

	public function testUpsertUnsupportedOnMssql()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL)) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$c = new Criteria();
		$c->add(BookPeer::ID, 999906);
		$c->add(BookPeer::TITLE, 'Book');
		$this->expectException(PropulsionException::class);
		BasePeer::doUpsert($c, new Criteria(), $con);
	}

	public function testCommentDoDelete()
	{
		$c = new Criteria();
		$c->setComment('Foo');
		$c->add(BookPeer::TITLE, 'War And Peace');
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BasePeer::doDelete($c, $con);
		$expected = 'DELETE /* Foo */ FROM book WHERE book.TITLE=\'War And Peace\'';
		$this->assertEquals($expected, normalizeGeneratedSql($con->getLastExecutedQuery()), 'Criteria::setComment() adds a comment to delete queries');
	}

}
