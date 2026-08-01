<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\SharedQueryCacheConfig;
use Propulsion\Cache\TableVersionRegistry;

/**
 * Table version tokens are what let the shared tier invalidate without an
 * index. The properties tested here are the ones the whole scheme rests on.
 */
class TableVersionRegistryUnitTest extends TestCase
{
    private function makeRegistry(?ArrayCache $backend = null): TableVersionRegistry
    {
        $backend ??= new ArrayCache(10000);

        return new TableVersionRegistry($backend, new SharedQueryCacheConfig(namespace: 'test'));
    }

    public function testTokensAreStableWithinARequest()
    {
        $registry = $this->makeRegistry();

        $this->assertSame(
            $registry->tokensFor('bookstore', ['book']),
            $registry->tokensFor('bookstore', ['book'])
        );
    }

    public function testTokenOrderIsCanonicalRegardlessOfInputOrder()
    {
        // Otherwise the same query could produce two different cache keys
        // depending on the order getQueryCacheTouchedTables() happened to
        // return, halving the hit rate for no reason.
        $registry = $this->makeRegistry();

        $this->assertSame(
            $registry->tokensFor('bookstore', ['author', 'book']),
            $registry->tokensFor('bookstore', ['book', 'author'])
        );
    }

    public function testDuplicateTablesCollapse()
    {
        $registry = $this->makeRegistry();

        $this->assertCount(1, $registry->tokensFor('bookstore', ['book', 'book']));
    }

    public function testPublishChangesTheToken()
    {
        $registry = $this->makeRegistry();
        $before = $registry->tokensFor('bookstore', ['book']);

        $registry->publish('bookstore', ['book']);

        $this->assertNotSame($before, $registry->tokensFor('bookstore', ['book']), 'a write must orphan every key built on the old token');
    }

    public function testPublishOnlyAffectsTheNamedTable()
    {
        $registry = $this->makeRegistry();
        $authorBefore = $registry->tokensFor('bookstore', ['author']);

        $registry->publish('bookstore', ['book']);

        $this->assertSame($authorBefore, $registry->tokensFor('bookstore', ['author']));
    }

    public function testTokensAreSharedThroughTheBackend()
    {
        // Two registries over one pool stand in for two processes.
        $backend = new ArrayCache(10000);
        $a = $this->makeRegistry($backend);
        $b = $this->makeRegistry($backend);

        $this->assertSame(
            $a->tokensFor('bookstore', ['book']),
            $b->tokensFor('bookstore', ['book']),
            'a token seeded by one process must be observed by another'
        );
    }

    public function testAWriteInOneProcessIsObservedByAnother()
    {
        $backend = new ArrayCache(10000);
        $writer = $this->makeRegistry($backend);
        $reader = $this->makeRegistry($backend);

        $before = $reader->tokensFor('bookstore', ['book']);
        $writer->publish('bookstore', ['book']);

        $fresh = $this->makeRegistry($backend);
        $this->assertNotSame($before, $fresh->tokensFor('bookstore', ['book']));
    }

    /**
     * The property that makes the whole scheme safe to run unattended: an
     * evicted token fails toward a miss, never toward staleness. A counter
     * reseeded to 1 would instead resurrect orphaned entries.
     */
    public function testAnEvictedTokenIsReseededToANeverUsedValue()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $original = $registry->tokensFor('bookstore', ['book']);

        $backend->clear();

        $fresh = $this->makeRegistry($backend);
        $this->assertNotSame($original, $fresh->tokensFor('bookstore', ['book']));
    }

    public function testDifferentDatasourcesDoNotShareTokens()
    {
        $registry = $this->makeRegistry();

        $this->assertNotSame(
            $registry->tokensFor('bookstore', ['book']),
            $registry->tokensFor('other', ['book']),
            'two datasources with a same-named table must not cross-invalidate'
        );
    }

    public function testResetForgetsTheMemoButNotTheBackend()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $before = $registry->tokensFor('bookstore', ['book']);

        $registry->reset();

        $this->assertSame($before, $registry->tokensFor('bookstore', ['book']), 'reset() must not discard published tokens');
    }

    // ---------------------------------------------------------------
    // Transaction buffering
    // ---------------------------------------------------------------

    public function testAnInTransactionBumpIsNotPublished()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $observer = $this->makeRegistry($backend);

        $published = $observer->tokensFor('bookstore', ['book']);
        $registry->tokensFor('bookstore', ['book']);
        $registry->overrideLocally('bookstore', 'book', 1);

        $other = $this->makeRegistry($backend);
        $this->assertSame(
            $published,
            $other->tokensFor('bookstore', ['book']),
            'another process must not see the bump before commit -- otherwise it could cache our uncommitted rows under it'
        );
    }

    public function testAnInTransactionBumpIsVisibleLocally()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $before = $registry->tokensFor('bookstore', ['book']);

        $registry->overrideLocally('bookstore', 'book', 1);

        $this->assertNotSame($before, $registry->tokensFor('bookstore', ['book']), 'our own reads must see our own writes');
    }

    public function testCommitPublishesTheBufferedBump()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $observer = $this->makeRegistry($backend);
        $before = $observer->tokensFor('bookstore', ['book']);

        $registry->overrideLocally('bookstore', 'book', 1);
        $this->assertTrue($registry->hasPending(1));
        $registry->publishPending(1);

        $after = $this->makeRegistry($backend);
        $this->assertNotSame($before, $after->tokensFor('bookstore', ['book']));
        $this->assertFalse($registry->hasPending(1));
    }

    public function testRollbackDiscardsTheBufferedBump()
    {
        $backend = new ArrayCache(10000);
        $registry = $this->makeRegistry($backend);
        $before = $registry->tokensFor('bookstore', ['book']);

        $registry->overrideLocally('bookstore', 'book', 1);
        $registry->discardPending(1);

        $this->assertSame($before, $registry->tokensFor('bookstore', ['book']), 'a rolled-back write published nothing and should stop bypassing the tier');
        $this->assertFalse($registry->hasPending(1));
    }

    public function testPendingBumpsAreTrackedPerConnection()
    {
        $registry = $this->makeRegistry();
        $registry->overrideLocally('bookstore', 'book', 1);

        $this->assertTrue($registry->hasPending(1));
        $this->assertFalse($registry->hasPending(2), 'one connection rolling back must not discard another\'s buffered bumps');
    }

    public function testResetDropsUnpublishedBumps()
    {
        $registry = $this->makeRegistry();
        $registry->overrideLocally('bookstore', 'book', 1);

        $registry->reset();

        $this->assertFalse($registry->hasPending(1));
    }
}
