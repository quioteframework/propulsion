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
}
