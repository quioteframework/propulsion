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
use Propulsion\Connection\DebugPDOStatement;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\PropulsionStatement;
use Propulsion\Propulsion;

/**
 * The dropped-connection detection gap on the ordinary
 * `PDOStatement::execute()` path -- the one essentially all ORM traffic takes,
 * and the one nothing wrapped until {@see PropulsionStatement}.
 *
 * These run against a real `sqlite::memory:` connection through the real
 * driver subclass, with a real prepared statement, and the drop is induced
 * rather than mocked: a SQLite trigger raising `RAISE(ABORT, 'server closed the
 * connection unexpectedly')` produces a genuine PDOException from inside
 * `execute()` whose message {@see Propulsion::isConnectionDropped()} classifies
 * as a drop. That exercises the whole path -- prepare, execute, catch,
 * classify, hand to the connection, evict from the pool -- rather than calling
 * the handler directly the way
 * {@see PropulsionPDODroppedConnectionTest} does for the pieces it covers.
 */
class PropulsionStatementTest extends TestCase
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
        Propulsion::discardConnection($this->pdo);
        parent::tearDown();
    }

    /**
     * Arm the table so the next INSERT fails with a message that classifies as
     * a dropped connection.
     */
    private function armDropOnInsert(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER widgets_drop BEFORE INSERT ON widgets BEGIN "
            . "SELECT RAISE(ABORT, 'server closed the connection unexpectedly'); END"
        );
    }

    public function testAPlainConnectionPreparesAPropulsionStatement(): void
    {
        // The regression this whole class exists for: with debugging off -- the
        // default, and what production runs -- prepare() used to hand back a
        // plain \PDOStatement, so nothing was watching execute() at all.
        $this->assertInstanceOf(PropulsionStatement::class, $this->pdo->prepare('SELECT 1'));
    }

    public function testADebugConnectionStillPreparesADebugStatement(): void
    {
        $this->pdo->useDebug(true);
        try {
            $stmt = $this->pdo->prepare('SELECT 1');
            $this->assertInstanceOf(DebugPDOStatement::class, $stmt);
            $this->assertInstanceOf(
                PropulsionStatement::class,
                $stmt,
                'and it inherits the detection rather than repeating it'
            );
        } finally {
            $this->pdo->useDebug(false);
        }
    }

    public function testTurningDebuggingOffLeavesDetectionOn(): void
    {
        $this->pdo->useDebug(true);
        $this->pdo->useDebug(false);

        $this->assertInstanceOf(
            PropulsionStatement::class,
            $this->pdo->prepare('SELECT 1'),
            'useDebug(false) used to reset the statement class to a plain PDOStatement, '
            . 'which would silently switch dropped-connection detection off with it'
        );
    }

    public function testExecuteEvictsTheConnectionOnADroppedConnection(): void
    {
        Propulsion::setConnection('statement_drop_test', $this->pdo, Propulsion::CONNECTION_WRITE);
        $this->armDropOnInsert();

        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');

        try {
            $stmt->execute(array('anything'));
            $this->fail('the statement was expected to fail');
        } catch (PDOException $e) {
            $this->assertTrue(Propulsion::isConnectionDropped($e), 'sanity: the induced failure classifies as a drop');
        }

        $this->assertNotContains(
            $this->pdo,
            Propulsion::getOpenConnections(),
            'a connection that died under a prepared statement must not stay in the pool for the next caller'
        );
    }

    public function testExecuteResetsTransactionBookkeepingOnADroppedConnection(): void
    {
        $this->armDropOnInsert();
        $this->pdo->beginTransaction();
        $this->assertSame(1, $this->pdo->getNestedTransactionCount());

        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
        try {
            $stmt->execute(array('anything'));
        } catch (PDOException) {
            // expected
        }

        // The server-side transaction died with the connection, so nothing may
        // later try to unwind a level that no longer exists.
        $this->assertSame(0, $this->pdo->getNestedTransactionCount());
        $this->assertFalse($this->pdo->isInTransaction());
    }

    public function testExecuteRethrowsTheOriginalExceptionUnchanged(): void
    {
        $this->armDropOnInsert();
        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');

        // A notification seam, not an error-handling one: callers already
        // catching PDOException must see exactly what they saw before.
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/server closed the connection unexpectedly/');
        $stmt->execute(array('anything'));
    }

    public function testAnOrdinarySqlErrorLeavesTheConnectionInThePool(): void
    {
        Propulsion::setConnection('statement_ordinary_test', $this->pdo, Propulsion::CONNECTION_WRITE);
        $this->pdo->exec('CREATE UNIQUE INDEX widgets_name ON widgets (name)');
        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
        $stmt->execute(array('duplicate'));

        try {
            $stmt->execute(array('duplicate'));
            $this->fail('the duplicate insert was expected to fail');
        } catch (PDOException) {
            // expected
        }

        $this->assertContains(
            $this->pdo,
            Propulsion::getOpenConnections(),
            'a constraint violation says nothing about the health of the connection'
        );

        Propulsion::discardConnection($this->pdo);
    }

    public function testExecuteRefreshesTheIdleTimer(): void
    {
        $stmt = $this->pdo->prepare('SELECT 1');
        usleep(20000);
        $before = $this->pdo->getIdleSeconds();
        $stmt->execute();

        $this->assertGreaterThan(0.01, $before);
        $this->assertLessThan($before, $this->pdo->getIdleSeconds());
    }
}
