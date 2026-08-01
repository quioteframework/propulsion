<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Driver;

use Propulsion\Cache\Exception\InvalidCacheKeyException;
use Psr\SimpleCache\CacheInterface;

/**
 * Shared behaviour for Propulsion's first-party PSR-16 drivers: key validation,
 * TTL normalisation, and naive loop implementations of the *Multiple() methods
 * for drivers that have no native batch operation to map them onto.
 *
 * Two conventions established here are relied on by the rest of the cache
 * subsystem:
 *
 *  - **A non-positive TTL deletes rather than stores.** PSR-16 leaves the
 *    behaviour of an already-elapsed TTL to the implementation; treating it as
 *    "delete and report success" is both a defensible reading and the thing
 *    that makes expiry testable with no `sleep()` and no injected clock
 *    anywhere in the suite.
 *  - **Reads never throw on backend trouble.** A corrupt file, a full shared
 *    memory segment or a dead socket degrades to a miss. Only *programming*
 *    errors (an illegal key) and *configuration* errors (an unwritable cache
 *    directory, a missing extension) throw, and the latter throw at
 *    construction, not on the hot path.
 */
abstract class AbstractCacheDriver implements CacheInterface
{
    /**
     * PSR-16 reserves `{}()/\@:` for future use and requires support for keys
     * of at least 64 characters drawn from `A-Za-z0-9_.`. Propulsion only ever
     * generates keys from that charset (a namespace plus a hex digest), so the
     * drivers enforce the strict form: anything outside it is rejected rather
     * than quietly encoded, which is what keeps us honest about staying
     * portable to third-party pools that enforce the same rule.
     */
    private const KEY_PATTERN = '/^[A-Za-z0-9_.]+$/';

    /**
     * @throws InvalidCacheKeyException
     */
    protected function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKeyException('Cache key must not be an empty string');
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidCacheKeyException(sprintf(
                'Cache key "%s" contains characters not permitted by PSR-16; legal characters are A-Za-z0-9_.',
                $key
            ));
        }
    }

    /**
     * Normalise a PSR-16 TTL into an absolute unix expiry timestamp.
     *
     * @param  null|int|\DateInterval $ttl
     * @param  int|null $defaultTtl seconds, used when $ttl is null
     * @return int|null absolute unix timestamp, or null for "never expires"
     */
    protected function expiryFor(null|int|\DateInterval $ttl, ?int $defaultTtl): ?int
    {
        $seconds = $this->ttlToSeconds($ttl, $defaultTtl);
        if ($seconds === null) {
            return null;
        }

        return time() + $seconds;
    }

    /**
     * @param  null|int|\DateInterval $ttl
     * @return int|null seconds, or null for "never expires"
     */
    protected function ttlToSeconds(null|int|\DateInterval $ttl, ?int $defaultTtl): ?int
    {
        if ($ttl === null) {
            return $defaultTtl;
        }
        if (is_int($ttl)) {
            return $ttl;
        }

        $reference = new \DateTimeImmutable('@0');

        return $reference->add($ttl)->getTimestamp();
    }

    /**
     * True when the given TTL means "already expired", in which case callers
     * must delete rather than store. See the class docblock.
     */
    protected function isElapsed(null|int|\DateInterval $ttl, ?int $defaultTtl): bool
    {
        $seconds = $this->ttlToSeconds($ttl, $defaultTtl);

        return $seconds !== null && $seconds <= 0;
    }

    /**
     * @param  iterable<mixed> $keys
     * @return list<string>
     * @throws InvalidCacheKeyException
     */
    protected function normaliseKeys(iterable $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidCacheKeyException(
                    'Cache keys must be strings, got ' . get_debug_type($key)
                );
            }
            $this->validateKey($key);
            $out[] = $key;
        }

        return $out;
    }

    /**
     * @param  iterable<mixed> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($this->normaliseKeys($keys) as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidCacheKeyException(
                    'Cache keys must be strings, got ' . get_debug_type($key)
                );
            }
            $ok = $this->set($key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($this->normaliseKeys($keys) as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }
}
