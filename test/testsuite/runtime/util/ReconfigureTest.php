<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

/**
 * Applying a new configuration to a live process -- multi-tenant hosts
 * switching datasources, test harnesses swapping fixture databases.
 *
 * `initialize()` used to reset only the connection map, so the adapters and the
 * memoised default datasource name from the *previous* configuration survived:
 * the process kept talking to the new DSN through the old adapter, and
 * `getDefaultDB()` kept naming the old default.
 */
class ReconfigureTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousConfiguration = null;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            /** @var array<string, mixed> $existing */
            $existing = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_ARRAY);
            $this->previousConfiguration = $existing;
        } catch (PropulsionException) {
            $this->previousConfiguration = null;
        }
    }

    protected function tearDown(): void
    {
        // Propulsion's configuration is process-global and the rest of the
        // suite runs in this process, so put it back exactly as found.
        if ($this->previousConfiguration !== null) {
            Propulsion::setConfiguration($this->previousConfiguration);
            Propulsion::initialize();
        }
        parent::tearDown();
    }

    /**
     * @param array<string, array<string, mixed>> $datasources
     * @return array<string, mixed>
     */
    private function configuration(string $default, array $datasources): array
    {
        return ['datasources' => array_merge(['default' => $default], $datasources)];
    }

    public function testReinitialisingPicksUpTheNewDefaultDatasource(): void
    {
        Propulsion::setConfiguration($this->configuration('tenant_a', [
            'tenant_a' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();
        $this->assertSame('tenant_a', Propulsion::getDefaultDB());

        Propulsion::setConfiguration($this->configuration('tenant_b', [
            'tenant_b' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();

        // The memo used to survive, so this kept returning 'tenant_a' -- and
        // every getConnection()/getDatabaseMap() call defaulting to it went to
        // the wrong datasource.
        $this->assertSame('tenant_b', Propulsion::getDefaultDB());
    }

    public function testReinitialisingPicksUpTheNewAdapterForTheSameDatasourceName(): void
    {
        Propulsion::setConfiguration($this->configuration('shared', [
            'shared' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();
        $this->assertInstanceOf(DBSQLite::class, Propulsion::getDB('shared'));

        Propulsion::setConfiguration($this->configuration('shared', [
            'shared' => ['adapter' => 'mysql', 'connection' => ['dsn' => 'mysql:host=localhost']],
        ]));
        Propulsion::initialize();

        // Same datasource *name*, different adapter. The cached DBSQLite used
        // to survive, so the process kept quoting identifiers and building
        // LIMIT clauses in SQLite's dialect against a MySQL server.
        $this->assertInstanceOf(DBMySQL::class, Propulsion::getDB('shared'));
    }

    public function testSetConfigurationAloneAlsoDropsTheMemos(): void
    {
        // initialize() is the documented reconfiguration step, but the memos are
        // stale the moment the configuration they summarise is replaced, so a
        // caller that only calls setConfiguration() must not be handed the old
        // answers either.
        Propulsion::setConfiguration($this->configuration('first', [
            'first' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();
        $this->assertSame('first', Propulsion::getDefaultDB());

        Propulsion::setConfiguration($this->configuration('second', [
            'second' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));

        $this->assertSame('second', Propulsion::getDefaultDB());
    }

    public function testDatabaseMapsSurviveReinitialisation(): void
    {
        // Deliberately *not* reset, and not an oversight. A DatabaseMap
        // describes the schema, which belongs to the generated model classes,
        // not to the DSN they are reached through. Clearing it would also be
        // unrecoverable: each generated Peer registers its TableMap from a
        // statement at the bottom of its own class file, which runs once at
        // autoload time and is never re-run, and DatabaseMap::getTable() has
        // only a table name to work from -- so every getTableMap() call would
        // throw for the rest of the process.
        Propulsion::setConfiguration($this->configuration('mapped', [
            'mapped' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();

        $map = Propulsion::getDatabaseMap('mapped');
        $map->addTableFromMapClass(ReconfigureProbeTableMap::class);
        $this->assertTrue($map->hasTable('reconfigure_probe'));

        Propulsion::setConfiguration($this->configuration('mapped', [
            'mapped' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();

        $this->assertTrue(
            Propulsion::getDatabaseMap('mapped')->hasTable('reconfigure_probe'),
            'a reconfiguration must not orphan table maps that nothing will ever re-register'
        );
    }

    public function testAnExplicitlyRegisteredAdapterIsSupersededByANewConfiguration(): void
    {
        Propulsion::setConfiguration($this->configuration('explicit', [
            'explicit' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();

        Propulsion::setDB('explicit', new DBMySQL());
        $this->assertInstanceOf(DBMySQL::class, Propulsion::getDB('explicit'));

        Propulsion::setConfiguration($this->configuration('explicit', [
            'explicit' => ['adapter' => 'sqlite', 'connection' => ['dsn' => 'sqlite::memory:']],
        ]));
        Propulsion::initialize();

        // Documented consequence rather than a surprise: setDB() describes the
        // configuration the process was running under, and a new configuration
        // supersedes it. Register it again if it was meant to override the new
        // one too.
        $this->assertInstanceOf(DBSQLite::class, Propulsion::getDB('explicit'));
    }
}

/**
 * A minimal TableMap, so the map-survival test needs no generated fixture
 * classes and runs on the no-Docker tier.
 */
class ReconfigureProbeTableMap extends \Propulsion\Map\TableMap
{
    public function initialize(): void
    {
        $this->setName('reconfigure_probe');
        $this->setPhpName('ReconfigureProbe');
        $this->setClassname('ReconfigureProbe');
        $this->setUseIdGenerator(false);
    }
}
