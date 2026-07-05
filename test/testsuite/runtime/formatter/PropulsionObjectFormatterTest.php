<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionObjectFormatter.
 *
 * @author     Francois Zaninotto
 * @version    $Id$
 */
class PropulsionObjectFormatterTest extends BookstoreEmptyTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::populate();
	}

	public function testFormatNoCriteria()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionObjectFormatter();
		try {
			$books = $formatter->format($stmt);
			$this->fail('PropulsionObjectFormatter::format() trows an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->assertTrue(true,'PropulsionObjectFormatter::format() trows an exception when called with no valid criteria');
		}
	}

	public function testFormatValidClass()
	{
		$stmt = $this->con->query('SELECT * FROM book');
		$formatter = new PropulsionObjectFormatter();
		$formatter->setClass('Book');
		$books = $formatter->format($stmt);
		$this->assertTrue($books instanceof PropulsionObjectCollection);
		$this->assertEquals(4, $books->count());
	}

	public function testFormatManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionObjectFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionObjectFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(4, count($books), 'PropulsionObjectFormatter::format() returns as many rows as the results in the query');
		foreach ($books as $book) {
			$this->assertTrue($book instanceof Book, 'PropulsionObjectFormatter::format() returns an array of Model objects');
		}
	}

	public function testFormatOneResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'Quicksilver\'');
		$formatter = new PropulsionObjectFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionObjectFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(1, count($books), 'PropulsionObjectFormatter::format() returns as many rows as the results in the query');
		$book = $books->shift();
		$this->assertTrue($book instanceof Book, 'PropulsionObjectFormatter::format() returns an array of Model objects');
		$this->assertEquals('Quicksilver', $book->getTitle(), 'PropulsionObjectFormatter::format() returns the model objects matching the query');
	}

	public function testFormatNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionObjectFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionObjectFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(0, count($books), 'PropulsionObjectFormatter::format() returns as many rows as the results in the query');
	}

	public function testFormatOneNoCriteria()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionObjectFormatter();
		try {
			$book = $formatter->formatOne($stmt);
			$this->fail('PropulsionObjectFormatter::formatOne() throws an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->assertTrue(true,'PropulsionObjectFormatter::formatOne() throws an exception when called with no valid criteria');
		}
	}

	public function testFormatOneManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionObjectFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertTrue($book instanceof Book, 'PropulsionObjectFormatter::formatOne() returns a model object');
	}

	public function testFormatOneNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionObjectFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertNull($book, 'PropulsionObjectFormatter::formatOne() returns null when no result');
	}

}
