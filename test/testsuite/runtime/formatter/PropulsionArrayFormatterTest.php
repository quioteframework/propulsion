<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionArrayFormatter.
 *
 * @author     Francois Zaninotto
 * @version    $Id$
 * @package    runtime.formatter
 */
class PropulsionArrayFormatterTest extends BookstoreEmptyTestBase
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
		$formatter = new PropulsionArrayFormatter();
		try {
			$books = $formatter->format($stmt);
			$this->fail('PropulsionArrayFormatter::format() throws an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->assertTrue(true,'PropulsionArrayFormatter::format() throws an exception when called with no valid criteria');
		}
	}

	public function testFormatManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionArrayFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionArrayFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(4, count($books), 'PropulsionArrayFormatter::format() returns as many rows as the results in the query');
		foreach ($books as $book) {
			$this->assertTrue(is_array($book), 'PropulsionArrayFormatter::format() returns an array of arrays');
		}
	}

	public function testFormatOneResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'Quicksilver\'');
		$formatter = new PropulsionArrayFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionArrayFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(1, count($books), 'PropulsionArrayFormatter::format() returns as many rows as the results in the query');
		$book = $books->shift();
		$this->assertTrue(is_array($book), 'PropulsionArrayFormatter::format() returns an array of arrays');
		$this->assertEquals('Quicksilver', $book['Title'], 'PropulsionArrayFormatter::format() returns the arrays matching the query');
		$expected = array('Id', 'Title', 'ISBN', 'Price', 'PublisherId', 'AuthorId');
		$this->assertEquals($expected, array_keys($book), 'PropulsionArrayFormatter::format() returns an associative array with column phpNames as keys');
	}

	public function testFormatNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionArrayFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PropulsionCollection, 'PropulsionArrayFormatter::format() returns a PropulsionCollection');
		$this->assertEquals(0, count($books), 'PropulsionArrayFormatter::format() returns as many rows as the results in the query');
	}

	public function testFormatOneNoCriteria()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionArrayFormatter();
		try {
			$book = $formatter->formatOne($stmt);
			$this->fail('PropulsionArrayFormatter::formatOne() throws an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->assertTrue(true,'PropulsionArrayFormatter::formatOne() throws an exception when called with no valid criteria');
		}
	}

	public function testFormatOneManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionArrayFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertTrue(is_array($book), 'PropulsionArrayFormatter::formatOne() returns an array');
		$this->assertEquals(array('Id', 'Title', 'ISBN', 'Price', 'PublisherId', 'AuthorId'), array_keys($book), 'PropulsionArrayFormatter::formatOne() returns a single row even if the query has many results');
	}

	public function testFormatOneNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionArrayFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertNull($book, 'PropulsionArrayFormatter::formatOne() returns null when no result');
	}

}
