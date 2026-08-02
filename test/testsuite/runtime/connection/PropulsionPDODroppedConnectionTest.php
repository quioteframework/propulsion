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
 * Covers what happens when the server-side session behind a connection goes
 * away: {@see Propulsion::isConnectionDropped()} classification,
 * {@see Propulsion::discardConnection()} pool eviction, and
 * {@see \Propulsion\Connection\PropulsionPDOTrait::handleDroppedConnection()}'s
 * state reset.
 *
 * Uses a real `sqlite::memory:` connection through the real driver subclass --
 * no mocking of PDO -- but drives the handler directly rather than trying to
 * make SQLite produce a dropped-connection error, which it has no way to do.
 * exec()/query() reach the handler through a one-line delegation in their
 * catch blocks; what those blocks must *not* do (swallow an unrelated
 * PDOException, or retry a dropped one on the dead handle) is asserted below.
 */
class PropulsionPDODroppedConnectionTest extends TestCase
{
    private PropulsionPDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
        $this->pdo->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
    }

    protected function tearDown(): void
    {
        // Evict precisely this test's connection rather than Propulsion::close(),
        // which would clear the whole process-wide map and orphan any
        // transaction another test in the same run is holding.
        Propulsion::discardConnection($this->pdo);
        parent::tearDown();
    }

    private function droppedException(): PDOException
    {
        $e = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        $e->errorInfo = array('08006', 7, 'server closed the connection unexpectedly');

        return $e;
    }

    public function testIsConnectionDroppedRecognisesConnectionClassSqlStates(): void
    {
        $this->assertTrue(Propulsion::isConnectionDropped($this->droppedException()));
    }

    public function testIsConnectionDroppedIgnoresOrdinarySqlErrors(): void
    {
        $e = new PDOException('SQLSTATE[23505] duplicate key value violates unique constraint');
        $e->errorInfo = array('23505', 7, 'duplicate key value violates unique constraint');

        $this->assertFalse(Propulsion::isConnectionDropped($e));
    }

    public function testDiscardConnectionEvictsOnlyTheGivenConnection(): void
    {
        $other = new SqlitePropulsionPDO('sqlite::memory:');
        Propulsion::setConnection('dropped_test_a', $this->pdo, Propulsion::CONNECTION_WRITE);
        Propulsion::setConnection('dropped_test_b', $other, Propulsion::CONNECTION_WRITE);

        $this->assertTrue(Propulsion::discardConnection($this->pdo));

        $open = Propulsion::getOpenConnections();
        $this->assertNotContains($this->pdo, $open, 'the dead connection is gone from the pool');
        $this->assertContains($other, $open, 'an unrelated datasource must not be taken down with it');

        Propulsion::discardConnection($other);
    }

    public function testDiscardConnectionEvictsEveryModeItIsRegisteredUnder(): void
    {
        // getSlaveConnection() stores the master object under 'slave' too when
        // a datasource has no slaves configured, so one object can occupy two
        // slots and both have to go.
        Propulsion::setConnection('dropped_test_modes', $this->pdo, Propulsion::CONNECTION_WRITE);
        Propulsion::setConnection('dropped_test_modes', $this->pdo, Propulsion::CONNECTION_READ);

        $this->assertTrue(Propulsion::discardConnection($this->pdo));
        $this->assertNotContains($this->pdo, Propulsion::getOpenConnections());
    }

    public function testDiscardConnectionReportsAConnectionItNeverHeld(): void
    {
        $this->assertFalse(Propulsion::discardConnection($this->pdo));
    }

    public function testHandleDroppedConnectionResetsTransactionStateAndEvictsFromThePool(): void
    {
        Propulsion::setConnection('dropped_test_state', $this->pdo, Propulsion::CONNECTION_WRITE);

        $this->pdo->beginTransaction();
        $this->pdo->beginTransaction();
        $this->assertSame(2, $this->pdo->getNestedTransactionCount());

        $this->pdo->handleDroppedConnection($this->droppedException(), __METHOD__);

        // Whatever the server had open died with the connection, so the depth
        // counter must not keep claiming levels that no longer exist -- a later
        // commit()/rollBack(), or Session::reset()'s dangling-transaction
        // sweep, would otherwise try to unwind them on a dead handle.
        $this->assertSame(0, $this->pdo->getNestedTransactionCount());
        $this->assertFalse($this->pdo->isInTransaction());
        $this->assertNotContains($this->pdo, Propulsion::getOpenConnections());
    }

    public function testHandleDroppedConnectionIsSafeWithNoTransactionAndNoPoolEntry(): void
    {
        // Best-effort cleanup: it must never itself throw, since it runs on the
        // way out of an already-failing statement.
        $this->pdo->handleDroppedConnection($this->droppedException());

        $this->assertSame(0, $this->pdo->getNestedTransactionCount());
    }

    public function testExecRethrowsAnOrdinarySqlErrorUntouched(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec('THIS IS NOT SQL');
    }

    public function testQueryRethrowsAnOrdinarySqlErrorUntouched(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->query('SELECT * FROM no_such_table');
    }

    public function testGetLastExecutedQueryIsAnEmptyStringBeforeAnyDebugQuery(): void
    {
        // Declared `: string` but only ever assigned inside `if ($this->useDebug)`
        // branches, so on a connection that never ran with debugging on this
        // used to be a TypeError rather than "nothing recorded yet".
        $this->assertSame('', $this->pdo->getLastExecutedQuery());
    }
}
