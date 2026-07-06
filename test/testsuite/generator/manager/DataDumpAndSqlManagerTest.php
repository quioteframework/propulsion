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
use Propulsion\Generator\Manager\DataDumpManager;
use Propulsion\Generator\Manager\DataSqlManager;
use Propulsion\Generator\Manager\SqlExecManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end coverage for the console-app `data:dump`/`data:sql` path
 * (Propulsion\Generator\Manager\{DataDumpManager,DataSqlManager}), the plain-PHP
 * replacement for the Phing-based PropulsionDataDumpTask/PropulsionDataSQLTask
 * pair -- minus their Phing "datadbmap"/"sqldbmap" multi-database file-routing
 * machinery, and using DOMDocument instead of XmlToDataSQL's
 * Phing\Parser\ExpatParser-based SAX handler.
 *
 * Drives the full round trip: seed a live table -> dump it to an XML dataset ->
 * convert that dataset to INSERT SQL -> execute the SQL against a *different*,
 * empty table -> confirm the rows came through correctly. This is the
 * strongest proof that both halves of the pair (and the DataSQLBuilder classes
 * they drive, which reference the DataRow/ColumnValue value objects recreated
 * alongside this port) actually work together, not just in isolation.
 */
class DataDumpAndSqlManagerTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_datadump_manager';

    private ?PDO $pdo = null;
    private string $dsn;
    private string $workDir;
    private string $schemaFile;

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
        $this->pdo->exec('DROP TABLE IF EXISTS dump_widget');
        $this->pdo->exec(
            'CREATE TABLE dump_widget (id INTEGER PRIMARY KEY, name VARCHAR(50) NOT NULL, in_stock BOOLEAN NOT NULL, price NUMERIC(10,2))'
        );
        $this->pdo->exec("INSERT INTO dump_widget (id, name, in_stock, price) VALUES (1, 'Gadget', true, 9.99)");
        $this->pdo->exec("INSERT INTO dump_widget (id, name, in_stock, price) VALUES (2, 'Gizmo', false, NULL)");

        $this->workDir = sys_get_temp_dir() . '/propulsion-datadump-manager-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        $this->schemaFile = $this->workDir . '/schema.xml';
        file_put_contents($this->schemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="datadump_test" defaultIdMethod="native">
  <table name="dump_widget" phpName="DumpWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
    <column name="in_stock" type="BOOLEAN" required="true"/>
    <column name="price" type="DECIMAL" size="10" scale="2"/>
  </table>
</database>
EOT
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS dump_widget');
            $this->pdo->exec('DROP TABLE IF EXISTS dump_widget_target');
        }
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
            ['propulsion.database' => 'pgsql']
        );
    }

    public function testDumpProducesXmlDatasetWithExpectedRowsAndTypes(): void
    {
        $manager = new DataDumpManager($this->buildConfig(), $this->dsn, 'propulsion', 'propulsion');
        $outFile = $this->workDir . '/dataset.xml';

        $rowCount = $manager->dump([$this->schemaFile], $outFile);

        $this->assertSame(2, $rowCount);
        $this->assertFileExists($outFile);

        $xml = simplexml_load_file($outFile);
        $this->assertNotFalse($xml);
        $this->assertCount(2, $xml->DumpWidget);

        $rowsById = [];
        foreach ($xml->DumpWidget as $row) {
            $rowsById[(string) $row['Id']] = $row;
        }

        $this->assertSame('Gadget', (string) $rowsById['1']['Name']);
        $this->assertSame('9.99', (string) $rowsById['1']['Price']);
        // NULL price on row 2 should be omitted as an attribute entirely, not
        // written as an empty string (matching the original Task's behavior).
        $this->assertFalse(isset($rowsById['2']['Price']));
    }

    public function testFullRoundTripFromLiveTableThroughDumpAndSqlToNewTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS dump_widget_target');
        $this->pdo->exec(
            'CREATE TABLE dump_widget_target (id INTEGER PRIMARY KEY, name VARCHAR(50) NOT NULL, in_stock BOOLEAN NOT NULL, price NUMERIC(10,2))'
        );

        // Second schema, describing the *target* table under the same phpNames
        // dump_widget used -- data:sql only needs the schema to resolve
        // phpName -> real column name/type, not the literal table name to
        // match, so pointing DataSQLBuilder at "dump_widget_target" via a
        // schema whose table name differs from the source proves the round
        // trip isn't accidentally relying on the two tables sharing a name.
        $targetSchemaFile = $this->workDir . '/target-schema.xml';
        file_put_contents($targetSchemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="datadump_test" defaultIdMethod="native">
  <table name="dump_widget_target" phpName="DumpWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
    <column name="in_stock" type="BOOLEAN" required="true"/>
    <column name="price" type="DECIMAL" size="10" scale="2"/>
  </table>
</database>
EOT
        );

        $dumpManager = new DataDumpManager($this->buildConfig(), $this->dsn, 'propulsion', 'propulsion');
        $datasetFile = $this->workDir . '/dataset.xml';
        $dumpManager->dump([$this->schemaFile], $datasetFile);

        $sqlManager = new DataSqlManager($this->buildConfig());
        $sqlFile = $this->workDir . '/dataset.sql';
        $rowCount = $sqlManager->transform([$targetSchemaFile], $datasetFile, $sqlFile);

        $this->assertSame(2, $rowCount);
        $this->assertFileExists($sqlFile);
        $this->assertStringContainsString('dump_widget_target', file_get_contents($sqlFile));

        $execManager = new SqlExecManager($this->dsn, 'propulsion', 'propulsion', autocommit: true);
        $execManager->execute([$sqlFile]);

        $rows = $this->pdo->query('SELECT id, name, in_stock, price FROM dump_widget_target ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('Gadget', $rows[0]['name']);
        $this->assertTrue((bool) $rows[0]['in_stock']);
        $this->assertEquals(9.99, $rows[0]['price']);
        $this->assertSame('Gizmo', $rows[1]['name']);
        $this->assertFalse((bool) $rows[1]['in_stock']);
        $this->assertNull($rows[1]['price']);
    }
}
