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
use Propulsion\Generator\Task\PropulsionSQLTask;
use Propulsion\Generator\Task\PropulsionSQLExec;
use Propulsion\Generator\Task\PropulsionGraphvizTask;
use Propulsion\Generator\Task\PropulsionDataDumpTask;
use Propulsion\Generator\Task\PropulsionDataSQLTask;

require_once dirname(__DIR__, 3) . '/tools/helpers/PhingGeneratorTaskTestHelper.php';
require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Lower-priority smoke coverage (per KNOWN_ISSUES.md's "Phing Task classes" entry):
 * PropulsionSQLTask, PropulsionSQLExec, PropulsionGraphvizTask, PropulsionDataDumpTask,
 * PropulsionDataSQLTask just need to run without fataling on minimal real input --
 * this is not full output-parity verification the way OM/SchemaReverse/Diff/
 * Migrations get, just confirmation each still works at all.
 */
class LowerPriorityTaskSmokeTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = dirname(__DIR__, 3) . '/fixtures/generator-parity';
    }

    private function addSchemaFileset($task, $project): void
    {
        $fileset = new PropulsionFileSet();
        $fileset->setProject($project);
        $fileset->setDir(new PhingFile($this->fixtureDir));
        $fileset->createInclude()->setName('schema.xml');
        $task->addSchemaFileset($fileset);
    }

    public function testSqlTaskGeneratesDDL(): void
    {
        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);
        $outDir = sys_get_temp_dir() . '/propulsion-sqltask-smoke-' . uniqid();
        mkdir($outDir, 0777, true);

        try {
            $task = new PropulsionSQLTask();
            PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-sql');
            $task->setOutputDirectory(new PhingFile($outDir));
            $task->setTargetDatabase('pgsql');
            $task->setValidate(false);
            $task->setSqlDbMap(new PhingFile($outDir . '/sqldb.map'));

            $mapper = $task->createMapper();
            $mapper->setType('glob');
            $mapper->setFrom('*schema.xml');
            $mapper->setTo('*.sql');

            $this->addSchemaFileset($task, $project);

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

            $sqlFiles = glob($outDir . '/*.sql');
            $this->assertNotEmpty($sqlFiles, 'PropulsionSQLTask should produce at least one .sql file');
            $sql = file_get_contents($sqlFiles[0]);
            $this->assertMatchesRegularExpression('/CREATE TABLE/i', $sql);
            $this->assertFileExists($outDir . '/sqldb.map');
        } finally {
            $this->removeDir($outDir);
        }
    }

    public function testSqlExecInsertsGeneratedDDLIntoRealDatabase(): void
    {
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $dbName = 'propulsion_test_sqlexec_smoke';
        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote($dbName))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . $dbName);
        }
        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=$dbName";
        $pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS parity_book');
        $pdo->exec('DROP TABLE IF EXISTS parity_author');

        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);
        $outDir = sys_get_temp_dir() . '/propulsion-sqlexec-smoke-' . uniqid();
        mkdir($outDir, 0777, true);

        try {
            $sqlTask = new PropulsionSQLTask();
            PhingGeneratorTaskTestHelper::configureTask($sqlTask, $project, 'propel-sql');
            $sqlTask->setOutputDirectory(new PhingFile($outDir));
            $sqlTask->setTargetDatabase('pgsql');
            $sqlTask->setValidate(false);
            $sqlTask->setSqlDbMap(new PhingFile($outDir . '/sqldb.map'));
            $mapper = $sqlTask->createMapper();
            $mapper->setType('glob');
            $mapper->setFrom('*schema.xml');
            $mapper->setTo('*.sql');
            $this->addSchemaFileset($sqlTask, $project);
            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $sqlTask->main());

            $execTask = new PropulsionSQLExec();
            PhingGeneratorTaskTestHelper::configureTask($execTask, $project, 'propel-sql-exec');
            $execTask->setSqlDbMap(new PhingFile($outDir . '/sqldb.map'));
            $execTask->setSrcDir(new PhingFile($outDir));
            $execTask->setUrl($dsn);
            $execTask->setUserid('propulsion');
            $execTask->setPassword('propulsion');
            $execTask->setAutoCommit(true);
            $execTask->setOnerror('abort');

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $execTask->main());

            $tableExists = $pdo->query(
                "SELECT 1 FROM information_schema.tables WHERE table_name = 'parity_author'"
            )->fetchColumn();
            $this->assertNotFalse($tableExists, 'PropulsionSQLExec should have created parity_author for real');
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS parity_book');
            $pdo->exec('DROP TABLE IF EXISTS parity_author');
            $this->removeDir($outDir);
        }
    }

    public function testGraphvizTaskGeneratesDotFile(): void
    {
        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);
        $outDir = sys_get_temp_dir() . '/propulsion-graphviz-smoke-' . uniqid();
        mkdir($outDir, 0777, true);

        try {
            $task = new PropulsionGraphvizTask();
            PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-graphviz');
            $task->setOutputDirectory(new PhingFile($outDir));
            $task->setTargetDatabase('pgsql');
            $task->setValidate(false);
            $this->addSchemaFileset($task, $project);

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

            $dotFiles = glob($outDir . '/*.dot');
            $this->assertNotEmpty($dotFiles, 'PropulsionGraphvizTask should produce a .dot file');
            $dot = file_get_contents($dotFiles[0]);
            $this->assertStringContainsString('digraph G', $dot);
            $this->assertStringContainsString('parity_book', $dot);
        } finally {
            $this->removeDir($outDir);
        }
    }

    public function testDataDumpTaskDumpsRealRowsToXml(): void
    {
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $dbName = 'propulsion_test_datadump_smoke';
        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote($dbName))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . $dbName);
        }
        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=$dbName";
        $pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS parity_book');
        $pdo->exec('DROP TABLE IF EXISTS parity_author');
        $pdo->exec('CREATE TABLE parity_author (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $pdo->exec('CREATE TABLE parity_book (id INTEGER PRIMARY KEY, title VARCHAR(255) NOT NULL, author_id INTEGER, created_at TIMESTAMP, updated_at TIMESTAMP)');
        $pdo->exec("INSERT INTO parity_author (id, name) VALUES (1, 'Jane Doe')");

        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);
        $outDir = sys_get_temp_dir() . '/propulsion-datadump-smoke-' . uniqid();
        mkdir($outDir, 0777, true);

        try {
            $task = new PropulsionDataDumpTask();
            PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-data-dump');
            $task->setOutputDirectory(new PhingFile($outDir));
            $task->setTargetDatabase('pgsql');
            $task->setValidate(false);
            $task->setDatabaseUrl($dsn);
            $task->setDatabaseUser('propulsion');
            $task->setDatabasePassword('propulsion');

            $mapper = $task->createMapper();
            $mapper->setType('glob');
            $mapper->setFrom('*schema.xml');
            $mapper->setTo('*data.xml');

            $this->addSchemaFileset($task, $project);

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

            $dataFiles = glob($outDir . '/*data.xml');
            $this->assertNotEmpty($dataFiles, 'PropulsionDataDumpTask should produce a data.xml file');
            $xml = file_get_contents($dataFiles[0]);
            $this->assertStringContainsString('Jane Doe', $xml);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS parity_book');
            $pdo->exec('DROP TABLE IF EXISTS parity_author');
            $this->removeDir($outDir);
        }
    }

    public function testDataSqlTaskConvertsDataXmlToInsertStatements(): void
    {
        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);
        $outDir = sys_get_temp_dir() . '/propulsion-datasql-smoke-' . uniqid();
        mkdir($outDir, 0777, true);

        try {
            $dataXmlFile = $outDir . '/schema-data.xml';
            file_put_contents($dataXmlFile, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dataset name="all">
  <ParityAuthor Id="1" Name="Jane Doe"/>
</dataset>
XML
            );

            $datadbmap = $outDir . '/datadb.map';
            file_put_contents($datadbmap, "schema-data.xml=generator_parity\n");

            $task = new PropulsionDataSQLTask();
            PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-data-sql');
            $task->setOutputDirectory(new PhingFile($outDir));
            $task->setTargetDatabase('pgsql');
            $task->setValidate(false);
            $task->setSqlDbMap(new PhingFile($outDir . '/sqldb.map'));
            $task->setDataDbMap(new PhingFile($datadbmap));
            $task->setSrcDir(new PhingFile($outDir));

            $mapper = $task->createMapper();
            $mapper->setType('glob');
            $mapper->setFrom('*data.xml');
            $mapper->setTo('*.sql');

            $this->addSchemaFileset($task, $project);

            PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

            $sqlFiles = glob($outDir . '/*.sql');
            $this->assertNotEmpty($sqlFiles, 'PropulsionDataSQLTask should produce an INSERT sql file');
            $sql = file_get_contents($sqlFiles[0]);
            $this->assertMatchesRegularExpression('/INSERT INTO/i', $sql);
            $this->assertStringContainsString('Jane Doe', $sql);
        } finally {
            $this->removeDir($outDir);
        }
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
