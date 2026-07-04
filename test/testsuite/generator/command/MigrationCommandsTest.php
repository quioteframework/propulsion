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
