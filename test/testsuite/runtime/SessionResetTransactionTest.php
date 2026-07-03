<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the parts of Session::reset() that need a real database connection:
 * force-rolling-back a dangling open transaction, and clearing instance pools
 * through Propel's connection/database-map machinery. See KNOWN_ISSUES.md,
 * "Phase 4 -- Worker-safety rework (ServiceContainer/Session split)".
 *
 * @package    runtime
 */
class SessionResetTransactionTest extends BookstoreTestBase
{
    /**
     * BookstoreTestBase::setUp() already opens an outer transaction on
     * $this->con. Nest another one on top to simulate mid-request work, then
     * call Session::reset() to simulate a worker request boundary -- it must
     * force-rollback regardless of nesting depth, the same way
     * PropelPDO::forceRollBack() already does for test teardown (commit
     * 6f6b08e).
     */
    public function testResetForceRollsBackDanglingTransaction()
    {
        $this->con->beginTransaction();
        $this->assertTrue($this->con->isInTransaction());

        Propel::getSession()->reset();

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

        Propel::getSession()->reset();

        $this->assertFalse($this->con->isInTransaction());
    }

    /**
     * Session::reset() delegates instance-pool clearing to
     * ServiceContainer::clearInstancePools() -- verify the two are actually
     * wired together end to end (ServiceContainerTest covers
     * clearInstancePools() itself in isolation).
     */
    public function testResetClearsInstancePools()
    {
        AuthorPeer::clearInstancePool();

        $author = new Author();
        $author->setFirstName('Reset');
        $author->setLastName('Pools');
        $author->save($this->con);

        $ref = new ReflectionProperty(AuthorPeer::class, 'instances');
        $ref->setAccessible(true);
        $this->assertGreaterThan(0, count($ref->getValue()));

        Propel::getSession()->reset();

        $this->assertSame(0, count($ref->getValue()));
    }

    public function testResetClearsForceMasterConnectionEndToEnd()
    {
        Propel::setForceMasterConnection(true);

        Propel::getSession()->reset();

        $this->assertFalse(Propel::getForceMasterConnection());
    }
}
