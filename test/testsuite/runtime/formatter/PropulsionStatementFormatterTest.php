<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionStatementFormatter.
 *
 * @author     Francois Zaninotto
 * @version    $Id$
 */
class PropulsionStatementFormatterTest extends BookstoreEmptyTestBase
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
		$formatter = new PropulsionStatementFormatter();
		try {
			$books = $formatter->format($stmt);
			$this->assertTrue(true, 'PropulsionStatementFormatter::format() does not trow an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->fail('PropulsionStatementFormatter::format() does not trow an exception when called with no valid criteria');
		}
	}

	public function testFormatManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionStatementFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PDOStatement, 'PropulsionStatementFormatter::format() returns a PDOStatement');
		$this->assertEquals(4, $books->rowCount(), 'PropulsionStatementFormatter::format() returns as many rows as the results in the query');
		while ($book = $books->fetch()) {
			$this->assertTrue(is_array($book), 'PropulsionStatementFormatter::format() returns a statement that can be fetched');
		}
	}

	public function testFormatOneResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'Quicksilver\'');
		$formatter = new PropulsionStatementFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PDOStatement, 'PropulsionStatementFormatter::format() returns a PDOStatement');
		$this->assertEquals(1, $books->rowCount(), 'PropulsionStatementFormatter::format() returns as many rows as the results in the query');
		$book = $books->fetch(PDO::FETCH_ASSOC);
		$this->assertEquals('Quicksilver', $book['title'], 'PropulsionStatementFormatter::format() returns the rows matching the query');
	}

	public function testFormatNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionStatementFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$books = $formatter->format($stmt);

		$this->assertTrue($books instanceof PDOStatement, 'PropulsionStatementFormatter::format() returns a PDOStatement');
		$this->assertEquals(0, $books->rowCount(), 'PropulsionStatementFormatter::format() returns as many rows as the results in the query');
	}

	public function testFormatoneNoCriteria()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionStatementFormatter();
		try {
			$books = $formatter->formatOne($stmt);
			$this->assertTrue(true, 'PropulsionStatementFormatter::formatOne() does not trow an exception when called with no valid criteria');
		} catch (PropulsionException $e) {
			$this->fail('PropulsionStatementFormatter::formatOne() does not trow an exception when called with no valid criteria');
		}
	}

	public function testFormatOneManyResults()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book');
		$formatter = new PropulsionStatementFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertTrue($book instanceof PDOStatement, 'PropulsionStatementFormatter::formatOne() returns a PDO Statement');
	}

	public function testFormatOneNoResult()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

		$stmt = $con->query('SELECT * FROM book WHERE book.TITLE = \'foo\'');
		$formatter = new PropulsionStatementFormatter();
		$formatter->init(new ModelCriteria('bookstore', 'Book'));
		$book = $formatter->formatOne($stmt);

		$this->assertNull($book, 'PropulsionStatementFormatter::formatOne() returns null when no result');
	}

}
