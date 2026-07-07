<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Propulsion\Generator\Command\MigrationStatusCommand;
use Propulsion\Generator\Command\MigrationUpCommand;
use Propulsion\Generator\Command\MigrationDownCommand;
use Propulsion\Generator\Util\PropulsionMigrationManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end CommandTester coverage for `bin/propulsion migration:status` /
 * `migration:up` / `migration:down` against a real testcontainer.
 *
 * This proves the console entry point wires up
 * PropulsionMigrationManager::runMigrationDirection() correctly -- the same
 * transaction/ledger execution logic is exercised (and already proven correct
 * in detail, including the transactional-vs-non-transactional-DDL platform
 * split) by PropulsionMigrationTaskTest against the Phing task adapter, so
 * this file focuses on what is specific to the console path: option wiring,
 * output, and -- the important regression to guard against -- a non-zero
 * process exit code on a statement failure (the console-world equivalent of
 * the `BuildException`-not-`return false` fix from the migration ledger
 * redesign, see KNOWN_ISSUES.md).
 *
 * Runs against whichever platform IntegrationDatabase's shared testcontainer is
 * currently backing (Postgres by default, MySQL under PROPULSION_TEST_DB=mysql).
 */
class MigrationCommandsTest extends TestCase
{
    private const DATASOURCE = 'migration_command_parity';
    private const MIGRATION_TABLE = 'propulsion_migration_command_test';

    private ?PDO $pdo = null;
    private string $migrationDir;
    private string $buildtimeConfFile;
    private string $buildtimeConfigPhpFile;
    private string $dsn;
    private string $platform;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->platform = IntegrationDatabase::currentPlatform();

        $this->dsn = "{$this->platform}:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $this->pdo = new PDO($this->dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS mig_cmd_book');
        $this->pdo->exec($this->platform === 'mysql'
            ? 'CREATE TABLE mig_cmd_book (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL)'
            : 'CREATE TABLE mig_cmd_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)'
        );

        $this->migrationDir = sys_get_temp_dir() . '/propulsion-migration-command-test-' . uniqid();
        mkdir($this->migrationDir, 0777, true);

        $this->buildtimeConfFile = $this->migrationDir . '/buildtime-conf.xml';
        file_put_contents($this->buildtimeConfFile, sprintf(
            '<config><propel><datasources default="%1$s">'
            . '<datasource id="%1$s"><adapter>%3$s</adapter>'
            . '<connection><dsn>%2$s</dsn><user>propulsion</user><password>propulsion</password></connection>'
            . '</datasource></datasources></propel></config>',
            self::DATASOURCE,
            htmlspecialchars($this->dsn, ENT_XML1),
            $this->platform
        ));

        $this->buildtimeConfigPhpFile = $this->migrationDir . '/buildtime-conf.php';
        file_put_contents($this->buildtimeConfigPhpFile, '<?php return ' . var_export([
            'default' => self::DATASOURCE,
            'datasources' => [
                self::DATASOURCE => [
                    'adapter' => $this->platform,
                    'dsn' => $this->dsn,
                    'user' => 'propulsion',
                    'password' => 'propulsion',
                ],
            ],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS mig_cmd_book');
        }
        if (isset($this->migrationDir)) {
            $this->removeDir($this->migrationDir);
        }
        parent::tearDown();
    }

    public function testStatusUpDownCycleAgainstRealDatabase(): void
    {
        $timestamp = 1750000000;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildRawManager();

        // --- migration:status (before up): creates the table, reports pending ---
        $this->assertFalse($manager->migrationTableExists(self::DATASOURCE));

        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute($this->commandArgs());
        $this->assertSame(0, $exitCode, $statusTester->getDisplay());
        $this->assertStringContainsString('PropulsionMigration_' . $timestamp, $statusTester->getDisplay());

        $this->assertTrue($manager->migrationTableExists(self::DATASOURCE), 'migration:status should create the migration table on first run');
        $this->assertFalse($this->columnExists('subtitle'), 'schema should be untouched before migration:up runs');

        // --- migration:up: alters the live table and records the version ---
        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());
        $this->assertSame(0, $exitCode, $upTester->getDisplay());

        $this->assertTrue($this->columnExists('subtitle'), 'migration:up should have added the column for real');
        $this->assertSame($timestamp, $manager->getOldestDatabaseVersion());
        $this->assertSame([], $manager->getValidMigrationTimestamps(), 'no migrations should be pending after migration:up');

        // Running migration:up again with nothing pending must still succeed (exit 0).
        $upAgainTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upAgainTester->execute($this->commandArgs());
        $this->assertSame(0, $exitCode, $upAgainTester->getDisplay());
        $this->assertStringContainsString('nothing to migrate', $upAgainTester->getDisplay());

        // --- migration:down: reverts both the schema and the recorded version ---
        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());
        $this->assertSame(0, $exitCode, $downTester->getDisplay());

