<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Propulsion;
use Propulsion\Exception\PropulsionException;

/**
 * Integration tests for the compiled-query cache ({@see \Propulsion\Cache\CompiledQueryCache},
 * {@see \Propulsion\Query\Criteria::setCompiledQueryCache()}). Needs a real DB
 * and generated model classes for the same reason QueryResultCacheTest does.
 *
 * Unlike the query *result* cache, this caches the SQL *string*, not rows --
 * so a cache hit must still return the correct row set for whatever bound
 * values the current Criteria carries, even though the SQL text came from an
 * earlier call with different values.
 */
class CompiledQueryCacheTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::depopulate();
		BookstoreDataPopulator::populate();
		Propulsion::getServiceContainer()->getCompiledQueryCache()->clear();
	}

	public function testCompiledQueryCacheIsOffByDefault(): void
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->find($this->con);

		$this->assertSame(0, Propulsion::getServiceContainer()->getCompiledQueryCache()->count(), 'a query must not populate the compiled query cache unless setCompiledQueryCache() was called with a key');
	}

	public function testSameShapeDifferentValuesBothReturnTheirOwnCorrectRow(): void
	{
		$books = BookPeer::doSelect(new Criteria('bookstore'), $this->con);
		$this->assertGreaterThanOrEqual(2, count($books), 'fixture must have at least two books for this test to be meaningful');

		$first = $books[0];
		$second = $books[1];

		$c1 = new ModelCriteria('bookstore', 'Book', 'b');
		$c1->setCompiledQueryCache(__METHOD__);
		$c1->where('b.Id = ?', $first->getId());
		$result1 = $c1->find($this->con);

		$this->assertSame(1, Propulsion::getServiceContainer()->getCompiledQueryCache()->count(), 'the first call should have compiled and cached the SQL');
		$this->assertCount(1, $result1);
		$this->assertSame($first->getId(), $result1[0]->getId());

		// Same shape (same key, same comparison), different bound value -- must
		// still get the right row back from the cached SQL template, and must
		// not add a second cache entry.
		$c2 = new ModelCriteria('bookstore', 'Book', 'b');
		$c2->setCompiledQueryCache(__METHOD__);
		$c2->where('b.Id = ?', $second->getId());
		$result2 = $c2->find($this->con);

		$this->assertSame(1, Propulsion::getServiceContainer()->getCompiledQueryCache()->count(), 'a second call with the same shape key must reuse the cached SQL, not add a new entry');
		$this->assertCount(1, $result2);
		$this->assertSame($second->getId(), $result2[0]->getId());
	}

	public function testReusingAKeyForADifferentParameterCountThrows(): void
	{
		$c1 = new ModelCriteria('bookstore', 'Book', 'b');
		$c1->setCompiledQueryCache(__METHOD__);
		$c1->where('b.Title = ?', 'Some Title');
		$c1->find($this->con);

		$c2 = new ModelCriteria('bookstore', 'Book', 'b');
		$c2->setCompiledQueryCache(__METHOD__);
		$c2->where('b.Title = ?', 'Some Title');
		$c2->where('b.ISBN = ?', '000-0-000-00000-0');

		$this->expectException(PropulsionException::class);
		$c2->find($this->con);
	}

	/**
	 * The compiled-query cache is process-scoped, so it deliberately survives the
	 * worker request boundary -- reuse *across* requests is the entire point, and
	 * clearing it every request made a worker recompile the same SQL forever. The
	 * entry holds only SQL text derived from the datasource and the query shape:
	 * no bound values, nothing request-identifying. Contrast the query *result*
	 * cache, which holds hydrated rows and must not survive.
	 */
	public function testSessionResetDoesNotClearTheCompiledQueryCache(): void
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->setCompiledQueryCache(__METHOD__);
		$c->find($this->con);

		$cache = Propulsion::getServiceContainer()->getCompiledQueryCache();
		$compiled = $cache->count();
		$this->assertGreaterThan(0, $compiled, 'the query should have populated the compiled query cache');

		Propulsion::getSession()->reset();

		$this->assertSame(
			$compiled,
			$cache->count(),
			'the compiled query cache is process-scoped and must outlive the request boundary'
		);

		// ...and the request-scoped result cache still must not.
		$this->assertSame(0, Propulsion::getSession()->getQueryResultCache()->count());
	}

	public function testReconfiguringClearsTheCompiledQueryCache(): void
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->setCompiledQueryCache(__METHOD__);
		$c->find($this->con);

		$cache = Propulsion::getServiceContainer()->getCompiledQueryCache();
		$this->assertGreaterThan(0, $cache->count());

		// Reconfiguring is this test's subject, so it cannot avoid dropping the
		// adapter map; it can avoid leaving it dropped. Restoring is what stops
		// the QuickBuilder-registered adapters -- unrebuildable from any
		// configuration -- vanishing for every test that runs after this one.
		$state = PropulsionStateSnapshot::capture();

		try {
			// A new configuration can name a different adapter for the same
			// datasource, and the cached SQL carries that adapter's identifier
			// quoting and LIMIT/OFFSET dialect, so it must not stay reachable.
			Propulsion::setConfiguration(Propulsion::getConfiguration());

			$this->assertSame(0, $cache->count(), 'setConfiguration() must drop SQL compiled against the previous configuration');
		} finally {
			$state->restore();
		}
	}

	public function testTheSessionAccessorStillReachesTheProcessWideCache(): void
	{
		$this->assertSame(
			Propulsion::getServiceContainer()->getCompiledQueryCache(),
			Propulsion::getSession()->getCompiledQueryCache(),
			'the deprecated Session accessor must delegate to the real, process-scoped cache'
		);
	}
}
