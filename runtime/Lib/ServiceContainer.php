<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

use Propulsion\Cache\CacheDriverFactory;
use Propulsion\Cache\CompiledQueryCache;
use Propulsion\Cache\Driver\NullCache;
use Propulsion\Cache\QueryCacheConfig;
use Psr\SimpleCache\CacheInterface;

/**
 * Process-scoped service registry (Propulsion worker-safety rework, phase 4a --
 * see PROPULSION_WORKER_REWORK.md / KNOWN_ISSUES.md "Phase 4").
 *
 * In a persistent-worker environment (FrankenPHP worker mode, etc.) a single PHP
 * process serves many requests, so process-wide state has to be split into two
 * kinds:
 *
 *  - state that is genuinely expensive/safe to share across requests (database
 *    connections, adapters, table maps) -- this belongs here, on ServiceContainer.
 *  - state that must not leak between requests (object instance pools, the
 *    forceMasterConnection replication flag, in-flight transactions) -- this
 *    belongs on {@see Session}, which is reset at each request boundary.
 *
 * This is phase 4a/4b: `Propulsion`'s other existing process-global statics
 * (connection map, adapter map, database maps) are NOT being ripped out or
 * re-homed here yet -- that is phase 4c, gated on the (separately in
 * progress) Phase 3 builder rename landing first. For now, ServiceContainer's
 * concrete job is a thin `clearInstancePools()` convenience delegating to
 * `Session` (see below); the connection/adapter/table-map ownership described
 * above is the target shape, not what 4a/4b actually move.
 *
 * Phase 4b history: prior to this phase, every generated `FooPeer` class had
 * its own private `static $instances` array with no central registry, so this
 * class had to walk every table in every loaded `DatabaseMap` to guess which
 * Peer classes existed, plus an explicit `registerInstancePoolClass()`
 * escape hatch for classes that hadn't been touched yet. Now that pool
 * storage genuinely lives on `Session` (keyed by Peer FQCN, populated lazily
 * the first time a class pools anything), there is nothing left to walk or
 * register -- `Session::clearAllPools()` clears every pool that could
 * possibly exist, full stop. `registerInstancePoolClass()`/
 * `getRegisteredInstancePoolClasses()` are kept as inert bookkeeping only
 * because existing tests (`ServiceContainerTest`) exercise them directly;
 * they no longer influence `clearInstancePools()`'s behavior.
 */
class ServiceContainer
{
    /**
     * @var array<class-string, true>
     */
    private array $instancePoolClasses = [];

    /**
     * Kept for backwards compatibility -- no longer consulted by
     * {@see clearInstancePools()}, which now clears every Peer's pool
     * unconditionally via `Session::clearAllPools()`. Safe to call more than
     * once for the same class.
     */
    /** @param class-string $peerClass */
    public function registerInstancePoolClass(string $peerClass): void
    {
        $this->instancePoolClasses[$peerClass] = true;
    }

    /**
     * @return array<int, class-string> Peer classnames explicitly registered via
     *                                   {@see registerInstancePoolClass()}.
     */
    public function getRegisteredInstancePoolClasses(): array
    {
        return array_keys($this->instancePoolClasses);
    }

    /**
     * Clear every generated Peer class's instance pool by delegating straight
     * to the current `Session`, which is where pool storage genuinely lives
     * as of phase 4b (see `Session::$instancePools`). No more walking
     * `DatabaseMap`s or tracking a registry of classnames -- a pool that was
     * never populated is already empty.
     */
    public function clearInstancePools(): void
    {
        Propulsion::getSession()->clearAllPools();
    }

    /**
     * Compiled SELECT SQL strings, shared by every request this process serves.
     *
     * Built lazily so a process that never opts a query into it never allocates
     * one. See {@see CompiledQueryCache}'s own docblock for why this is
     * process-scoped rather than living on {@see Session} as it originally did.
     */
    private ?CompiledQueryCache $compiledQueryCache = null;

    /**
     * The process-wide compiled-query (SQL-string) cache. Opted into per query
     * via {@see \Propulsion\Query\Criteria::setCompiledQueryCache()}.
     */
    public function getCompiledQueryCache(): CompiledQueryCache
    {
        return $this->compiledQueryCache ??= new CompiledQueryCache();
    }

