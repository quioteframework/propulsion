<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Propulsion;

/**
 * An already-open connection must read Propulsion's *current* configuration,
 * not whichever instance happened to be current when it was built.
 *
 * This is a regression test for a bug that cost a suite-wide, order-dependent
 * failure. `PropulsionPDO::getConfiguration()` memoised the global
 * configuration on first access -- which is at construction, in practice.
 * `Propulsion::setConfiguration()` *replaces* that instance (it builds a fresh
 * `PropulsionConfiguration` from an array), so every connection already open
 * carried on reading the old one for the rest of the process: logging
 * settings, `debugpdo.logging.methods`, the prepared-statement cache size.
 * Nothing reported it; reconfiguration simply had no effect where it mattered.
 *
 * It surfaced as `PropulsionPDOTest::testDebugLog` failing in CI but not
 * locally: that test enables a log method by mutating the global
 * configuration, and only sees it take effect if no earlier test happened to
 * have replaced the instance in between. Whether one had depended on execution
 * order, which differs between a fresh checkout and a warm `.phpunit.cache`.
 */
class ConnectionConfigurationFollowsPropulsionTest extends TestCase
{
    private const DATASOURCE = 'connection_configuration_follows_test';

    /** @var array<string, mixed>|null */
    private ?array $previousConfiguration = null;

    private ?SqlitePropulsionPDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            /** @var array<string, mixed> $existing */
            $existing = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_ARRAY);
            $this->previousConfiguration = $existing;
        } catch (\Propulsion\Exception\PropulsionException) {
            $this->previousConfiguration = null;
        }

        Propulsion::setDB(self::DATASOURCE, new DBSQLite());
        $this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            Propulsion::discardConnection($this->pdo);
            $this->pdo = null;
        }
        if ($this->previousConfiguration !== null) {
            Propulsion::setConfiguration($this->previousConfiguration);
        }
        parent::tearDown();
    }

    public function testAConnectionSeesAConfigurationReplacedAfterItWasOpened()
    {
        $this->assertNotNull($this->pdo);

        // Touch the configuration first: this is what used to pin the instance.
        $this->pdo->getConfiguration();

        // A scalar leaf, not a list: PropulsionConfiguration::getParameter()
        // reads the *flattened* map, in which a list value explodes into
        // indexed keys rather than staying retrievable under its own key. That
        // quirk is why PropulsionPDOTest sets its method list with
        // $autoFlattenArrays = false; it is not what this test is about.
        Propulsion::setConfiguration(array(
            'datasources' => array(),
            'debugpdo' => array('logging' => array('onlyslow' => true)),
        ));

        $this->assertTrue(
            $this->pdo->getConfiguration()->getParameter('debugpdo.logging.onlyslow'),
            'the connection must read the replacement configuration, not the one it was built with'
        );
        $this->assertSame(
            Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT),
            $this->pdo->getConfiguration(),
            'and it must be the very same instance, so mutating the global one reaches the connection'
        );
    }

    public function testMutatingTheGlobalConfigurationReachesAnOpenConnection()
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->getConfiguration();

        // The shape PropulsionPDOTest::testDebugLog uses: reach for the global
        // configuration object and set a parameter on it in place.
        $config = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
        $this->assertInstanceOf(PropulsionConfiguration::class, $config);
        $config->setParameter('debugpdo.logging.methods', array('Mutated::method'), false);

        $this->assertSame(
            array('Mutated::method'),
            $this->pdo->getConfiguration()->getParameter('debugpdo.logging.methods')
        );
    }

    public function testAnInjectedConfigurationStillWins()
    {
        // The fallback is what follows Propulsion; a configuration handed to
        // this connection explicitly is its own and must not be overridden by a
        // later global reconfiguration.
        $this->assertNotNull($this->pdo);
        $own = new PropulsionConfiguration(array('debugpdo' => array('logging' => array('onlyslow' => false))));
        $this->pdo->setConfiguration($own);

        Propulsion::setConfiguration(array(
            'datasources' => array(),
            'debugpdo' => array('logging' => array('onlyslow' => true)),
        ));

        $this->assertSame($own, $this->pdo->getConfiguration());
        $this->assertFalse($this->pdo->getConfiguration()->getParameter('debugpdo.logging.onlyslow'));
    }
}
