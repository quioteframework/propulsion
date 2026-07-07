<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Propulsion\Generator\Command\DataDumpCommand;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end CommandTester coverage for `bin/propulsion data:dump`, which had
 * no test at all, against a real Postgres testcontainer.
 */
class DataDumpCommandTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_data_dump_command';

    private ?PDO $pdo = null;
    private string $dsn;
    private string $workDir;

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
        $this->pdo->exec('DROP TABLE IF EXISTS dd_widget');
        $this->pdo->exec('CREATE TABLE dd_widget (id INTEGER PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        $this->pdo->exec("INSERT INTO dd_widget (id, name) VALUES (1, 'Gadget')");
        $this->pdo->exec("INSERT INTO dd_widget (id, name) VALUES (2, 'Gizmo')");

        $this->workDir = sys_get_temp_dir() . '/propulsion-data-dump-command-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        file_put_contents($this->workDir . '/schema.xml', <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="data_dump_command_test" defaultIdMethod="native">
  <table name="dd_widget" phpName="DdWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
  </table>
</database>
EOT
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS dd_widget');
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

    public function testDumpsLiveDatabaseRowsToXmlDataset(): void
    {
        $application = new Application();
        $application->addCommand(new DataDumpCommand());

        $tester = new CommandTester($application->find('data:dump'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/schema.xml',
            '--dsn' => $this->dsn,
            '--user' => 'propulsion',
            '--password' => 'propulsion',
            '--output' => $this->workDir . '/dataset.xml',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFileExists($this->workDir . '/dataset.xml');
        $xml = simplexml_load_file($this->workDir . '/dataset.xml');
        $this->assertCount(2, $xml->DdWidget);
        $this->assertStringContainsString('2 rows written', $tester->getDisplay());
    }

    public function testFailsCleanlyWithoutDsn(): void
    {
        $application = new Application();
        $application->addCommand(new DataDumpCommand());

        $tester = new CommandTester($application->find('data:dump'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/schema.xml',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--dsn', $tester->getDisplay());
    }

    public function testFailsCleanlyWhenNoSchemaFilesFound(): void
    {
        $application = new Application();
        $application->addCommand(new DataDumpCommand());

        $tester = new CommandTester($application->find('data:dump'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/does-not-exist',
            '--dsn' => $this->dsn,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No schema files found', $tester->getDisplay());
    }
}
