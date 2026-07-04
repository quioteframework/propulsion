<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Util\PropulsionMigrationManager;
use Propulsion\Generator\Util\MigrationExecutionException;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Direct, Phing-free integration coverage for
 * PropulsionMigrationManager::runMigrationDirection() -- the single shared
 * implementation of "how a migration direction actually executes" that both
 * the (now-deleted) Phing BasePropulsionMigrationTask and the
 * migration:up/migration:down console commands delegate to. See
 * KNOWN_ISSUES.md's migration-ledger redesign notes for the bugs this
 * behavior fixes.
 *
 * This preserves the detailed regression coverage that used to live in
 * PropulsionMigrationTaskTest (removed along with the Phing task classes),
 * calling the shared manager method directly rather than through either
 * entry point -- test/testsuite/generator/command/MigrationCommandsTest.php
 * covers that the console commands wire this method up correctly (including
 * the non-zero-exit-code regression guard), so it doesn't need to re-prove
 * the transaction/ledger semantics themselves here.
 *
 * Runs against whichever platform IntegrationDatabase's shared testcontainer is
 * currently backing (Postgres by default, MySQL under PROPULSION_TEST_DB=mysql --
 * see IntegrationDatabase::currentPlatform()). Two tests are deliberately gated on
 * the platform's PropulsionPlatformInterface::supportsTransactionalDDL() value,
 * since they exist specifically to prove opposite behavior on transactional vs.
 * non-transactional DDL platforms.
 */
class PropulsionMigrationManagerTest extends TestCase
{
    private const DATASOURCE = 'migration_manager_direct';
    private const MIGRATION_TABLE = 'propulsion_migration_manager_test';

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

        $this->dsn = "{$this->platform}:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $this->pdo = new PDO($this->dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS mig_mgr_book');
        $this->pdo->exec($this->platform === 'mysql'
            ? 'CREATE TABLE mig_mgr_book (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL)'
            : 'CREATE TABLE mig_mgr_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)'
        );

