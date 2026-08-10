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
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;

/**
 * What {@see Propulsion::close()} and {@see Propulsion::initialize()} do to the
 * connections they drop.
 *
 * Neither can close anything: PHP ends a PDO connection when its *last*
 * reference goes, and a caller that cached the handle getConnection() gave it
 * still holds one. Clearing the map therefore leaves that connection open, and
 * an open transaction on it keeps its locks until the holder is collected --
 * which under a worker may be never.
 *
 * So what is asserted here is the part that is achievable: whatever the pooled
 * connections had open is rolled back before the references go. A real
 * `sqlite::memory:` connection through the real driver subclass, no mocking.
 */
class PropulsionConnectionReleaseTest extends TestCase
{
    private const DATASOURCE = 'release-test';

    private ?PropulsionStateSnapshot $state = null;

    protected function setUp(): void
    {
        // One test here reconfigures on purpose, and setConfiguration() drops the adapter map
        // with it -- adapters registered by QuickBuilder cannot be rebuilt from anything, so
        // the rest of the suite would die naming datasources it never touched.
        $this->state = PropulsionStateSnapshot::capture();
        Propulsion::close();
    }

    protected function tearDown(): void
    {
        Propulsion::close();
        $this->state?->restore();
        $this->state = null;
    }

    private function pooledConnection(): PropulsionPDO
    {
        $connection = new SqlitePropulsionPDO('sqlite::memory:');
        $connection->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        Propulsion::setConnection(self::DATASOURCE, $connection);

        return $connection;
    }

    public function testClosingRollsBackATransactionThePooledConnectionLeftOpen(): void
    {
        $connection = $this->pooledConnection();
        $connection->beginTransaction();
        $connection->exec("INSERT INTO t (name) VALUES ('uncommitted')");
        $this->assertTrue($connection->isInTransaction());

        Propulsion::close();

        $this->assertFalse($connection->isInTransaction(), 'the transaction outlived close()');

        $count = $connection->query('SELECT COUNT(*) FROM t');
        $this->assertNotFalse($count);
        $this->assertSame('0', (string) $count->fetchColumn(), 'the uncommitted row was not rolled back');
    }

    /**
     * A nested transaction needs one rollBack() per level, since each call
     * unwinds one and only the outermost issues a real ROLLBACK.
     */
    public function testClosingUnwindsEveryNestingLevel(): void
    {
        $connection = $this->pooledConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->exec("INSERT INTO t (name) VALUES ('nested')");
        $this->assertSame(2, $connection->getNestedTransactionCount());

        Propulsion::close();

        $this->assertSame(0, $connection->getNestedTransactionCount());
        $this->assertFalse($connection->isInTransaction());
    }

    /** Re-initializing drops the pool too, so it owes the same rollback. */
    public function testReInitializingRollsBackAnOpenTransaction(): void
    {
        Propulsion::setConfiguration(['datasources' => ['default' => self::DATASOURCE]]);
        Propulsion::initialize();

        $connection = $this->pooledConnection();
        $connection->beginTransaction();
        $connection->exec("INSERT INTO t (name) VALUES ('uncommitted')");

        Propulsion::initialize();

        $this->assertFalse($connection->isInTransaction());
    }

    /** A connection with nothing open is simply released. */
    public function testAnIdleConnectionIsReleasedWithoutFuss(): void
    {
        $connection = $this->pooledConnection();
        $this->assertFalse($connection->isInTransaction());

        Propulsion::close();

        $this->assertFalse($connection->isInTransaction());
    }

    /**
     * Releasing must not depend on the connection still working: a handle whose
     * server went away has already had its locks reclaimed, and it must not
     * stop the rest of the pool being released.
     */
    public function testAnUnusableConnectionDoesNotStopTheRelease(): void
    {
        $broken = new SqlitePropulsionPDO('sqlite::memory:');
        $broken->beginTransaction();
        Propulsion::setConnection('broken', $broken);

        $healthy = $this->pooledConnection();
        $healthy->beginTransaction();

        // Nothing here may throw, and the healthy connection is still unwound.
        Propulsion::close();

        $this->assertFalse($healthy->isInTransaction());
    }
}
