<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for Criteria::addExistsQuery()/addInQuery() and
 * ModelCriteria::useExistsQuery()/useNotExistsQuery()/useInQuery().
 */
class ExistsQueryTest extends BookstoreTestBase
{
	protected function assertCriteriaTranslation($criteria, $expectedSql, $expectedParams, $message = '')
	{
		$params = array();
		$result = BasePeer::createSelectSql($criteria, $params);

		$this->assertEquals($expectedSql, $result, $message);
		$this->assertEquals($expectedParams, $params, $message);
	}

	public function testAddExistsQuery()
	{
		$sub = new Criteria();
		$sub->setPrimaryTableName('review');
		$sub->addSelectColumn('1');
		$sub->add(new Criterion($sub, null, 'review.BOOK_ID = book.ID', Criteria::CUSTOM));

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addExistsQuery($sub);

		$sql = 'SELECT book.ID FROM book WHERE EXISTS (SELECT 1 FROM review WHERE review.BOOK_ID = book.ID)';
		$this->assertCriteriaTranslation($c, $sql, array(), 'addExistsQuery() nests the subquery in the WHERE clause');
	}

	public function testAddExistsQueryNegated()
	{
		$sub = new Criteria();
		$sub->setPrimaryTableName('review');
		$sub->addSelectColumn('1');
		$sub->add(new Criterion($sub, null, 'review.BOOK_ID = book.ID', Criteria::CUSTOM));

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addExistsQuery($sub, true);

		$sql = 'SELECT book.ID FROM book WHERE NOT EXISTS (SELECT 1 FROM review WHERE review.BOOK_ID = book.ID)';
		$this->assertCriteriaTranslation($c, $sql, array(), 'addExistsQuery($sub, true) uses NOT EXISTS');
	}

	public function testAddExistsQueryWithSubqueryParams()
	{
		$sub = new Criteria();
		$sub->setPrimaryTableName('review');
		$sub->addSelectColumn('1');
		$sub->add(new Criterion($sub, null, 'review.BOOK_ID = book.ID', Criteria::CUSTOM));
		$sub->add(ReviewPeer::RECOMMENDED, true);

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->add(BookPeer::PRICE, 10, Criteria::GREATER_THAN);
		$c->addExistsQuery($sub);

		$sql = 'SELECT book.ID FROM book WHERE book.PRICE>:p1 AND EXISTS (SELECT 1 FROM review WHERE review.BOOK_ID = book.ID AND review.RECOMMENDED=:p2)';
		$params = array(
			array('table' => 'book', 'column' => 'PRICE', 'value' => 10),
			array('table' => 'review', 'column' => 'RECOMMENDED', 'value' => true),
		);
		$this->assertCriteriaTranslation($c, $sql, $params, 'the subquery\'s own bound params are appended in placeholder order');
	}

	public function testAddInQuery()
	{
		$sub = new Criteria();
		$sub->setPrimaryTableName('review');
		$sub->addSelectColumn(ReviewPeer::BOOK_ID);
		$sub->add(ReviewPeer::RECOMMENDED, true);

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addInQuery(BookPeer::ID, $sub);

		$sql = 'SELECT book.ID FROM book WHERE book.ID IN (SELECT review.BOOK_ID FROM review WHERE review.RECOMMENDED=:p1)';
		$params = array(
			array('table' => 'review', 'column' => 'RECOMMENDED', 'value' => true),
		);
		$this->assertCriteriaTranslation($c, $sql, $params, 'addInQuery() nests the subquery in an IN() filter');
	}

	public function testAddInQueryNegated()
	{
		$sub = new Criteria();
		$sub->setPrimaryTableName('review');
		$sub->addSelectColumn(ReviewPeer::BOOK_ID);

		$c = new Criteria();
		$c->addSelectColumn(BookPeer::ID);
		$c->addInQuery(BookPeer::ID, $sub, true);

		$sql = 'SELECT book.ID FROM book WHERE book.ID NOT IN (SELECT review.BOOK_ID FROM review)';
		$this->assertCriteriaTranslation($c, $sql, array(), 'addInQuery($col, $sub, true) uses NOT IN');
	}

	public function testUseExistsQuery()
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->useExistsQuery('Review', function ($sub) {
			$sub->where('review.BOOK_ID = book.ID');
		});

		$sql = 'SELECT  FROM  WHERE EXISTS (SELECT 1 FROM review WHERE review.BOOK_ID = book.ID)';
		$params = array();
		$this->assertCriteriaTranslation($c, $sql, $params, 'useExistsQuery() defaults the subquery select to "1"');
	}

	public function testUseNotExistsQuery()
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->useNotExistsQuery('Review', function ($sub) {
			$sub->where('review.BOOK_ID = book.ID');
		});

		$sql = 'SELECT  FROM  WHERE NOT EXISTS (SELECT 1 FROM review WHERE review.BOOK_ID = book.ID)';
		$this->assertCriteriaTranslation($c, $sql, array(), 'useNotExistsQuery() uses NOT EXISTS');
	}

	public function testUseExistsQueryRespectsExplicitSelect()
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->useExistsQuery('Review', function ($sub) {
			$sub->addSelectColumn(ReviewPeer::ID);
			$sub->where('review.BOOK_ID = book.ID');
		});

		$sql = 'SELECT  FROM  WHERE EXISTS (SELECT review.ID FROM review WHERE review.BOOK_ID = book.ID)';
		$this->assertCriteriaTranslation($c, $sql, array(), 'an explicit select column in the callback is not overridden');
	}

	public function testUseInQuery()
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->useInQuery('Id', 'Review', function ($sub) {
			$sub->addSelectColumn(ReviewPeer::BOOK_ID);
			$sub->add(ReviewPeer::RECOMMENDED, true);
		});

		$sql = 'SELECT  FROM book WHERE book.ID IN (SELECT review.BOOK_ID FROM review WHERE review.RECOMMENDED=:p1)';
		$params = array(
			array('table' => 'review', 'column' => 'RECOMMENDED', 'value' => true),
		);
		$this->assertCriteriaTranslation($c, $sql, $params, 'useInQuery() filters on the given column');
	}

	public function testUseExistsQueryFiltersRealData()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::depopulate($con);
		BookstoreDataPopulator::populate($con);

		$totalBooks = BookQuery::create()->count();
		$this->assertGreaterThan(0, $totalBooks, 'sanity check: the fixture has books');

		$booksWithReviews = (new ModelCriteria('bookstore', 'Book'))
			->useExistsQuery('Review', function ($sub) {
				$sub->where('review.BOOK_ID = book.ID');
			})
			->count($con);

		$booksWithoutReviews = (new ModelCriteria('bookstore', 'Book'))
			->useNotExistsQuery('Review', function ($sub) {
				$sub->where('review.BOOK_ID = book.ID');
			})
			->count($con);

		$this->assertEquals($totalBooks, $booksWithReviews + $booksWithoutReviews, 'EXISTS and NOT EXISTS partition all books');
	}
}
