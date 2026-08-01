<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use Propulsion\Cache\Driver\ApcuCache;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\Driver\ConfigurableCacheDriver;
use Propulsion\Cache\Driver\FileCache;
use Propulsion\Cache\Driver\NullCache;
use Propulsion\Exception\PropulsionException;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a PSR-16 pool from a `cache.query.driver` name, mirroring
 * {@see \Propulsion\Adapter\DBAdapter::factory()} -- including its
 * empty-string null-object entry, which is what lets "no cache configured" be
 * an ordinary code path rather than a null check at every call site.
 *
 * Propulsion deliberately ships **no Redis or Memcached driver**. Both
 * protocols already have several mature PSR-16 implementations, and owning
 * reconnection, cluster and sentinel topologies, TLS, authentication and
 * protocol versions in order to duplicate them would be pure maintenance cost.
 * Point `driver` at `psr16` and hand Propulsion any third-party pool instead:
 *
 *     Propulsion::setQueryCachePool(new Psr16Cache(new RedisAdapter($redis)));
 */
abstract class CacheDriverFactory
{
    /**
     * Driver name to first-party driver class.
     *
     * `psr16` is deliberately absent: it means "the application supplies the
     * pool", so there is nothing here to construct.
     *
     * @var array<string, class-string<ConfigurableCacheDriver>>
     */
    private static array $drivers = [
        '' => NullCache::class,
        'null' => NullCache::class,
        'array' => ArrayCache::class,
        'apcu' => ApcuCache::class,
        'file' => FileCache::class,
    ];

    /**
     * Create the pool named by $driver.
     *
     * @param  string $driver one of {@see getDriverNames()}
     * @param  array<string, mixed> $options the driver's own option block
     * @param  int|null $defaultTtl the configured `cache.query.ttl`
     * @throws PropulsionException if the driver is unknown, is `psr16` (which
     *         cannot be constructed here), or cannot run on this system
     */
    public static function factory(string $driver, array $options = [], ?int $defaultTtl = null): CacheInterface
    {
        if ($driver === QueryCacheConfig::DRIVER_USER_SUPPLIED) {
            throw new PropulsionException(
                'Propulsion cache driver "psr16" requires a pool supplied by the application: call '
                . 'Propulsion::setQueryCachePool() with your own PSR-16 implementation before the first cached '
                . 'query, or choose a built-in driver (' . implode(', ', self::getDriverNames()) . ')'
            );
        }

        $driverClass = self::$drivers[$driver] ?? null;
        if ($driverClass === null) {
            throw new PropulsionException(
                'Unsupported Propulsion cache driver: ' . $driver . ': Check your configuration file'
            );
        }

        return $driverClass::fromConfig($options, $defaultTtl);
    }

    /**
     * Constructible driver names, for error messages and configuration
     * validation. Excludes the empty-string alias (which is an internal
     * spelling of `null`) and `psr16` (which is not constructible).
     *
     * @return list<string>
     */
    public static function getDriverNames(): array
    {
        return array_values(array_filter(
            array_keys(self::$drivers),
            static fn (string $name): bool => $name !== ''
        ));
    }
}
