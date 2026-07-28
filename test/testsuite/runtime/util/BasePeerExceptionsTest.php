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
			BasePeer::doDeleteAll('BAD TABLE', Propulsion::getConnection());
		} catch (PropulsionException $e) {
			$this->assertStringContainsString('[DELETE FROM BAD TABLE]', normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
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
			// The expected INSERT column list genuinely differs by id-generation
			// strategy, not just quoting: DBAdapter::isGetIdBeforeInsert() platforms
			// (e.g. Postgres's SEQUENCE method) pre-fetch the next id and include it
			// explicitly; DBAdapter::isGetIdAfterInsert() platforms (e.g. MySQL's
			// AUTOINCREMENT method) omit the id column and let its own default
			// populate it -- see BasePeer::doInsert().
			$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
			$expected = $db->isGetIdBeforeInsert()
				? '[INSERT INTO book (AUTHOR_ID,ID) VALUES (:p1,:p2)]'
				: '[INSERT INTO book (AUTHOR_ID) VALUES (:p1)]';
			$this->assertStringContainsString($expected, normalizeGeneratedSql($e->getMessage()), 'SQL query is written in the exception message');
		}
	}

}