    /**
     * Discard every compiled statement.
     *
     * Called by {@see Propulsion::setConfiguration()}, because the SQL text an
     * entry holds is specific to the adapter the datasource was using when it was
     * compiled -- identifier quoting and LIMIT/OFFSET dialect both come from
     * there -- so a reconfiguration must not leave entries compiled against the
     * previous one reachable. Also useful for test isolation.
     */
    public function clearCompiledQueryCache(): void
    {
        $this->compiledQueryCache?->clear();
    }

    /**
     * The PSR-16 pool backing the global (L2) query result cache, once one has
     * been registered or lazily built. Null means "not resolved yet", which is
     * not the same as "none configured" -- see {@see queryCachePool()}.
     */
    private ?CacheInterface $queryCachePool = null;

    /** Parsed `cache.query` configuration, resolved lazily and then memoised. */
    private ?QueryCacheConfig $queryCacheConfig = null;

    /**
     * Register the PSR-16 pool to back the global query result cache.
     *
     * Propulsion ships no Redis or Memcached client of its own: hand it any
     * third-party PSR-16 implementation instead, e.g. symfony/cache's
     * `new Psr16Cache(new RedisAdapter($redis))`. The first-party drivers under
     * `Propulsion\Cache\Driver` are also ordinary PSR-16 implementations and
     * can be passed here directly.
     *
     * A pool registered this way always wins over `cache.query.driver`.
     *
     * Unlike the PSR-3 logger and PSR-14 dispatcher facades this mirrors, an
     * unregistered cache is not simply inert: it can still build itself from
     * the runtime configuration, because a cache backend is deployment
     * configuration rather than application wiring.
     */
    public function setQueryCachePool(CacheInterface $pool): void
    {
        $this->queryCachePool = $pool;
    }

    /**
     * True once a pool has been resolved -- either registered explicitly or
     * built from configuration by a previous {@see queryCachePool()} call.
     */
    public function hasQueryCachePool(): bool
    {
        return $this->queryCachePool !== null;
    }

    /**
     * The pool backing the global query result cache.
     *
     * Resolution order: a pool registered via {@see setQueryCachePool()};
     * otherwise one built by {@see CacheDriverFactory} from `cache.query`;
     * otherwise a {@see NullCache}. Never returns null -- "no cache
     * configured" is modelled as the null object, the same way
     * {@see \Propulsion\Adapter\DBNone} models "no adapter".
     *
     * The result is memoised for the life of the process, so a `file` driver
     * only creates its directory tree once.
     */
    public function queryCachePool(): CacheInterface
    {
        if ($this->queryCachePool !== null) {
            return $this->queryCachePool;
        }

        $config = $this->getQueryCacheConfig();
        if (!$config->isActive() || $config->driver === QueryCacheConfig::DRIVER_USER_SUPPLIED) {
            // driver=psr16 with nothing registered is a misconfiguration, but
            // failing every query over it would be a poor trade: the shared
            // tier simply stays inert, and CacheDriverFactory carries the
            // explanatory message for anyone who asks it to build one.
            return $this->queryCachePool = new NullCache();
        }

        return $this->queryCachePool = CacheDriverFactory::factory(
            $config->driver,
            $config->driverOptions,
            $config->ttl
        );
    }

    /**
     * The parsed `cache.query` section, read from the runtime configuration on
     * first use and memoised thereafter.
     */
    public function getQueryCacheConfig(): QueryCacheConfig
    {
        return $this->queryCacheConfig ??= QueryCacheConfig::fromConfigArray(
            Propulsion::getConfigurationArray()
        );
    }

    /**
     * Override the cache configuration, bypassing the runtime configuration
     * file. Also drops any pool already built from the previous config.
     */
    public function setQueryCacheConfig(QueryCacheConfig $config): void
    {
        $this->queryCacheConfig = $config;
        $this->queryCachePool = null;
    }

    /**
     * Forget the registered/memoised pool and configuration, so the next
     * {@see queryCachePool()} call resolves them again.
     *
     * Called by {@see Propulsion::setConfiguration()} -- a new configuration
     * may name a different driver -- and useful in tests for isolation.
     */
    public function clearQueryCachePool(): void
    {
        $this->queryCachePool = null;
        $this->queryCacheConfig = null;
    }
}
