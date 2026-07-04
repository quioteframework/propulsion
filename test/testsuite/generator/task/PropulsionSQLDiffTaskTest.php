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
use Phing\Type\FileSet as PropulsionFileSet;
use Propulsion\Generator\Task\PropulsionSQLDiffTask;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Builder\Util\XmlToAppData;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseComparator;

require_once dirname(__DIR__, 3) . '/tools/helpers/PhingGeneratorTaskTestHelper.php';
require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Coverage for the legacy Phing PropulsionSQLDiffTask (`propel-gen diff` /
 * `sql-diff`) -- see KNOWN_ISSUES.md's "Phing Task classes" entry.
 *
 * Two angles, per the underlying engine's two real use cases:
 *  - testTwoSchemaVersionsProduceExpectedStructuralDiff: the schema-diffing engine
 *    (Propulsion\Generator\Model\Diff\PropulsionDatabaseComparator, the same class
 *    PropulsionSQLDiffTask::main() calls) applied directly to two schema.xml
 *    versions with a real structural diff (added column, changed column size,
 *    added FK) -- confirms the emitted DDL actually reflects the diff.
 *  - testLiveDatabaseDriftAgainstSchemaGeneratesMigrationClass: the Task itself,
 *    end-to-end, diffing a live (deliberately out-of-date) Postgres schema against
 *    a newer schema.xml, verifying the generated migration class' up/down SQL.
 *
 * PropulsionSQLDiffTask only supports "live database vs schema.xml" (it reads
 * connections from a buildtime-conf; there is no "two schema.xml files" mode on the
 * Task itself), hence exercising the shared comparator directly for the first case.
 */
class PropulsionSQLDiffTaskTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = dirname(__DIR__, 3) . '/fixtures/generator-parity-diff';
    }

    public function testTwoSchemaVersionsProduceExpectedStructuralDiff(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $config = GeneratorConfig::createFromPropertiesFile(
            $repoRoot . '/generator/default.properties',
            null,
            ['propel.database' => 'pgsql']
        );
        $platform = $config->getConfiguredPlatform();

        $fromDb = $this->loadDatabase($this->fixtureDir . '/schema-v1.xml', $platform, $config);
        $toDb = $this->loadDatabase($this->fixtureDir . '/schema-v2.xml', $platform, $config);

        $diff = PropulsionDatabaseComparator::computeDiff($fromDb, $toDb);
        $this->assertNotFalse($diff, 'A structural diff should be detected between schema-v1 and schema-v2');

        $upSql = $platform->getModifyDatabaseDDL($diff);
        $downSql = $platform->getModifyDatabaseDDL($diff->getReverseDiff());

        // Added columns (author_id FK column, price)
        $this->assertMatchesRegularExpression('/ADD\s+(COLUMN\s+)?"?author_id"?/i', $upSql);
        $this->assertMatchesRegularExpression('/ADD\s+(COLUMN\s+)?"?price"?/i', $upSql);

        // Changed column size (title VARCHAR(100) -> VARCHAR(255))
        $this->assertMatchesRegularExpression('/"?title"?[^\n]*VARCHAR\(255\)/i', $upSql);

        // Added foreign key to diff_author
        $this->assertMatchesRegularExpression('/FOREIGN KEY/i', $upSql);
        $this->assertStringContainsString('diff_author', $upSql);

        // Down migration should reverse it: drop the added columns/FK
        $this->assertMatchesRegularExpression('/DROP\s+(COLUMN\s+)?"?author_id"?/i', $downSql);
        $this->assertMatchesRegularExpression('/DROP\s+(COLUMN\s+)?"?price"?/i', $downSql);
    }

    public function testLiveDatabaseDriftAgainstSchemaGeneratesMigrationClass(): void
    {
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $dbName = 'propulsion_test_diff_task';
        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote($dbName))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . $dbName);
        }

        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=$dbName";
        $pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Deliberately out-of-date: the live DB only has schema-v1's shape (no
        // author_id/price columns, no FK) -- schema-v2.xml is the "desired" state.
        $pdo->exec('DROP TABLE IF EXISTS diff_book');
        $pdo->exec('DROP TABLE IF EXISTS diff_author');
        $pdo->exec('CREATE TABLE diff_author (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $pdo->exec('CREATE TABLE diff_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)');

        $migrationDir = sys_get_temp_dir() . '/propulsion-sqldiff-task-test-' . uniqid();
        mkdir($migrationDir, 0777, true);

        try {
            $buildtimeConfXml = sprintf(
                '<config><propel><datasources default="diff_parity">'
                . '<datasource id="diff_parity"><adapter>pgsql</adapter>'
                . '<connection><dsn>%s</dsn><user>propulsion</user><password>propulsion</password></connection>'
                . '</datasource></datasources></propel></config>',
                htmlspecialchars($dsn, ENT_XML1)
            );

            $project = PhingGeneratorTaskTestHelper::bootProject([
                'propel.database' => 'pgsql',
                'propel.buildtimeConf' => base64_encode($buildtimeConfXml),
            ]);

            $task = new PropulsionSQLDiffTask();
            PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-sql-diff');
            $task->setOutputDirectory(new PhingFile($migrationDir));
            $task->setTargetDatabase('pgsql');
            $task->setDatabaseName('diff_parity');
            $task->setValidate(false);

            $fileset = new PropulsionFileSet();
            $fileset->setProject($project);
            $fileset->setDir(new PhingFile($this->fixtureDir));
            $fileset->createInclude()->setName('schema-v2.xml');
            $task->addSchemaFileset($fileset);

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

            $migrationFiles = glob($migrationDir . '/PropulsionMigration_*.php');
            $this->assertNotEmpty($migrationFiles, 'A migration class file should have been generated');

            $body = file_get_contents($migrationFiles[0]);
            $this->assertStringContainsString('getUpSQL', $body);
            $this->assertStringContainsString('getDownSQL', $body);
            $this->assertMatchesRegularExpression('/author_id/i', $body);
            $this->assertMatchesRegularExpression('/price/i', $body);
            $this->assertMatchesRegularExpression('/FOREIGN KEY/i', $body);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS diff_book');
            $pdo->exec('DROP TABLE IF EXISTS diff_author');
            $this->removeDir($migrationDir);
        }
    }

    private function loadDatabase(string $schemaFile, $platform, GeneratorConfig $config)
    {
        $xmlParser = new XmlToAppData($platform, null, 'utf-8');
        $xmlParser->setGeneratorConfig($config);
        $appData = $xmlParser->parseFile($schemaFile);
        $appData->doFinalInitialization();
        $database = $appData->getDatabase(null, false);
        $database->setPlatform($platform);

        return $database;
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
