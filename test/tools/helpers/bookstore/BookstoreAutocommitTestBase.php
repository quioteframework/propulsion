<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Propulsion\ServiceContainer;
use Propulsion\Session;

/**
 * A Bookstore test base that does **not** wrap each test in a transaction.
 *
 * {@see BookstoreTestBase} opens a transaction in setUp() and commits it in
 * tearDown(), which is the right default for most tests but makes the global
 * query cache untestable: the shared tier is deliberately inert inside a
 * transaction, because a SELECT there can see uncommitted rows and publishing
 * those to a cache other processes read would leak them. Tests that need to
 * exercise that tier therefore have to run in autocommit and clean up after
 * themselves, which is what this class provides.
 *
 * It exists as a shared base rather than as copy-pasted setUp()/tearDown()
 * bodies because both of the details below were originally duplicated, and one
 * of them was duplicated *wrongly*.
 */
abstract class BookstoreAutocommitTestBase extends TestCase
{
    /** @var mixed the Bookstore connection, or null when setUp() skipped */
    protected $con;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            IntegrationDatabase::ensureReady();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        if (!Propulsion::isInit()) {
            set_include_path(get_include_path() . PATH_SEPARATOR . realpath(IntegrationDatabase::classesDir()));
        }

        // Checking isInit() alone is not enough, and this is the subtle part.
        // isInit() is a one-way latch set by Propulsion::initialize(), while
        // setConfiguration() can be called independently -- so another test
        // that swapped in its own configuration (a generator test building a
        // throwaway schema, say) leaves isInit() true while the bookstore
        // datasource is simply absent, and the next getConnection() fails with
        // "No connection information in your runtime configuration file for
        // datasource [bookstore]". Verifying the datasource is actually present
        // makes this robust against test ordering, which varies between suites
        // (phpunit.xml uses executionOrder="depends,defects").
        if (!Propulsion::isInit() || !self::bookstoreDatasourceConfigured()) {
            Propulsion::init(IntegrationDatabase::confFile());
        }

        $this->con = Propulsion::getConnection(BookPeer::DATABASE_NAME);

        BookstoreDataPopulator::depopulate();
        BookstoreDataPopulator::populate();

        // A fresh Session, not merely a reset one: the tiered cache memoises
        // the shared tier it was built with, and an earlier test in this
        // process will have built one with no shared tier at all.
        Propulsion::setSession(new Session());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even for a test that setUp() skipped, and a
        // skip happens precisely when there is no usable database -- so
        // everything needing a connection sits behind this guard, exactly as
        // BookstoreTestBase::tearDown() does it. Without the guard every
        // skipped test in these classes *errors* instead of skipping, which is
        // how the no-Docker, MSSQL and Oracle CI tiers all went red.
        if ($this->con !== null) {
            if ($this->con->isInTransaction()) {
                $this->con->forceRollBack();
            }
            BookstoreDataPopulator::depopulate();
        }

        // Safe with or without a database, and needed for isolation either
        // way: this is what stops a cache pool configured by a subclass from
        // leaking into unrelated tests.
        Propulsion::setServiceContainer(new ServiceContainer());
        Propulsion::setSession(new Session());

        parent::tearDown();
    }

    /**
     * Simulates a worker request boundary: exactly what a FrankenPHP/RoadRunner
     * host calls between requests.
     */
    protected function newRequest(): void
    {
        Propulsion::getSession()->reset();
    }

    private static function bookstoreDatasourceConfigured(): bool
    {
        $config = Propulsion::getConfigurationArray();
        $datasources = $config['datasources'] ?? null;

        return is_array($datasources) && isset($datasources[BookPeer::DATABASE_NAME]);
    }
}
