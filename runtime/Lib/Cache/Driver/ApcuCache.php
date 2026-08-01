<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Driver;

use Propulsion\Cache\AtomicCache;
use Propulsion\Exception\PropulsionException;

/**
 * PSR-16 over APCu shared memory.
 *
 * The realistic zero-infrastructure choice for a single host: the segment is
 * shared by every php-fpm worker and every FrankenPHP thread in the pool, so a
 * result cached by one request is visible to all the others, and lookups cost
 * a hash probe in memory already mapped into the process.
 *
 * Two limits matter enough to state plainly, because both are silent:
 *
 *  - **It is per host and per SAPI master.** Restarting php-fpm discards the
 *    whole segment, so a deploy cold-starts the cache.
 *  - **CLI processes do not share it.** With `apc.enable_cli=1` each CLI
 *    invocation gets its *own* segment, destroyed when the process exits. A
 *    cron job or `bin/propulsion` command therefore cannot see, warm, or --
 *    the dangerous one -- *invalidate* the web tier's entries. If anything
 *    writes to the database from CLI, {@see FileCache} or a shared network
 *    pool is not merely faster to reason about, it is more correct.
 *
 * Capacity is bounded by `apc.shm_size`; APCu evicts under pressure on its own,
 * so this driver adds no bound of its own.
 */
final class ApcuCache extends AbstractCacheDriver implements ConfigurableCacheDriver, AtomicCache
{
    public function __construct(
        private readonly string $prefix = '',
        private readonly ?int $defaultTtl = null,
    ) {
        if (!extension_loaded('apcu')) {
            throw new PropulsionException(
                'The "apcu" cache driver requires ext-apcu, which is not loaded: install it or choose another '
                . 'Propulsion cache driver. Check your configuration file'
            );
        }
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new PropulsionException(
                'The "apcu" cache driver requires APCu to be enabled (apc.enabled=1, and apc.enable_cli=1 under '
                . 'the CLI SAPI). Check your php.ini'
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromConfig(array $options, ?int $defaultTtl): static
    {
        return new static('', $defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $success = false;
        /** @var mixed $value */
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    /**
     * Uses APCu's native multi-key fetch, so a query needing several table
     * version tokens costs one call rather than one per token.
     *
     * @param  iterable<mixed> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->normaliseKeys($keys);
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map(fn (string $k): string => $this->prefix . $k, $keys);
        /** @var array<string, mixed>|false $fetched */
        $fetched = apcu_fetch($prefixed);
        if (!is_array($fetched)) {
            $fetched = [];
        }

        $result = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->prefix . $key;
            $result[$key] = array_key_exists($prefixedKey, $fetched) ? $fetched[$prefixedKey] : $default;
        }

        return $result;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->isElapsed($ttl, $this->defaultTtl)) {
            apcu_delete($this->prefix . $key);

            return true;
        }

        return apcu_store($this->prefix . $key, $value, $this->apcuTtl($ttl));
    }

    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->isElapsed($ttl, $this->defaultTtl)) {
            return false;
        }

        return apcu_add($this->prefix . $key, $value, $this->apcuTtl($ttl));
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        apcu_delete($this->prefix . $key);

        // A delete of an absent key is a success per PSR-16; apcu_delete()
        // reports false in that case, so its return value is not the answer.
        return true;
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $result = apcu_exists($this->prefix . $key);

        return $result === true;
    }

    /**
     * APCu expresses "never expires" as a TTL of 0.
     */
    private function apcuTtl(null|int|\DateInterval $ttl): int
    {
        $seconds = $this->ttlToSeconds($ttl, $this->defaultTtl);

        return $seconds === null ? 0 : max(0, $seconds);
    }
}
