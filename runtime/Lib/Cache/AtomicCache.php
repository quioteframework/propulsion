<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * An optional capability on top of PSR-16: a store-if-absent operation that is
 * atomic with respect to concurrent callers.
 *
 * PSR-16 itself has no such operation -- it offers only get/set/has/delete, and
 * a `has()`-then-`set()` pair is a read-modify-write race, not a lock. That
 * makes strict single-flight (exactly one process recomputing a given cache
 * entry at a time) *impossible* to implement correctly against an arbitrary
 * PSR-16 pool. Rather than pretend otherwise, Propulsion treats single-flight
 * as a capability: backends that can do it advertise it by implementing this
 * interface, and {@see \Propulsion\Cache\SharedQueryCache} detects it with a
 * plain `instanceof`. A pool that does not implement it still gets the
 * probabilistic early-recomputation defence, which needs no atomicity at all.
 *
 * All three of Propulsion's real first-party drivers can honour this natively:
 * {@see \Propulsion\Cache\Driver\ApcuCache} via `apcu_add()`,
 * {@see \Propulsion\Cache\Driver\FileCache} via `O_EXCL` file creation, and
 * {@see \Propulsion\Cache\Driver\ArrayCache} trivially (single process, and PHP
 * does not preempt mid-statement).
 */
interface AtomicCache extends CacheInterface
{
    /**
     * Store $value under $key only if $key is not already present.
     *
     * @param  string $key   PSR-16 legal cache key
     * @param  mixed  $value the value to store
     * @param  null|int|\DateInterval $ttl expiry; null means the driver default
     * @return bool   true if this caller created the entry (i.e. "won the
     *                race"), false if it already existed or the write failed
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is not a legal PSR-16 key
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
}
