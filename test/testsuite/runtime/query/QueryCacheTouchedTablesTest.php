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
}
