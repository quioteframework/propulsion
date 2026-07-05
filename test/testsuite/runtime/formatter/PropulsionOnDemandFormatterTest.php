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
 * @version    $Id: PropulsionOnDemandFormatterTest.php 1374 2009-12-26 23:21:37Z francois $
 */
class PropulsionOnDemandFormatterTest extends BookstoreEmptyTestBase
{

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
		$this->assertEquals(4, count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
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
		$this->assertEquals($nbBooks, count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
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
		$this->assertEquals(1, count($books), 'PropulsionOnDemandFormatter::format() returns a collection that counts as many rows as the results in the query');
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
