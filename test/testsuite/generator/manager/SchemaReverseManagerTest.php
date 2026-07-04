<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Manager\SchemaReverseManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Integration coverage for the console-app `schema:reverse` path
 * (Propulsion\Generator\Manager\SchemaReverseManager -- see SchemaReverseCommand).
 *
 * Mirrors PropulsionSchemaReverseTaskTest's Phing-task coverage (same two-table,
 * one-FK Postgres schema, same assertions on tables/columns/types/FK) so the two
 * code paths can be checked for structural/functional equivalence; per KNOWN_ISSUES.md
 * a byte-for-byte comparison isn't required since the two writers may format the XML
 * slightly differently.
 */
class SchemaReverseManagerTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_reverse_manager';

    private ?PDO $pdo = null;
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
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS rev_book');
            $this->pdo->exec('DROP TABLE IF EXISTS rev_author');
        }
        parent::tearDown();
    }

    private function buildConfig(): GeneratorConfig
    {
        return GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.properties',
            null,
            ['propel.database' => 'pgsql']
        );
    }

    public function testReverseEngineersTablesColumnsTypesAndForeignKey(): void
    {
        $manager = new SchemaReverseManager($this->buildConfig(), $this->dsn, 'propulsion', 'propulsion');

        $doc = $manager->reverse('reverse_test');
        $xml = simplexml_load_string($doc->saveXML());
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

    public function testGenerateWritesXmlFileToDisk(): void
    {
        $outputFile = sys_get_temp_dir() . '/propulsion-schema-reverse-manager-test-' . uniqid() . '.xml';

        try {
            $manager = new SchemaReverseManager($this->buildConfig(), $this->dsn, 'propulsion', 'propulsion');
            $manager->generate('reverse_test', $outputFile);

            $this->assertFileExists($outputFile);
            $xml = simplexml_load_file($outputFile);
            $this->assertNotFalse($xml);
            $names = [];
            foreach ($xml->table as $table) {
                $names[] = (string) $table['name'];
            }
            $this->assertContains('rev_author', $names);
            $this->assertContains('rev_book', $names);
        } finally {
            if (is_file($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    public function testAddValidatorsAddsRequiredAndMaxLengthRules(): void
    {
        $manager = new SchemaReverseManager($this->buildConfig(), $this->dsn, 'propulsion', 'propulsion');

        $doc = $manager->reverse('reverse_test', SchemaReverseManager::parseValidatorBits('required,maxlength'));
        $xml = simplexml_load_string($doc->saveXML());

        $tablesByName = [];
        foreach ($xml->table as $table) {
            $tablesByName[(string) $table['name']] = $table;
        }

        $validatorsByColumn = [];
        foreach ($tablesByName['rev_author']->validator as $validator) {
            $validatorsByColumn[(string) $validator['column']] = $validator;
        }

        $this->assertArrayHasKey('name', $validatorsByColumn);
        $ruleNames = [];
        foreach ($validatorsByColumn['name']->rule as $rule) {
            $ruleNames[] = (string) $rule['name'];
        }
        $this->assertContains('required', $ruleNames);
        $this->assertContains('maxLength', $ruleNames);
    }

    public function testInvalidValidatorTokenThrows(): void
    {
        $this->expectException(\Propulsion\Generator\Exception\EngineException::class);
        SchemaReverseManager::parseValidatorBits('bogus');
    }
}
