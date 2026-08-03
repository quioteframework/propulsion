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
}
