<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Adapter\DBOracle;
use Propulsion\Query\Criteria;

/**
 * The two adapters whose applyLimit() emits the ANSI "OFFSET n ROWS [FETCH NEXT
 * m ROWS ONLY]" clause: DBMSSQL and DBOracle.
 *
 * That clause is a syntax error without an ORDER BY on the same query level, so
 * both adapters splice in a no-op ordering when the query has none. Deciding
 * whether it has none used to be `preg_match('/\bORDER BY\b/i', $sql)` over the
 * whole generated string, which matches an ORDER BY belonging to a *nested*
 * query -- a FROM-clause subquery, an IN/EXISTS subquery, a CTE, or one branch
 * of a set operation. The adapter then concluded "already ordered" for an outer
 * query that had no ordering at all, skipped the synthetic clause, and emitted
 * a bare "OFFSET n ROWS": ORA-00907 on Oracle, "invalid usage of the option
 * NEXT in the FETCH statement" on SQL Server. PropulsionModelPager always sets
 * a limit, so any paginated query of that shape failed outright.
 *
 * Deliberately a plain TestCase rather than a BookstoreTestBase subclass:
 * applyLimit() is pure string manipulation and needs no connection, no
 * DatabaseMap and no fixture, so these run on the no-Docker tier. That matters
 * more here than usual -- MSSQL and Oracle are the two platforms whose
 * integration tiers are least often run (see KNOWN_ISSUES.md), which is a large
 * part of why this survived as long as it did.
 */
class OffsetFetchLimitTest extends TestCase
{
	/**
	 * @return array<string, array{0: DBMSSQL|DBOracle, 1: string}>
	 */
	public static function offsetFetchAdapters(): array
	{
		return array(
			// The synthetic ordering each adapter appends. Oracle needs a FROM
			// on every query including this one; SQL Server does not.
			'mssql'  => array(new DBMSSQL(), 'ORDER BY (SELECT NULL)'),
			'oracle' => array(new DBOracle(), 'ORDER BY (SELECT NULL FROM dual)'),
		);
	}

