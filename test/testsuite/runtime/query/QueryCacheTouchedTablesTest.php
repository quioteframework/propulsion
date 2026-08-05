<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Query\Criteria;
use Propulsion\Query\ModelCriteria;

/**
 * DB-independent coverage for {@see \Propulsion\Query\Criteria::getQueryCacheTouchedTables()}
 * -- the list a cached result is indexed under, and therefore exactly the set
 * of names a write has to bump for that result to be evicted.
 *
 * Every name it returns must be a *real* table name. The write paths
 * (`BasePeer::doInsert()`/doUpdate()/doDelete()/doDeleteAll()/doUpsert()) only
 * ever know a table by its real name, so a dependency recorded under an alias
 * is indexed under a key nothing bumps: the entry then survives a write that
 * should have evicted it, for the rest of the request (L1) or until its TTL
 * lapses (L2). That is a silent stale read, which is why these assertions are
 * exact-set rather than "contains".
 *
 * Needs only the generated bookstore model classes and their DatabaseMap, not a
 * live connection, so this runs on the no-Docker tier too.
 */
class QueryCacheTouchedTablesTest extends TestCase
{
	/**
	 * Skip unless every named generated class is loadable.
	 *
	 * Most of this file needs only `BookQuery` and the bookstore DatabaseMap,
	 * which are reachable on the no-Docker tier -- hence the class docblock's
	 * claim that it runs there. A few cases below reach for a *second* model's
	 * generated Query class (`AuthorQuery`, or `PropulsionQuery::from('Review')`
	 * inside useExistsQuery()), and those are not reliably autoloadable when
	 * PROPULSION_SKIP_INTEGRATION is set: the fixtures' classmap autoloader is
	 * registered as part of the fixture build, which that mode skips. They run,
	 * and are asserted, in every integration job.
	 */
	private function requireGeneratedClasses(string ...$classes): void
	{
		foreach ($classes as $class) {
			if (!class_exists($class)) {
				$this->markTestSkipped($class . ' is not available (bookstore fixtures not built)');
			}
		}
	}

	/**
	 * @param list<string> $expected
	 */
	private function assertTouchedTables(array $expected, Criteria $c, string $message = ''): void
	{
		$actual = $c->getQueryCacheTouchedTables();
		sort($expected);
		sort($actual);
		$this->assertSame($expected, $actual, $message);
	}

	public function testPlainQueryDependsOnItsOwnTable(): void
	{
		$this->assertTouchedTables(
			array('book'),
			BookQuery::create()->filterByTitle('Anything')
		);
	}

	public function testModelAliasResolvesToTheRealTableName(): void
	{
		// The criterion map keys are "b.TITLE" here, not "book.TITLE", so a
		// naive array_keys(getTablesColumns()) yields "b" -- a name no write
		// path ever invalidates.
		$this->assertTouchedTables(
			array('book'),
			BookQuery::create()->setModelAlias('b', true)->filterByTitle('Anything'),
			'an aliased query must still depend on the real table it reads'
		);
	}

	public function testJoinedQueryDependsOnBothSidesEvenWithNoWhereClause(): void
	{
		// Nothing here contributes "book": there are no criterions at all, and
		// setPrimaryTableName() is not called on the find() path. Only the
		// join's left side supplies it.
		$this->assertTouchedTables(
			array('book', 'author'),
			BookQuery::create()->join('Book.Author'),
			'a join must register a dependency on the left table, not just the right'
		);
	}

	public function testJoinedQueryWithConditionsOnBothSides(): void
	{
		$this->assertTouchedTables(
			array('book', 'author'),
			BookQuery::create()
				->join('Book.Author')
				->where('Book.Title = ?', 'Anything')
				->where('Author.FirstName = ?', 'Someone')
		);
	}

	public function testRelationAliasResolvesToTheRealTableName(): void
	{
		// "a" is the relation alias for author; it must not leak into the
		// dependency list, and "book" (the join's left side) must be present.
		$this->assertTouchedTables(
			array('book', 'author'),
			BookQuery::create()->join('Book.Author a')->where('a.FirstName = ?', 'Someone')
		);
	}

	public function testModelAliasAndJoinCombined(): void
	{
		$this->assertTouchedTables(
			array('book', 'author'),
			BookQuery::create()
				->setModelAlias('b', true)
				->join('b.Author')
				->filterByTitle('Anything')
		);
	}

