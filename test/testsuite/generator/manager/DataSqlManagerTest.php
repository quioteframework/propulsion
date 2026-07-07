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
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Manager\DataSqlManager;

/**
 * Unit-level coverage for Propulsion\Generator\Manager\DataSqlManager::transform(),
 * which converts an XML dataset (as produced by DataDumpManager) into INSERT SQL
 * without ever needing a live database connection -- see
 * DataDumpAndSqlManagerTest for coverage of the full dump-to-sql-to-database
 * round trip against a real Postgres testcontainer.
 */
class DataSqlManagerTest extends TestCase
{
    private string $workDir;
    private string $schemaFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/propulsion-datasql-manager-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        $this->schemaFile = $this->workDir . '/schema.xml';
        file_put_contents($this->schemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="datasql_test" defaultIdMethod="native">
  <table name="sql_widget" phpName="SqlWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
  </table>
</database>
EOT
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->workDir);
        }
        parent::tearDown();
    }

    private function buildConfig(): GeneratorConfig
    {
        return GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.php',
            null,
            ['propulsion.database' => 'mysql']
        );
    }

    public function testTransformProducesInsertSqlForEachDataRow(): void
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <SqlWidget Id="1" Name="Gadget"/>
  <SqlWidget Id="2" Name="Gizmo"/>
</dataset>
EOT
        );

        $manager = new DataSqlManager($this->buildConfig());
        $sqlFile = $this->workDir . '/dataset.sql';
        $rowCount = $manager->transform([$this->schemaFile], $dataXmlFile, $sqlFile);

        $this->assertSame(2, $rowCount);
        $this->assertFileExists($sqlFile);
        $sql = file_get_contents($sqlFile);
        $this->assertStringContainsString('sql_widget', $sql);
        $this->assertStringContainsString('Gadget', $sql);
        $this->assertStringContainsString('Gizmo', $sql);
    }

    public function testTransformThrowsWhenDataXmlFileIsMissing(): void
    {
        $manager = new DataSqlManager($this->buildConfig());

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Data XML file not found');

        $manager->transform([$this->schemaFile], $this->workDir . '/does-not-exist.xml', $this->workDir . '/out.sql');
    }

    public function testTransformThrowsWhenDataXmlFileIsMalformed(): void
    {
        $dataXmlFile = $this->workDir . '/broken.xml';
        file_put_contents($dataXmlFile, 'not-xml-at-all');

        $manager = new DataSqlManager($this->buildConfig());

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Unable to parse data XML file');

        $manager->transform([$this->schemaFile], $dataXmlFile, $this->workDir . '/out.sql');
    }

    public function testTransformThrowsWhenNamedDatabaseIsNotFound(): void
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, '<dataset><SqlWidget Id="1" Name="Gadget"/></dataset>');

        $manager = new DataSqlManager($this->buildConfig());

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('No database named "nonexistent" found');

        $manager->transform([$this->schemaFile], $dataXmlFile, $this->workDir . '/out.sql', 'nonexistent');
    }

    public function testTransformThrowsWhenRowReferencesUnknownTable(): void
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, '<dataset><UnknownTable Id="1"/></dataset>');

        $manager = new DataSqlManager($this->buildConfig());

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('references table "UnknownTable"');

        $manager->transform([$this->schemaFile], $dataXmlFile, $this->workDir . '/out.sql');
    }

    public function testTransformThrowsWhenRowReferencesUnknownColumn(): void
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, '<dataset><SqlWidget Id="1" Bogus="x"/></dataset>');

        $manager = new DataSqlManager($this->buildConfig());

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('references column "Bogus"');

        $manager->transform([$this->schemaFile], $dataXmlFile, $this->workDir . '/out.sql');
    }
}
