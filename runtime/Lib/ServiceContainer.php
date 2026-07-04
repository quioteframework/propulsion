<?php

/**
 * This file is part of the Propulsion package.
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
}
