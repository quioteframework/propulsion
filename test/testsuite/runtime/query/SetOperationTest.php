<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for Criteria::union()/unionAll()/intersect()/except().
 */
class SetOperationTest extends BookstoreTestBase
{
	protected function assertCriteriaTranslation($criteria, $expectedSql, $expectedParams, $message = '')
	{
		$params = array();
		$result = normalizeGeneratedSql(BasePeer::createSelectSql($criteria, $params));

		$this->assertEquals($expectedSql, $result, $message);
		$this->assertEquals($expectedParams, $params, $message);
	}

	public function testUnion()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);
		$c1->add(BookPeer::PRICE, 10, Criteria::GREATER_THAN);

		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);
		$c2->add(BookPeer::PRICE, 5, Criteria::LESS_THAN);

		$c1->union($c2);

		$sql = '(SELECT book.ID FROM book WHERE book.PRICE>:p1) UNION (SELECT book.ID FROM book WHERE book.PRICE<:p2)';
		$params = array(
			array('table' => 'book', 'column' => 'PRICE', 'value' => 10),
			array('table' => 'book', 'column' => 'PRICE', 'value' => 5),
		);
		$this->assertCriteriaTranslation($c1, $sql, $params, 'union() combines two full SELECT statements');
	}

	public function testUnionAll()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);

		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);

		$c1->unionAll($c2);

		$sql = '(SELECT book.ID FROM book) UNION ALL (SELECT book.ID FROM book)';
		$this->assertCriteriaTranslation($c1, $sql, array(), 'unionAll() uses UNION ALL');
	}

	public function testIntersect()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);

		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);

		$c1->intersect($c2);

		$sql = '(SELECT book.ID FROM book) INTERSECT (SELECT book.ID FROM book)';
		$this->assertCriteriaTranslation($c1, $sql, array(), 'intersect() uses INTERSECT');
	}

	public function testExcept()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);

		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);

		$c1->except($c2);

		$sql = '(SELECT book.ID FROM book) EXCEPT (SELECT book.ID FROM book)';
		$this->assertCriteriaTranslation($c1, $sql, array(), 'except() uses EXCEPT');
	}

	public function testUnionWithOrderByAndLimitAppliesToCombinedResult()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);
		$c1->addSelectColumn(BookPeer::TITLE);

		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);
		$c2->addSelectColumn(BookPeer::TITLE);

		$c1->union($c2);
		$c1->addAscendingOrderByColumn(BookPeer::TITLE);
		$c1->setLimit(10);

		$sql = '(SELECT book.ID, book.TITLE FROM book) UNION (SELECT book.ID, book.TITLE FROM book) ORDER BY book.TITLE ASC LIMIT 10';
		$this->assertCriteriaTranslation($c1, $sql, array(), 'ORDER BY/LIMIT set on the combined query apply after every branch, not inside the first one');
	}

	public function testUnionIsChainable()
	{
		$c1 = new BookQuery();
		$c1->addSelectColumn(BookPeer::ID);
		$c2 = new BookQuery();
		$c2->addSelectColumn(BookPeer::ID);
		$c3 = new BookQuery();
		$c3->addSelectColumn(BookPeer::ID);

		$c1->union($c2)->union($c3);

		$sql = '(SELECT book.ID FROM book) UNION (SELECT book.ID FROM book) UNION (SELECT book.ID FROM book)';
		$this->assertCriteriaTranslation($c1, $sql, array(), 'chained union() calls combine all three branches');
	}

	public function testUnionFiltersRealData()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::depopulate($con);
		BookstoreDataPopulator::populate($con);

		$cheapBooks = new ModelCriteria('bookstore', 'Book');
		$cheapBooks->add(BookPeer::PRICE, 15, Criteria::LESS_THAN);
		$cheapCount = (clone $cheapBooks)->count($con);

		$expensiveBooks = new ModelCriteria('bookstore', 'Book');
		$expensiveBooks->add(BookPeer::PRICE, 15, Criteria::GREATER_EQUAL);
		$expensiveCount = (clone $expensiveBooks)->count($con);

		$totalBooks = BookQuery::create()->count($con);
		$this->assertEquals($totalBooks, $cheapCount + $expensiveCount, 'sanity check: the two price bands partition all books');

		$combined = new ModelCriteria('bookstore', 'Book');
		$combined->addSelectColumn(BookPeer::ID);
		$combined->add(BookPeer::PRICE, 15, Criteria::LESS_THAN);

		$other = new ModelCriteria('bookstore', 'Book');
		$other->addSelectColumn(BookPeer::ID);
		$other->add(BookPeer::PRICE, 15, Criteria::GREATER_EQUAL);

		$combined->unionAll($other);
		$stmt = BasePeer::doSelect($combined, $con);
		$rows = $stmt->fetchAll();

		$this->assertCount($totalBooks, $rows, 'unionAll() of the two price bands returns every book exactly once');
	}
}
