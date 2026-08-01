<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Driver;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 driver that {@see \Propulsion\Cache\CacheDriverFactory} can build
 * from a configuration array.
 *
 * Every implementation also has an ordinary typed constructor, so tests and
 * hand-wired applications never have to go through an untyped array.
 */
interface ConfigurableCacheDriver extends CacheInterface
{
    /**
     * @param  array<string, mixed> $options the driver's own option block from
     *         `cache.query.<driver>`, already type-checked by
     *         {@see \Propulsion\Cache\QueryCacheConfig}
     * @param  int|null $defaultTtl the configured `cache.query.ttl`
     * @throws \Propulsion\Exception\PropulsionException if this runtime cannot
     *         support the driver (missing extension, unwritable directory, ...)
     *         or an option is invalid
     */
    public static function fromConfig(array $options, ?int $defaultTtl): static;
}
