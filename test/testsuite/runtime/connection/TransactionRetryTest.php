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
use Propulsion\Connection\ConnectionConfig;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\RetryPolicy;
use Propulsion\Propulsion;

/**
 * {@see Propulsion::transaction()}: what it commits, what it rolls back, and
 * above all what it will and will not re-run.
 *
 * The transaction machinery here is real -- a real `sqlite::memory:`
 * connection through the real driver subclass and the real adapter, with real
 * BEGIN/COMMIT/ROLLBACK and rows to check afterwards. Only the *failures* are
 * synthetic, thrown by the closure with the errorInfo triple a real driver
 * would carry, because a deadlock cannot be induced on demand in a
 * single-process SQLite test. That is the right seam: what is being tested is
 * the retry decision, and the retry decision reads exactly those fields.
 */
class TransactionRetryTest extends TestCase
{
    private const DATASOURCE = 'transaction_retry_test';

    private PropulsionPDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
        $this->pdo->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        Propulsion::setDB(self::DATASOURCE, new DBSQLite());
        Propulsion::setConnection(self::DATASOURCE, $this->pdo, Propulsion::CONNECTION_WRITE);
    }

    protected function tearDown(): void
    {
        Propulsion::discardConnection($this->pdo);
        Propulsion::getServiceContainer()->clearConnectionConfig();
        parent::tearDown();
    }

    private function deadlock(): PDOException
    {
        $e = new PDOException('deadlock detected');
        $e->errorInfo = array('40001', 1213, 'deadlock detected');

        return $e;
    }

    private function dropped(): PDOException
    {
        $e = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        $e->errorInfo = array('08006', 7, 'server closed the connection unexpectedly');

        return $e;
    }

    /** @return list<array{name: string}> */
    private function rows(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM widgets ORDER BY id');
        $this->assertNotFalse($stmt);
        /** @var list<array{name: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function immediateRetries(int $maxAttempts): RetryPolicy
    {
        // No backoff: the delay arithmetic has its own test, and sleeping here
        // would only make the suite slower.
        return new RetryPolicy(maxAttempts: $maxAttempts, baseDelayMs: 0, maxDelayMs: 0, jitter: 0.0);
    }

    public function testCommitsAndReturnsTheClosureResult(): void
    {
        $result = Propulsion::transaction(function (PropulsionPDO $con) {
            $con->exec("INSERT INTO widgets (name) VALUES ('committed')");

            return 'the return value';
        }, self::DATASOURCE);

        $this->assertSame('the return value', $result);
        $this->assertSame([['name' => 'committed']], $this->rows());
        $this->assertFalse($this->pdo->isInTransaction(), 'and it leaves no transaction open');
    }

    public function testRollsBackAndRethrowsWhenTheClosureThrows(): void
    {
        try {
            Propulsion::transaction(function (PropulsionPDO $con) {
                $con->exec("INSERT INTO widgets (name) VALUES ('doomed')");

                throw new RuntimeException('business rule violated');
            }, self::DATASOURCE);
            $this->fail('the exception was expected to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('business rule violated', $e->getMessage());
        }

        $this->assertSame([], $this->rows());
        $this->assertFalse($this->pdo->isInTransaction());
    }

    public function testDoesNotRetryAnApplicationException(): void
    {
        $attempts = 0;

        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('business rule violated');
            }, self::DATASOURCE, $this->immediateRetries(5));
        } catch (RuntimeException) {
            // expected
        }

        // A business failure is deterministic. Re-running it would burn four
        // more transactions to arrive at the same answer.
        $this->assertSame(1, $attempts);
    }

    public function testRetriesADeadlockAndCommitsTheSuccessfulAttempt(): void
    {
        $attempts = 0;

        $result = Propulsion::transaction(function (PropulsionPDO $con) use (&$attempts) {
            $attempts++;
            $con->exec("INSERT INTO widgets (name) VALUES ('attempt " . $attempts . "')");
            if ($attempts < 3) {
                throw $this->deadlock();
            }

            return $attempts;
        }, self::DATASOURCE, $this->immediateRetries(5));

        $this->assertSame(3, $result);
        $this->assertSame(3, $attempts);
        // The two failed attempts were rolled back, so only the third one's row
        // survives -- the property that makes retrying safe in the first place.
        $this->assertSame([['name' => 'attempt 3']], $this->rows());
    }

    public function testGivesUpAfterTheAttemptLimitAndRethrowsTheLastFailure(): void
    {
        $attempts = 0;

        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                throw $this->deadlock();
            }, self::DATASOURCE, $this->immediateRetries(3));
            $this->fail('the deadlock was expected to propagate once attempts ran out');
        } catch (PDOException $e) {
            $this->assertSame('deadlock detected', $e->getMessage());
        }

        $this->assertSame(3, $attempts, 'three attempts, not three retries');
        $this->assertSame([], $this->rows());
    }

    public function testDoesNotRetryANonRetryableDatabaseError(): void
    {
        $attempts = 0;
        $e = new PDOException('duplicate key value');
        $e->errorInfo = array('23505', 7, 'duplicate key value');

        try {
            Propulsion::transaction(function () use (&$attempts, $e) {
                $attempts++;

                throw $e;
            }, self::DATASOURCE, $this->immediateRetries(5));
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }

    public function testRetriesAConnectionDroppedBeforeTheCommit(): void
    {
        $attempts = 0;

        $result = Propulsion::transaction(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                // Nothing was committed, because the commit was never issued --
                // so re-running the work cannot double-apply it.
                throw $this->dropped();
            }

            return 'recovered';
        }, self::DATASOURCE, $this->immediateRetries(3));

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $attempts);
    }

    public function testDoesNotRetryAConnectionDroppedDuringTheCommit(): void
    {
        // The one case that must not be retried however transient it looks: the
        // server may have committed and died before saying so, in which case
        // re-running the closure applies the work twice. Simulated by a
        // connection whose commit() itself throws.
        $con = new class ('sqlite::memory:') extends SqlitePropulsionPDO {
            public int $commitAttempts = 0;

            public function commit(): bool
            {
                $this->commitAttempts++;
                $e = new PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
                $e->errorInfo = array('08006', 7, 'server closed the connection unexpectedly');

                throw $e;
            }
        };
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        Propulsion::setConnection(self::DATASOURCE, $con, Propulsion::CONNECTION_WRITE);

        $attempts = 0;
        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                return 'done';
            }, self::DATASOURCE, $this->immediateRetries(5));
            $this->fail('a drop during commit must not be swallowed');
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(1, $attempts, 'the closure must not run a second time');
        $this->assertSame(1, $con->commitAttempts);

        Propulsion::discardConnection($con);
    }

    public function testRetryPolicyNoneOptsOneCallOut(): void
    {
        $attempts = 0;

        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                throw $this->deadlock();
            }, self::DATASOURCE, RetryPolicy::none());
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }

    public function testRetryingIsOffUnlessConfigured(): void
    {
        Propulsion::getServiceContainer()->setConnectionConfig(ConnectionConfig::defaults());
        $attempts = 0;

        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                throw $this->deadlock();
            }, self::DATASOURCE);
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(1, $attempts, 'retrying re-runs application code, so it has to be asked for');
    }

    public function testUsesTheConfiguredPolicyWhenNoneIsPassed(): void
    {
        Propulsion::getServiceContainer()->setConnectionConfig(new ConnectionConfig(
            retryEnabled: true,
            maxAttempts: 4,
            baseDelayMs: 0,
            maxDelayMs: 0,
            jitter: 0.0,
        ));
        $attempts = 0;

        try {
            Propulsion::transaction(function () use (&$attempts) {
                $attempts++;

                throw $this->deadlock();
            }, self::DATASOURCE);
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(4, $attempts);
    }

    public function testANestedCallJoinsTheOuterTransactionAndNeverRetries(): void
    {
        $inner = 0;

        $this->pdo->beginTransaction();
        try {
            Propulsion::transaction(function () use (&$inner) {
                $inner++;

                throw $this->deadlock();
            }, self::DATASOURCE, $this->immediateRetries(5));
            $this->fail('the deadlock was expected to propagate');
        } catch (PDOException) {
            // expected
        } finally {
            if ($this->pdo->isInTransaction()) {
                $this->pdo->rollBack();
            }
        }

        // Retrying an inner scope cannot work: the failures this retries abort
        // the whole transaction, and the outer one is already dead.
        $this->assertSame(1, $inner);
    }

    public function testANestedCallCommitsIntoTheOuterTransaction(): void
    {
        $this->pdo->beginTransaction();

        $result = Propulsion::transaction(function (PropulsionPDO $con) {
            $con->exec("INSERT INTO widgets (name) VALUES ('nested')");

            return 'inner';
        }, self::DATASOURCE);

        $this->assertSame('inner', $result);
        $this->assertTrue($this->pdo->isInTransaction(), 'the outer transaction is still the caller\'s to finish');
        $this->pdo->commit();

        $this->assertSame([['name' => 'nested']], $this->rows());
    }
}
