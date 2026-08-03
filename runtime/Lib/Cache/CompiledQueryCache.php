<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

/**
 * Process-scoped cache of compiled SELECT SQL strings, keyed by a caller-supplied
 * "shape" key (see {@see \Propulsion\Query\Criteria::setCompiledQueryCache()}).
 *
 * This is a different axis than {@see QueryResultCache}: that one caches *rows*
 * for a given SQL+params pair; this one caches the *SQL string itself* so that a
 * `Criteria` rebuilt with the same shape but different bound values (the common
 * case for the same generated Query/Peer method called repeatedly in a long-lived
 * worker process) skips re-walking joins/columns/criterions to re-derive text that
 * would come out identical every time. Bound parameter *values* are never part of
 * the cached entry -- only the SQL template and how many placeholders it expects.
 *
 * **Owned by {@see \Propulsion\ServiceContainer}, not {@see \Propulsion\Session}.**
 * It began on the Session, cleared at every request boundary alongside
 * {@see QueryResultCache}, on the reasoning that an entry keyed by a
 * caller-chosen string must not leak between unrelated requests using the same
 * key for differently-scoped queries. But that made the cache almost pointless
 * for the deployment it exists to serve: a worker recompiled identical SQL on
 * every request, and the whole benefit is meant to accrue *across* requests. The
 * entry is a pure function of the datasource and the query shape -- no bound
 * values, no request data, nothing that could identify who asked -- which is
 * exactly the definition of process-scoped state (see `docs/WORKER_MODE.md`, R3).
 *
 * The key-collision hazard is real but unchanged in kind: two call sites sharing
 * one key for different shapes collided *within* a request before and collide
 * across requests now. It is still guarded the same way -- the recorded
 * `paramCount` is compared on every hit -- and still the caller's responsibility,
 * as setCompiledQueryCache() documents at length. What did change is that
 * {@see \Propulsion\Propulsion::setConfiguration()} now clears this: SQL text
 * depends on the datasource's adapter (identifier quoting, LIMIT/OFFSET
 * dialect), so reconfiguring a live process must not leave entries compiled for
 * the previous adapter reachable. Request-scoping used to hide that.
 */
class CompiledQueryCache
{
    /**
     * How many compiled statements to hold.
     *
     * Process-scoped and never expiring, so it needs a ceiling for the same
     * reason every other long-lived cache here does. The natural bound is the
     * number of distinct shape keys in the application -- one per generated Query
     * method that opted in, which is a property of the code rather than of the
     * traffic, so a correctly-used cache will sit far below this. A caller
     * deriving keys from request data instead (against setCompiledQueryCache()'s
     * documented contract) is the case this stops from growing unboundedly.
     */
    public const MAX_ENTRIES = 1000;

    /**
     * @var array<string, array{sql: string, paramCount: int}>
     */
    private array $entries = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /**
     * @return array{sql: string, paramCount: int}|null
     */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, string $sql, int $paramCount): void
    {
        $this->entries[$key] = ['sql' => $sql, 'paramCount' => $paramCount];

        // Oldest-first, like the other bounded caches here: evicting only ever
        // costs recompiling the SQL, and a cache that has reached this bound is
        // being keyed wrongly rather than being used hard.
        while (count($this->entries) > self::MAX_ENTRIES) {
            unset($this->entries[(string) array_key_first($this->entries)]);
        }
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
