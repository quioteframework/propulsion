<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the exceptions thrown by the BasePeer classes.
 *
 * @see        BookstoreDataPopulator
 * @author     Francois Zaninotto
 */
class BasePeerExceptionsTest extends BookstoreTestBase
{

	public function testDoSelect()
	{
		try {
			$c = new Criteria();
			$c->add(BookPeer::ID, 12, ' BAD SQL');
			BookPeer::addSelectColumns($c);
			BasePeer::doSelect($c);
		} catch (PropulsionException $e) {
			$this->assertStringContainsString('[SELECT book.ID, book.TITLE, book.ISBN, book.PRICE, book.PUBLISHER_ID, book.AUTHOR_ID FROM book WHERE book.ID BAD SQL:p1]', normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

	public function testDoCount()
	{
		try {
			$c = new Criteria();
			$c->add(BookPeer::ID, 12, ' BAD SQL');
			BookPeer::addSelectColumns($c);
			BasePeer::doCount($c);
		} catch (PropulsionException $e) {
			$this->assertStringContainsString('[SELECT COUNT(*) FROM book WHERE book.ID BAD SQL:p1]', normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

	public function testDoDelete()
	{
		try {
			$c = new Criteria();
			$c->setPrimaryTableName(BookPeer::TABLE_NAME);
			$c->add(BookPeer::ID, 12, ' BAD SQL');
			BasePeer::doDelete($c, Propulsion::getConnection());
		} catch (PropulsionException $e) {
			$this->assertStringContainsString('[DELETE FROM book WHERE book.ID BAD SQL:p1]', normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

	public function testDoDeleteAll()
	{
		try {
			BasePeer::doDeleteAll('BAD TABLE', Propulsion::getConnection(), Propulsion::getDefaultDB());
		} catch (PropulsionException $e) {
			// "TABLE" (the second, alias-shaped word in this deliberately fake
			// table name) is a genuine Oracle reserved word -- DBOracle quotes
			// it (see quoteIdentifier()'s own doc comment), unlike every other
			// platform here.
			$expected = Propulsion::getDB() instanceof DBOracle
				? '[DELETE FROM BAD "TABLE"]'
				: '[DELETE FROM BAD TABLE]';
			$this->assertStringContainsString($expected, normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

	public function testDoUpdate()
	{
		try {
			$c1 = new Criteria();
			$c1->setPrimaryTableName(BookPeer::TABLE_NAME);
			$c1->add(BookPeer::ID, 12, ' BAD SQL');
			$c2 = new Criteria();
			$c2->add(BookPeer::TITLE, 'Foo');
			BasePeer::doUpdate($c1, $c2, Propulsion::getConnection());
		} catch (PropulsionException $e) {
			$this->assertStringContainsString('[UPDATE book SET TITLE=:p1 WHERE book.ID BAD SQL:p2]', normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

	public function testDoInsert()
	{
		try {
			$c = new Criteria();
			$c->setPrimaryTableName(BookPeer::TABLE_NAME);
			$c->add(BookPeer::AUTHOR_ID, 'lkhlkhj');
			BasePeer::doInsert($c, Propulsion::getConnection());
		} catch (PropulsionException $e) {
			// The expected INSERT shape genuinely differs by id-generation/retrieval
			// strategy, not just quoting -- see BasePeer::doInsert():
			// - MSSQL folds id retrieval in via an OUTPUT clause spliced before VALUES.
			// - Oracle folds it in via RETURNING ... INTO a bound OUT parameter.
			// - Postgres/SQLite/MariaDB (DBAdapter::supportsInsertReturning()) fold it
			//   in via a trailing RETURNING clause, and no longer pre-fetch/include the
			//   id column at all -- it's left to the column's own SERIAL/AUTOINCREMENT
			//   default.
			// - Plain MySQL (DBAdapter::isGetIdAfterInsert()) has none of the above and
			//   omits the id column, relying on a separate lastInsertId() round trip.
			$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
			$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
			if ($db instanceof DBMSSQL) {
				$expected = '[INSERT INTO book (AUTHOR_ID) OUTPUT INSERTED.ID VALUES (:p1)]';
			} elseif ($db instanceof DBOracle) {
				$expected = '[INSERT INTO book (AUTHOR_ID) VALUES (:p1) RETURNING ID INTO :ret_id]';
			} elseif ($db->supportsInsertReturning($con)) {
				$expected = '[INSERT INTO book (AUTHOR_ID) VALUES (:p1) RETURNING ID]';
			} elseif ($db->isGetIdBeforeInsert()) {
				$expected = '[INSERT INTO book (AUTHOR_ID,ID) VALUES (:p1,:p2)]';
			} else {
				$expected = '[INSERT INTO book (AUTHOR_ID) VALUES (:p1)]';
			}
			$this->assertStringContainsString($expected, normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

}
