<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\CacheDriverFactory;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\Driver\FileCache;
use Propulsion\Cache\Driver\NullCache;
use Propulsion\Exception\PropulsionException;

/**
 * @see \Propulsion\Adapter\DBAdapter::factory() the pattern this mirrors.
 */
class CacheDriverFactoryTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            FileCacheTest::removeTree($this->dir);
        }
        parent::tearDown();
    }

    public function testEmptyDriverYieldsTheNullObject()
    {
        // The '' => NullCache entry mirrors DBAdapter's '' => DBNone: it is
        // what makes "no cache configured" an ordinary code path.
        $this->assertInstanceOf(NullCache::class, CacheDriverFactory::factory(''));
    }

    public function testNullDriverYieldsTheNullObject()
    {
        $this->assertInstanceOf(NullCache::class, CacheDriverFactory::factory('null'));
    }

    public function testArrayDriver()
    {
        $cache = CacheDriverFactory::factory('array', ['max_entries' => 5]);

        $this->assertInstanceOf(ArrayCache::class, $cache);
        $this->assertSame(5, $cache->getMaxEntries());
    }

    public function testFileDriver()
    {
        $this->dir = sys_get_temp_dir() . '/propulsion-factory-' . bin2hex(random_bytes(6));
        $cache = CacheDriverFactory::factory('file', ['directory' => $this->dir]);

        $this->assertInstanceOf(FileCache::class, $cache);
    }

    public function testApcuDriver()
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('ext-apcu is not loaded or not enabled');
        }

        $this->assertInstanceOf(\Propulsion\Cache\Driver\ApcuCache::class, CacheDriverFactory::factory('apcu'));
    }

    public function testUnknownDriverThrowsTheDocumentedMessage()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unsupported Propulsion cache driver: memcached: Check your configuration file');

        CacheDriverFactory::factory('memcached');
    }

    public function testPsr16DriverPointsAtSetQueryCachePool()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Propulsion::setQueryCachePool()');

        CacheDriverFactory::factory('psr16');
    }

    public function testDriverNamesExcludeTheEmptyAlias()
    {
        $names = CacheDriverFactory::getDriverNames();

        $this->assertContains('null', $names);
        $this->assertContains('array', $names);
        $this->assertContains('apcu', $names);
        $this->assertContains('file', $names);
        $this->assertNotContains('', $names);
        $this->assertNotContains('psr16', $names, 'psr16 is not constructible by the factory');
    }

    public function testDefaultTtlIsPassedThroughToTheDriver()
    {
        $cache = CacheDriverFactory::factory('array', [], -1);
        $cache->set('immediately_stale', 'v');

        $this->assertFalse($cache->has('immediately_stale'), 'the configured default TTL should apply');
    }
}