        $this->assertFalse($this->columnExists('subtitle'), 'migration:down should have dropped the column for real');
        $this->assertSame(0, $manager->getOldestDatabaseVersion());
        $this->assertSame([$timestamp], array_values($manager->getValidMigrationTimestamps()), 'migration should be pending again after migration:down');

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $this->assertSame('up', $ledger[0]['direction']);
        $this->assertSame('down', $ledger[1]['direction']);
    }

    /**
     * Regression guard for the console-world equivalent of the
     * BuildException-not-`return false` bug: a statement failure during
     * `migration:up` must exit non-zero, not print an error and return 0.
     */
    public function testUpCommandExitsNonZeroOnStatementFailureAndDoesNotApplyVersion(): void
    {
        $timestamp = 1750000100;
        // The second statement deterministically fails on every platform: you
        // cannot add the same column twice.
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => "ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);\n"
                . 'ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildRawManager();

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode, 'a statement failure must produce a non-zero exit code');
        $this->assertStringContainsString('STATEMENT LOG', strtoupper($upTester->getDisplay()));

        // The migration must NOT count as applied.
        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertSame([$timestamp], array_values($manager->getValidMigrationTimestamps()), 'the failed migration should still be pending');

        // The ledger must record exactly one attempt, marked unsuccessful.
        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(1, $ledger);
        $this->assertFalse($this->isTruthy($ledger[0]['success']));
    }

    /**
     * Same regression guard, for `migration:down`.
     */
    public function testDownCommandExitsNonZeroOnStatementFailureAndDoesNotRevertVersion(): void
    {
        $timestamp = 1750000200;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            // Second statement deterministically fails: the column no longer
            // exists once the first statement has dropped it.
            self::DATASOURCE => "ALTER TABLE mig_cmd_book DROP COLUMN subtitle;\n"
                . 'ALTER TABLE mig_cmd_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildRawManager();

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());
        $this->assertSame(0, $exitCode, $upTester->getDisplay());
        $this->assertSame($timestamp, $manager->getOldestDatabaseVersion(), 'sanity check: up should have applied cleanly');

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode, 'a statement failure must produce a non-zero exit code');

        // The migration must still count as applied (NOT reverted).
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertSame([], $manager->getValidMigrationTimestamps(), 'the migration must still be considered executed');

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $this->assertFalse($this->isTruthy($ledger[1]['success']));
    }

    public function testDownCommandReportsNoMigrationEverExecuted(): void
    {
        // Migration file exists but was never applied -- migration:down has
        // nothing to reverse.
        $this->writeMigrationFile(1750000400, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN subtitle;',
        ]);

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());

        $this->assertSame(0, $exitCode, $downTester->getDisplay());
        $this->assertStringContainsString('nothing to reverse', $downTester->getDisplay());
    }

    public function testUpCommandReportsRemainingMigrationsAfterApplyingOne(): void
    {
        $this->writeMigrationFile(1750000500, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_a VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_a;',
        ]);
        $this->writeMigrationFile(1750000501, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_b VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_b;',
        ]);

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());

        $this->assertSame(0, $exitCode, $upTester->getDisplay());
        $this->assertStringContainsString('1 migration(s) left to execute', $upTester->getDisplay());
    }

    public function testDownCommandReportsRemainingMigrationsAfterRevertingOne(): void
    {
        $this->writeMigrationFile(1750000600, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_c VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_c;',
        ]);
        $this->writeMigrationFile(1750000601, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_d VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_d;',
        ]);

        // Apply both, one at a time (each migration:up call only applies the
        // next pending one).
        $this->tester(new MigrationUpCommand(), 'migration:up')->execute($this->commandArgs());
        $this->tester(new MigrationUpCommand(), 'migration:up')->execute($this->commandArgs());

        // Revert just the most recent one.
        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());

        $this->assertSame(0, $exitCode, $downTester->getDisplay());
        $this->assertStringContainsString('1 more migration(s) available for reverse', $downTester->getDisplay());
    }

    public function testUpCommandAbortsWhenPreUpReturnsFalse(): void
    {
        $this->writeCustomMigrationFile(1750000700, <<<'EOT'
public function preUp($manager)
{
    return false;
}

public function postUp($manager) {}
public function preDown($manager) {}
public function postDown($manager) {}

public function getUpSQL()
{
    return ['migration_command_parity' => 'ALTER TABLE mig_cmd_book ADD COLUMN col_e VARCHAR(10);'];
}

public function getDownSQL()
{
    return ['migration_command_parity' => 'ALTER TABLE mig_cmd_book DROP COLUMN col_e;'];
}
EOT
        );

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('preUp() returned false', $upTester->getDisplay());
        $this->assertFalse($this->columnExists('col_e'), 'the SQL must never run when preUp() vetoes the migration');
    }

    public function testDownCommandAbortsWhenPreDownReturnsFalse(): void
    {
        $this->writeCustomMigrationFile(1750000800, <<<'EOT'
public function preUp($manager) {}
public function postUp($manager) {}

public function preDown($manager)
{
    return false;
}

public function postDown($manager) {}

public function getUpSQL()
{
    return ['migration_command_parity' => 'ALTER TABLE mig_cmd_book ADD COLUMN col_f VARCHAR(10);'];
}

public function getDownSQL()
{
    return ['migration_command_parity' => 'ALTER TABLE mig_cmd_book DROP COLUMN col_f;'];
}
EOT
        );

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $this->assertSame(0, $upTester->execute($this->commandArgs()), $upTester->getDisplay());
        $this->assertTrue($this->columnExists('col_f'));

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('preDown() returned false', $downTester->getDisplay());
        $this->assertTrue($this->columnExists('col_f'), 'the column must still be there when preDown() vetoes the revert');
    }

    public function testUpAndDownCommandsReportGenericFailureForNonStatementErrors(): void
    {
        // A buildtime-conf that fails to parse (rather than a live-database
        // statement failure) must hit the *generic* \Throwable catch, not the
        // MigrationExecutionException branch -- and still exit non-zero.
        $brokenConfFile = $this->migrationDir . '/broken-buildtime-conf.php';
        file_put_contents($brokenConfFile, '<?php this is not valid php');

        $args = [
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => self::MIGRATION_TABLE,
            '--buildtime-conf' => $brokenConfFile,
            '--database' => $this->platform,
        ];

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($args);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Migration failed', $upTester->getDisplay());

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($args);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Migration failed', $downTester->getDisplay());
    }

    public function testUpCommandWithNoStatementsRendersEmptyStatementLogSilently(): void
    {
        // A migration whose Up SQL is only a comment parses to zero
        // statements: runMigrationDirection() throws a MigrationExecutionException
        // with an *empty* statement log, exercising renderStatementLog()'s
        // early-return-on-empty-log branch (as opposed to the populated-log
        // branch exercised by the statement-failure tests above).
        $this->writeMigrationFile(1750001100, [
            self::DATASOURCE => '-- nothing to do here',
        ], [
            self::DATASOURCE => '-- nothing to do here either',
        ]);

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('aborted: no SQL statements', $upTester->getDisplay());
        $this->assertStringNotContainsString('Statement log', $upTester->getDisplay());
    }

    public function testDownCommandWithNoStatementsRendersEmptyStatementLogSilently(): void
    {
        // Same as the Up-side test above, but for migration:down's own copy of
        // renderStatementLog(): a migration whose Down SQL is only a comment.
        $this->writeMigrationFile(1750001200, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_i VARCHAR(10);',
        ], [
            self::DATASOURCE => '-- nothing to do here',
        ]);

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $this->assertSame(0, $upTester->execute($this->commandArgs()), $upTester->getDisplay());

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($this->commandArgs());

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('aborted: no SQL statements', $downTester->getDisplay());
        $this->assertStringNotContainsString('Statement log', $downTester->getDisplay());
    }

    public function testUpAndDownCommandsPrintStackTraceInVeryVerboseMode(): void
    {
        $brokenConfFile = $this->migrationDir . '/broken-buildtime-conf-2.php';
        file_put_contents($brokenConfFile, '<?php this is not valid php');

        $args = [
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => self::MIGRATION_TABLE,
            '--buildtime-conf' => $brokenConfFile,
            '--database' => $this->platform,
        ];
        $verbosity = ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERY_VERBOSE];

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $upTester->execute($args, $verbosity);
        $this->assertStringContainsString('.php', $upTester->getDisplay(), 'a stack trace (with file paths) should be printed in -vv mode');

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $downTester->execute($args, $verbosity);
        $this->assertStringContainsString('.php', $downTester->getDisplay(), 'a stack trace (with file paths) should be printed in -vv mode');
    }

    /**
     * Unlike testStatusCommandFailsCleanlyWhenBuildtimeConfIsUnparsable() (a
     * config-parsing failure caught by the *first* try block, around
     * buildManager()), this needs a failure inside the *second* try block --
     * config parses fine, but the live DB interaction itself fails -- to
     * exercise that block's own verbose-trace branch. An invalid migration
     * table name (containing characters no SQL identifier allows) fails
     * migrationTableExists()'s query without failing config loading.
     */
    public function testStatusCommandPrintsStackTraceInVeryVerboseModeOnDatabaseError(): void
    {
        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute([
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => 'not a valid identifier; drop table foo',
            '--buildtime-conf' => $this->buildtimeConfFile,
            '--database' => $this->platform,
        ], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERY_VERBOSE]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Failed to determine migration status', $statusTester->getDisplay());
        $this->assertStringContainsString('.php', $statusTester->getDisplay(), 'a stack trace (with file paths) should be printed in -vv mode');
    }

    public function testStatusCommandFailsCleanlyWhenBuildtimeConfIsUnparsable(): void
    {
        $brokenConfFile = $this->migrationDir . '/broken-buildtime-conf-3.php';
        file_put_contents($brokenConfFile, '<?php this is not valid php');

        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute([
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => self::MIGRATION_TABLE,
            '--buildtime-conf' => $brokenConfFile,
            '--database' => $this->platform,
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Failed to determine datasource connections', $statusTester->getDisplay());
    }

    public function testStatusCommandReportsNoMigrationFilesInEmptyDirectory(): void
    {
        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute($this->commandArgs());

        $this->assertSame(0, $exitCode, $statusTester->getDisplay());
        $this->assertStringContainsString('No migration file found', $statusTester->getDisplay());
    }

    public function testStatusCommandReportsAllMigrationsAlreadyExecuted(): void
    {
        $timestamp = 1750000900;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_g VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_g;',
        ]);

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $this->assertSame(0, $upTester->execute($this->commandArgs()), $upTester->getDisplay());

        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute($this->commandArgs());

        $this->assertSame(0, $exitCode, $statusTester->getDisplay());
        $this->assertStringContainsString('already executed - nothing to migrate', $statusTester->getDisplay());
    }

    public function testStatusCommandVerboseOutputShowsOldestMigrationTimestamp(): void
    {
        $timestamp = 1750001000;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN col_h VARCHAR(10);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN col_h;',
        ]);

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $this->assertSame(0, $upTester->execute($this->commandArgs()), $upTester->getDisplay());

        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute($this->commandArgs(), ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(0, $exitCode, $statusTester->getDisplay());
        $this->assertStringContainsString('Latest migration was executed on', $statusTester->getDisplay());
        $this->assertStringContainsString((string) $timestamp, $statusTester->getDisplay());
    }

    /**
     * Same status -> up -> down cycle as
     * testStatusUpDownCycleAgainstRealDatabase(), but via the recommended
     * plain-PHP-array buildtime config file (--buildtime-conf pointing at a
     * .php file returning ['default' => ..., 'datasources' => [...]]) instead
     * of the legacy buildtime-conf.xml format -- proves GeneratorConfig's
     * extension-based dispatch (see getBuildConnections()/
     * loadBuildConnectionsFile()) actually wires a PHP config all the way
     * through the console commands, not just at the GeneratorConfig unit level.
     */
    public function testStatusUpDownCycleAgainstRealDatabaseUsingPhpConfigFile(): void
    {
        $timestamp = 1750000300;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_cmd_book DROP COLUMN subtitle;',
        ]);

        $args = [
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => self::MIGRATION_TABLE,
            '--buildtime-conf' => $this->buildtimeConfigPhpFile,
            '--database' => $this->platform,
        ];

        $statusTester = $this->tester(new MigrationStatusCommand(), 'migration:status');
        $exitCode = $statusTester->execute($args);
        $this->assertSame(0, $exitCode, $statusTester->getDisplay());
        $this->assertStringContainsString('PropulsionMigration_' . $timestamp, $statusTester->getDisplay());

        $upTester = $this->tester(new MigrationUpCommand(), 'migration:up');
        $exitCode = $upTester->execute($args);
        $this->assertSame(0, $exitCode, $upTester->getDisplay());
        $this->assertTrue($this->columnExists('subtitle'), 'migration:up should have added the column for real');

        $downTester = $this->tester(new MigrationDownCommand(), 'migration:down');
        $exitCode = $downTester->execute($args);
        $this->assertSame(0, $exitCode, $downTester->getDisplay());
        $this->assertFalse($this->columnExists('subtitle'), 'migration:down should have dropped the column for real');
    }

    public function testStatusCommandFailsCleanlyWithNoConnectionSettings(): void
    {
        $emptyDir = sys_get_temp_dir() . '/propulsion-migration-command-noconf-' . uniqid();
        mkdir($emptyDir, 0777, true);

        try {
            $tester = $this->tester(new MigrationStatusCommand(), 'migration:status');
            $exitCode = $tester->execute([
                '--migration-dir' => $emptyDir,
            ]);

            $this->assertNotSame(0, $exitCode);
        } finally {
            rmdir($emptyDir);
        }
    }

    private function commandArgs(): array
    {
        return [
            '--migration-dir' => $this->migrationDir,
            '--migration-table' => self::MIGRATION_TABLE,
            '--buildtime-conf' => $this->buildtimeConfFile,
            '--database' => $this->platform,
        ];
    }

    private function tester(\Symfony\Component\Console\Command\Command $command, string $name): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find($name));
    }

    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'y', 'yes'], true);
    }

    private function columnExists(string $column, string $table = 'mig_cmd_book'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function writeMigrationFile(int $timestamp, array $upSql, array $downSql): void
    {
        $manager = new PropulsionMigrationManager();
        $fileName = PropulsionMigrationManager::getMigrationFileName($timestamp);
        $body = $manager->getMigrationClassBody($upSql, $downSql, $timestamp);
        file_put_contents($this->migrationDir . '/' . $fileName, $body);
        $this->assertFileExists($this->migrationDir . '/' . $fileName);
    }

    /**
     * Same as writeMigrationFile(), but with a hand-written class body instead
     * of the standard generated one -- used to exercise hooks
     * (preUp()/preDown() returning false) that getMigrationClassBody()'s
     * fixed template can't produce.
     */
    private function writeCustomMigrationFile(int $timestamp, string $classBody): void
    {
        $fileName = PropulsionMigrationManager::getMigrationFileName($timestamp);
        $className = PropulsionMigrationManager::getMigrationClassName($timestamp);
        $body = "<?php\n\nclass $className\n{\n$classBody\n}\n";
        file_put_contents($this->migrationDir . '/' . $fileName, $body);
        $this->assertFileExists($this->migrationDir . '/' . $fileName);
    }

    private function buildRawManager(): PropulsionMigrationManager
    {
        $manager = new PropulsionMigrationManager();
        $manager->setConnections([
            self::DATASOURCE => [
                'adapter' => $this->platform,
                'dsn' => $this->dsn,
                'user' => 'propulsion',
                'password' => 'propulsion',
            ],
        ]);
        $manager->setMigrationTable(self::MIGRATION_TABLE);
        $manager->setMigrationDir($this->migrationDir);

        return $manager;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
