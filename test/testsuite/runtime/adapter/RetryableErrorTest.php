<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBOracle;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLSRV;
use Propulsion\Adapter\DBSQLite;

/**
 * {@see DBAdapter::isRetryableError()} across every adapter -- the
 * classification {@see \Propulsion\Propulsion::transaction()} retries on.
 *
 * Pure classification, so no live connection is needed: the exceptions are
 * built with the errorInfo triples the real drivers populate. The codes
 * themselves are the load-bearing part, and they are the thing most easily got
 * wrong, so each one is asserted individually rather than as a set.
 */
class RetryableErrorTest extends TestCase
{
    /**
     * @param  array{0: string, 1?: int, 2?: string} $errorInfo
     */
    private function exception(array $errorInfo, string $message = 'failure'): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /**
     * @return array<string, array{0: DBAdapter}>
     */
    public static function adapters(): array
    {
        return [
            'postgres' => [new DBPostgres()],
            'mysql' => [new DBMySQL()],
            'sqlite' => [new DBSQLite()],
            'mssql' => [new DBMSSQL()],
            'sqlsrv' => [new DBSQLSRV()],
            'oracle' => [new DBOracle()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function testSerializationFailureIsRetryableEverywhere(DBAdapter $adapter): void
    {
        $this->assertTrue($adapter->isRetryableError(
            $this->exception(['40001', 1, 'could not serialize access due to concurrent update'])
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function testOrdinaryErrorsAreNotRetryable(DBAdapter $adapter): void
    {
        $this->assertFalse(
            $adapter->isRetryableError($this->exception(['23505', 7, 'duplicate key value'])),
            'a unique-constraint violation is deterministic -- retrying it just fails again'
        );
        $this->assertFalse(
            $adapter->isRetryableError($this->exception(['42601', 7, 'syntax error'])),
            'a syntax error is not going to fix itself either'
        );
        $this->assertFalse(
            $adapter->isRetryableError($this->exception(['08006', 7, 'server closed the connection unexpectedly'])),
            'a dropped connection is transient but is classified at the call site, not here, '
            . 'because whether it is safe to retry depends on when it happened'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adapters')]
    public function testAnExceptionCarryingNoErrorInfoIsNotRetryable(DBAdapter $adapter): void
    {
        $this->assertFalse($adapter->isRetryableError(new PDOException('no errorInfo at all')));
    }

    public function testPostgresDeadlockSqlState(): void
    {
        $this->assertTrue((new DBPostgres())->isRetryableError(
            $this->exception(['40P01', 7, 'deadlock detected'])
        ));
    }

    public function testMysqlDeadlockAndLockWaitTimeout(): void
    {
        $adapter = new DBMySQL();

        // 1213 ER_LOCK_DEADLOCK arrives under SQLSTATE 40001 ...
        $this->assertTrue($adapter->isRetryableError(
            $this->exception(['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'])
        ));
        // ... but 1205 ER_LOCK_WAIT_TIMEOUT hides under a generic HY000, so
        // only the driver code identifies it.
        $this->assertTrue($adapter->isRetryableError(
            $this->exception(['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'])
        ));
        $this->assertFalse($adapter->isRetryableError(
            $this->exception(['HY000', 2006, 'MySQL server has gone away']),
            'a dropped connection is not this hook\'s business'
        ));
    }

    public function testSqliteBusyAndLocked(): void
    {
        $adapter = new DBSQLite();

        $this->assertTrue($adapter->isRetryableError($this->exception(['HY000', 5, 'database is locked'])));
        $this->assertTrue($adapter->isRetryableError($this->exception(['HY000', 6, 'database table is locked'])));
        $this->assertFalse($adapter->isRetryableError($this->exception(['HY000', 1, 'SQL logic error'])));
    }

    public function testMssqlDeadlockVictim(): void
    {
        // Asserted with and without the accompanying SQLSTATE, because
        // pdo_dblib and pdo_sqlsrv do not agree on how faithfully they surface
        // it -- the driver code is what both report reliably.
        foreach ([new DBMSSQL(), new DBSQLSRV()] as $adapter) {
            $this->assertTrue($adapter->isRetryableError(
                $this->exception(['40001', 1205, 'Transaction was deadlocked ... chosen as the deadlock victim'])
            ));
            $this->assertTrue($adapter->isRetryableError(
                $this->exception(['HY000', 1205, 'Transaction was deadlocked ... chosen as the deadlock victim'])
            ));
        }
    }

    public function testOracleDeadlockAndSerializationFailure(): void
    {
        $adapter = new DBOracle();

        // Oracle reports both under a generic HY000, so the ORA number is the
        // only discriminator.
        $this->assertTrue($adapter->isRetryableError(
            $this->exception(['HY000', 60, 'ORA-00060: deadlock detected while waiting for resource'])
        ));
        $this->assertTrue($adapter->isRetryableError(
            $this->exception(['HY000', 8177, "ORA-08177: can't serialize access for this transaction"])
        ));
        $this->assertFalse($adapter->isRetryableError(
            $this->exception(['HY000', 1, 'ORA-00001: unique constraint violated'])
        ));
    }
}
