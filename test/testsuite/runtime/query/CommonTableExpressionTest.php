<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for Criteria::withCte().
 */
class CommonTableExpressionTest extends BookstoreTestBase
{
	protected function assertCriteriaTranslation($criteria, $expectedSql, $expectedParams, $message = '')
	{
		$params = array();
		$result = normalizeGeneratedSql(BasePeer::createSelectSql($criteria, $params));

		$this->assertEquals($expectedSql, $result, $message);
		$this->assertEquals($expectedParams, $params, $message);
	}

	public function testWithCte()
	{
		$cte = new BookQuery();
		$cte->addSelectColumn(BookPeer::ID);
		$cte->add(BookPeer::PRICE, 10, Criteria::GREATER_THAN);

		$main = new Criteria();
		$main->withCte('expensive_books', $cte);
		$main->setPrimaryTableName('expensive_books');
		$main->addSelectColumn('expensive_books.ID');

		$sql = 'WITH expensive_books AS (SELECT book.ID FROM book WHERE book.PRICE>:p1) SELECT expensive_books.ID FROM expensive_books';
		$params = array(
			array('table' => 'book', 'column' => 'PRICE', 'value' => 10),
		);
		$this->assertCriteriaTranslation($main, $sql, $params, 'withCte() prefixes a WITH clause and the CTE name resolves as a plain table name');
	}

	public function testWithCteExplicitColumnList()
	{
		$cte = new BookQuery();
		$cte->addSelectColumn(BookPeer::ID);

		$main = new Criteria();
		$main->withCte('ids', $cte, array('book_id'));
		$main->setPrimaryTableName('ids');
		$main->addSelectColumn('ids.book_id');

		$sql = 'WITH ids (book_id) AS (SELECT book.ID FROM book) SELECT ids.book_id FROM ids';
		$this->assertCriteriaTranslation($main, $sql, array(), 'an explicit $columns list is rendered as "name (col1, col2)"');
	}

	public function testMultipleCtesAreCumulative()
	{
		$cte1 = new BookQuery();
		$cte1->addSelectColumn(BookPeer::ID);

		$cte2 = new BookQuery();
		$cte2->addSelectColumn(BookPeer::ID);

		$main = new Criteria();
		$main->withCte('a', $cte1);
		$main->withCte('b', $cte2);
		$main->setPrimaryTableName('a');
		$main->addSelectColumn('a.ID');

		$sql = 'WITH a AS (SELECT book.ID FROM book), b AS (SELECT book.ID FROM book) SELECT a.ID FROM a';
		$this->assertCriteriaTranslation($main, $sql, array(), 'multiple withCte() calls are combined into a single WITH clause');
	}

	public function testWithCteWrapsASetOperationBody()
	{
		$cte = new BookQuery();
		$cte->addSelectColumn(BookPeer::ID);

		$main = new BookQuery();
		$main->addSelectColumn(BookPeer::ID);
		$other = new BookQuery();
		$other->addSelectColumn(BookPeer::ID);
		$main->union($other);
		$main->withCte('ids', $cte);

		$sql = 'WITH ids AS (SELECT book.ID FROM book) (SELECT book.ID FROM book) UNION (SELECT book.ID FROM book)';
		$this->assertCriteriaTranslation($main, $sql, array(), 'the WITH clause is a textual prefix regardless of whether the body itself is a plain SELECT or a set operation');
	}

	public function testRecursiveCteRequiresExplicitColumns()
	{
		$this->expectException(PropulsionException::class);

		$cte = new BookQuery();
		$main = new Criteria();
		$main->withCte('cte', $cte, array(), true);
	}

	public function testRecursiveCteEmitsRecursiveKeywordOnPostgresLikeAdapter()
	{
		$anchor = new Criteria('bookstore');
		$anchor->setPrimaryTableName('category');
		$anchor->addSelectColumn('category.ID');
		$anchor->addSelectColumn('category.PARENT_ID');
		$anchor->add('category.PARENT_ID', null, Criteria::ISNULL);

		$recursiveMember = new Criteria('bookstore');
		$recursiveMember->addSelectColumn('category.ID');
		$recursiveMember->addSelectColumn('category.PARENT_ID');
		$recursiveMember->addJoin('category.PARENT_ID', 'category_tree.ID');
		$anchor->unionAll($recursiveMember);

		$main = new Criteria();
		$main->withCte('category_tree', $anchor, array('ID', 'PARENT_ID'), true);
		$main->setPrimaryTableName('category_tree');
		$main->addSelectColumn('category_tree.ID');

		$params = array();
		$sql = normalizeGeneratedSql(BasePeer::createSelectSql($main, $params));

		$this->assertStringStartsWith('WITH RECURSIVE category_tree (ID, PARENT_ID) AS (', $sql, 'a recursive CTE is prefixed with WITH RECURSIVE on an adapter using the default supportsRecursiveCteKeyword()');
	}

	public function testWithCteFiltersRealData()
	{
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		BookstoreDataPopulator::depopulate($con);
		BookstoreDataPopulator::populate($con);

		$cte = new ModelCriteria('bookstore', 'Book');
		$cte->addSelectColumn(BookPeer::ID);
		$cte->add(BookPeer::PRICE, 15, Criteria::GREATER_EQUAL);
		$expectedCount = (clone $cte)->count($con);

		$main = new ModelCriteria('bookstore', 'Book');
		$main->withCte('expensive_books', $cte);
		$main->setPrimaryTableName('expensive_books');
		$main->addSelectColumn('expensive_books.ID');

		$stmt = BasePeer::doSelect($main, $con);
		$rows = $stmt->fetchAll();

		$this->assertCount($expectedCount, $rows, 'a CTE referenced by name in FROM filters against a live connection');
	}
}
