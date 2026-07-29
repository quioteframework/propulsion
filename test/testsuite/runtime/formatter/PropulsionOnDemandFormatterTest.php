<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionOnDemandFormatter.
 *
 * @author     Francois Zaninotto
 */
class PropulsionOnDemandFormatterTest extends BookstoreEmptyTestBase
{
	/**
	 * FreeTDS/pdo_dblib (MSSQL) doesn't support PDOStatement::rowCount() for a
	 * SELECT -- always -1 -- unlike Postgres/MySQL/SQLite, which is exactly
	 * the portability caveat PropulsionOnDemandIterator::count()'s own
	 * docblock warns about ("inaccurate for most databases"). pdo_oci
	 * (Oracle) has the same portability gap, always reporting 0 instead.
	 * Neither is fixable without buffering every row up front, which defeats
	 * the point of an on-demand collection.
	 */
	private function expectedRowCount(int $actual): int
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL) {
			return -1;
		}
		return $db instanceof DBOracle ? 0 : $actual;
	}

	public function testFormatNoCriteria()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionOnDemandFormatter();
		try {
			$books = $formatter->format($stmt);
			$this->fail('PropulsionOnDemandFormatter::format() trows an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->assertTrue(true,'PropulsionOnDemandFormatter::format() trows an exception when called with no valid criteria');
		}
	}

	public function testFormatManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::populate($con);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionOnDemandCollection, 'PropulsionOnDemandFormatter::format() returns a PropulsionOnDemandCollection');
		$this->assertEquals($this->expectedRowCount(4), count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
		foreach ($books as $book) {
			$this->assertTrue($book instanceof Book, 'PropulsionOnDemandFormatter::format() returns an traversable collection of Model objects');
		}
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testFormatManyResultsIteratedTwice()
	{
		$this->expectException(PropulsionException::class);
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::populate($con);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		foreach ($books as $book) {
			// do nothing
		}
		foreach ($books as $book) {
			// this should throw a PropulsionException since we're iterating a second time over a stream
		}
	}

	public function testFormatALotOfResults()
	{
		$nbBooks = 50;
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		Propulsion::disableInstancePooling();
		$book = new Book();
		for ($i=0; $i < $nbBooks; $i++) {
			$book->clear();
			$book->setTitle('BookTest' . $i);
			$book->setISBN('0000000000');
			$book->save($con);
		}

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionOnDemandCollection, 'PropulsionOnDemandFormatter::format() returns a PropulsionOnDemandCollection');
		$this->assertEquals($this->expectedRowCount($nbBooks), count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
		$i = 0;
		foreach ($books as $book) {
			$this->assertTrue($book instanceof Book, 'PropulsionOnDemandFormatter::format() returns a collection of Model objects');
			$this->assertEquals('BookTest' . $i, $book->getTitle(), 'PropulsionOnDemandFormatter::format() returns the model objects matching the query');
			$i++;
		}
		Propulsion::enableInstancePooling();
	}

	public function testFormatOneResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::populate($con);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'Quicksilver\'');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionOnDemandCollection, 'PropulsionOnDemandFormatter::format() returns a PropulsionOnDemandCollection');
		$this->assertEquals($this->expectedRowCount(1), count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
		foreach ($books as $book) {
			$this->assertTrue($book instanceof Book, 'PropulsionOnDemandFormatter::format() returns a collection of Model objects');
			$this->assertEquals('Quicksilver', $book->getTitle(), 'PropulsionOnDemandFormatter::format() returns the model objects matching the query');
		}
	}

	public function testFormatNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionOnDemandCollection, 'PropulsionOnDemandFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(0, count($books), 'PropulsionOnDemandFormatter::format() returns an empty collection when no record match the query');
		foreach ($books as $book) {
			$this->fail('PropulsionOnDemandFormatter returns an empty iterator when no record match the query');
		}
	}

	public function testFormatOneManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::populate($con);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionOnDemandFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertTrue($book instanceof Book, 'PropulsionOnDemandFormatter::formatOne() returns a model object');
	}

}
