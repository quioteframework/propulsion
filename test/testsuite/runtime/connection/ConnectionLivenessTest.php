<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Connection\ConnectionConfig;
use Propulsion\Propulsion;

/**
 * The pre-checkout liveness check: {@see \Propulsion\Connection\PropulsionPDOTrait::ping()}
 * and the {@see Propulsion::getConnection()} path that consults it.
 *
 * Real `sqlite::memory:` connections throughout. A connection that fails its
 * ping is modelled by a subclass whose query() throws the exception a real
 * dropped connection produces -- SQLite has no way to actually lose a server,
 * having no server -- but everything around it (the idle bookkeeping, the
 * eviction, the rebuild from datasource configuration) is the real code path.
 */
class ConnectionLivenessTest extends TestCase
{
    private const DATASOURCE = 'liveness_test';

    /** @var array<string, mixed>|null */
    private ?array $previousConfiguration = null;

    private ?PropulsionStateSnapshot $state = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Saved and restored rather than clobbered: Propulsion's configuration
        // is process-global and the rest of the suite runs in this process too.
        // The snapshot covers the adapter map as well, which setConfiguration()
        // also drops and which restoring the configuration alone does not bring
        // back -- see PropulsionStateSnapshot.
        $this->state = PropulsionStateSnapshot::capture();
        try {
            /** @var array<string, mixed> $existing */
            $existing = Propulsion::getConfiguration(\Propulsion\Config\PropulsionConfiguration::TYPE_ARRAY);
            $this->previousConfiguration = $existing;
        } catch (\Propulsion\Exception\PropulsionException) {
            $this->previousConfiguration = null;
        }

        $config = $this->previousConfiguration ?? array();
        $datasources = is_array($config['datasources'] ?? null) ? $config['datasources'] : array();
        $datasources[self::DATASOURCE] = array(
            'adapter' => 'sqlite',
            'connection' => array('dsn' => 'sqlite::memory:'),
        );
        $config['datasources'] = $datasources;
        Propulsion::setConfiguration($config);
    }

    protected function tearDown(): void
    {
        Propulsion::forceReconnect(self::DATASOURCE);
        $this->state?->restore();
        Propulsion::getServiceContainer()->clearConnectionConfig();
        parent::tearDown();
    }

    private function enableLiveness(float $idleThreshold): void
    {
        Propulsion::getServiceContainer()->setConnectionConfig(
            new ConnectionConfig(livenessEnabled: true, idleThreshold: $idleThreshold)
        );
    }

    public function testPingSucceedsOnAHealthyConnection(): void
    {
        $con = new SqlitePropulsionPDO('sqlite::memory:');
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));

        $this->assertTrue($con->ping());
    }

    public function testPingRefreshesTheIdleTimer(): void
    {
        $con = new SqlitePropulsionPDO('sqlite::memory:');
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        usleep(20000);
        $this->assertGreaterThan(0.01, $con->getIdleSeconds());

        $con->ping();

        $this->assertLessThan(0.01, $con->getIdleSeconds());
    }

    public function testPingIsSkippedInsideATransaction(): void
    {
        // A stray statement inside somebody's transaction is worse than a
        // stale connection -- and the pool never hands out a connection
        // mid-transaction anyway.
        $con = new class ('sqlite::memory:') extends SqlitePropulsionPDO {
            public int $queries = 0;

            public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
            {
                $this->queries++;

                return parent::query($query, $fetchMode, ...$args);
            }
        };
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $con->beginTransaction();

        $this->assertTrue($con->ping());
        $this->assertSame(0, $con->queries, 'no statement was issued');

        $con->rollBack();
    }

    public function testPingReportsADroppedConnectionRatherThanThrowing(): void
    {
        $con = $this->deadConnection();

        $this->assertFalse($con->ping());
    }

    public function testPingRethrowsAnythingThatIsNotADrop(): void
    {
        // A ping that fails because the server rejected `SELECT 1` is a
        // misconfiguration worth surfacing, not something to paper over by
        // rebuilding the connection in a loop.
        $con = new class ('sqlite::memory:') extends SqlitePropulsionPDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
            {
                $e = new PDOException('SQLSTATE[42601] syntax error');
                $e->errorInfo = array('42601', 1, 'syntax error');

                throw $e;
            }
        };
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/syntax error/');
        $con->ping();
    }

    public function testCheckoutIsUntouchedWhenLivenessIsOff(): void
    {
        Propulsion::getServiceContainer()->setConnectionConfig(ConnectionConfig::defaults());
        $con = $this->deadConnection();
        Propulsion::setConnection(self::DATASOURCE, $con, Propulsion::CONNECTION_WRITE);

        // No ping, so the dead connection is handed straight back -- the
        // pre-existing behaviour, and what an unconfigured deployment keeps.
        $this->assertSame($con, Propulsion::getWriteConnection(self::DATASOURCE));
    }

    public function testARecentlyUsedConnectionIsNotPinged(): void
    {
        $this->enableLiveness(60.0);
        $con = $this->deadConnection();
        Propulsion::setConnection(self::DATASOURCE, $con, Propulsion::CONNECTION_WRITE);
        $con->touchActivity();

        // Under sustained traffic this is the common case, and it must cost
        // nothing: a connection that ran a statement moments ago is evidence of
        // its own liveness.
        $this->assertSame($con, Propulsion::getWriteConnection(self::DATASOURCE));
    }

    public function testAnIdleDeadConnectionIsReplacedAtCheckout(): void
    {
        $this->enableLiveness(0.0);
        $dead = $this->deadConnection();
        Propulsion::setConnection(self::DATASOURCE, $dead, Propulsion::CONNECTION_WRITE);

        $fresh = Propulsion::getWriteConnection(self::DATASOURCE);

        $this->assertNotSame($dead, $fresh, 'the caller gets a working connection, not the corpse');
        $this->assertNotContains($dead, Propulsion::getOpenConnections());
        $this->assertSame(1, (int) $fresh->query('SELECT 1')->fetchColumn(), 'and it really works');
    }

    public function testTheReplacementIsNotItselfPinged(): void
    {
        // With a zero threshold every checkout is a candidate, so the rebuild
        // has to be exempt or it would ping (and potentially recurse) forever.
        $this->enableLiveness(0.0);
        $dead = $this->deadConnection();
        Propulsion::setConnection(self::DATASOURCE, $dead, Propulsion::CONNECTION_WRITE);

        $fresh = Propulsion::getWriteConnection(self::DATASOURCE);
        $this->assertNotSame($dead, $fresh);

        // And the replacement is now the pooled one, returned as-is next time.
        $this->assertSame($fresh, Propulsion::getWriteConnection(self::DATASOURCE));
    }

    public function testAHealthyIdleConnectionSurvivesItsCheck(): void
    {
        $this->enableLiveness(0.0);
        $con = new SqlitePropulsionPDO('sqlite::memory:');
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        Propulsion::setConnection(self::DATASOURCE, $con, Propulsion::CONNECTION_WRITE);

        $this->assertSame($con, Propulsion::getWriteConnection(self::DATASOURCE));
    }

    /**
     * A connection whose every statement fails the way a lost server does.
     */
    private function deadConnection(): SqlitePropulsionPDO
    {
        $con = new class ('sqlite::memory:') extends SqlitePropulsionPDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
            {
                $e = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
                $e->errorInfo = array('08006', 7, 'server closed the connection unexpectedly');

                throw $e;
            }
        };
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));

        return $con;
    }
}
