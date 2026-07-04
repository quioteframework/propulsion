<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Util\PropulsionSQLParser;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Plain-PHP replacement for the Phing-based PropulsionSQLExec: executes one or more
 * `.sql` files against a live database over a single PDO connection.
 *
 * Simplified relative to the original Task: PropulsionSQLExec drove itself off a
 * Phing-Properties `sqldbmap` file mapping many `.sql` files to many different
 * per-database DSNs in one run (useful inside a multi-schema Phing build where
 * PropulsionSQLTask had just produced one `.sql` file per database). A standalone
 * console command has no such multi-database build context to draw a map from, so
 * this instead takes an explicit DSN and an explicit, ordered list of `.sql` files
 * and runs all of them against that one connection -- the same execution semantics
 * (each statement in its own transaction unless autocommit is on; "abort" vs
 * "continue" on error) minus the file-to-database routing machinery.
 */
class SqlExecManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly string $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly bool $autocommit = false,
        private readonly string $onError = 'abort',
    ) {
        $this->logger = new NullLogger();
    }

    /**
     * @param string[] $sqlFiles Absolute or relative paths to .sql files, executed in order.
     * @return int Number of successfully executed statements.
     */
    public function execute(array $sqlFiles): int
    {
        if (!in_array($this->onError, ['abort', 'continue'], true)) {
            throw new EngineException("Invalid onError action '{$this->onError}': expected 'abort' or 'continue'");
        }

        $statements = [];
        foreach ($sqlFiles as $file) {
            if (!is_file($file)) {
                $this->logger->warning('File "{file}" does not exist, skipping it.', ['file' => $file]);
                continue;
            }

            $this->logger->info('Loading statements from "{file}"', ['file' => $file]);
            $fileStatements = PropulsionSQLParser::parseFile($file);
            $this->logger->debug('{count} statements to execute', ['count' => count($fileStatements)]);
            array_push($statements, ...$fileStatements);
        }

        $con = $this->connect();

        $goodSql = 0;
        try {
            foreach ($statements as $statement) {
                $goodSql += $this->execSQL($con, $statement);
                if (!$this->autocommit) {
                    $this->logger->debug('Committing transaction');
                    // execSQL() already commits/rolls back its own per-statement
                    // transaction; nothing further to do here, matching the fact
                    // that the original per-file commit() call was a no-op once
                    // execSQL()'s own commit() already ran.
                }
            }
        } catch (\Throwable $e) {
            throw new EngineException($e->getMessage(), 0, $e);
        }

        $this->logger->info('{good} of {total} SQL statements executed successfully', [
            'good' => $goodSql,
            'total' => count($statements),
        ]);

        return $goodSql;
    }

    private function connect(): \PDO
    {
        try {
            $con = new \PDO($this->dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new EngineException('Unable to connect to database: ' . $e->getMessage(), 0, $e);
        }

        return $con;
    }

    private function execSQL(\PDO $con, string $sql): int
    {
        if (trim($sql) === '') {
            return 0;
        }

        try {
            if (!$this->autocommit) {
                $con->beginTransaction();
            }

            $stmt = $con->prepare($sql);
            $this->logger->debug('Executing statement "{sql}"', ['sql' => $sql]);
            $stmt->execute();
            $this->logger->debug('{count} rows affected', ['count' => $stmt->rowCount()]);

            if (!$this->autocommit) {
                $con->commit();
            }

            return 1;
        } catch (\PDOException $e) {
            $this->logger->error('Failed to execute: {sql}', ['sql' => $sql]);

            if (!$this->autocommit && $con->inTransaction()) {
                try {
                    $con->rollBack();
                } catch (\PDOException $ex) {
                    $this->logger->error('Rollback failed.');
                }
            }

            if ($this->onError !== 'continue') {
                throw $e;
            }

            $this->logger->error($e->getMessage());

            return 0;
        }
    }
}