	public function testPrimaryTableNameIsAliasResolvedToo(): void
	{
		// count() sets the primary table name explicitly; an aliased Criteria
		// whose primary table name is itself the alias must still resolve.
		$c = new Criteria('bookstore');
		$c->addAlias('b', 'book');
		$c->setPrimaryTableName('b');

		$this->assertTouchedTables(array('book'), $c);
	}

	public function testUnaliasedNamesArePassedThroughUnchanged(): void
	{
		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->add('book.TITLE', 'Anything');

		$this->assertTouchedTables(array('book'), $c);
	}

	/**
	 * A query as find()/findOne() see it when they compute dependencies.
	 *
	 * ModelCriteria::prepareSelectSql() calls addSelfSelectColumns() and runs
	 * *before* getQueryCacheTouchedTables() on both paths, so the select columns
	 * are populated by the time dependencies are collected -- which is what makes
	 * them a usable dependency source. A freshly constructed BookQuery has none
	 * yet, so asserting against one would be testing a state find() never
	 * actually asks about.
	 */
	private function asPreparedForFind(ModelCriteria $query): ModelCriteria
	{
		$query->addSelfSelectColumns();

		return $query;
	}

	public function testFilterlessQueryDependsOnItsOwnTable(): void
	{
		// The regression this guards: nothing here contributes "book" except the
		// select columns. There are no criterions (so getTablesColumns() is
		// empty), no joins, and find()/findOne() -- unlike count() -- never call
		// setPrimaryTableName(). This used to return [], and an entry with no
		// dependencies cannot be invalidated by anything: not
		// QueryResultCache::invalidateTable(), not a table version bump (the L2
		// key folds in one token per dependency, so with none it is immune), and
		// therefore not Propulsion::invalidateQueryCacheForTables() either.
		// "Cache every row of this table" is about the most obviously cacheable
		// query there is, so this was reachable with one ->setQueryCache() call.
		$this->assertTouchedTables(
			array('book'),
			$this->asPreparedForFind(BookQuery::create()),
			'a filter-less, join-less query must still depend on the table it selects from'
		);
	}

	public function testFilterlessQueryWithModelAliasResolvesToTheRealTableName(): void
	{
		// Same shape, but the select columns are now qualified with the model
		// alias ("b.TITLE"), so the name recovered from them needs the same
		// alias resolution every other source here gets.
		$this->assertTouchedTables(
			array('book'),
			$this->asPreparedForFind(BookQuery::create()->setModelAlias('b', true)),
			'the table recovered from select columns must be alias-resolved'
		);
	}

	public function testFilterlessFindAndCountAgreeOnDependencies(): void
	{
		// count() sets the primary table name explicitly and so was always
		// correct; find() did not and was not. The asymmetry between two
		// terminal methods on the same query object is the bug in miniature, so
		// pin the two together rather than only assert find()'s new behaviour.
		$forCount = BookQuery::create();
		$forCount->setPrimaryTableName('book');

		$this->assertTouchedTables(
			$forCount->getQueryCacheTouchedTables(),
			$this->asPreparedForFind(BookQuery::create()),
			'find() and count() on the same filter-less query must record the same dependencies'
		);
	}

	public function testWithColumnExpressionContributesEveryTableItReferences(): void
	{
		// A withColumn() expression reaches the SELECT list but never the FROM
		// clause (DBAdapter::createSelectSqlPart() derives FROM entries from the
		// select columns only), so a correlated expression can name a table that
		// appears nowhere else in the query. Missing it serves stale rows.
		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addAsColumn(
			'ReviewCount',
			'(SELECT COUNT(*) FROM review WHERE review.BOOK_ID = book.ID)'
		);

		$this->assertTouchedTables(array('book', 'review'), $c);
	}

	public function testSchemaQualifiedSelectColumnKeepsItsSchemaPrefix(): void
	{
		// Only the final segment of a dotted run is the column, so a
		// schema-qualified table name survives whole -- "myschema.mytable", not
		// "myschema" or "mytable" (see the pgsql-multi-schema fixture).
		$c = new Criteria('bookstore');
		$c->addSelectColumn('myschema.mytable.COLUMN');

		$this->assertTouchedTables(array('myschema.mytable'), $c);
	}

	public function testUnqualifiedSelectColumnContributesNoTable(): void
	{
		// "COUNT(*)" names no table at all. It must not invent one -- a bogus
		// dependency is harmless at L1 but seeds a pointless version token at
		// L2, and inventing names is how alias leakage started.
		$c = new Criteria('bookstore');
		$c->addSelectColumn('COUNT(*)');

		$this->assertTouchedTables(array(), $c);
	}

