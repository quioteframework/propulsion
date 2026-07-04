<?php

use PHPUnit\Framework\TestCase;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Session;

/**
 * Test for Session, the request-scoped state container introduced by the
 * worker-safety rework (phase 4a). See KNOWN_ISSUES.md, "Phase 4 --
 * Worker-safety rework (ServiceContainer/Session split)".
 *
 * These are the DB-independent tests (forceMasterConnection get/set/reset,
 * per-instance isolation, and Propulsion's delegation to it). The transaction
 * rollback behavior of reset() needs a real connection and lives in
 * SessionResetTransactionTest instead.
 *
 * @package    runtime
 */
class SessionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Don't leak forceMasterConnection state into unrelated tests that run
        // later in the same process -- exactly the kind of cross-request bleed
        // this whole rework exists to prevent.
        Propulsion::getSession()->setForceMasterConnection(false);
        parent::tearDown();
    }

    public function testForceMasterConnectionDefaultsFalse()
    {
        $session = new Session();
        $this->assertFalse($session->getForceMasterConnection());
    }

    public function testSetAndGetForceMasterConnection()
    {
        $session = new Session();
        $session->setForceMasterConnection(true);
        $this->assertTrue($session->getForceMasterConnection());

        $session->setForceMasterConnection(false);
        $this->assertFalse($session->getForceMasterConnection());
    }

    public function testResetClearsForceMasterConnection()
    {
        $session = new Session();
        $session->setForceMasterConnection(true);

        $session->reset();

        $this->assertFalse($session->getForceMasterConnection());
    }

    /**
     * forceMasterConnection must live on the Session instance, not on any
     * remaining process-global (Propulsion static) state -- that's the whole point
     * of moving it in phase 4a.
     */
    public function testForceMasterConnectionIsPerSessionInstance()
    {
        $a = new Session();
        $a->setForceMasterConnection(true);

        $b = new Session();

        $this->assertTrue($a->getForceMasterConnection());
        $this->assertFalse($b->getForceMasterConnection());
    }

    public function testPropulsionDelegatesForceMasterConnectionToItsSession()
    {
        Propulsion::setForceMasterConnection(true);

        $this->assertTrue(Propulsion::getSession()->getForceMasterConnection());
        $this->assertTrue(Propulsion::getForceMasterConnection());

        Propulsion::setForceMasterConnection(false);
        $this->assertFalse(Propulsion::getSession()->getForceMasterConnection());
        $this->assertFalse(Propulsion::getForceMasterConnection());
    }

    public function testPropulsionSetSessionSwapsTheActiveSession()
    {
        $original = Propulsion::getSession();
        $custom = new Session();
        $custom->setForceMasterConnection(true);

        try {
            Propulsion::setSession($custom);
            $this->assertSame($custom, Propulsion::getSession());
            $this->assertTrue(Propulsion::getForceMasterConnection());
        } finally {
            Propulsion::setSession($original);
        }

        $this->assertSame($original, Propulsion::getSession());
    }

    public function testGetServiceContainerReturnsSameInstanceAcrossCalls()
    {
        $this->assertSame(Propulsion::getServiceContainer(), Propulsion::getServiceContainer());
    }

    public function testGetSessionReturnsSameInstanceAcrossCalls()
    {
        $this->assertSame(Propulsion::getSession(), Propulsion::getSession());
    }

    /**
     * Phase 4b: instance-pool storage lives directly on Session
     * (Session::$instancePools), replacing the per-generated-class
     * `static $instances` array. These are the DB-independent unit tests for
     * that storage API in isolation -- see SessionResetTransactionTest for
     * the end-to-end version exercised through real generated Peer classes.
     */
    public function testPoolStartsEmpty()
    {
        $session = new Session();
        $this->assertSame([], $session->getPool('SomePeerClass'));
        $this->assertNull($session->getPooledInstance('SomePeerClass', 'k'));
    }

    public function testAddAndGetPooledInstance()
    {
        $session = new Session();
        $obj = new \stdClass();

        $session->addPooledInstance('SomePeerClass', 'k1', $obj);

        $this->assertSame($obj, $session->getPooledInstance('SomePeerClass', 'k1'));
        $this->assertSame(['k1' => $obj], $session->getPool('SomePeerClass'));
    }

    public function testRemovePooledInstance()
    {
        $session = new Session();
        $obj = new \stdClass();
        $session->addPooledInstance('SomePeerClass', 'k1', $obj);

        $session->removePooledInstance('SomePeerClass', 'k1');

        $this->assertNull($session->getPooledInstance('SomePeerClass', 'k1'));
        $this->assertSame([], $session->getPool('SomePeerClass'));
    }

    public function testClearPoolOnlyClearsTheNamedClass()
    {
        $session = new Session();
        $session->addPooledInstance('PeerA', 'k1', new \stdClass());
        $session->addPooledInstance('PeerB', 'k1', new \stdClass());

        $session->clearPool('PeerA');

        $this->assertSame([], $session->getPool('PeerA'));
        $this->assertCount(1, $session->getPool('PeerB'));
    }

    public function testClearAllPoolsClearsEveryClass()
    {
        $session = new Session();
        $session->addPooledInstance('PeerA', 'k1', new \stdClass());
        $session->addPooledInstance('PeerB', 'k1', new \stdClass());

        $session->clearAllPools();

        $this->assertSame([], $session->getPool('PeerA'));
        $this->assertSame([], $session->getPool('PeerB'));
    }

    public function testResetClearsAllPools()
    {
        $session = new Session();
        $session->addPooledInstance('PeerA', 'k1', new \stdClass());

        $session->reset();

        $this->assertSame([], $session->getPool('PeerA'));
    }

    /**
     * The actual worker-safety property this whole phase exists to deliver:
     * pools are keyed off the *current* Session instance, not any
     * class-level static -- a fresh Session must never see instances pooled
     * by a different, still-populated Session.
     */
    public function testPoolsAreIsolatedPerSessionInstance()
    {
        $a = new Session();
        $a->addPooledInstance('SomePeerClass', 'k1', new \stdClass());

        $b = new Session();

        $this->assertCount(1, $a->getPool('SomePeerClass'));
        $this->assertSame([], $b->getPool('SomePeerClass'));
    }
}
