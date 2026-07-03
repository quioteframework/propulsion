<?php

use PHPUnit\Framework\TestCase;

/**
 * This file is part of the Propel package.
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
 * per-instance isolation, and Propel's delegation to it). The transaction
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
        Propel::getSession()->setForceMasterConnection(false);
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
     * remaining process-global (Propel static) state -- that's the whole point
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
        Propel::setForceMasterConnection(true);

        $this->assertTrue(Propel::getSession()->getForceMasterConnection());
        $this->assertTrue(Propel::getForceMasterConnection());

        Propel::setForceMasterConnection(false);
        $this->assertFalse(Propel::getSession()->getForceMasterConnection());
        $this->assertFalse(Propel::getForceMasterConnection());
    }

    public function testPropelSetSessionSwapsTheActiveSession()
    {
        $original = Propel::getSession();
        $custom = new Session();
        $custom->setForceMasterConnection(true);

        try {
            Propel::setSession($custom);
            $this->assertSame($custom, Propel::getSession());
            $this->assertTrue(Propel::getForceMasterConnection());
        } finally {
            Propel::setSession($original);
        }

        $this->assertSame($original, Propel::getSession());
    }

    public function testGetServiceContainerReturnsSameInstanceAcrossCalls()
    {
        $this->assertSame(Propel::getServiceContainer(), Propel::getServiceContainer());
    }

    public function testGetSessionReturnsSameInstanceAcrossCalls()
    {
        $this->assertSame(Propel::getSession(), Propel::getSession());
    }
}