	// ------------------------------------------------------------------
	// Nested queries.
	//
	// A table read only from inside a subquery, CTE, set-operation branch or
	// EXISTS/IN filter is every bit as much a dependency as one named in the
	// outer FROM clause, and missing it is a silent stale read. While
	// addSelectQuery() was the only nesting the builder could express this was
	// a narrow scope-down; withCte(), union()/intersect()/except() and
	// addExistsQuery()/addInQuery() each widened it.
	// ------------------------------------------------------------------

	public function testFromClauseSubqueryContributesItsTables(): void
	{
		$subQuery = new Criteria('bookstore');
		$subQuery->setPrimaryTableName('review');
		$subQuery->add('review.RECOMMENDED', true);

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addSelectQuery($subQuery, 'recommended_reviews');

		$this->assertContains(
			'review',
			$c->getQueryCacheTouchedTables(),
			'a write to review must evict a result whose rows came from it'
		);
	}

	public function testCteBodyContributesItsTables(): void
	{
		$cteQuery = new Criteria('bookstore');
		$cteQuery->setPrimaryTableName('author');
		$cteQuery->addSelectColumn('author.ID');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->add('book.AUTHOR_ID', 1);
		$c->withCte('prolific_authors', $cteQuery);

		$this->assertContains('author', $c->getQueryCacheTouchedTables());
		$this->assertContains('book', $c->getQueryCacheTouchedTables());
	}

	public function testRecursiveCteTerminates(): void
	{
		// A recursive CTE's body references the CTE's own name, so the naive
		// "resolve every name and recurse" reading of this would not obviously
		// terminate. It does, because the self-reference is a string in the
		// body's FROM clause and not a link back to a Criteria object -- but
		// that is worth pinning, since the alternative is an infinite recursion
		// inside a cache lookup.
		$anchor = new Criteria('bookstore');
		$anchor->setPrimaryTableName('book');
		$anchor->addSelectColumn('book.ID');

		$recursiveBranch = new Criteria('bookstore');
		$recursiveBranch->setPrimaryTableName('descendants');
		$recursiveBranch->addSelectColumn('descendants.ID');
		$anchor->unionAll($recursiveBranch);

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('descendants');
		$c->withCte('descendants', $anchor, array('ID'), true);

		$tables = $c->getQueryCacheTouchedTables();
		$this->assertContains('book', $tables, 'the anchor branch really reads book');
	}

	public function testEverySetOperationBranchContributesItsTables(): void
	{
		$other = new Criteria('bookstore');
		$other->setPrimaryTableName('author');
		$other->addSelectColumn('author.ID');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addSelectColumn('book.ID');
		$c->union($other);

		// Both branches' rows are in the result, so a write to either has to
		// evict it.
		$this->assertTouchedTables(array('book', 'author'), $c);
	}

	public function testChainedSetOperationsContributeEveryBranch(): void
	{
		$second = new Criteria('bookstore');
		$second->setPrimaryTableName('author');
		$second->addSelectColumn('author.ID');

		$third = new Criteria('bookstore');
		$third->setPrimaryTableName('publisher');
		$third->addSelectColumn('publisher.ID');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addSelectColumn('book.ID');
		$c->union($second);
		$c->except($third);

		$this->assertTouchedTables(array('book', 'author', 'publisher'), $c);
	}

	public function testExistsSubqueryContributesItsTables(): void
	{
		$subQuery = new Criteria('bookstore');
		$subQuery->setPrimaryTableName('review');
		$subQuery->addSelectColumn('1');
		$subQuery->add('review.BOOK_ID', 'book.ID', Criteria::CUSTOM_EQUAL);

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addExistsQuery($subQuery);

		// review appears nowhere in this query except inside the EXISTS, and a
		// new review row changes which books come back.
		$this->assertTouchedTables(array('book', 'review'), $c);
	}

	public function testNotExistsSubqueryContributesItsTables(): void
	{
		$subQuery = new Criteria('bookstore');
		$subQuery->setPrimaryTableName('review');
		$subQuery->addSelectColumn('1');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addExistsQuery($subQuery, true);

		$this->assertTouchedTables(array('book', 'review'), $c);
	}

	public function testInSubqueryContributesItsTables(): void
	{
		$subQuery = new Criteria('bookstore');
		$subQuery->setPrimaryTableName('author');
		$subQuery->addSelectColumn('author.ID');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addInQuery('book.AUTHOR_ID', $subQuery);

		$this->assertTouchedTables(array('book', 'author'), $c);
	}

