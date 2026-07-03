<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

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
 * This is phase 4a: additive scaffolding only. `Propel`'s existing process-global
 * statics (connection map, adapter map, database maps) are NOT being ripped out
 * or re-homed here yet -- that is phase 4b/4c, gated on the (separately in
 * progress) Phase 3 builder rename landing first. For now, ServiceContainer's
 * concrete job is the interim instance-pool registry described below; the
 * connection/adapter/table-map ownership described above is the target shape,
 * not what phase 4a actually moves.
 */
class ServiceContainer
{
    /**
     * Interim hack (phase 4a only, per the rework plan): a central registry of
     * generated Peer classnames, so that all of their static instance pools can be
     * cleared in one call. Each generated `FooPeer` class today has its own
     * private `public static $instances = array();` with no central registry at
     * all -- `FooPeer::clearInstancePool()` already exists per-class (see
     * generator/Lib/Builder/OM/*PeerBuilder.php's addClearInstancePool()), it just
     * has nothing that calls all of them together.
     *
     * This registry is explicitly interim: phase 4b reworks the (renamed, per
     * Phase 3) PeerBuilder template so pooling delegates to Session directly,
     * which removes the need for this class-name bookkeeping entirely. Don't
     * build more on top of this than that -- it is meant to be deleted.
     *
     * @var array<class-string, true>
     */
    private array $instancePoolClasses = [];

    /**
     * Register a generated Peer classname so {@see clearInstancePools()} will
     * clear its static instance pool. Safe to call more than once for the same
     * class.
     */
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
     * Clear every generated Peer class's static instance pool: both the ones
     * explicitly registered via {@see registerInstancePoolClass()}, and (since
     * nothing calls that today -- no generated code has been touched for this
     * phase) every Peer reachable via Propel's already-loaded DatabaseMaps, on a
     * best-effort basis. A table only shows up here once something has actually
     * looked up its TableMap/Peer at least once, which is fine for this interim
     * hack: an object class that was never touched has an empty pool anyway.
     */
    public function clearInstancePools(): void
    {
        $classes = $this->instancePoolClasses;

        foreach (Propel::getDatabaseMapNames() as $dbName) {
            $dbMap = Propel::getDatabaseMap($dbName);
            foreach ($dbMap->getTables() as $table) {
                try {
                    $peerClass = $table->getPeerClassname();
                } catch (\Throwable $e) {
                    // Not every TableMap necessarily resolves a PEER constant
                    // (e.g. it hasn't been fully initialized) -- skip rather than
                    // let one bad table map abort clearing the rest.
                    continue;
                }
                if ($peerClass) {
                    $classes[$peerClass] = true;
                }
            }
        }

        foreach (array_keys($classes) as $peerClass) {
            if (is_callable([$peerClass, 'clearInstancePool'])) {
                $peerClass::clearInstancePool();
            }
        }
    }
}
