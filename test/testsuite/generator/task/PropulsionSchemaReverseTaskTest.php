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
use Propulsion\Generator\Task\PropulsionSchemaReverseTask;

require_once dirname(__DIR__, 3) . '/tools/helpers/PhingGeneratorTaskTestHelper.php';
require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Integration coverage for the legacy Phing PropulsionSchemaReverseTask
 * (`propel-gen reverse`) -- see KNOWN_ISSUES.md's "Phing Task classes" entry.
 * There is no console-app equivalent to compare against (bin/propulsion has no
 * "reverse" subcommand), so this instead builds a small real schema directly in the
 * shared Postgres testcontainer (see IntegrationDatabase) and verifies the task
 * reverse-engineers a schema.xml with the right tables, columns, types, and FK.
 */
class PropulsionSchemaReverseTaskTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_reverse_task';

    private ?PDO $pdo = null;
    private string $outputFile;

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

        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME;
        $this->pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS rev_book');
        $this->pdo->exec('DROP TABLE IF EXISTS rev_author');
        $this->pdo->exec('CREATE TABLE rev_author (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE rev_book ('
            . 'id SERIAL PRIMARY KEY, '
            . 'title VARCHAR(255) NOT NULL, '
            . 'author_id INTEGER REFERENCES rev_author(id), '
            . 'is_active BOOLEAN NOT NULL DEFAULT true'
            . ')'
        );

        $this->outputFile = sys_get_temp_dir() . '/propulsion-schema-reverse-task-test-' . uniqid() . '.xml';
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS rev_book');
            $this->pdo->exec('DROP TABLE IF EXISTS rev_author');
        }
        if (is_file($this->outputFile)) {
            unlink($this->outputFile);
        }
        parent::tearDown();
    }

    public function testReverseEngineersTablesColumnsTypesAndForeignKey(): void
    {
        $conn = IntegrationDatabase::containerConnection();
        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME;

        $project = PhingGeneratorTaskTestHelper::bootProject(['propel.database' => 'pgsql']);

        $task = new PropulsionSchemaReverseTask();
        PhingGeneratorTaskTestHelper::configureTask($task, $project, 'propel-schema-reverse');
        $task->setUrl($dsn);
        $task->setUserid('propulsion');
        $task->setPassword('propulsion');
        $task->setDatabaseName('reverse_test');
        $task->setOutputFile(new PhingFile($this->outputFile));

        PhingGeneratorTaskTestHelper::withoutDeprecationNotices(fn () => $task->main());

        $this->assertFileExists($this->outputFile);
        $xml = simplexml_load_file($this->outputFile);
        $this->assertNotFalse($xml, 'Reversed schema.xml should be well-formed');

        $tablesByName = [];
        foreach ($xml->table as $table) {
            $tablesByName[(string) $table['name']] = $table;
        }

        $this->assertArrayHasKey('rev_author', $tablesByName);
        $this->assertArrayHasKey('rev_book', $tablesByName);

        // --- rev_author ------------------------------------------------------
        $authorColumns = [];
        foreach ($tablesByName['rev_author']->column as $col) {
            $authorColumns[(string) $col['name']] = $col;
        }
        $this->assertArrayHasKey('id', $authorColumns);
        $this->assertSame('true', (string) $authorColumns['id']['primaryKey']);
        $this->assertArrayHasKey('name', $authorColumns);
        $this->assertSame('VARCHAR', (string) $authorColumns['name']['type']);
        $this->assertSame('100', (string) $authorColumns['name']['size']);
        $this->assertSame('true', (string) $authorColumns['name']['required']);

        // --- rev_book: types + FK ---------------------------------------------
        $bookColumns = [];
        foreach ($tablesByName['rev_book']->column as $col) {
            $bookColumns[(string) $col['name']] = $col;
        }
        $this->assertSame('VARCHAR', (string) $bookColumns['title']['type']);
        $this->assertSame('INTEGER', (string) $bookColumns['author_id']['type']);
        $this->assertSame('BOOLEAN', (string) $bookColumns['is_active']['type']);

        $this->assertGreaterThan(0, count($tablesByName['rev_book']->{'foreign-key'}));
        $fk = $tablesByName['rev_book']->{'foreign-key'}[0];
        $this->assertSame('rev_author', (string) $fk['foreignTable']);
        $this->assertSame('author_id', (string) $fk->reference['local']);
        $this->assertSame('id', (string) $fk->reference['foreign']);
    }
}
