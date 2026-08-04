<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the parts of Session::reset() that need a real database connection:
 * force-rolling-back a dangling open transaction, and clearing instance pools
 * through Propulsion's connection/database-map machinery. See KNOWN_ISSUES.md,
 * "Phase 4 -- Worker-safety rework (ServiceContainer/Session split)".
 *
 */
class SessionResetTransactionTest extends BookstoreTestBase
{
    /**
     * BookstoreTestBase::setUp() already opens an outer transaction on
     * $this->con. Nest another one on top to simulate mid-request work, then
     * call Session::reset() to simulate a worker request boundary -- it must
     * force-rollback regardless of nesting depth, the same way
     * PropulsionPDO::forceRollBack() already does for test teardown (commit
     * 6f6b08e).
     */
    public function testResetForceRollsBackDanglingTransaction()
    {
        $this->con->beginTransaction();
        $this->assertTrue($this->con->isInTransaction());

        Propulsion::getSession()->reset();

        $this->assertFalse(
            $this->con->isInTransaction(),
            'Session::reset() should have force-rolled-back the dangling transaction'
        );
    }

    /**
     * A connection with no open transaction at all must be left alone -- reset()
     * should be a safe no-op for it rather than erroring.
     */
    public function testResetIsANoOpWhenNoTransactionIsOpen()
    {
        // Bring $this->con back to a clean, non-transactional state first (undo
        // the transaction BookstoreTestBase::setUp() opened for us).
        $this->con->forceRollBack();
        $this->assertFalse($this->con->isInTransaction());

        Propulsion::getSession()->reset();

        $this->assertFalse($this->con->isInTransaction());
    }

    /**
     * Session::reset() clears every generated Peer's instance pool directly
     * (phase 4b: pool storage lives on Session itself now, see
     * Session::$instancePools) -- verify end to end (ServiceContainerTest
     * covers ServiceContainer::clearInstancePools()'s delegation to
     * Session::clearAllPools() in isolation).
     */
    public function testResetClearsInstancePools()
    {
        AuthorPeer::clearInstancePool();

        $author = new Author();
        $author->setFirstName('Reset');
        $author->setLastName('Pools');
        $author->save($this->con);

        $this->assertGreaterThan(0, count(AuthorPeer::getInstancePool()));

        Propulsion::getSession()->reset();

        $this->assertSame(0, count(AuthorPeer::getInstancePool()));
    }

    /**
     * The actual worker-safety property this phase exists to deliver: pools
     * are keyed off the *current* Session object, not a class-level static.
     * A fresh Session must start with empty pools even though a previous
     * Session (still holding a reference, as if a previous "request" had
     * used it) has populated ones -- proving pool storage really moved off
     * process-global class statics and onto Session instances.
     */
    public function testFreshSessionDoesNotSeePoolsFromAPreviousSession(): void
    {
        $original = Propulsion::getSession();

        AuthorPeer::clearInstancePool();
        $author = new Author();
        $author->setFirstName('Old');
        $author->setLastName('Session');
        $author->save($this->con);

        $this->assertGreaterThan(
            0,
            count(AuthorPeer::getInstancePool()),
            'sanity check: saving pooled something on the original session'
        );

        $fresh = new Propulsion\Session();
        Propulsion::setSession($fresh);

        try {
            $this->assertSame(
                0,
                count(AuthorPeer::getInstancePool()),
                'a fresh Session must not see instances pooled under a previous Session'
            );
        } finally {
            Propulsion::setSession($original);
        }

        // Swapping the original session back restores visibility of what it
        // had pooled -- confirming the pool genuinely lives on the Session
        // object itself, not anywhere process-global.
        $this->assertGreaterThan(0, count(AuthorPeer::getInstancePool()));
    }

    public function testResetClearsForceMasterConnectionEndToEnd()
    {
        Propulsion::setForceMasterConnection(true);

        Propulsion::getSession()->reset();

        $this->assertFalse(Propulsion::getForceMasterConnection());
    }

    /**
     * The connection itself is process-scoped and deliberately survives the
     * boundary, but the debug counters it carries are per-request figures --
     * that is what they meant under PHP-FPM, where the connection died with the
     * request. Without this, getQueryCount() reports a process total and
     * getLastExecutedQuery() can return a statement issued for someone else's
     * request.
     */
    public function testResetZeroesConnectionDebugCounters()
    {
        // Restore whatever debug mode this connection was already in, rather
        // than forcing it off: the connection is process-scoped and shared with
        // every later test, several of which assert on getQueryCount() and get
        // no counting at all if debug is left switched off behind them.
        $wasDebug = $this->con->useDebug;
        $this->con->useDebug(true);
        try {
            // Deliberately query() + consume + closeCursor() rather than
            // exec('SELECT 1'): exec() is for statements that return no result
            // set, and on MySQL the unconsumed rows it leaves behind make every
            // later query on this (process-scoped, shared) connection fail with
            // "Cannot execute queries while other unbuffered queries are active"
            // -- including the rollback in tearDown(), so the outer transaction
            // stays open and every subsequent test dies on beginTransaction().
            // A real fixture table rather than a bare "SELECT 1" keeps it valid
            // on Oracle, which has no FROM-less SELECT.
            $stmt = $this->con->query('SELECT COUNT(*) FROM ' . AuthorPeer::TABLE_NAME);
            $this->assertNotFalse($stmt);
            $stmt->fetchAll();
            $stmt->closeCursor();

            $this->assertGreaterThan(0, $this->con->getQueryCount());
            $this->assertNotSame('', $this->con->getLastExecutedQuery());

            Propulsion::getSession()->reset();

            $this->assertSame(0, $this->con->getQueryCount(), 'the query count is a per-request figure');
            $this->assertSame('', $this->con->getLastExecutedQuery());
        } finally {
            $this->con->useDebug($wasDebug);
        }
    }

    /**
     * Resetting the counters must not turn debugging off as a side effect --
     * useDebug(false) clears the same two fields, but as part of changing mode.
     */
    public function testResetLeavesDebugModeAlone()
    {
        $wasDebug = $this->con->useDebug;
        $this->con->useDebug(true);
        try {
            Propulsion::getSession()->reset();
            $this->assertTrue($this->con->useDebug);
        } finally {
            $this->con->useDebug($wasDebug);
        }
    }
}
