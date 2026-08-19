<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

use Propulsion\Cache\CompiledQueryCache;
use Propulsion\Cache\Driver\NullCache;
use Propulsion\Cache\QueryResultCache;
use Propulsion\Cache\SharedQueryCache;
use Propulsion\Cache\SharedQueryCacheConfig;
use Propulsion\Cache\TableVersionRegistry;
use Propulsion\Cache\TieredQueryCache;
use Propulsion\Connection\PropulsionPDO;

/**
 * Request-scoped state (Propulsion worker-safety rework, phase 4a -- see
 * PROPULSION_WORKER_REWORK.md / KNOWN_ISSUES.md "Phase 4").
 *
 * In a persistent-worker environment a single PHP process serves many requests
 * over the lifetime of `Propulsion`'s process-wide statics, so anything that must
 * not leak from one request into the next needs a home that gets reset at each
 * request boundary. That's this class. Contrast with {@see ServiceContainer},
 * which owns state that's fine (indeed, desirable) to keep shared across
 * requests within the same worker process.
 *
 * Phase 4a moved exactly two pieces of state here: `forceMasterConnection`
 * (previously a `Propulsion` static) and the reset-on-request-boundary wiring
 * itself (instance pool clearing + dangling-transaction rollback), using an
 * interim registry on `ServiceContainer` to clear each generated Peer's own
 * `private static $instances` array.
 *
 * Phase 4b finishes the job: the per-class `static $instances` array is gone
 * from generated Peer classes entirely. Every generated `getInstanceFromPool()`
 * / `addInstanceToPool()` / `removeInstanceFromPool()` / `clearInstancePool()`
 * / `getInstancePool()` method (see `generator/Lib/Builder/OM/PeerBuilder.php`)
 * is now a thin wrapper delegating to the pool-storage API on *this* class, so
 * pooled objects genuinely live on the current request's `Session` instead of
 * on process-global class statics -- swapping in a fresh `Session` (or calling
 * `reset()`) now actually relocates/clears that storage instead of just
 * remembering to call each class's own clear method.
 *
 * Storage shape: `array<class-string, array<string, object>>`, keyed first by
 * the generated Peer's FQCN (`self::class` from inside the generated class),
 * then by the same string instance-pool key the generated code has always
 * computed (a single PK's string value, or a `serialize()` of a composite PK
 * tuple -- see `PeerBuilder::getInstancePoolKeySnippet()`). This mirrors the
 * old per-class `static $instances` array exactly, just namespaced by class
 * and relocated onto `Session`.
 *
 * A later addition, the query result cache ({@see QueryResultCache}, `$queryCache`),
 * follows the same rule: it's request-scoped for the same reason `$instancePools`
 * is, and {@see reset()} clears it the same way.
 */
class Session
{
    /**
     * @var bool For replication, whether to always force the use of the master
     *           connection. Moved here (off `Propulsion`) in phase 4a: this is
     *           exactly the kind of state that must not bleed from one request
     *           to the next in a persistent worker.
     */
    private bool $forceMasterConnection = false;

    /**
     * Correlation id for whatever request this worker is currently serving --
     * request-scoped for the same reason `$forceMasterConnection` is: a
     * caller-supplied identifier (e.g. quiote's cassette recorder) set once
     * per request via {@see setCorrelationId()} and stamped onto every
     * {@see \Propulsion\Observability\QueryExecution} by
     * {@see \Propulsion\Observability\QueryObservers::start()}, so an
     * observer can tell which request's queries are which without needing
     * its own request-scoped storage.
     */
    private ?string $correlationId = null;

    /**
     * Generated-Peer instance pools, keyed by Peer FQCN then by instance-pool
     * key. Phase 4b: this replaces every generated Peer class's own private
     * `static $instances` array as the real storage backing
     * `addInstanceToPool()`/`getInstanceFromPool()`/etc.
     *
     * @var array<class-string, array<string, object>>
     */
    private array $instancePools = [];

