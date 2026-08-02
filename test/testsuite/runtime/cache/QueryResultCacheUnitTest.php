<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\QueryResultCache;

/**
 * DB-independent unit tests for QueryResultCache's storage API in isolation
 * (get/set/has/invalidateTable/clear). See QueryResultCacheTest for the
 * end-to-end version exercised through a real ModelCriteria/DB, and
 * SessionTest for Session's ownership/reset() wiring of this class.
 */
class QueryResultCacheUnitTest extends TestCase
{
    public function testMissReturnsNull()
    {
        $cache = new QueryResultCache();
        $this->assertFalse($cache->has('k'));
        $this->assertNull($cache->get('k'));
    }

    public function testSetThenGetReturnsTheStoredValue()
    {
        $cache = new QueryResultCache();
        $cache->set('k', ['some', 'rows'], ['book']);

        $this->assertTrue($cache->has('k'));
        $this->assertSame(['some', 'rows'], $cache->get('k'));
    }

    public function testHasDistinguishesAStoredNullFromAMiss()
    {
        $cache = new QueryResultCache();
        $cache->set('k', null, ['book']);

        $this->assertTrue($cache->has('k'));
        $this->assertNull($cache->get('k'));
        $this->assertFalse($cache->has('unknown-key'));
    }

    public function testInvalidateTableEvictsOnlyEntriesTouchingThatTable()
    {
        $cache = new QueryResultCache();
        $cache->set('book-query', 'books', ['book']);
        $cache->set('author-query', 'authors', ['author']);

        $cache->invalidateTable('book');

        $this->assertFalse($cache->has('book-query'));
        $this->assertTrue($cache->has('author-query'));
        $this->assertSame(1, $cache->count());
    }

    public function testInvalidateTableEvictsEveryEntryThatTouchesIt()
    {
        $cache = new QueryResultCache();
        $cache->set('join-query', 'rows', ['book', 'author']);

        $cache->invalidateTable('author');

        $this->assertFalse($cache->has('join-query'));
        $this->assertSame(0, $cache->count());
    }

    public function testInvalidateTableWithNoMatchingEntriesIsANoop()
    {
        $cache = new QueryResultCache();
        $cache->set('k', 'v', ['book']);

        $cache->invalidateTable('unrelated_table');

        $this->assertTrue($cache->has('k'));
        $this->assertSame(1, $cache->count());
    }

    public function testClearEmptiesEverything()
    {
        $cache = new QueryResultCache();
        $cache->set('a', 1, ['book']);
        $cache->set('b', 2, ['author']);

        $cache->clear();

        $this->assertSame(0, $cache->count());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testInvalidatingOneTableDoesNotLeaveTheKeyListedUnderTheOthers()
    {
        // An entry from a join depends on several tables. Evicting via one of
        // them used to drop only that table's list, leaving the dead key listed
        // under every other table it touched -- so re-running and re-caching
        // the same query appended it again, and a request that invalidated and
        // re-cached in a loop grew the index without bound.
        $cache = new QueryResultCache();
        $cache->set('join-query', 'rows', ['book', 'author']);

        $cache->invalidateTable('book');
        $this->assertFalse($cache->has('join-query'));

        // Re-cache the same key, then invalidate via the *other* table: if the
        // stale entry were still listed under 'author', it would appear twice.
        $cache->set('join-query', 'rows again', ['book', 'author']);
        $cache->invalidateTable('author');

        $this->assertFalse($cache->has('join-query'));
        $this->assertSame(0, $cache->count());
        $this->assertSame([], $this->tableIndexOf($cache), 'no table should still list an evicted key');
    }

    public function testInvalidatingATableLeavesUnrelatedEntriesIndexed()
    {
        $cache = new QueryResultCache();
        $cache->set('join-query', 'rows', ['book', 'author']);
        $cache->set('author-only', 'rows', ['author']);

        $cache->invalidateTable('book');

        $this->assertFalse($cache->has('join-query'));
        $this->assertTrue($cache->has('author-only'), 'an entry that does not depend on the written table survives');

        // ...and is still reachable through its own table's index afterwards.
        $cache->invalidateTable('author');
        $this->assertFalse($cache->has('author-only'));
    }

    /**
     * @return array<string, list<string>>
     */
    private function tableIndexOf(QueryResultCache $cache): array
    {
        $prop = new ReflectionProperty(QueryResultCache::class, 'tableIndex');

        /** @var array<string, list<string>> $value */
        $value = $prop->getValue($cache);

        return $value;
    }
}
