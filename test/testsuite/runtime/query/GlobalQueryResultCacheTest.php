<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Propulsion;
use Propulsion\ServiceContainer;
use Propulsion\Session;

/**
 * End-to-end tests for the global (cross-request) cache tier.
 *
 * The defining behaviour is proven by running a query, calling
 * `Session::reset()` -- which is exactly what a worker host does at a request
 * boundary, and which wipes the request-scoped tier and every instance pool --
 * and then running the same query again. Anything that still comes back
 * without touching the database came from the shared tier.
 *
 * Backed by {@see ArrayCache} rather than APCu or Redis: it is deterministic,
 * needs no extension or container, and the tier under test is the same either
 * way. Genuine cross-*process* behaviour is covered by
 * FileCacheCrossProcessTest and by the FrankenPHP harness in test/worker/.
 *
 * Extends {@see BookstoreAutocommitTestBase} rather than BookstoreTestBase,
 * which wraps every test in a transaction: the shared tier is intentionally
 * inert inside one -- a SELECT there can see uncommitted rows, and publishing
 * those to a cache other processes read would leak them -- so a
 * transaction-wrapped test could never exercise it. The two
 * transaction-specific cases below open their own transactions explicitly,
 * which is the honest way to test that rule.
 *
 * @see QueryResultCacheTest for the request-scoped tier.
 */