    /**
     * How many scopes have suspended instance pooling and not yet resumed.
     *
     * Distinct from `Propulsion::$instancePoolingEnabled`, which is the
     * *explicit* application-level switch (`Propulsion::disableInstancePooling()`)
     * and stays process-scoped because it is deployment configuration -- a batch
     * worker that turns pooling off at boot means it for the whole process. This
     * counter is the other thing that switch was being used for: a transient,
     * nestable suspension for the duration of one streamed result set
     * ({@see \Propulsion\Collection\PropulsionOnDemandIterator}).
     *
     * It is a counter rather than a boolean, and it lives here rather than on
     * `Propulsion`, for two separate reasons:
     *
     *  - **A boolean cannot nest.** The iterator used to call
     *    `Propulsion::disableInstancePooling()` and restore based on its "did I
     *    change it" return value. With two on-demand iterations alive at once
     *    (nested `foreach` over two streamed queries -- legitimate, and the
     *    reason on-demand mode exists) the inner one saw "already disabled" and
     *    recorded that it must not restore, so when the *outer* one finished it
     *    re-enabled pooling while the inner was still streaming, and the inner
     *    silently began pooling every row it hydrated -- the exact unbounded
     *    growth on-demand mode exists to avoid.
     *  - **A leaked suspension must not outlive the request.** An iterator kept
     *    alive past the request boundary (by a reference cycle, or by an
     *    exception holding the collection) never resumed, so pooling stayed off
     *    for every subsequent request that worker process served, silently.
     *    Being here means {@see reset()} restores it.
     */
    private int $instancePoolingSuspendCount = 0;

    /**
     * Request-scoped cache of formatted query results (see
     * {@see QueryResultCache}). Same axis as `$instancePools`: cleared at
     * every request boundary by {@see reset()} so a cached row from one
     * worker request never leaks into the next.
     */
    private QueryResultCache $queryCache;


    /**
     * Coordinates the request-scoped cache above with the process-shared tier.
     * Built lazily rather than in the constructor so that every existing
     * `new Session()` -- in tests, and in worker hosts that construct one per
     * request -- keeps working untouched, and so that a session which never
     * runs a cached query never resolves a cache backend at all.
     */
    private ?TieredQueryCache $tieredCache = null;

    public function __construct()
    {
        $this->queryCache = new QueryResultCache();
    }

    /**
     * The current request's query result cache. Opted into per query via
     * {@see \Propulsion\Query\Criteria::setQueryCache()}.
     *
     * This is the L1 tier alone. Read paths should generally go through
     * {@see getQueryCache()}, which layers the shared tier on top.
     */
    public function getQueryResultCache(): QueryResultCache
    {
        return $this->queryCache;
    }

    /**
     * The tiered query cache: this request's {@see QueryResultCache} plus,
     * where configured, the process-shared tier.
     */
    public function getQueryCache(): TieredQueryCache
    {
        return $this->tieredCache ??= new TieredQueryCache($this->queryCache, $this->buildSharedCache());
    }

    /**
     * The shared tier, or null when none is configured -- in which case the
     * tiered cache behaves exactly as the request-scoped cache always has.
     *
     * The registry is built here, per session, so its per-request token memo
     * and its unpublished-transaction buffer die at the request boundary; the
     * PSR-16 pool underneath it is process-scoped and outlives them.
     */
    private function buildSharedCache(): ?SharedQueryCache
    {
        $config = Propulsion::getQueryCacheConfig();
        if (!$config->isActive()) {
            return null;
        }

        $pool = Propulsion::queryCachePool();
        if ($pool instanceof NullCache) {
            return null;
        }

        $sharedConfig = SharedQueryCacheConfig::fromQueryCacheConfig($config);

        return new SharedQueryCache($pool, $sharedConfig, new TableVersionRegistry($pool, $sharedConfig));
    }

    /**
     * The compiled-query (SQL-string) cache. Opted into per query via
     * {@see \Propulsion\Query\Criteria::setCompiledQueryCache()}.
     *
     * @deprecated This cache is no longer owned by the Session -- it is
     *             process-scoped now, so it survives {@see reset()} and is shared
     *             by every request the worker serves (see
     *             {@see CompiledQueryCache}'s docblock for why). This accessor is
     *             kept as a delegator so existing callers keep working; new code
     *             should ask {@see ServiceContainer::getCompiledQueryCache()},
     *             which is where it actually lives.
     */
    public function getCompiledQueryCache(): CompiledQueryCache
    {
        return Propulsion::getServiceContainer()->getCompiledQueryCache();
    }

