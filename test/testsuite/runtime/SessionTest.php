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

    public function testPropelDelegatesForceMasterConnectionToItsSession()
    {
        Propulsion::setForceMasterConnection(true);

        $this->assertTrue(Propulsion::getSession()->getForceMasterConnection());
        $this->assertTrue(Propulsion::getForceMasterConnection());

        Propulsion::setForceMasterConnection(false);
        $this->assertFalse(Propulsion::getSession()->getForceMasterConnection());
        $this->assertFalse(Propulsion::getForceMasterConnection());
    }

    public function testPropelSetSessionSwapsTheActiveSession()
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
}