class GlobalQueryResultCacheTest extends BookstoreAutocommitTestBase
{
    private ArrayCache $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new ArrayCache(10000);
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(
            enabled: true,
            driver: 'array',
            ttl: 300,
            namespace: 'test',
            // Admission control is exercised in SharedQueryCacheUnitTest; these
            // tests want the straightforward cache-on-first-miss behaviour.
            minSightings: 1,
            // Deterministic: no probabilistic early recomputation.
            beta: 0.0,
        ));
        $container->setQueryCachePool($this->pool);
        Propulsion::setServiceContainer($container);
        Propulsion::setSession(new Session());
    }

    private function cachedCount(): int
    {
        $c = new ModelCriteria('bookstore', 'Book');
        $c->setQueryCache(true);

        return $c->count($this->con);
    }

    /**
     * @return PropulsionObjectCollection<int, mixed>
     */
    private function cachedFind()
    {
        $c = new ModelCriteria('bookstore', 'Book', 'b');
        $c->setQueryCache(true);
        $c->orderBy('b.Id');

        return $c->find($this->con);
    }

    public function testResultSurvivesARequestBoundary(): void
    {
        $before = $this->cachedCount();

        $this->newRequest();

        // Bypass the ORM so nothing bumps the table version: if the second
        // count still matches, it was served from the shared tier rather than
        // re-executed.
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Global Cache Book', '000-0-000-00000-1')");

        $this->assertSame($before, $this->cachedCount(), 'the shared tier must survive Session::reset()');

        $uncached = new ModelCriteria('bookstore', 'Book');
        $this->assertSame($before + 1, $uncached->count($this->con), 'sanity: the direct insert really landed');
    }

    public function testRequestScopedTierStillDoesNotSurviveARequestBoundary(): void
    {
        Propulsion::getSession()->getQueryResultCache()->set('some_key', 'value', ['book']);
        $this->assertTrue(Propulsion::getSession()->getQueryResultCache()->has('some_key'));

        $this->newRequest();

        $this->assertFalse(
            Propulsion::getSession()->getQueryResultCache()->has('some_key'),
            'the request-scoped tier must still be wiped at the boundary -- that contrast is the whole point'
        );
    }

    public function testOrmWriteInvalidatesAcrossTheRequestBoundary(): void
    {
        $before = $this->cachedCount();
        $this->newRequest();

        $book = new Book();
        $book->setTitle('Written Through The ORM');
        $book->setISBN('000-0-000-00000-2');
        $book->save($this->con);

        $this->newRequest();

        $this->assertSame($before + 1, $this->cachedCount(), 'an ORM write must invalidate the shared entry, not just the request-scoped one');
    }

    public function testWriteToAnUnrelatedTableDoesNotInvalidate(): void
    {
        $before = $this->cachedCount();
        $this->newRequest();

        $author = new Author();
        $author->setFirstName('Unrelated');
        $author->setLastName('Author');
        $author->save($this->con);

        $this->newRequest();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Should Stay Hidden', '000-0-000-00000-3')");

        $this->assertSame($before, $this->cachedCount(), 'a write to author must not evict a cached count over book');
    }

    /**
     * The single most dangerous possible bug in this feature: if the shared
     * tier stored formatted results, a live object graph would be handed
     * across a request boundary and mutations would leak between requests.
     * Storing raw rows means each hit re-hydrates.
     */
    public function testSharedHitReturnsEqualButNotIdenticalObjects(): void
    {
        $first = $this->cachedFind();
        $this->assertGreaterThan(0, count($first));

        $this->newRequest();

        $second = $this->cachedFind();

        $this->assertSame(count($first), count($second));
        $this->assertSame($first[0]->getTitle(), $second[0]->getTitle(), 'the same data must come back');
        $this->assertNotSame($first[0], $second[0], 'a shared-tier hit must re-hydrate, never hand back the previous request\'s object');
    }

    /**
     * The same query, formatted differently. Identical SQL by construction --
     * the formatter is not part of it -- so both are asking the shared tier for
     * the same rows.
     *
     * @return PropulsionObjectCollection<int, mixed>|PropulsionArrayCollection<int, mixed>
     */
    private function cachedFindAs(string $format)
    {
        $c = new ModelCriteria('bookstore', 'Book', 'b');
        $c->setQueryCache(true);
        $c->orderBy('b.Id');
        $c->setFormatter($format);

        return $c->find($this->con);
    }

    /**
     * How many entries the shared pool is holding.
     */
    private function sharedEntryCount(): int
    {
        return $this->pool->count();
    }

    public function testTwoFormattersShareOneSharedEntry(): void
    {
        // The payoff: L2 stores raw rows, which are formatter-independent, so
        // folding the formatter into its key -- as L1 must, since L1 stores the
        // formatted result -- would store one identical row set twice and make
        // each formatter miss on the other's work.
        $this->pool->clear();

        $objects = $this->cachedFindAs(ModelCriteria::FORMAT_OBJECT);
        $this->assertGreaterThan(0, count($objects));
        $afterFirst = $this->sharedEntryCount();

        $this->newRequest();

        $arrays = $this->cachedFindAs(ModelCriteria::FORMAT_ARRAY);

        $this->assertSame(
            $afterFirst,
            $this->sharedEntryCount(),
            'the ARRAY-formatted query must reuse the OBJECT-formatted query\'s stored rows, not store a second copy'
        );
        $this->assertSame(count($objects), count($arrays));
    }

    public function testArrayFormatterConsumesRowsStoredByTheObjectFormatter(): void
    {
        // The correctness half of the sharing claim, and the coverage that was
        // named as the precondition for making it: it is not enough that the
        // two agree on a key, the rows one stored have to be genuinely usable
        // by the other.
        $objects = $this->cachedFindAs(ModelCriteria::FORMAT_OBJECT);
        $expectedTitles = array();
        foreach ($objects as $book) {
            $expectedTitles[] = $book->getTitle();
        }

        $this->newRequest();

        // Bypass the ORM, so no table version is bumped and the stored entry
        // stays valid. This is what makes the assertion below load-bearing: if
        // the ARRAY query re-executed instead of consuming the OBJECT query's
        // stored rows, it would see this row and the titles would differ.
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Formatter Sharing Probe A', '000-0-000-00000-6')");

        $arrays = $this->cachedFindAs(ModelCriteria::FORMAT_ARRAY);

        $actualTitles = array();
        foreach ($arrays as $row) {
            $this->assertIsArray($row, 'the ARRAY formatter must still produce arrays off a shared hit');
            $actualTitles[] = $row['Title'];
        }

        $this->assertSame(
            $expectedTitles,
            $actualTitles,
            'the ARRAY-formatted query must have been served the rows the OBJECT-formatted one stored'
        );
        $this->assertNotContains('Formatter Sharing Probe A', $actualTitles);
    }

    public function testObjectFormatterConsumesRowsStoredByTheArrayFormatter(): void
    {
        // And the other direction, which is the one that would break if the
        // stored rows were ever formatter-shaped rather than raw: hydrating a
        // real object graph out of them has to work too.
        $arrays = $this->cachedFindAs(ModelCriteria::FORMAT_ARRAY);
        $expectedTitles = array();
        foreach ($arrays as $row) {
            $expectedTitles[] = $row['Title'];
        }

        $this->newRequest();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Formatter Sharing Probe B', '000-0-000-00000-7')");

        $objects = $this->cachedFindAs(ModelCriteria::FORMAT_OBJECT);

        $actualTitles = array();
        foreach ($objects as $book) {
            $this->assertInstanceOf(Book::class, $book, 'a shared hit must hydrate real model objects');
            $this->assertFalse($book->isNew(), 'and they must be hydrated as existing rows, not new ones');
            $actualTitles[] = $book->getTitle();
        }

        $this->assertSame(
            $expectedTitles,
            $actualTitles,
            'the OBJECT-formatted query must have been served the rows the ARRAY-formatted one stored'
        );
        $this->assertNotContains('Formatter Sharing Probe B', $actualTitles);
    }

    public function testSharingFormattersStillRespectsInvalidation(): void
    {
        // Sharing must not accidentally make an entry harder to evict: the
        // single entry the two formatters share still depends on `book`.
        $this->cachedFindAs(ModelCriteria::FORMAT_OBJECT);
        $this->newRequest();

        $book = new Book();
        $book->setTitle('Shared Between Formatters');
        $book->setISBN('000-0-000-00000-9');
        $book->save($this->con);

        $this->newRequest();

        $titles = array();
        foreach ($this->cachedFindAs(ModelCriteria::FORMAT_ARRAY) as $row) {
            $titles[] = $row['Title'];
        }

        $this->assertContains(
            'Shared Between Formatters',
            $titles,
            'an ORM write must evict the entry both formatters share'
        );
    }

    public function testUncachedQueriesAreUnaffected(): void
    {
        $uncached = new ModelCriteria('bookstore', 'Book');
        $before = $uncached->count($this->con);

        $this->newRequest();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Uncached Sees This', '000-0-000-00000-4')");

        $again = new ModelCriteria('bookstore', 'Book');
        $this->assertSame($before + 1, $again->count($this->con), 'a query that never opted in must never be cached');
    }

    public function testDisabledConfigurationBypassesTheSharedTier(): void
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: false, driver: 'array'));
        $container->setQueryCachePool($this->pool);
        Propulsion::setServiceContainer($container);
        Propulsion::getSession()->reset();

        $before = $this->cachedCount();
        $this->newRequest();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Config Off', '000-0-000-00000-5')");

        $this->assertSame($before + 1, $this->cachedCount(), 'with the config flag off, nothing should be shared across the boundary');
    }

    public function testPerQuerySharedOptOutKeepsResultsRequestScoped(): void
    {
        $c = new ModelCriteria('bookstore', 'Book');
        $c->setQueryCache(true, null, false);
        $before = $c->count($this->con);

        $this->newRequest();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Opted Out', '000-0-000-00000-6')");

        $after = new ModelCriteria('bookstore', 'Book');
        $after->setQueryCache(true, null, false);

        $this->assertSame($before + 1, $after->count($this->con), 'shared:false must keep a query out of the cross-request tier');
    }

    /**
     * A write inside an open transaction must not publish its invalidation
     * until commit -- otherwise another process could cache our uncommitted
     * rows under an already-bumped version and nothing would dislodge them.
     */
    public function testInTransactionReadsAreNotPublishedToTheSharedTier(): void
    {
        $this->cachedCount();
        $this->newRequest();

        $entriesBefore = $this->pool->count();

        $this->con->beginTransaction();
        try {
            $c = new ModelCriteria('bookstore', 'Book', 'b');
            $c->setQueryCache(true);
            $c->where('b.Title = ?', 'In Transaction Only');
            $c->find($this->con);

            $this->assertSame(
                $entriesBefore,
                $this->pool->count(),
                'a SELECT inside a transaction sees uncommitted rows and must not be published to a shared cache'
            );
        } finally {
            $this->con->rollBack();
        }
    }

    public function testCommittedOrmWriteInvalidatesAfterCommit(): void
    {
        $before = $this->cachedCount();
        $this->newRequest();

        $this->con->beginTransaction();
        $book = new Book();
        $book->setTitle('Committed In Transaction');
        $book->setISBN('000-0-000-00000-7');
        $book->save($this->con);
        $this->con->commit();

        $this->newRequest();

        $this->assertSame($before + 1, $this->cachedCount(), 'the version bump buffered during the transaction must be published on commit');
    }

    public function testRolledBackWriteLeavesTheCachedResultIntact(): void
    {
        $before = $this->cachedCount();
        $this->newRequest();

        $this->con->beginTransaction();
        $book = new Book();
        $book->setTitle('Rolled Back');
        $book->setISBN('000-0-000-00000-8');
        $book->save($this->con);
        $this->con->rollBack();

        $this->newRequest();

        $this->assertSame($before, $this->cachedCount(), 'a rolled-back write published nothing, so the cached result is still correct');
    }
}
