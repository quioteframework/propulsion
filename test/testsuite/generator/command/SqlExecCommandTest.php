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
use Propulsion\Generator\Command\SqlExecCommand;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end CommandTester coverage for `bin/propulsion sql:exec`.
 */
class SqlExecCommandTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_sqlexec_command';

    private ?PDO $pdo = null;
    private string $dsn;
    private string $outDir;

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
        $this->pdo->exec('DROP TABLE IF EXISTS cmd_sqlexec_widget');

        $this->outDir = sys_get_temp_dir() . '/propulsion-sqlexec-command-test-' . uniqid();
        mkdir($this->outDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS cmd_sqlexec_widget');
        }
        if (isset($this->outDir) && is_dir($this->outDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->outDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->outDir);
        }
        parent::tearDown();
    }

    public function testCommandExecutesSqlFileAgainstRealDatabase(): void
    {
        $sqlFile = $this->outDir . '/create.sql';
        file_put_contents($sqlFile, "CREATE TABLE cmd_sqlexec_widget (id INTEGER PRIMARY KEY, name VARCHAR(50));\nINSERT INTO cmd_sqlexec_widget (id, name) VALUES (1, 'Gadget');\n");

        $application = new Application();
        $application->addCommand(new SqlExecCommand());

        $tester = new CommandTester($application->find('sql:exec'));
        $exitCode = $tester->execute([
            'sql-files' => [$sqlFile],
            '--dsn' => $this->dsn,
            '--user' => 'propulsion',
            '--password' => 'propulsion',
            '--autocommit' => true,
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());

        $row = $this->pdo->query('SELECT name FROM cmd_sqlexec_widget WHERE id = 1')->fetch();
        $this->assertSame('Gadget', $row['name']);
    }

    public function testCommandFailsCleanlyWithoutDsn(): void
    {
        $application = new Application();
        $application->addCommand(new SqlExecCommand());

        $tester = new CommandTester($application->find('sql:exec'));
        $exitCode = $tester->execute([
            'sql-files' => ['/tmp/does-not-matter.sql'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--dsn', $tester->getDisplay());
    }
}
