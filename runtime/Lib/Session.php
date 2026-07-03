<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

use Propulsion\Connection\PropelPDO;

/**
 * Request-scoped state (Propulsion worker-safety rework, phase 4a -- see
 * PROPULSION_WORKER_REWORK.md / KNOWN_ISSUES.md "Phase 4").
 *
 * In a persistent-worker environment a single PHP process serves many requests
 * over the lifetime of `Propel`'s process-wide statics, so anything that must
 * not leak from one request into the next needs a home that gets reset at each
 * request boundary. That's this class. Contrast with {@see ServiceContainer},
 * which owns state that's fine (indeed, desirable) to keep shared across
 * requests within the same worker process.
 *
 * Phase 4a moves exactly two pieces of state here: `forceMasterConnection`
 * (previously a `Propel` static) and the reset-on-request-boundary wiring
 * itself (instance pool clearing + dangling-transaction rollback). It does
 * NOT yet move connections/adapters/table maps anywhere, and does not yet
 * change any generated code -- see ServiceContainer's docblock and
 * KNOWN_ISSUES.md for what's deliberately deferred to 4b/4c.
 */
class Session
{
    /**
     * @var bool For replication, whether to always force the use of the master
     *           connection. Moved here (off `Propel`) in phase 4a: this is
     *           exactly the kind of state that must not bleed from one request
     *           to the next in a persistent worker.
     */
    private bool $forceMasterConnection = false;

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
     *     `Propel` currently knows about. This is the same failure mode
     *     `PropelPDO::forceRollBack()` was wired up to fix at *test*-teardown
     *     boundaries in commit 6f6b08e ("Fix the real driver of the ~300-error
     *     cascade: unrolled-back transactions") -- an uncommitted transaction
     *     left open past its boundary poisons the connection for whatever reuses
     *     it next (on Postgres, every subsequent statement fails with "current
     *     transaction is aborted" until an explicit ROLLBACK). There it was a
     *     test reusing a process-wide connection from a previous test; here it's
     *     the next request in the same worker reusing a process-wide connection
     *     from a previous request -- same bug shape, different boundary.
     *  2. Clear every generated Peer class's static instance pool, via
     *     `ServiceContainer`'s interim pool registry -- otherwise objects loaded
     *     while serving one request would stay resident (and be handed back out
     *     of the pool) to a later, unrelated request.
     *  3. Reset `forceMasterConnection` back to its default (false), so a
     *     request that opted into forcing master reads doesn't leak that choice
     *     onto the next request sharing this worker.
     */
    public function reset(): void
    {
        $this->rollBackDanglingTransactions();
        Propel::getServiceContainer()->clearInstancePools();
        $this->forceMasterConnection = false;
    }

    /**
     * Force-rollback any connection Propel currently has open that's sitting in
     * an uncommitted transaction. Best-effort: forceRollBack() itself is a no-op
     * for a connection that isn't in a transaction.
     */
    private function rollBackDanglingTransactions(): void
    {
        foreach (Propel::getOpenConnections() as $con) {
            if ($con instanceof PropelPDO && $con->isInTransaction()) {
                $con->forceRollBack();
            }
        }
    }
}
