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
 * A bounded in-process PSR-16 store.
 *
 * This is not only a test double. In a persistent-worker SAPI (FrankenPHP,
 * RoadRunner, Swoole) the process outlives the request, so a plain PHP array
 * *is* a genuine cross-request cache tier -- and the fastest one available,
 * since it involves no serialization, no syscalls and no network.
 *
 * Its scope is narrower than that suggests, in two ways, and both are silent:
 *
 *  - **Not cross-process.** Two php-fpm workers, or a CLI cron job and the web
 *    tier, each get their own copy and never see each other's writes -- or,
 *    more dangerously, each other's *invalidations*.
 *  - **Not cross-thread either**, under a threaded worker. PHP memory belongs
 *    to the thread, so with FrankenPHP's `worker <n>` setting above 1 each
 *    thread accumulates its own entries. Measured directly by the
 *    `l2:array-cross-thread` profile in test/worker/driver.php: sequential
 *    requests tend to land on one thread and hit, while concurrently-issued
 *    writes are spread across threads and are then invisible to the others.
 *
 * So it is the right choice for a single-threaded long-lived worker and for
 * tests, and the wrong one everywhere else -- {@see ApcuCache} shares across
 * threads and processes on one host, {@see FileCache} across SAPIs as well.
 *
 * The entry count is bounded and the oldest-used entry is evicted when the
 * bound is reached. That bound is not optional decoration: an unbounded array
 * in a process that never exits is an out-of-memory condition waiting for
 * enough distinct cache keys, and the shared query cache is keyed partly by
 * bound parameter values, so "enough distinct keys" can be attacker-controlled.
 *
 * @phpstan-type Entry array{value: mixed, expiry: int|null}
 */
final class ArrayCache extends AbstractCacheDriver implements ConfigurableCacheDriver, AtomicCache
{
    public const DEFAULT_MAX_ENTRIES = 1000;

    /**
     * Use-ordered map of live entries. PHP preserves insertion
     * order, and every read moves its entry to the end (see {@see touch()}),
     * so the first element is always the least recently used one.
     *
     * @var array<string, Entry>
     */
    private array $entries = [];

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly ?int $defaultTtl = null,
    ) {
        if ($this->maxEntries < 1) {
            throw new PropulsionException('ArrayCache max_entries must be at least 1, got ' . $this->maxEntries);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromConfig(array $options, ?int $defaultTtl): static
    {
        $max = $options['max_entries'] ?? self::DEFAULT_MAX_ENTRIES;
        if (!is_int($max)) {
            throw new PropulsionException(
                'Propulsion configuration option "cache.query.array.max_entries" must be an integer, got ' . get_debug_type($max)
            );
        }

        return new static($max, $defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return $default;
        }
        if ($this->hasExpired($entry)) {
            unset($this->entries[$key]);

            return $default;
        }
        $this->touch($key);

        return $entry['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->isElapsed($ttl, $this->defaultTtl)) {
            unset($this->entries[$key]);

            return true;
        }

        unset($this->entries[$key]);
        $this->entries[$key] = ['value' => $value, 'expiry' => $this->expiryFor($ttl, $this->defaultTtl)];
        $this->evictIfNeeded();

        return true;
    }

    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        // An already-elapsed TTL would store nothing, so reporting "won the
        // race" would hand the caller a lock that does not exist.
        if ($this->isElapsed($ttl, $this->defaultTtl) || $this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        if ($this->hasExpired($entry)) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    /**
     * Live entry count, after dropping anything already expired. Exposed for
     * the bounded-growth assertions in the worker harness and the driver tests.
     */
    public function count(): int
    {
        foreach ($this->entries as $key => $entry) {
            if ($this->hasExpired($entry)) {
                unset($this->entries[$key]);
            }
        }

        return count($this->entries);
    }

    public function getMaxEntries(): int
    {
        return $this->maxEntries;
    }

    /**
     * @param Entry $entry
     */
    private function hasExpired(array $entry): bool
    {
        return $entry['expiry'] !== null && $entry['expiry'] <= time();
    }

    /**
     * Move an entry to the end of the map, making it the most recently used.
     */
    private function touch(string $key): void
    {
        $entry = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
    }

    private function evictIfNeeded(): void
    {
        if (count($this->entries) <= $this->maxEntries) {
            return;
        }

        // Expired entries are dead weight -- drop those before evicting
        // anything that is still live.
        foreach ($this->entries as $key => $entry) {
            if ($this->hasExpired($entry)) {
                unset($this->entries[$key]);
            }
        }

        // The loop condition guarantees a non-empty array, so array_key_first()
        // always yields a key here.
        while (count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }
}