        $this->migrationDir = sys_get_temp_dir() . '/propulsion-migration-manager-test-' . uniqid();
        mkdir($this->migrationDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MIGRATION_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS mig_mgr_book');
        }
        if (isset($this->migrationDir) && is_dir($this->migrationDir)) {
            $this->removeDir($this->migrationDir);
        }
        parent::tearDown();
    }

    public function testFullUpDownUpCycleAccumulatesLedgerAndTracksCurrentVersion(): void
    {
        $timestamp = 1720000000;
        $manager = $this->buildManager();

        $upSql = ['ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);'];
        $downSql = ['ALTER TABLE mig_mgr_book DROP COLUMN subtitle;'];

        $runUp = fn () => $manager->runMigrationDirection($timestamp, 'up', [self::DATASOURCE => $upSql[0]]);
        $runDown = fn () => $manager->runMigrationDirection($timestamp, 'down', [self::DATASOURCE => $downSql[0]]);

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
     * Bug fix regression: a statement failure must throw
     * MigrationExecutionException (never signal failure by silently returning),
     * and the failed attempt must be recorded in the ledger without ever
     * counting as "applied".
     */
    public function testStatementFailureThrowsAndDoesNotApplyVersion(): void
    {
        $timestamp = 1720000100;
        $manager = $this->buildManager();

        // The second statement deterministically fails on every platform: you
        // cannot add the same column twice.
        $sql = "ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);\n"
            . 'ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);';

        $threw = false;
        try {
            $manager->runMigrationDirection($timestamp, 'up', [self::DATASOURCE => $sql]);
        } catch (MigrationExecutionException $e) {
            $threw = true;
            $this->assertSame(self::DATASOURCE, $e->getDatasource());
            $this->assertSame($timestamp, $e->getTimestamp());
            $this->assertSame('up', $e->getDirection());
        }
        $this->assertTrue($threw, 'a statement failure must throw MigrationExecutionException');

        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE));

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(1, $ledger);
        $row = $ledger[0];
        $this->assertSame('up', $row['direction']);
        $this->assertFalse($this->isTruthy($row['success']));

        $statementLog = json_decode((string) $row['statement_log'], true);
        $this->assertCount(2, $statementLog);
        $this->assertSame('success', $statementLog[0]['status']);
        $this->assertArrayNotHasKey('error', $statementLog[0]);
        $this->assertSame('failed', $statementLog[1]['status']);
        $this->assertArrayHasKey('error', $statementLog[1]);
    }

    /**
     * Bug fix regression: PropulsionMigrationDownTask used to catch a failed
     * statement's PDOException, log it, and keep going -- if ANY statement in
     * the direction succeeded, the migration was still recorded as fully
     * reverted even though a later statement failed. runMigrationDirection()
     * now stops at the first failure and does not revert the tracked version.
     */
    public function testDownStatementFailureThrowsAndDoesNotRevertVersion(): void
    {
        $timestamp = 1720000200;
        $manager = $this->buildManager();

        $manager->runMigrationDirection($timestamp, 'up', [
            self::DATASOURCE => 'ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);',
        ]);
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE), 'sanity check: up should have applied cleanly');

        // Second statement deterministically fails: the column no longer
        // exists once the first statement has dropped it.
        $downSql = "ALTER TABLE mig_mgr_book DROP COLUMN subtitle;\n"
            . 'ALTER TABLE mig_mgr_book DROP COLUMN subtitle;';

        $threw = false;
        try {
            $manager->runMigrationDirection($timestamp, 'down', [self::DATASOURCE => $downSql]);
        } catch (MigrationExecutionException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a statement failure must throw MigrationExecutionException');

        // The migration must still count as applied (NOT reverted).
        $this->assertSame($timestamp, $manager->getCurrentVersion(self::DATASOURCE));

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $downRow = $ledger[1];
        $this->assertSame('down', $downRow['direction']);
        $this->assertFalse($this->isTruthy($downRow['success']));

        $statementLog = json_decode((string) $downRow['statement_log'], true);
        $this->assertCount(2, $statementLog);
        $this->assertSame('success', $statementLog[0]['status']);
        $this->assertSame('failed', $statementLog[1]['status']);
    }

    /**
     * On a transactional-DDL platform (Postgres in the default test run --
     * PropulsionPlatformInterface::supportsTransactionalDDL() === true), a
     * later statement's failure must roll back everything the migration did,
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

        $timestamp = 1720000300;
        $sql = "ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);\n"
            . 'ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);';

        try {
            $manager->runMigrationDirection($timestamp, 'up', [self::DATASOURCE => $sql]);
            $this->fail('Expected a MigrationExecutionException.');
        } catch (MigrationExecutionException $e) {
            // expected
        }

        $this->assertFalse($this->columnExists('subtitle'), 'the transaction must have rolled back the earlier, would-have-succeeded statement');
    }

    /**
     * On a non-transactional-DDL platform (MySQL, only exercised when this
     * test suite is run with PROPULSION_TEST_DB=mysql), a later statement's
     * failure leaves whatever earlier statements already succeeded applied
     * for real -- an inherent limitation of non-transactional DDL, not
     * something this behavior papers over.
     */
    public function testNonTransactionalPlatformLeavesEarlierStatementAppliedOnFailure(): void
    {
        $manager = $this->buildManager();
        if ($manager->getPlatform(self::DATASOURCE)->supportsTransactionalDDL()) {
            $this->markTestSkipped('This test only applies to a non-transactional-DDL platform; run with PROPULSION_TEST_DB=mysql to exercise it (got: ' . $this->platform . ').');
        }

        $timestamp = 1720000400;
        $sql = "ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);\n"
            . 'ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);';

        try {
            $manager->runMigrationDirection($timestamp, 'up', [self::DATASOURCE => $sql]);
            $this->fail('Expected a MigrationExecutionException.');
        } catch (MigrationExecutionException $e) {
            // expected
        }

        $this->assertTrue($this->columnExists('subtitle'), 'on a non-transactional platform the earlier successful statement must remain applied');
        $this->assertSame(0, $manager->getCurrentVersion(self::DATASOURCE), 'the migration must still be reported as not applied despite the partial schema change');
    }

    /**
     * The ledger's checksum column is a sha256 digest of the exact SQL string
     * executed for a given direction, computed and stored on every run, and
     * differs between two migrations whose SQL differs.
     */
    public function testChecksumIsComputedAndStoredAndDiffersForDifferentSql(): void
    {
        $manager = $this->buildManager();

        $timestampA = 1720000500;
        $upSqlA = 'ALTER TABLE mig_mgr_book ADD COLUMN subtitle VARCHAR(120);';
        $manager->runMigrationDirection($timestampA, 'up', [self::DATASOURCE => $upSqlA]);

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(1, $ledger);
        $checksumA = $ledger[0]['checksum'];
        $this->assertSame(hash('sha256', $upSqlA), $checksumA);
        $this->assertSame(64, strlen($checksumA));

        $timestampB = 1720000600;
        $upSqlB = 'ALTER TABLE mig_mgr_book ADD COLUMN blurb VARCHAR(200);';
        $manager->runMigrationDirection($timestampB, 'up', [self::DATASOURCE => $upSqlB]);

        $ledger = $manager->getMigrationLedger(self::DATASOURCE);
        $this->assertCount(2, $ledger);
        $checksumB = $ledger[1]['checksum'];
        $this->assertSame(hash('sha256', $upSqlB), $checksumB);
        $this->assertNotSame($checksumA, $checksumB);
    }

    /**
     * A datasource with no SQL statements to execute for the requested
     * direction must abort loudly (MigrationExecutionException), not
     * silently proceed as if it had nothing to do.
     */
    public function testEmptyStatementsThrows(): void
    {
        $manager = $this->buildManager();

        $this->expectException(MigrationExecutionException::class);
        $manager->runMigrationDirection(1720000700, 'up', [self::DATASOURCE => '   ']);
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

    private function columnExists(string $column, string $table = 'mig_mgr_book'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (bool) $stmt->fetchColumn();
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

        if (!$manager->migrationTableExists(self::DATASOURCE)) {
            $manager->createMigrationTable(self::DATASOURCE);
        }

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
