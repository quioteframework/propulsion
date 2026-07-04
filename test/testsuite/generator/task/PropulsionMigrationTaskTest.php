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
use Phing\Exception\BuildException;
use Propulsion\Generator\Task\PropulsionMigrationStatusTask;
use Propulsion\Generator\Task\PropulsionMigrationUpTask;
use Propulsion\Generator\Task\PropulsionMigrationDownTask;
use Propulsion\Generator\Util\PropulsionMigrationManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/PhingGeneratorTaskTestHelper.php';
require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Integration coverage for the legacy Phing migration Task classes
 * (PropulsionMigrationStatusTask/PropulsionMigrationUpTask/PropulsionMigrationDownTask,
 * `propel-gen status`/`up`/`down`) against a real testcontainer -- see
 * KNOWN_ISSUES.md's "Phing Task classes" entry and its migration-ledger redesign
 * notes.
 *
 * Writes a migration class file directly in the format
 * PropulsionMigrationManager::getMigrationClassBody() produces (also what
 * PropulsionSQLDiffTask emits -- see PropulsionSQLDiffTaskTest for that half), since
 * that's the actual on-disk contract PropulsionMigrationTask/*Up/*Down/*Status all
 * read via PropulsionMigrationManager::getMigrationObject(); it doesn't matter
 * whether the file came from `sql-diff` or was migrated by hand, only that it
 * matches the expected `PropulsionMigration_<timestamp>` class shape.
 *
 * Runs against whichever platform IntegrationDatabase's shared testcontainer is
 * currently backing (Postgres by default, MySQL under PROPULSION_TEST_DB=mysql --
 * see IntegrationDatabase::currentPlatform()). Most tests here are written to work
 * identically on either platform; two are deliberately gated on the platform's
 * PropulsionPlatformInterface::supportsTransactionalDDL() value, since they exist
 * specifically to prove opposite behavior on transactional vs. non-transactional
 * DDL platforms (see testTransactionalPlatformRollsBackEarlierStatementOnLaterFailure()/
 * testNonTransactionalPlatformLeavesEarlierStatementAppliedOnFailure()).
 */
class PropulsionMigrationTaskTest extends TestCase
{
    private const DATASOURCE = 'migration_parity';
    private const MIGRATION_TABLE = 'propulsion_migration_task_test';

    private ?PDO $pdo = null;
    private string $migrationDir;
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

        // Reuse the container's own default database (already granted to the
        // 'propulsion' user -- the MySQL testcontainer module only grants
        // that user access to the database it was created with, not
        // CREATE DATABASE / cross-database privileges the way the Postgres
        // superuser role does) rather than provisioning a sibling database
        // per platform. Table names below are unique enough
        // (mig_book/propulsion_migration_task_test) to not collide with the
        // Bookstore fixture tables that also live in this database.
        $this->dsn = "{$this->platform}:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $this->pdo = new PDO($this->dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS mig_book');
        $this->pdo->exec($this->platform === 'mysql'
            ? 'CREATE TABLE mig_book (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL)'
            : 'CREATE TABLE mig_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)'
        );

        $this->migrationDir = sys_get_temp_dir() . '/propulsion-migration-task-test-' . uniqid();
        mkdir($this->migrationDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS mig_book');
        }
        // setUp() calls markTestSkipped() (e.g. no Docker, or a container
        // startup timeout) before $migrationDir gets initialized -- guard
        // against turning a clean skip into a fatal "must not be accessed
        // before initialization" error here, matching the tearDown-guarding
        // convention used elsewhere in this suite (see KNOWN_ISSUES.md).
        if (isset($this->migrationDir)) {
            $this->removeDir($this->migrationDir);
        }
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

