<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Cache\Driver\ApcuCache;
use Psr\SimpleCache\CacheInterface;

/**
 * These tests can only prove *within-process* correctness.
 *
 * APCu under the CLI SAPI is not shared between processes: `apc.enable_cli=1`
 * gives each CLI invocation its own segment, destroyed when the process exits.
 * The cross-request and cross-thread sharing that makes this driver worth using
 * is only observable under a persistent SAPI, which is what the FrankenPHP
 * harness in test/worker/ exists to demonstrate.
 *
 * @see Psr16DriverTestCase for the shared PSR-16 conformance assertions.
 */
class ApcuCacheTest extends Psr16DriverTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::apcuUsable()) {
            $this->markTestSkipped('ext-apcu is not loaded or not enabled (needs apc.enable_cli=1 under CLI)');
        }
        // Without this the suite is order-dependent: APCu's segment outlives an
        // individual test method.
        apcu_clear_cache();
    }

    protected function createCache(): CacheInterface
    {
        // A per-test prefix keeps the conformance suite's fixed key names from
        // colliding across methods within one segment.
        return new ApcuCache('propulsion_test_' . str_replace([':', ' '], '_', $this->name()) . '_');
    }

    private static function apcuUsable(): bool
    {
        return extension_loaded('apcu')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    public function testGetMultipleUsesTheNativeBatchFetch()
    {
        $cache = $this->createCache();
        $cache->set('batch_a', 1);
        $cache->set('batch_c', 3);

        $result = $cache->getMultiple(['batch_a', 'batch_b', 'batch_c'], 'MISS');
        $result = is_array($result) ? $result : iterator_to_array($result);

        $this->assertSame(['batch_a' => 1, 'batch_b' => 'MISS', 'batch_c' => 3], $result);
    }

    public function testGetMultipleWithNoKeysIsAnEmptyResult()
    {
        $cache = $this->createCache();
        $result = $cache->getMultiple([]);
        $result = is_array($result) ? $result : iterator_to_array($result);

        $this->assertSame([], $result);
    }

    public function testPrefixIsolatesTwoPoolsInOneSegment()
    {
        $a = new ApcuCache('propulsion_test_ns_a_');
        $b = new ApcuCache('propulsion_test_ns_b_');

        $a->set('shared_name', 'from_a');
        $b->set('shared_name', 'from_b');

        $this->assertSame('from_a', $a->get('shared_name'));
        $this->assertSame('from_b', $b->get('shared_name'));
    }

    public function testStoredFalseIsAHitNotAMiss()
    {
        // apcu_fetch() signals a miss through its by-reference $success flag,
        // not its return value -- storing false would otherwise read as absent.
        $cache = $this->createCache();
        $cache->set('false_key', false);

        $this->assertTrue($cache->has('false_key'));
        $this->assertFalse($cache->get('false_key', 'SENTINEL'));
    }
}
