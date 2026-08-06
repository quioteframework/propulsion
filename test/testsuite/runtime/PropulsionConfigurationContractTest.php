<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\DatabaseMap;
use Propulsion\Propulsion;

/**
 * {@see Propulsion}'s configuration contract: what it accepts, what it
 * refuses, and what it says when an application is wired up wrongly.
 *
 * These are the errors a real deployment meets first -- a config path that
 * does not exist, a datasource nothing was registered under, a
 * `Propulsion::getConfiguration()` before `init()`. Each one's message is the
 * only thing the person debugging has to go on, so the messages are asserted
 * rather than just the exception type.
 *
 * Runs without a database: nothing here opens a connection, and the two
 * datasource-lookup failures happen before one would be attempted.
 */
class PropulsionConfigurationContractTest extends TestCase
{
    private ?PropulsionStateSnapshot $state = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Adapters as well as the configuration: setConfiguration() drops the
        // whole adapter map, and one registered with setDB() rather than
        // described in the configuration cannot be rebuilt. See
        // PropulsionStateSnapshot.
        $this->state = PropulsionStateSnapshot::capture();
    }

    protected function tearDown(): void
    {
        $this->state?->restore();
        parent::tearDown();
    }

    // ---- what setConfiguration() accepts ---------------------------------

    public function testAPropulsionConfigurationInstanceIsUsedAsIs()
    {
        $config = new PropulsionConfiguration(array('datasources' => array()));
        Propulsion::setConfiguration($config);

        $this->assertSame($config, Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT));
    }

    public function testAnArrayIsWrappedIntoAConfiguration()
    {
        Propulsion::setConfiguration(array('datasources' => array(), 'foo' => 'bar'));

        $config = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
        $this->assertInstanceOf(PropulsionConfiguration::class, $config);
        $this->assertSame('bar', $config->getParameter('foo'));
    }

    public function testALegacyPropelWrappedArrayIsUnwrapped()
    {
        // Propel 1's runtime-conf put everything under a top-level 'propel'
        // key. A migrated configuration is accepted as-is rather than
        // silently producing a config whose every key is one level too deep.
        Propulsion::setConfiguration(array('propel' => array('datasources' => array(), 'foo' => 'unwrapped')));

        $config = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
        $this->assertInstanceOf(PropulsionConfiguration::class, $config);
        $this->assertSame('unwrapped', $config->getParameter('foo'), 'the propel wrapper should have been stripped');
    }

    public function testANonArrayNonConfigurationIsRefused()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('must be an array or a PropulsionConfiguration instance');
        Propulsion::setConfiguration('not a configuration');
    }

    // ---- reading configuration -------------------------------------------

    public function testGetConfigurationArrayIsSafeBeforeAnythingIsConfigured()
    {
        // Unlike getConfiguration(), this one is documented as safe to call
        // early -- the query cache resolves its settings lazily and may well
        // ask in a process that never configured Propulsion at all.
        Propulsion::setConfiguration(array('datasources' => array(), 'cache' => array('query' => array('driver' => 'array'))));
        $this->assertSame(array('driver' => 'array'), Propulsion::getConfigurationArray()['cache']['query'] ?? null);
    }

    public function testConfigureRefusesAMissingFile()
    {
        $missing = sys_get_temp_dir() . '/propulsion-no-such-conf-' . uniqid() . '.php';

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unable to open configuration file');
        Propulsion::configure($missing);
    }

    // ---- datasource lookup ------------------------------------------------

    public function testAnUnknownDatasourceHasNoAdapter()
    {
        Propulsion::setConfiguration(array('datasources' => array()));

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unable to find adapter for datasource [no_such_datasource]');
        Propulsion::getDB('no_such_datasource');
    }

    public function testAnUnknownDatasourceHasNoConnectionInformation()
    {
        // Distinct from the adapter failure above: the adapter is registered,
        // but the configuration names no connection for it. Telling the two
        // apart is the difference between "you forgot to register the
        // adapter" and "your runtime-conf is missing a datasource".
        Propulsion::setConfiguration(array('datasources' => array()));
        Propulsion::setDB('adapter_but_no_connection', new \Propulsion\Adapter\DBSQLite());

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('No connection information in your runtime configuration file for datasource [adapter_but_no_connection]');
        Propulsion::getConnection('adapter_but_no_connection', Propulsion::CONNECTION_WRITE);
    }

    // ---- database maps ----------------------------------------------------

    public function testADatabaseMapCanBeRegisteredAndListed()
    {
        Propulsion::setConfiguration(array('datasources' => array()));

        $map = new DatabaseMap('contract_test_map');
        Propulsion::setDatabaseMap('contract_test_map', $map);

        $this->assertSame($map, Propulsion::getDatabaseMap('contract_test_map'));
        $this->assertContains('contract_test_map', Propulsion::getDatabaseMapNames());
    }

    public function testAnUnknownDatabaseMapIsCreatedOnDemand()
    {
        // Asking for a map that was never registered yields a fresh empty one
        // rather than failing: the generated TableMap classes register
        // themselves into it lazily, so it has to exist before they do.
        Propulsion::setConfiguration(array('datasources' => array()));

        $map = Propulsion::getDatabaseMap('never_registered_map');
        $this->assertInstanceOf(DatabaseMap::class, $map);
        $this->assertSame('never_registered_map', $map->getName());
        $this->assertSame($map, Propulsion::getDatabaseMap('never_registered_map'), 'and is kept, not rebuilt');
    }

    // ---- optional collaborators ------------------------------------------

    public function testLoggerIsOptionalAndReportsWhetherOneIsRegistered()
    {
        $previous = Propulsion::hasLogger() ? Propulsion::logger() : null;
        try {
            $logger = new class extends \Psr\Log\AbstractLogger {
                /** @var array<int, string> */
                public array $records = array();

                public function log($level, string|\Stringable $message, array $context = array()): void
                {
                    $this->records[] = (string) $message;
                }
            };
            Propulsion::setLogger($logger);

            $this->assertTrue(Propulsion::hasLogger());
            $this->assertSame($logger, Propulsion::logger());

            Propulsion::log('hello', \Psr\Log\LogLevel::INFO);
            $this->assertSame(array('hello'), $logger->records);
        } finally {
            if ($previous !== null) {
                Propulsion::setLogger($previous);
            }
        }
    }

    public function testEventDispatcherIsOptional()
    {
        // Both accessors exist so a caller can tell "none registered" from
        // "registered but did nothing" without triggering a dispatch.
        $this->assertSame(Propulsion::hasEventDispatcher(), Propulsion::eventDispatcher() !== null);
    }
}