    /**
     * Store an object in the named Peer class's instance pool under $key.
     * Called from generated `FooPeer::addInstanceToPool()`.
     *
     * @param class-string $peerClass
     */
    public function addPooledInstance(string $peerClass, string $key, object $instance): void
    {
        $this->instancePools[$peerClass][$key] = $instance;
    }

    /**
     * Retrieve a previously-pooled object, or null if nothing is pooled under
     * that key (or the class has no pool at all yet). Called from generated
     * `FooPeer::getInstanceFromPool()`.
     */
    public function getPooledInstance(string $peerClass, string $key): ?object
    {
        return $this->instancePools[$peerClass][$key] ?? null;
    }

    /**
     * Remove a single pooled object by key. Called from generated
     * `FooPeer::removeInstanceFromPool()`.
     */
    public function removePooledInstance(string $peerClass, string $key): void
    {
        unset($this->instancePools[$peerClass][$key]);
    }

    /**
     * @return array<string, object> Every currently-pooled instance for the
     *                                given Peer class, keyed by instance-pool
     *                                key. Called from generated
     *                                `FooPeer::getInstancePool()` (used by a
     *                                couple of behaviors -- e.g. `nested_set`
     *                                -- that need to iterate all currently
     *                                loaded instances of a table).
     */
    public function getPool(string $peerClass): array
    {
        return $this->instancePools[$peerClass] ?? [];
    }

    /**
     * Empty a single Peer class's instance pool. Called from generated
     * `FooPeer::clearInstancePool()`.
     */
    public function clearPool(string $peerClass): void
    {
        unset($this->instancePools[$peerClass]);
    }

    /**
     * Empty every Peer class's instance pool. This is what a fresh `Session`
     * starts with implicitly (an empty `$instancePools` array) and what
     * {@see reset()} calls at a request boundary -- it's also what
     * `ServiceContainer::clearInstancePools()` now delegates to directly,
     * since real pool storage lives here, not scattered across class statics.
     */
    public function clearAllPools(): void
    {
        $this->instancePools = [];
    }

    /**
     * Suspend instance pooling for the current scope. Every call must be paired
     * with a {@see resumeInstancePooling()} call -- suspensions nest, and pooling
     * only resumes once the outermost one has resumed.
     *
     * @see $instancePoolingSuspendCount for why this is a counter and why it
     *      lives on the request-scoped Session.
     */
    public function suspendInstancePooling(): void
    {
        $this->instancePoolingSuspendCount++;
    }

    /**
     * End one scope's suspension of instance pooling.
     *
     * Floors at zero rather than going negative: an unbalanced resume (a caller
     * that resumes twice, or resumes something it never suspended) would
     * otherwise leave the counter below zero and make the *next* legitimate
     * suspension a no-op, which is a far more confusing failure than simply
     * ignoring the extra resume.
     */
    public function resumeInstancePooling(): void
    {
        if ($this->instancePoolingSuspendCount > 0) {
            $this->instancePoolingSuspendCount--;
        }
    }

    /**
     * Whether any scope currently has instance pooling suspended.
     *
     * Consulted by {@see Propulsion::isInstancePoolingEnabled()}, which ANDs it
     * with the explicit application-level switch.
     */
    public function isInstancePoolingSuspended(): bool
    {
        return $this->instancePoolingSuspendCount > 0;
    }

    /**
     * For replication, set whether to always force the use of a master
     * connection.
     */
    public function setForceMasterConnection(bool $bit): void
    {
        $this->forceMasterConnection = $bit;
    }

    /**
     * For replication, whether to always force the use of a master connection.
     */
    public function getForceMasterConnection(): bool
    {
        return $this->forceMasterConnection;
    }

