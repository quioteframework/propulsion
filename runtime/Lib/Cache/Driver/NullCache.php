<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Driver;

/**
 * The "no cache configured" null object, mirroring
 * {@see \Propulsion\Adapter\DBNone}'s role in {@see \Propulsion\Adapter\DBAdapter::factory()}.
 *
 * Everything is a miss and every write silently succeeds, so the rest of the
 * cache subsystem needs no null checks: an unconfigured deployment runs the
 * exact same code path as a configured one, just without ever getting a hit.
 *
 * It still *validates* keys. That is deliberate: a project developing against
 * the null driver would otherwise only discover an illegal key when it first
 * deployed against a real backend.
 */
final class NullCache extends AbstractCacheDriver implements ConfigurableCacheDriver
{
    /**
     * @param array<string, mixed> $options
     */
    public static function fromConfig(array $options, ?int $defaultTtl): static
    {
        return new static();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        return $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return false;
    }
}
