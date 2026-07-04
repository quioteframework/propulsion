<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

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
     * Generated-Peer instance pools, keyed by Peer FQCN then by instance-pool
     * key. Phase 4b: this replaces every generated Peer class's own private
     * `static $instances` array as the real storage backing
     * `addInstanceToPool()`/`getInstanceFromPool()`/etc.
     *
     * @var array<class-string, array<string, object>>
     */
    private array $instancePools = [];

    /**
     * Store an object in the named Peer class's instance pool under $key.
     * Called from generated `FooPeer::addInstanceToPool()`.
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
     */
    public function reset(): void
    {
        $this->rollBackDanglingTransactions();
        $this->clearAllPools();
        $this->forceMasterConnection = false;
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
}
