<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Util;

/**
 * Thrown by PropulsionMigrationManager::runMigrationDirection() when a migration
 * direction ('up' or 'down') fails to fully execute for some datasource -- either
 * because a statement failed, or because there were no statements to execute at
 * all. The failure has already been recorded in the migration ledger (via
 * recordMigrationRun()) by the time this is thrown; callers (the Phing task
 * adapter, or a console command) only need to decide how to surface it (a
 * Phing\Exception\BuildException, a non-zero console exit code, ...).
 */
class MigrationExecutionException extends \RuntimeException
{
    /**
     * @param string $datasource The datasource on which the failure occurred.
     * @param int $timestamp The migration's timestamp identifier.
     * @param string $direction 'up' or 'down'.
     * @param array<int, array{sql: string, status: string, error?: string}> $statementLog
     *              The exact per-statement log recorded in the ledger for this
     *              attempt (possibly empty, when no statements were found at all).
     */
    public function __construct(
        string $message,
        private readonly string $datasource,
        private readonly int $timestamp,
        private readonly string $direction,
        private readonly array $statementLog,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getDatasource(): string
    {
        return $this->datasource;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    /**
     * @return array<int, array{sql: string, status: string, error?: string}> List of entries.
     */
    public function getStatementLog(): array
    {
        return $this->statementLog;
    }
}
