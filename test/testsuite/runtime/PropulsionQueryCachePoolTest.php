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
use Propulsion\Cache\Driver\NullCache;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Propulsion;
use Propulsion\ServiceContainer;

/**
 * The query cache pool is process-scoped, so it lives on ServiceContainer
 * rather than becoming a new static on Propulsion -- phase 4c would only have
 * had to move it again, and swapping the container gives tests a reset seam
 * that already exists.
 *
 * The Propulsion::setQueryCachePool()/hasQueryCachePool()/queryCachePool()
 * trio are thin delegators, named to match the PSR-3 and PSR-14 facades.
 */
class PropulsionQueryCachePoolTest extends TestCase
{
    protected function tearDown(): void
    {
        // Swapping in a fresh container is the whole reset story -- no bespoke
        // static-clearing needed.
        Propulsion::setServiceContainer(new ServiceContainer());
        parent::tearDown();
    }

    public function testDefaultsToTheNullObjectRatherThanNull()
    {
        Propulsion::setServiceContainer(new ServiceContainer());

        $this->assertFalse(Propulsion::hasQueryCachePool());
        $this->assertInstanceOf(NullCache::class, Propulsion::queryCachePool());
    }

    public function testRegisteredPoolIsReturned()
    {
        $pool = new ArrayCache();
        Propulsion::setQueryCachePool($pool);

        $this->assertTrue(Propulsion::hasQueryCachePool());
        $this->assertSame($pool, Propulsion::queryCachePool());
    }

    public function testDelegatesToTheServiceContainer()
    {
        $container = new ServiceContainer();
        Propulsion::setServiceContainer($container);

        $pool = new ArrayCache();
        Propulsion::setQueryCachePool($pool);

        $this->assertSame($pool, $container->queryCachePool(), 'the pool must live on the container');
    }

    public function testSwappingTheContainerDropsThePool()
    {
        Propulsion::setQueryCachePool(new ArrayCache());
        Propulsion::setServiceContainer(new ServiceContainer());

        $this->assertFalse(Propulsion::hasQueryCachePool());
    }

    public function testRegisteredPoolWinsOverConfiguredDriver()
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: true, driver: 'file', driverOptions: ['directory' => '/definitely/not/writable/xyz']));
        Propulsion::setServiceContainer($container);

        $pool = new ArrayCache();
        Propulsion::setQueryCachePool($pool);

        // If the configured driver were consulted it would throw on the
        // unwritable directory; the explicit registration must short-circuit it.
        $this->assertSame($pool, Propulsion::queryCachePool());
    }

    public function testPoolIsBuiltFromConfigurationAndMemoised()
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(
            enabled: true,
            driver: 'array',
            driverOptions: ['max_entries' => 7],
        ));
        Propulsion::setServiceContainer($container);

        $first = Propulsion::queryCachePool();
        $second = Propulsion::queryCachePool();

        $this->assertInstanceOf(ArrayCache::class, $first);
        $this->assertSame(7, $first->getMaxEntries());
        $this->assertSame($first, $second, 'the pool must be built once per process, not per call');
    }

    public function testDisabledConfigurationYieldsTheNullObject()
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: false, driver: 'array'));
        Propulsion::setServiceContainer($container);

        // Configuring a driver is not, by itself, consent to serve stale data.
        $this->assertInstanceOf(NullCache::class, Propulsion::queryCachePool());
    }

    public function testPsr16DriverWithNoRegisteredPoolStaysInert()
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: true, driver: 'psr16'));
        Propulsion::setServiceContainer($container);

        // A misconfiguration, but one that must not fail every query: the
        // shared tier simply never engages.
        $this->assertInstanceOf(NullCache::class, Propulsion::queryCachePool());
    }

    public function testSetQueryCacheConfigDropsAPreviouslyBuiltPool()
    {
        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: true, driver: 'array', driverOptions: ['max_entries' => 3]));

        $first = $container->queryCachePool();
        $this->assertInstanceOf(ArrayCache::class, $first);
        $this->assertSame(3, $first->getMaxEntries());

        $container->setQueryCacheConfig(new QueryCacheConfig(enabled: true, driver: 'array', driverOptions: ['max_entries' => 9]));
        $second = $container->queryCachePool();

        $this->assertInstanceOf(ArrayCache::class, $second);
        $this->assertSame(9, $second->getMaxEntries());
        $this->assertNotSame($first, $second);
    }

    public function testClearQueryCachePoolForcesReResolution()
    {
        $container = new ServiceContainer();
        Propulsion::setServiceContainer($container);
        Propulsion::setQueryCachePool(new ArrayCache());

        $container->clearQueryCachePool();

        $this->assertFalse($container->hasQueryCachePool());
        $this->assertInstanceOf(NullCache::class, $container->queryCachePool());
    }

    public function testConfigIsReadFromTheRuntimeConfigurationWhenUnset()
    {
        $container = new ServiceContainer();
        Propulsion::setServiceContainer($container);

        // Never configured in this test, so this must not fatal -- generator
        // commands and unit tests routinely run without any configuration.
        $config = $container->getQueryCacheConfig();

        $this->assertFalse($config->enabled);
        $this->assertFalse($config->isActive());
    }
}
