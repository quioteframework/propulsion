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
 * Coverage for `data:sql --database=sqlite`, which used to throw before
 * SqliteDataSQLBuilder existed: `propulsion.builder.datasql.class`
 * (generator/default.php) templates on `${propulsion.database}`, and with no
 * `propulsion.builder.datasql.sqlite.class` entry or class registered, the
 * unresolved placeholder reached GeneratorConfig::getClassname() and came out
 * as the literal, garbled `...sqlite.Class}` from the bug report.
 */
class SqliteDataSQLBuilderTest extends TestCase
{
    private string $workDir;
    private string $schemaFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/propulsion-sqlite-datasql-builder-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        $this->schemaFile = $this->workDir . '/schema.xml';
        file_put_contents($this->schemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="sqlite_datasql_test" defaultIdMethod="native">
  <table name="sqlite_widget" phpName="SqliteWidget">
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
            ['propulsion.database' => 'sqlite']
        );
    }

    public function testDoesNotThrowAndProducesSqliteAppropriateInserts()
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <SqliteWidget Id="1" Name="Gadget" Active="true"/>
  <SqliteWidget Id="2" Name="Gizmo" Active="false"/>
</dataset>
EOT
        );

        $manager = new DataSqlManager($this->buildConfig());
        $sqlFile = $this->workDir . '/dataset.sql';
        $rowCount = $manager->transform([$this->schemaFile], $dataXmlFile, $sqlFile);

        $this->assertSame(2, $rowCount);
        $sql = file_get_contents($sqlFile);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('Gadget', $sql);

        // SqlitePlatform's own bracket-quoting convention, not Postgres-style
        // double quotes -- confirms the builder resolved SqlitePlatform via
        // propulsion.platform.sqlite.class, not some other adapter's quoting.
        $this->assertStringContainsString('[sqlite_widget]', $sql);
        $this->assertStringNotContainsString('"sqlite_widget"', $sql);

        // Plain integers, not Postgres-style 't'/'f' literals -- inherited
        // straight from the base DataSQLBuilder, no override needed.
        $this->assertStringContainsString('(1,\'Gadget\',1);', $sql);
        $this->assertStringContainsString('(2,\'Gizmo\',0);', $sql);
        $this->assertStringNotContainsString("'t'", $sql);
        $this->assertStringNotContainsString("'f'", $sql);
    }
}