	public function testSubqueryInsideAChainedOrClauseIsFound(): void
	{
		// An EXISTS added with addOr() ends up as a *clause* of an existing
		// criterion rather than as its own map entry, so walking the map alone
		// would miss it.
		$subQuery = new Criteria('bookstore');
		$subQuery->setPrimaryTableName('review');
		$subQuery->addSelectColumn('1');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->add('book.TITLE', 'Anything');
		$c->addOr(new \Propulsion\Query\Criterion($c, null, $subQuery, Criteria::EXISTS));

		$this->assertContains('review', $c->getQueryCacheTouchedTables());
	}

	public function testNestingIsFollowedToTheBottom(): void
	{
		$innermost = new Criteria('bookstore');
		$innermost->setPrimaryTableName('publisher');
		$innermost->addSelectColumn('publisher.ID');

		$middle = new Criteria('bookstore');
		$middle->setPrimaryTableName('author');
		$middle->addSelectColumn('author.ID');
		$middle->addInQuery('author.PUBLISHER_ID', $innermost);

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addInQuery('book.AUTHOR_ID', $middle);

		$this->assertTouchedTables(array('book', 'author', 'publisher'), $c);
	}

	public function testNestedQueryAliasesResolveAgainstTheirOwnQuery(): void
	{
		$this->requireGeneratedClasses('AuthorQuery');

		// The subquery's "a" and the outer query's "a" are different tables.
		// Recursing as a method call on the nested object is what keeps each
		// alias map local to the query that owns it.
		$subQuery = BookQuery::create()->setModelAlias('a', true)->filterByTitle('Anything');
		$subQuery->addSelectColumn('a.ID');

		$c = AuthorQuery::create()->setModelAlias('a', true);
		$c->addSelfSelectColumns();
		$c->addInQuery('a.ID', $subQuery);

		$this->assertTouchedTables(
			array('author', 'book'),
			$c,
			'the outer "a" is author, the inner "a" is book -- neither alias may leak into the other'
		);
	}

	public function testUseExistsQueryContributesItsTables(): void
	{
		$this->requireGeneratedClasses('ReviewQuery');

		// The same thing through the ModelCriteria-level API a caller actually
		// writes, rather than the Criteria primitive underneath it.
		$c = BookQuery::create();
		$c->addSelfSelectColumns();
		$c->useExistsQuery('Review', function ($subQuery) {
			$subQuery->where('review.BOOK_ID = book.ID');
		});

		$this->assertTouchedTables(array('book', 'review'), $c);
	}

	public function testUseCteQueryContributesItsTables(): void
	{
		$this->requireGeneratedClasses('AuthorQuery');

		$c = BookQuery::create();
		$c->addSelfSelectColumns();
		$c->useCteQuery('recent_authors', 'Author', function ($subQuery) {
			$subQuery->addSelectColumn('author.ID');
		});

		$this->assertContains('author', $c->getQueryCacheTouchedTables());
	}

	public function testACriteriaReachableFromItselfTerminates(): void
	{
		// Nothing in the query builder constructs this -- every nesting API
		// takes an already-built Criteria, so it needs a caller to hand a query
		// to itself deliberately. The guard is here because the failure mode is
		// an infinite recursion inside a cache lookup, which is a bad way to
		// find out.
		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addSelectQuery($c, 'self');

		$this->assertSame(array('book'), $c->getQueryCacheTouchedTables());
	}

	public function testARawSqlSubqueryStringIsNotFollowed(): void
	{
		// The boundary of what descending can reach, pinned deliberately rather
		// than left to be discovered. A subquery written as a raw string reaches
		// the query as opaque text -- there is no nested Criteria to recurse
		// into and no `table.column` reference in a select expression to scan,
		// so `review` is invisible here in a way it is not when the same filter
		// is written as addInQuery()/useExistsQuery(). Documented in
		// docs/CACHING.md: such a query wants rawQuery()->dependsOn(), or no
		// cache.
		$c = BookQuery::create();
		$c->addSelfSelectColumns();
		$c->where('Book.Id IN (SELECT r.book_id FROM review r WHERE r.recommended = 1)');

		$this->assertTouchedTables(array('book'), $c);
	}

	public function testANestedQueryContributingNothingChangesNothing(): void
	{
		$empty = new Criteria('bookstore');

		$c = new Criteria('bookstore');
		$c->setPrimaryTableName('book');
		$c->addExistsQuery($empty);

		$this->assertTouchedTables(array('book'), $c);
	}
}
