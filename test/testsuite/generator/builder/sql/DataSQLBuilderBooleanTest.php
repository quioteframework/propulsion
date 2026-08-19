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
 * `DataSQLBuilder::getBooleanSql()` (the base class) used to be a bare
 * `(int) $value` cast. Every value reaching it has already been stringified
 * into an XML attribute by a previous `data:dump`, and PDO_PGSQL specifically
 * never converts a boolean column to a native PHP bool -- it hands back the
 * literal strings "t"/"f". `(int) 't'` and `(int) 'f'` are **both** `0`: a
 * `true` value dumped from Postgres and converted to any platform using the
 * base method unmodified -- Mysql, Mssql, Sqlsrv, Oracle, and the new Sqlite
 * builder -- silently became `false`.
 *
 * Exercised through MysqlDataSQLBuilder, the same "no override needed" empty
 * subclass PgsqlDataSQLBuilderTest's docblock already relies on for the
 * *other* half of its own coverage -- what's under test here is the base
 * class's own getBooleanSql()/parseBoolean(), not anything Mysql-specific.
 */
class DataSQLBuilderBooleanTest extends TestCase
{
    private string $workDir;
    private string $schemaFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/propulsion-datasqlbuilder-boolean-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        $this->schemaFile = $this->workDir . '/schema.xml';
        file_put_contents($this->schemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="datasqlbuilder_boolean_test" defaultIdMethod="native">
  <table name="bool_widget" phpName="BoolWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
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

    private function transform(string $activeValue): string
    {
        $dataXmlFile = $this->workDir . '/dataset.xml';
        file_put_contents($dataXmlFile, <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <BoolWidget Id="1" Active="{$activeValue}"/>
</dataset>
EOT
        );

        $config = GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 5) . '/generator/default.php',
            null,
            ['propulsion.database' => 'mysql']
        );
        $manager = new DataSqlManager($config);
        $sqlFile = $this->workDir . '/dataset.sql';
        $manager->transform([$this->schemaFile], $dataXmlFile, $sqlFile);

        $contents = file_get_contents($sqlFile);
        $this->assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function booleanValueProvider(): array
    {
        return [
            // The regression case: a value dumped straight from a live
            // Postgres connection, converted to a non-Postgres target.
            'Postgres on-the-wire true ("t")' => ['t', 1],
            'Postgres on-the-wire false ("f")' => ['f', 0],
            'plain "1"' => ['1', 1],
            'plain "0"' => ['0', 0],
            '"true"' => ['true', 1],
            '"false"' => ['false', 0],
            'mixed case "True"/"FALSE" still parsed as words' => ['FALSE', 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('booleanValueProvider')]
    public function testBooleanIsParsedFromEveryOnTheWireSpelling(string $activeValue, int $expected)
    {
        $sql = $this->transform($activeValue);

        $this->assertStringContainsString("(1,{$expected});", $sql);
    }
}