    /**
     * Set the correlation id for the request this worker is currently
     * serving, or null to clear it early.
     */
    public function setCorrelationId(?string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * The current request's correlation id, or null if none was set.
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Reset all request-scoped state carried by this Session. Intended to be
     * called at a request boundary in a persistent-worker environment (between
     * requests that reuse the same PHP process, and therefore the same
     * `ServiceContainer`-owned connections).
     *
     * Order matters:
     *
     *  1. Force-rollback any dangling open transaction on every connection
     *     `Propulsion` currently knows about. This is the same failure mode
     *     `PropulsionPDO::forceRollBack()` was wired up to fix at *test*-teardown
     *     boundaries in commit 6f6b08e ("Fix the real driver of the ~300-error
     *     cascade: unrolled-back transactions") -- an uncommitted transaction
     *     left open past its boundary poisons the connection for whatever reuses
     *     it next (on Postgres, every subsequent statement fails with "current
     *     transaction is aborted" until an explicit ROLLBACK). There it was a
     *     test reusing a process-wide connection from a previous test; here it's
     *     the next request in the same worker reusing a process-wide connection
     *     from a previous request -- same bug shape, different boundary.
     *  2. Clear every generated Peer class's instance pool (now real storage
     *     on this Session, per phase 4b -- see $instancePools above) --
     *     otherwise objects loaded while serving one request would stay
     *     resident (and be handed back out of the pool) to a later, unrelated
     *     request.
     *  3. Reset `forceMasterConnection` back to its default (false), so a
     *     request that opted into forcing master reads doesn't leak that choice
     *     onto the next request sharing this worker.
     *  4. Clear the query result cache ({@see QueryResultCache}) -- same
     *     reasoning as step 2: a result cached while serving one request must
     *     not be handed out to a later, unrelated request.
     *  5. Drop any outstanding instance-pooling suspension (see
     *     $instancePoolingSuspendCount) -- an on-demand iteration abandoned
     *     mid-stream would otherwise leave pooling suspended for every later
     *     request this worker process serves.
     *  6. Zero each open connection's debug counters (query count, last
     *     executed query), which are per-request figures sitting on a
     *     process-scoped object -- see resetConnectionDebugCounters().
     *  7. Clear the correlation id, so a request that set one does not leak
     *     it onto queries belonging to the next request sharing this worker.
     *
     * Two things are deliberately *not* reset here:
     *
     *  - `Propulsion::$instancePoolingEnabled`, the explicit
     *    `Propulsion::disableInstancePooling()` switch. That one reads as
     *    deployment configuration -- a batch worker that turns pooling off at boot
     *    means it for the life of the process, and re-enabling it at every request
     *    boundary would silently override that. Only the transient, scoped
     *    suspension above is request state.
     *  - The compiled-query cache ({@see CompiledQueryCache}). This step used to be
     *    here, on the same reasoning as step 4; it is gone because that cache holds
     *    SQL text derived purely from the datasource and the query shape, with no
     *    bound values and nothing request-identifying in it, and clearing it every
     *    request made a worker recompile the same SQL forever -- defeating the one
     *    deployment the cache exists for. It is process-scoped now, on
     *    {@see ServiceContainer}, and cleared by `Propulsion::setConfiguration()`
     *    instead (a new configuration can mean a new adapter, hence different SQL).
     */
    public function reset(): void
    {
        $this->rollBackDanglingTransactions();
        $this->clearAllPools();
        $this->forceMasterConnection = false;
        $this->instancePoolingSuspendCount = 0;
        $this->queryCache->clear();
        // Request-scoped cache bookkeeping only. This must never reach the
        // shared PSR-16 backend: clearing that here would flush every other
        // process's cache at the end of every request.
        $this->tieredCache?->reset();
        $this->resetConnectionDebugCounters();
        $this->correlationId = null;
    }

    /**
     * Force-rollback any connection Propulsion currently has open that's sitting in
     * an uncommitted transaction. Best-effort: forceRollBack() itself is a no-op
     * for a connection that isn't in a transaction.
     */
    private function rollBackDanglingTransactions(): void
    {
        foreach (Propulsion::getOpenConnections() as $con) {
            if ($con instanceof PropulsionPDO && $con->isInTransaction()) {
                $con->forceRollBack();
            }
        }
    }

    /**
     * Put every open connection's per-request debug bookkeeping back to zero.
     *
     * The connections themselves are process-scoped and deliberately survive the
     * boundary -- reusing them is the point of worker mode -- but the query count
     * and last-executed-query they carry are read as per-request figures, which is
     * what they were under PHP-FPM where the connection died with the request.
     */
    private function resetConnectionDebugCounters(): void
    {
        foreach (Propulsion::getOpenConnections() as $con) {
            if ($con instanceof PropulsionPDO) {
                $con->resetDebugCounters();
            }
        }
    }
}
