<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\CompiledQueryCache;

/**
 * DB-independent unit tests for CompiledQueryCache's storage API in isolation
 * (get/set/has/clear). See CompiledQueryCacheTest for the end-to-end version
 * exercised through a real Criteria/BasePeer::createSelectSql(), and
 * SessionTest for Session's ownership/reset() wiring of this class.
 */
class CompiledQueryCacheUnitTest extends TestCase
{
    public function testEntryCountIsBounded()
    {
        // Process-scoped and never expiring, so it needs a ceiling. A correctly
        // keyed cache holds one entry per opted-in query shape -- a property of
        // the code, not the traffic -- so this only bites a caller deriving keys
        // from request data, against the documented contract.
        $cache = new CompiledQueryCache();
        for ($i = 0; $i < CompiledQueryCache::MAX_ENTRIES + 25; $i++) {
            $cache->set('shape-' . $i, 'SELECT ' . $i, 1);
        }

        $this->assertSame(CompiledQueryCache::MAX_ENTRIES, $cache->count());
        $this->assertFalse($cache->has('shape-0'), 'the oldest entry is evicted first');
        $this->assertTrue($cache->has('shape-' . (CompiledQueryCache::MAX_ENTRIES + 24)), 'the newest entry is kept');
    }

    public function testReSettingAKeyDoesNotGrowTheCache()
    {
        $cache = new CompiledQueryCache();
        $cache->set('shape', 'SELECT 1', 1);
        $cache->set('shape', 'SELECT 2', 1);

        $this->assertSame(1, $cache->count());
        $this->assertSame(['sql' => 'SELECT 2', 'paramCount' => 1], $cache->get('shape'));
    }

    public function testMissReturnsNull()
    {
        $cache = new CompiledQueryCache();
        $this->assertFalse($cache->has('k'));
        $this->assertNull($cache->get('k'));
    }

    public function testSetThenGetReturnsTheStoredSqlAndParamCount()
    {
        $cache = new CompiledQueryCache();
        $cache->set('k', 'SELECT * FROM book WHERE book.ID=:p1', 1);

        $this->assertTrue($cache->has('k'));
        $this->assertSame(['sql' => 'SELECT * FROM book WHERE book.ID=:p1', 'paramCount' => 1], $cache->get('k'));
    }

    public function testSetOverwritesAnExistingEntryForTheSameKey()
    {
        $cache = new CompiledQueryCache();
        $cache->set('k', 'SELECT 1', 0);
        $cache->set('k', 'SELECT 2', 0);

        $this->assertSame(['sql' => 'SELECT 2', 'paramCount' => 0], $cache->get('k'));
        $this->assertSame(1, $cache->count());
    }

    public function testClearEmptiesEverything()
    {
        $cache = new CompiledQueryCache();
        $cache->set('a', 'SELECT 1', 0);
        $cache->set('b', 'SELECT 2', 0);

        $cache->clear();

        $this->assertSame(0, $cache->count());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }
}
