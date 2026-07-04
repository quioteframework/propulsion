<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\ServiceContainer;

/**
 * Test for ServiceContainer, the process-scoped service registry introduced by
 * the worker-safety rework (phase 4a). See KNOWN_ISSUES.md, "Phase 4 --
 * Worker-safety rework (ServiceContainer/Session split)".
 *
 * @package    runtime
 */
class ServiceContainerTest extends BookstoreTestBase
{
    public function testGetRegisteredInstancePoolClassesStartsEmpty()
    {
        $sc = new ServiceContainer();
        $this->assertSame(array(), $sc->getRegisteredInstancePoolClasses());
    }

    public function testRegisterInstancePoolClassIsIdempotentPerClass()
    {
        $sc = new ServiceContainer();
        $sc->registerInstancePoolClass('SomePeerClass');
        $sc->registerInstancePoolClass('SomePeerClass');
        $sc->registerInstancePoolClass('AnotherPeerClass');

        $registered = $sc->getRegisteredInstancePoolClasses();
        sort($registered);
        $this->assertSame(array('AnotherPeerClass', 'SomePeerClass'), $registered);
    }

    /**
     * The interim pool-registry hack (see ServiceContainer::clearInstancePools())
     * should clear a generated Peer's static instance pool, whether or not it was
     * ever explicitly registered -- it also walks Propulsion's already-loaded
     * DatabaseMaps to find Peer classes on a best-effort basis.
     */
    public function testClearInstancePoolsClearsUnregisteredPeerViaDatabaseMap()
    {
        AuthorPeer::clearInstancePool();

        $author = new Author();
        $author->setFirstName('Pool');
        $author->setLastName('Test');
        $author->save($this->con);

        $this->assertGreaterThan(0, $this->countPooledAuthors(), 'sanity check: saving an object pools it');

        $sc = new ServiceContainer();
        $sc->clearInstancePools();

        $this->assertSame(0, $this->countPooledAuthors(), 'clearInstancePools() should have emptied AuthorPeer\'s instance pool');
    }

    /**
     * Explicit registration is also honored, independent of the DatabaseMap walk
     * -- this is what phase 4b's generated-code rework would eventually rely on
     * instead of the best-effort walk.
     */
    public function testClearInstancePoolsClearsExplicitlyRegisteredPeer()
    {
        AuthorPeer::clearInstancePool();

        $author = new Author();
        $author->setFirstName('Explicit');
        $author->setLastName('Registration');
        $author->save($this->con);

        $this->assertGreaterThan(0, $this->countPooledAuthors());

        $sc = new ServiceContainer();
        $sc->registerInstancePoolClass(AuthorPeer::class);
        $sc->clearInstancePools();

        $this->assertSame(0, $this->countPooledAuthors());
    }

    private function countPooledAuthors(): int
    {
        return count(AuthorPeer::getInstancePool());
    }
}
