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
use Propulsion\Generator\Manager\DataSqlManager;

/**
 * Coverage for PgsqlDataSQLBuilder, the Postgres-specific data-dump SQL
 * builder used by `data:sql` when propulsion.database=pgsql. The existing
 * DataSqlManagerTest only exercises the MySQL builder (chosen there because
 * sqlite has no datasql builder at all); this locks in Postgres-specific
 * behavior the Mysql builder doesn't have: boolean formatting ('t'/'f'), and
 * -- most importantly -- the auto-increment sequence resync SQL
 * (`SELECT pg_catalog.setval(...)`) that a data dump into a table with a
 * native-id-method primary key needs to keep the sequence in sync with the
 * highest imported id.
 */
class PgsqlDataSQLBuilderTest extends TestCase
{
    private string $workDir;
    private string $schemaFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/propulsion-pgsql-datasql-builder-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        $this->schemaFile = $this->workDir . '/schema.xml';
        file_put_contents($this->schemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="pgsql_datasql_test" defaultIdMethod="native">
  <table name="pg_widget" phpName="PgWidget">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
    <column name="active" type="BOOLEAN"/>
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
            dirname(__DIR__, 5) . '/generator/default.php',
            null,
            ['propulsion.database' => 'pgsql']
        );
    }

    public function testBooleanValuesAreFormattedAsPostgresLiterals()
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <PgWidget Id="1" Name="Gadget" Active="true"/>
  <PgWidget Id="2" Name="Gizmo" Active="false"/>
</dataset>
EOT
        );

        $manager = new DataSqlManager($this->buildConfig());
        $sqlFile = $this->workDir . '/dataset.sql';
        $manager->transform([$this->schemaFile], $dataXmlFile, $sqlFile);

        $sql = file_get_contents($sqlFile);
        $this->assertStringContainsString("'t'", $sql);
        $this->assertStringContainsString("'f'", $sql);
    }

    public function testAutoIncrementColumnsGetSequenceResyncedToMaxValue()
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <PgWidget Id="1" Name="Gadget" Active="true"/>
  <PgWidget Id="5" Name="Gizmo" Active="false"/>
  <PgWidget Id="3" Name="Widget" Active="true"/>
</dataset>
EOT
        );

        $manager = new DataSqlManager($this->buildConfig());
        $sqlFile = $this->workDir . '/dataset.sql';
        $manager->transform([$this->schemaFile], $dataXmlFile, $sqlFile);

        $sql = file_get_contents($sqlFile);
        $this->assertMatchesRegularExpression(
            "/SELECT pg_catalog\\.setval\\('[^']*',\\s*5\\)/",
            $sql,
            'The sequence should be resynced to the highest inserted id (5), not the row count (3) or insertion order'
        );
    }
}
