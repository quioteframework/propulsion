<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for DBAdapter::supportsBulkLoad()/bulkLoad() and BasePeer::doBulkInsert().
 */
class BulkLoadTest extends BookstoreTestBase
{
	public function testDoBulkInsert()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!$db->supportsBulkLoad()) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$countBefore = BookQuery::create()->count();

		$rows = array(
			array(999920, 'Bulk Book One', '1111111111', 9.99, null, null),
			array(999921, 'Bulk Book Two', '2222222222', 19.99, null, null),
			array(999922, "Bulk\tBook\nThree \\ special", '3333333333', null, null, null),
		);
		$columns = array('ID', 'TITLE', 'ISBN', 'PRICE', 'PUBLISHER_ID', 'AUTHOR_ID');

		$affected = BasePeer::doBulkInsert('book', $columns, $rows, $con, BookPeer::DATABASE_NAME);

		$this->assertEquals(3, $affected, 'doBulkInsert() returns the number of rows loaded');
		$this->assertEquals($countBefore + 3, BookQuery::create()->count(), 'the rows are actually visible afterward');

		$book = BookQuery::create()->findPk(999920);
		$this->assertEquals('Bulk Book One', $book->getTitle());
		$this->assertEquals(9.99, $book->getPrice());

		$bookTwo = BookQuery::create()->findPk(999921);
		$this->assertEquals(19.99, $bookTwo->getPrice());

		$bookWithSpecialChars = BookQuery::create()->findPk(999922);
		$this->assertEquals("Bulk\tBook\nThree \\ special", $bookWithSpecialChars->getTitle(), 'tabs, newlines, and backslashes in a value round-trip correctly');
		$this->assertNull($bookWithSpecialChars->getPrice(), 'a null value round-trips as a real SQL NULL, not a literal string');
	}

	public function testDoBulkInsertEmptyRows()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!$db->supportsBulkLoad()) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$affected = BasePeer::doBulkInsert('book', array('ID', 'TITLE'), array(), $con, BookPeer::DATABASE_NAME);
		$this->assertEquals(0, $affected, 'an empty row set loads zero rows without error');
	}

	public function testDoBulkInsertUnsupportedThrows()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db->supportsBulkLoad()) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$this->expectException(PropulsionException::class);
		BasePeer::doBulkInsert('book', array('ID'), array(array(1)), $con, BookPeer::DATABASE_NAME);
	}

	public function testMysqlBulkLoadThrowsWithoutLocalInfileAttribute()
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMySQL)) {
			$this->markTestSkipped();
		}

		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		if ($con->getAttribute(\Pdo\Mysql::ATTR_LOCAL_INFILE)) {
			$this->markTestSkipped('this connection was configured with ATTR_LOCAL_INFILE enabled');
		}

		$this->expectException(PropulsionException::class);
		BasePeer::doBulkInsert('book', array('ID'), array(array(1)), $con, BookPeer::DATABASE_NAME);
	}
}
