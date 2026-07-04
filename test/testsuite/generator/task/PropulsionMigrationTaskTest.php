<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Phing\Io\File as PhingFile;
use Propulsion\Generator\Task\PropulsionMigrationStatusTask;
use Propulsion\Generator\Task\PropulsionMigrationUpTask;
use Propulsion\Generator\Task\PropulsionMigrationDownTask;
use Propulsion\Generator\Util\PropulsionMigrationManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/PhingGeneratorTaskTestHelper.php';
require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Integration coverage for the legacy Phing migration Task classes
 * (PropulsionMigrationStatusTask/PropulsionMigrationUpTask/PropulsionMigrationDownTask,
 * `propel-gen status`/`up`/`down`) against a real Postgres testcontainer -- see
 * KNOWN_ISSUES.md's "Phing Task classes" entry.
 *
 * Writes a migration class file directly in the format
 * PropulsionMigrationManager::getMigrationClassBody() produces (also what
 * PropulsionSQLDiffTask emits -- see PropulsionSQLDiffTaskTest for that half), since
 * that's the actual on-disk contract PropulsionMigrationTask/*Up/*Down/*Status all
 * read via PropulsionMigrationManager::getMigrationObject(); it doesn't matter
 * whether the file came from `sql-diff` or was migrated by hand, only that it
 * matches the expected `PropulsionMigration_<timestamp>` class shape.
 *
 * Verifies the full cycle against a real table: `status` reports the pending
 * migration and creates the migration-tracking table on first use, `up` actually
 * ALTERs the live table and records the new version, `down` reverts both.
 */
class PropulsionMigrationTaskTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_migration_task';
    private const DATASOURCE = 'migration_parity';
    private const MIGRATION_TABLE = 'propulsion_migration_task_test';

    private ?PDO $pdo = null;
    private string $migrationDir;
    private string $dsn;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote(self::DB_NAME))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . self::DB_NAME);
        }

        $this->dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME;
        $this->pdo = new PDO($this->dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS mig_book');
        $this->pdo->exec('CREATE TABLE mig_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)');

        $this->migrationDir = sys_get_temp_dir() . '/propulsion-migration-task-test-' . uniqid();
        mkdir($this->migrationDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS mig_book');
        }
        $this->removeDir($this->migrationDir);
        parent::tearDown();
    }

    public function testStatusUpAndDownCycleAgainstRealDatabase(): void
    {
        $timestamp = 1700000000;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildManager();

        // --- status (before up): migration table gets created, migration pending ---
        $this->assertFalse($manager->migrationTableExists(self::DATASOURCE));

        $statusTask = new PropulsionMigrationStatusTask();
        PhingGeneratorTaskTestHelper::configureTask($statusTask, $this->bootProject(), 'propel-migration-status');
        $statusTask->setMigrationTable(self::MIGRATION_TABLE);
        $statusTask->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $statusTask->main());

        $this->assertTrue($manager->migrationTableExists(self::DATASOURCE), 'status should create the migration table on first run');
        $this->assertSame([$timestamp], array_values($manager->getValidMigrationTimestamps()));
        $this->assertFalse($this->columnExists('subtitle'), 'schema should be untouched before "up" runs');

        // --- up: actually alters the live table and records the version ---
        $upTask = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
        $upTask->setMigrationTable(self::MIGRATION_TABLE);
        $upTask->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());

        $this->assertTrue($this->columnExists('subtitle'), '"up" should have added the column for real');
        $this->assertSame($timestamp, $manager->getOldestDatabaseVersion());
        $this->assertSame([], $manager->getValidMigrationTimestamps(), 'no migrations should be pending after "up"');

        // --- down: reverts both the schema and the recorded version ---
        $downTask = new PropulsionMigrationDownTask();
        PhingGeneratorTaskTestHelper::configureTask($downTask, $this->bootProject(), 'propel-migration-down');
        $downTask->setMigrationTable(self::MIGRATION_TABLE);
        $downTask->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $downTask->main());

        $this->assertFalse($this->columnExists('subtitle'), '"down" should have dropped the column for real');
        $this->assertSame(0, $manager->getOldestDatabaseVersion());
        $this->assertSame([$timestamp], array_values($manager->getValidMigrationTimestamps()), 'migration should be pending again after "down"');
    }

    private function columnExists(string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
        );
        $stmt->execute(['mig_book', $column]);

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

    private function bootProject(): \Phing\Project
    {
        $buildtimeConfXml = sprintf(
            '<config><propel><datasources default="%1$s">'
            . '<datasource id="%1$s"><adapter>pgsql</adapter>'
            . '<connection><dsn>%2$s</dsn><user>propulsion</user><password>propulsion</password></connection>'
            . '</datasource></datasources></propel></config>',
            self::DATASOURCE,
            htmlspecialchars($this->dsn, ENT_XML1)
        );

        return PhingGeneratorTaskTestHelper::bootProject([
            'propel.database' => 'pgsql',
            'propel.buildtimeConf' => base64_encode($buildtimeConfXml),
        ]);
    }

    private function buildManager(): PropulsionMigrationManager
    {
        $manager = new PropulsionMigrationManager();
        $manager->setConnections([
            self::DATASOURCE => [
                'adapter' => 'pgsql',
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
