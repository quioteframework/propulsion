<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for WindowExpression, and its integration with ModelCriteria::withColumn().
 */
class WindowExpressionTest extends BookstoreTestBase
{
	public function testRowNumberWithPartitionAndOrder()
	{
		$window = \Propulsion\Query\WindowExpression::rowNumber()
			->partitionBy('book.PUBLISHER_ID')
			->orderBy('book.PRICE', 'DESC');

		$this->assertEquals(
			'ROW_NUMBER() OVER (PARTITION BY book.PUBLISHER_ID ORDER BY book.PRICE DESC)',
			$window->toSql()
		);
	}

	public function testToStringMatchesToSql()
	{
		$window = \Propulsion\Query\WindowExpression::rank()->orderBy('book.PRICE');
		$this->assertEquals($window->toSql(), (string) $window);
	}

	public function testNoOverClauseArgumentsProducesEmptyParens()
	{
		$this->assertEquals('DENSE_RANK() OVER ()', \Propulsion\Query\WindowExpression::denseRank()->toSql());
	}

	public function testPartitionByIsCumulative()
	{
		$window = \Propulsion\Query\WindowExpression::rowNumber()->partitionBy('a.X')->partitionBy('a.Y');
		$this->assertEquals('ROW_NUMBER() OVER (PARTITION BY a.X, a.Y)', $window->toSql());
	}

	public function testOrderByIsCumulative()
	{
		$window = \Propulsion\Query\WindowExpression::rowNumber()->orderBy('a.X')->orderBy('a.Y', 'DESC');
		$this->assertEquals('ROW_NUMBER() OVER (ORDER BY a.X ASC, a.Y DESC)', $window->toSql());
	}

	public function testAggregateFactories()
	{
		$this->assertEquals('SUM(book.PRICE) OVER ()', \Propulsion\Query\WindowExpression::sum('book.PRICE')->toSql());
		$this->assertEquals('AVG(book.PRICE) OVER ()', \Propulsion\Query\WindowExpression::avg('book.PRICE')->toSql());
		$this->assertEquals('COUNT(*) OVER ()', \Propulsion\Query\WindowExpression::count()->toSql());
		$this->assertEquals('MIN(book.PRICE) OVER ()', \Propulsion\Query\WindowExpression::min('book.PRICE')->toSql());
		$this->assertEquals('MAX(book.PRICE) OVER ()', \Propulsion\Query\WindowExpression::max('book.PRICE')->toSql());
	}

	public function testLagLeadWithOffset()
	{
		$this->assertEquals('LAG(book.PRICE, 2) OVER ()', \Propulsion\Query\WindowExpression::lag('book.PRICE', 2)->toSql());
		$this->assertEquals('LEAD(book.PRICE, 1) OVER ()', \Propulsion\Query\WindowExpression::lead('book.PRICE')->toSql());
	}

	public function testNtile()
	{
		$this->assertEquals('NTILE(4) OVER ()', \Propulsion\Query\WindowExpression::ntile(4)->toSql());
	}

	public function testRowsBetweenFrame()
	{
		$window = \Propulsion\Query\WindowExpression::sum('book.PRICE')
			->orderBy('book.ID')
			->rowsBetween(\Propulsion\Query\WindowExpression::UNBOUNDED_PRECEDING, \Propulsion\Query\WindowExpression::CURRENT_ROW);

		$this->assertEquals(
			'SUM(book.PRICE) OVER (ORDER BY book.ID ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)',
			$window->toSql()
		);
	}

	public function testRangeBetweenFrameWithNumericBounds()
	{
		$window = \Propulsion\Query\WindowExpression::avg('book.PRICE')
			->orderBy('book.ID')
			->rangeBetween(\Propulsion\Query\WindowExpression::preceding(3), \Propulsion\Query\WindowExpression::following(3));

		$this->assertEquals(
			'AVG(book.PRICE) OVER (ORDER BY book.ID ASC RANGE BETWEEN 3 PRECEDING AND 3 FOLLOWING)',
			$window->toSql()
		);
	}

	public function testRangeBetweenOverridesEarlierRowsBetween()
	{
		$window = \Propulsion\Query\WindowExpression::sum('book.PRICE')
			->rowsBetween(\Propulsion\Query\WindowExpression::UNBOUNDED_PRECEDING, \Propulsion\Query\WindowExpression::CURRENT_ROW)
			->rangeBetween(\Propulsion\Query\WindowExpression::UNBOUNDED_PRECEDING, \Propulsion\Query\WindowExpression::CURRENT_ROW);

		$this->assertEquals(
			'SUM(book.PRICE) OVER (RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)',
			$window->toSql()
		);
	}

	public function testRawFactoryForArbitraryFunctions()
	{
		$window = \Propulsion\Query\WindowExpression::raw('PERCENT_RANK()')->orderBy('book.PRICE');
		$this->assertEquals('PERCENT_RANK() OVER (ORDER BY book.PRICE ASC)', $window->toSql());
	}

	public function testWithColumnAcceptsWindowExpressionAndResolvesModelColumnNames()
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->withColumn(
			\Propulsion\Query\WindowExpression::rowNumber()->partitionBy('Book.PublisherId')->orderBy('Book.Price', 'DESC'),
			'PriceRank'
		);

		$params = array();
		$sql = normalizeGeneratedSql(BasePeer::createSelectSql($c, $params));

		$this->assertStringContainsString(
			'ROW_NUMBER() OVER (PARTITION BY book.PUBLISHER_ID ORDER BY book.PRICE DESC) AS PriceRank',
			$sql,
			'withColumn() resolves Model.Column names inside a WindowExpression the same way it does for a raw string clause'
		);
	}
}