	/**
	 * A non-zero offset is used throughout so DBMSSQL takes its OFFSET/FETCH
	 * path rather than the TOP rewrite it prefers when offset is 0 (DBOracle has
	 * only the one path, so the offset is immaterial there).
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testFromClauseSubqueryOrderByDoesNotSuppressTheSyntheticOrdering(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		// The exact shape SubQueryTest::testSubQueryExplicit produces: the
		// subquery is ordered, the outer query is not.
		$sql = 'SELECT a.ID FROM (SELECT book.ID FROM book ORDER BY book.TITLE ASC) AS a';
		$db->applyLimit($sql, 10, 10);

		$this->assertStringContainsString(
			$syntheticOrderBy,
			$sql,
			'an ORDER BY inside a FROM-clause subquery must not be mistaken for the outer query having one'
		);
		$this->assertOrderByPrecedesOffset($sql);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testSetOperationBranchOrderByDoesNotSuppressTheSyntheticOrdering(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		// BasePeer::createSetOperationSql() parenthesises each branch and lets it
		// keep its own ORDER BY, then applies the outer limit to the combination.
		$sql = '(SELECT a FROM t ORDER BY a) UNION (SELECT b FROM u)';
		$db->applyLimit($sql, 10, 5);

		$this->assertStringContainsString($syntheticOrderBy, $sql);
		$this->assertOrderByPrecedesOffset($sql);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testCteOrderByDoesNotSuppressTheSyntheticOrdering(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		$sql = 'WITH ranked AS (SELECT id FROM book ORDER BY title) SELECT id FROM ranked';
		$db->applyLimit($sql, 10, 5);

		$this->assertStringContainsString($syntheticOrderBy, $sql);
		$this->assertOrderByPrecedesOffset($sql);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testGenuineOuterOrderByIsNotDuplicated(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		$sql = 'SELECT a FROM t ORDER BY a';
		$db->applyLimit($sql, 10, 5);

		$this->assertStringNotContainsString(
			$syntheticOrderBy,
			$sql,
			'a query that really is ordered must not get a second, conflicting ORDER BY'
		);
		$this->assertStringContainsString('OFFSET 10 ROWS', $sql);
	}

	/**
	 * The Criteria is the authoritative source when one is passed:
	 * getOrderByColumns() is what BasePeer builds the outer ORDER BY *from*, so
	 * it answers the question exactly rather than by inference.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testCriteriaWithoutOrderByForcesTheSyntheticOrdering(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		$criteria = new Criteria('bookstore');
		$sql = 'SELECT a.ID FROM (SELECT book.ID FROM book ORDER BY book.TITLE ASC) AS a';
		$db->applyLimit($sql, 10, 10, $criteria);

		$this->assertStringContainsString($syntheticOrderBy, $sql);
		$this->assertOrderByPrecedesOffset($sql);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testCriteriaWithOrderBySuppressesTheSyntheticOrdering(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		$criteria = new Criteria('bookstore');
		$criteria->addAscendingOrderByColumn('book.TITLE');
		$sql = 'SELECT book.ID FROM book ORDER BY book.TITLE ASC';
		$db->applyLimit($sql, 10, 10, $criteria);

		$this->assertStringNotContainsString($syntheticOrderBy, $sql);
	}

	/**
	 * An "order by" inside a string literal is not a clause. Criteria::setComment()
	 * and any LIKE pattern can put arbitrary text into the SQL, and a false
	 * positive here reintroduces the bare-OFFSET syntax error this all guards.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('offsetFetchAdapters')]
	public function testOrderByInsideAStringLiteralIsNotAClause(
		DBMSSQL|DBOracle $db,
		string $syntheticOrderBy
	): void {
		$sql = "SELECT a FROM t WHERE note = 'sort order by hand'";
		$db->applyLimit($sql, 10, 5);

		$this->assertStringContainsString($syntheticOrderBy, $sql);
		$this->assertOrderByPrecedesOffset($sql);
	}

	public function testMssqlTopRewriteKeepsAnAggregatesOwnDistinct(): void
	{
		// The leading DISTINCT is re-emitted as part of the "SELECT DISTINCT TOP n"
		// prefix and so has to be stripped from the select list -- but only that
		// one. An unanchored str_ireplace('distinct ', '') also removed an
		// aggregate's own DISTINCT, which changes what the query counts and
		// returns wrong results silently, with no error to notice it by.
		$sql = 'SELECT DISTINCT book.ID, COUNT(DISTINCT book.AUTHOR_ID) FROM book';
		(new DBMSSQL())->applyLimit($sql, 0, 10);

		$this->assertSame(
			'SELECT DISTINCT TOP 10 book.ID, COUNT(DISTINCT book.AUTHOR_ID) FROM book',
			$sql
		);
	}

	public function testMssqlTopRewriteStillStripsTheLeadingDistinct(): void
	{
		$sql = 'SELECT DISTINCT book.ID FROM book';
		(new DBMSSQL())->applyLimit($sql, 0, 10);

		$this->assertSame('SELECT DISTINCT TOP 10 book.ID FROM book', $sql);
	}

	/**
	 * OFFSET/FETCH is only legal *after* ORDER BY, so containing both is not
	 * enough -- they have to be in that order.
	 */
	private function assertOrderByPrecedesOffset(string $sql): void
	{
		$offsetPos = strpos($sql, 'OFFSET ');
		$this->assertNotFalse($offsetPos, 'expected an OFFSET clause in: ' . $sql);

		$orderByPos = strripos(substr($sql, 0, $offsetPos), 'ORDER BY');
		$this->assertNotFalse(
			$orderByPos,
			'OFFSET/FETCH is a syntax error without a preceding ORDER BY: ' . $sql
		);
	}
}
