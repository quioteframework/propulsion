<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Propulsion;

/**
 * Integration tests for the query result cache ({@see \Propulsion\Cache\QueryResultCache},
 * {@see \Propulsion\Query\Criteria::setQueryCache()}). Needs a real DB and
 * generated model classes for the same reason ModelCriteriaBulkEventDispatchTest
 * does (see that class's docblock), so this follows the same
 * BookstoreTestBase + BookstoreDataPopulator style.
 *
 * "Cache hit" is proven by mutating the `book` table directly through
 * `$this->con` (bypassing the ORM's write path entirely, so no invalidation
 * fires) and asserting a cache-enabled query still returns the pre-mutation
 * result -- an uncached control query in the same test proves the DB really
 * did change, so this isn't just testing that nothing happened.
 */
class QueryResultCacheTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::depopulate();
		BookstoreDataPopulator::populate();
		Propulsion::getSession()->getQueryResultCache()->clear();
	}

	public function testCacheHitServesStaleResultUntilInvalidatedByAWriteThroughTheOrm(): void
	{
		$cached = new ModelCriteria('bookstore', 'Book');
		$cached->setQueryCache(true);
		$countBeforeMutation = $cached->count($this->con);

		// Bypass the ORM entirely: no BasePeer::doInsert() call means no
		// invalidateTable('book') fires.
		$this->con->exec("INSERT INTO book (title, isbn) VALUES ('Cache Test Book', '000-0-000-00000-0')");

		$cachedAgain = new ModelCriteria('bookstore', 'Book');
		$cachedAgain->setQueryCache(true);
		$countFromCache = $cachedAgain->count($this->con);
		$this->assertSame($countBeforeMutation, $countFromCache, 'a cache-enabled query must be served from the query result cache, not re-executed, so it does not see a write that bypassed the ORM');

		$uncached = new ModelCriteria('bookstore', 'Book');
		$countUncached = $uncached->count($this->con);
		$this->assertSame($countBeforeMutation + 1, $countUncached, 'sanity check: the direct SQL insert must actually have landed, proving the cached count above really was stale rather than coincidentally correct');

		// A real ORM write to the same table must invalidate the cache entry.
		$delete = new ModelCriteria('bookstore', 'Book', 'b');
		$delete->where('b.Title = ?', 'Cache Test Book');
		$delete->delete($this->con);

		$cachedAfterOrmWrite = new ModelCriteria('bookstore', 'Book');
		$cachedAfterOrmWrite->setQueryCache(true);
		$countAfterInvalidation = $cachedAfterOrmWrite->count($this->con);
		$this->assertSame($countBeforeMutation, $countAfterInvalidation, 'a write through the ORM (ModelCriteria::delete(), via BasePeer::doDelete()) must invalidate the stale cache entry so the next cache-enabled query re-executes');
	}

	public function testWriteToOneTableDoesNotInvalidateAnUnrelatedTablesCacheEntry(): void
	{
		$bookQuery = new ModelCriteria('bookstore', 'Book');
		$bookQuery->setQueryCache(true);
		$bookQuery->count($this->con);

		$authorQuery = new ModelCriteria('bookstore', 'Author');
		$authorQuery->setQueryCache(true);
		$authorQuery->count($this->con);

		$cache = Propulsion::getSession()->getQueryResultCache();
		$this->assertSame(2, $cache->count(), 'both queries should have populated the cache');

		$bookInsert = new ModelCriteria('bookstore', 'Book', 'b');
		BookPeer::doInsert(
			(new Criteria())->add(BookPeer::TITLE, 'Another Cache Test Book')->add(BookPeer::ISBN, '000-0-000-00000-1'),
			$this->con
		);

		$this->assertSame(1, $cache->count(), 'a write to the book table must evict only the book query cache entry, leaving the unrelated author query entry intact');
	}

	public function testSessionResetClearsTheQueryResultCache(): void
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->setQueryCache(true);
		$c->count($this->con);

		$cache = Propulsion::getSession()->getQueryResultCache();
		$this->assertGreaterThan(0, $cache->count(), 'the query should have populated the cache');

		Propulsion::getSession()->reset();

		$this->assertSame(0, $cache->count(), 'Session::reset() (the worker request-boundary hook) must clear the query result cache the same way it clears instance pools');
	}

	public function testQueryCacheIsOffByDefault(): void
	{
		$c = new ModelCriteria('bookstore', 'Book');
		$c->count($this->con);

		$this->assertSame(0, Propulsion::getSession()->getQueryResultCache()->count(), 'a query must not populate the cache unless setQueryCache(true) was called');
	}

	public function testAWriteInvalidatesAnEntryWhoseTableIsOnlyReadInsideASubquery(): void
	{
		// The table this query depends on most is one it never names in its own
		// FROM clause: which books come back is decided entirely by the review
		// rows the EXISTS finds. While getQueryCacheTouchedTables() did not
		// descend into subqueries, the entry was indexed under `book` alone, so
		// an ORM write to `review` -- a write Propulsion can see perfectly well
		// and does bump a version token for -- left it served for the rest of
		// the request and, at the shared tier, for the whole TTL.
		$booksWithReviews = static function (): ModelCriteria {
			$query = new ModelCriteria('bookstore', 'Book');
			$query->setQueryCache(true);
			$query->useExistsQuery('Review', static function ($subQuery) {
				$subQuery->where('Review.BookId = Book.Id');
			});

			return $query;
		};

		$before = $booksWithReviews()->count($this->con);

		// Give a book that had no review one, through the ORM, so the correct
		// answer really does change.
		$book = BookQuery::create()
			->useExistsQuery('Review', static function ($subQuery) {
				$subQuery->where('Review.BookId = Book.Id');
			}, true)
			->findOne($this->con);
		$this->assertNotNull($book, 'the fixture needs at least one book with no reviews for this to prove anything');

		$review = new Review();
		$review->setReviewedBy('Subquery Invalidation Test');
		$review->setRecommended(true);
		$review->setBook($book);
		$review->save($this->con);

		$after = $booksWithReviews()->count($this->con);

		$this->assertSame(
			$before + 1,
			$after,
			'a write to a table read only through an EXISTS subquery must still evict the entry'
		);
	}
}
