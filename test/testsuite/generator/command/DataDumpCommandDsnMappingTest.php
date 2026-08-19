<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Command\DataDumpCommand;

/**
 * `data:dump` used to be unable to pick its target SQL platform except via a
 * `-c` properties file: `--dsn`'s driver prefix was parsed only to open a PDO
 * connection, never to set `propulsion.database`, so it silently stayed at
 * `default.php`'s hardcoded `pgsql` default regardless of the DSN's real
 * driver -- `--dsn="mysql:..."` with no `-c` file dumped using PgsqlPlatform's
 * double-quoted identifiers against a MySQL connection.
 *
 * `DataDumpCommand::databaseFromDsn()` is the fix -- a small, pure, private
 * static mapping with no PDO connection or filesystem involved, so this is
 * Docker-free unlike the rest of this command's own CommandTester coverage
 * (`DataDumpCommandTest`, which needs a live Postgres testcontainer and is
 * skipped without one). Tested directly via reflection rather than through
 * the command's full execute() path, since there is nothing stateful here to
 * exercise end-to-end that the existing Postgres-only CommandTester test
 * doesn't already cover.
 */
class DataDumpCommandDsnMappingTest extends TestCase
{
    private function databaseFromDsn(string $dsn): ?string
    {
        $method = new ReflectionMethod(DataDumpCommand::class, 'databaseFromDsn');

        return $method->invoke(null, $dsn);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function dsnProvider(): array
    {
        return [
            'pgsql' => ['pgsql:host=localhost;dbname=mydb', 'pgsql'],
            'mysql' => ['mysql:host=localhost;dbname=mydb', 'mysql'],
            'sqlite' => ['sqlite:/tmp/mydb.sqlite3', 'sqlite'],
            'sqlsrv (native SQL Server driver)' => ['sqlsrv:Server=localhost;Database=mydb', 'sqlsrv'],
            // PDO's own driver name differs from this codebase's build-property
            // name for the same platform -- see PropulsionPDOTrait::$savepointCapableDrivers
            // and OpenTelemetryQueryObserver::DB_SYSTEM_BY_DRIVER for the same mapping.
            'dblib maps to mssql' => ['dblib:host=localhost;dbname=mydb', 'mssql'],
            'oci maps to oracle' => ['oci:dbname=//localhost/XE', 'oracle'],
            'driver name is case-insensitive' => ['MySQL:host=localhost;dbname=mydb', 'mysql'],
            'unrecognized driver yields null' => ['odbc:DSN=mydb', null],
            'no colon at all yields null' => ['not-a-dsn', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dsnProvider')]
    public function testDatabaseFromDsn(string $dsn, ?string $expected)
    {
        $this->assertSame($expected, $this->databaseFromDsn($dsn));
    }
}