        // The ledger should now hold exactly the two attempts made (up, then down),
        // both successful, and never having updated an existing row (append-only).
        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $this->assertSame('up', $ledger[0]['direction']);
        $this->assertSame('down', $ledger[1]['direction']);
        foreach ($ledger as $row) {
            $this->assertTrue($this->isTruthy($row['success']), 'both recorded attempts should be successful');
        }
    }

    /**
     * Bug fix: PropulsionMigrationUpTask used to signal a failed migration via
     * `return false` from main(), which does NOT fail a Phing build (Phing only
     * fails on an uncaught exception) -- so a partially-applied "up" migration
     * used to exit 0 silently. It now throws a BuildException, and the failed
     * attempt is recorded in the ledger (success=false) without ever counting as
     * "applied".
     */
    public function testUpMigrationStatementFailureAbortsBuildAndDoesNotApplyVersion(): void
    {
        $timestamp = 1700000100;
        // The second statement deterministically fails on every platform: you
        // cannot add the same column twice.
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => "ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);\n"
                . 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildManager();

        $upTask = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
        $upTask->setMigrationTable(self::MIGRATION_TABLE);
        $upTask->setOutputDirectory(new PhingFile($this->migrationDir));

        $threw = false;
        try {
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());
        } catch (BuildException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a statement failure must throw a BuildException, failing the Phing build');

        // The migration must NOT count as applied.
        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertSame(0, $manager->getOldestDatabaseVersion());
        $this->assertSame([$timestamp], array_values($manager->getValidMigrationTimestamps()), 'the failed migration should still be pending');

        // The ledger must record exactly one attempt, marked unsuccessful, with
        // an accurate per-statement log: the first statement succeeded, the
        // second failed, and there was no third statement to mark not_attempted.
        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(1, $ledger);
        $row = $ledger[0];
        $this->assertSame('up', $row['direction']);
        $this->assertSame($timestamp, (int) $row['migration_timestamp']);
        $this->assertFalse($this->isTruthy($row['success']), 'the failed attempt must be recorded as unsuccessful');

        $statementLog = json_decode((string) $row['statement_log'], true);
        $this->assertCount(2, $statementLog);
        $this->assertSame('success', $statementLog[0]['status']);
        $this->assertArrayNotHasKey('error', $statementLog[0]);
        $this->assertSame('failed', $statementLog[1]['status']);
        $this->assertArrayHasKey('error', $statementLog[1]);
        $this->assertNotSame('', $statementLog[1]['error']);
    }

    /**
     * Bug fix: PropulsionMigrationDownTask's per-statement loop used to catch a
     * failed statement's PDOException, log it, and keep going -- if ANY
     * statement in the direction succeeded, the migration was still recorded as
     * fully reverted even though a later statement failed. It now stops at the
     * first failure, throws a BuildException, and does not revert the tracked
     * version.
     */
    public function testDownMigrationStatementFailureAbortsBuildAndDoesNotRevertVersion(): void
    {
        $timestamp = 1700000200;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            // Second statement deterministically fails: the column no longer
            // exists once the first statement has dropped it.
            self::DATASOURCE => "ALTER TABLE mig_book DROP COLUMN subtitle;\n"
                . 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildManager();

        $upTask = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
        $upTask->setMigrationTable(self::MIGRATION_TABLE);
        $upTask->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());

        $this->assertSame($timestamp, $manager->getOldestDatabaseVersion(), 'sanity check: up should have applied cleanly');

        $downTask = new PropulsionMigrationDownTask();
        PhingGeneratorTaskTestHelper::configureTask($downTask, $this->bootProject(), 'propel-migration-down');
        $downTask->setMigrationTable(self::MIGRATION_TABLE);
        $downTask->setOutputDirectory(new PhingFile($this->migrationDir));

        $threw = false;
        try {
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $downTask->main());
        } catch (BuildException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a statement failure must throw a BuildException, failing the Phing build');

        // The migration must still count as applied (NOT reverted).
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertSame($timestamp, $manager->getOldestDatabaseVersion());
        $this->assertSame([], $manager->getValidMigrationTimestamps(), 'the migration must still be considered executed');

        // Ledger: one up (success), one down (failure) with an accurate log.
        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $downRow = $ledger[1];
        $this->assertSame('down', $downRow['direction']);
        $this->assertFalse($this->isTruthy($downRow['success']));

        $statementLog = json_decode((string) $downRow['statement_log'], true);
        $this->assertCount(2, $statementLog);
        $this->assertSame('success', $statementLog[0]['status']);
        $this->assertSame('failed', $statementLog[1]['status']);
        $this->assertArrayHasKey('error', $statementLog[1]);
    }

    /**
     * On a transactional-DDL platform (Postgres in the default test run --
     * PropulsionPlatformInterface::supportsTransactionalDDL() === true), a later
     * statement's failure must roll back everything the migration did,
     * including statements that would otherwise have succeeded -- not just
     * report the failure in the ledger, but actually undo the earlier
     * statement's real schema change.
     */
    public function testTransactionalPlatformRollsBackEarlierStatementOnLaterFailure(): void
    {
        $manager = $this->buildManager();
        if (!$manager->getPlatform(self::DATASOURCE)->supportsTransactionalDDL()) {
            $this->markTestSkipped('This test only applies to a transactional-DDL platform (got: ' . $this->platform . ').');
        }

        $timestamp = 1700000300;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => "ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);\n"
                . 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $upTask = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
        $upTask->setMigrationTable(self::MIGRATION_TABLE);
        $upTask->setOutputDirectory(new PhingFile($this->migrationDir));

        try {
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());
            $this->fail('Expected a BuildException.');
        } catch (BuildException $e) {
            // expected
        }

        // The first statement (adding "subtitle") would have succeeded on its
        // own -- assert it was actually rolled back in the live schema, not
        // just marked as a failure in the ledger.
        $this->assertFalse($this->columnExists('subtitle'), 'the transaction must have rolled back the earlier, would-have-succeeded statement');
    }

    /**
     * On a non-transactional-DDL platform (MySQL, only exercised when this test
     * suite is run with PROPULSION_TEST_DB=mysql --
     * PropulsionPlatformInterface::supportsTransactionalDDL() === false), a
     * later statement's failure leaves whatever earlier statements already
     * succeeded applied for real -- this is an inherent limitation of
     * non-transactional DDL, not something the redesign papers over. The
     * ledger must still accurately record the failure.
     */
    public function testNonTransactionalPlatformLeavesEarlierStatementAppliedOnFailure(): void
    {
        $manager = $this->buildManager();
        if ($manager->getPlatform(self::DATASOURCE)->supportsTransactionalDDL()) {
            $this->markTestSkipped('This test only applies to a non-transactional-DDL platform; run with PROPULSION_TEST_DB=mysql to exercise it (got: ' . $this->platform . ').');
        }

        $timestamp = 1700000400;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => "ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);\n"
                . 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $upTask = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
        $upTask->setMigrationTable(self::MIGRATION_TABLE);
        $upTask->setOutputDirectory(new PhingFile($this->migrationDir));

        try {
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());
            $this->fail('Expected a BuildException.');
        } catch (BuildException $e) {
            // expected
        }

        // The first statement's effect survives for real, even though the
        // overall migration is correctly recorded as failed/not-applied.
        $this->assertTrue($this->columnExists('subtitle'), 'on a non-transactional platform the earlier successful statement must remain applied');
        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE), 'the migration must still be reported as not applied despite the partial schema change');
    }

    /**
     * Full up -> down -> up cycle: the ledger must accumulate a new row for
     * every attempt (never update/delete an existing one), and
     * getCurrentVersion() must correctly report the migration as applied again
     * after the second "up", despite the intervening "down".
     */
    public function testFullUpDownUpCycleAccumulatesLedgerAndTracksCurrentVersion(): void
    {
        $timestamp = 1700000500;
        $this->writeMigrationFile($timestamp, [
            self::DATASOURCE => 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);',
        ], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildManager();

        $runUp = function () {
            $upTask = new PropulsionMigrationUpTask();
            PhingGeneratorTaskTestHelper::configureTask($upTask, $this->bootProject(), 'propel-migration-up');
            $upTask->setMigrationTable(self::MIGRATION_TABLE);
            $upTask->setOutputDirectory(new PhingFile($this->migrationDir));
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTask->main());
        };
        $runDown = function () {
            $downTask = new PropulsionMigrationDownTask();
            PhingGeneratorTaskTestHelper::configureTask($downTask, $this->bootProject(), 'propel-migration-down');
            $downTask->setMigrationTable(self::MIGRATION_TABLE);
            $downTask->setOutputDirectory(new PhingFile($this->migrationDir));
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $downTask->main());
        };

        $runUp();
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertCount(1, $manager->getMigrationLedger(self::DATASOURCE));

        $runDown();
        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE));
        $this->assertCount(2, $manager->getMigrationLedger(self::DATASOURCE), 'the down attempt must append a new row, not overwrite the up row');

        $runUp();
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE), 'the second "up" must be reflected as applied again');
        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(3, $ledger, 'every attempt must append a distinct row');
        $this->assertSame(['up', 'down', 'up'], array_column($ledger, 'direction'));
        foreach ($ledger as $row) {
            $this->assertTrue($this->isTruthy($row['success']));
        }
    }

    /**
     * The ledger's checksum column is a sha256 digest of the exact SQL string
     * executed for a given direction, computed and stored on every run, and
     * differs between two migrations whose SQL differs.
     */
    public function testChecksumIsComputedAndStoredAndDiffersForDifferentSql(): void
    {
        $timestampA = 1700000600;
        $upSqlA = 'ALTER TABLE mig_book ADD COLUMN subtitle VARCHAR(120);';
        $this->writeMigrationFile($timestampA, [self::DATASOURCE => $upSqlA], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN subtitle;',
        ]);

        $manager = $this->buildManager();
        $upTaskA = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTaskA, $this->bootProject(), 'propel-migration-up');
        $upTaskA->setMigrationTable(self::MIGRATION_TABLE);
        $upTaskA->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTaskA->main());

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(1, $ledger);
        $checksumA = $ledger[0]['checksum'];
        $this->assertSame(hash('sha256', $upSqlA), $checksumA);
        $this->assertSame(64, strlen($checksumA));

        // A second migration with different up SQL must get a different checksum.
        $timestampB = 1700000700;
        $upSqlB = 'ALTER TABLE mig_book ADD COLUMN blurb VARCHAR(200);';
        $this->writeMigrationFile($timestampB, [self::DATASOURCE => $upSqlB], [
            self::DATASOURCE => 'ALTER TABLE mig_book DROP COLUMN blurb;',
        ]);

        $upTaskB = new PropulsionMigrationUpTask();
        PhingGeneratorTaskTestHelper::configureTask($upTaskB, $this->bootProject(), 'propel-migration-up');
        $upTaskB->setMigrationTable(self::MIGRATION_TABLE);
        $upTaskB->setOutputDirectory(new PhingFile($this->migrationDir));
        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $upTaskB->main());

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $checksumB = $ledger[1]['checksum'];
        $this->assertSame(hash('sha256', $upSqlB), $checksumB);
        $this->assertNotSame($checksumA, $checksumB);
    }

    /**
     * Normalizes a fetched `success` column value (which may come back as a
     * PHP bool, an int, or a driver-native string like 't'/'f' depending on
     * platform) into a real bool for assertions.
     */
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

    private function columnExists(string $column, string $table = 'mig_book'): bool
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

    private function bootProject(): \Phing\Project
    {
        $buildtimeConfXml = sprintf(
            '<config><propel><datasources default="%1$s">'
            . '<datasource id="%1$s"><adapter>%3$s</adapter>'
            . '<connection><dsn>%2$s</dsn><user>propulsion</user><password>propulsion</password></connection>'
            . '</datasource></datasources></propel></config>',
            self::DATASOURCE,
            htmlspecialchars($this->dsn, ENT_XML1),
            $this->platform
        );

        return PhingGeneratorTaskTestHelper::bootProject([
            'propel.database' => $this->platform,
            'propel.buildtimeConf' => base64_encode($buildtimeConfXml),
        ]);
    }

    private function buildManager(): PropulsionMigrationManager
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
