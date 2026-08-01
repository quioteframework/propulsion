<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Exception\PropulsionException;
use Psr\SimpleCache\CacheInterface;

/**
 * @see Psr16DriverTestCase for the shared PSR-16 conformance assertions.
 */
class ArrayCacheTest extends Psr16DriverTestCase
{
    protected function createCache(): CacheInterface
    {
        // Comfortably above anything the conformance suite stores, so the
        // eviction bound never interferes with those assertions.
        return new ArrayCache(10000);
    }

    public function testEntryCountIsBoundedByMaxEntries()
    {
        $cache = new ArrayCache(10);
        for ($i = 0; $i < 100; $i++) {
            $cache->set('key_' . $i, $i);
        }

        $this->assertSame(10, $cache->count(), 'the cache must never exceed max_entries');
    }

    public function testEvictionDropsTheLeastRecentlyUsedEntry()
    {
        $cache = new ArrayCache(3);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Touch 'a' so 'b' becomes the least recently used.
        $cache->get('a');
        $cache->set('d', 4);

        $this->assertTrue($cache->has('a'), 'recently read entry should survive');
        $this->assertFalse($cache->has('b'), 'least recently used entry should be evicted');
        $this->assertTrue($cache->has('c'));
        $this->assertTrue($cache->has('d'));
    }

    /**
     * The bounded-growth guarantee the worker harness relies on: an unbounded
     * array in a process that never exits is an out-of-memory condition, and
     * cache keys can be driven by attacker-supplied bound parameter values.
     */
    public function testFloodOfDistinctKeysStaysBounded()
    {
        $cache = new ArrayCache(50);
        for ($i = 0; $i < 5000; $i++) {
            $cache->set('flood_' . $i, str_repeat('x', 100));
        }

        $this->assertSame(50, $cache->count());
    }

    public function testMaxEntriesMustBePositive()
    {
        $this->expectException(PropulsionException::class);
        new ArrayCache(0);
    }

    public function testFromConfigReadsMaxEntries()
    {
        $cache = ArrayCache::fromConfig(['max_entries' => 7], null);
        $this->assertSame(7, $cache->getMaxEntries());
    }

    public function testFromConfigDefaultsMaxEntries()
    {
        $cache = ArrayCache::fromConfig([], null);
        $this->assertSame(ArrayCache::DEFAULT_MAX_ENTRIES, $cache->getMaxEntries());
    }

    public function testFromConfigRejectsNonIntegerMaxEntries()
    {
        $this->expectException(PropulsionException::class);
        ArrayCache::fromConfig(['max_entries' => 'lots'], null);
    }

    public function testDefaultTtlAppliesWhenNoneIsGiven()
    {
        $cache = new ArrayCache(10, -1);
        $cache->set('expires_immediately', 'v');

        $this->assertFalse($cache->has('expires_immediately'));
    }
}
