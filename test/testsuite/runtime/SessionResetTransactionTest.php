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
}
