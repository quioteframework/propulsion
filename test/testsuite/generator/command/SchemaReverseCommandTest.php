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
use Propulsion\Generator\Command\SchemaReverseCommand;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end CommandTester coverage for `bin/propulsion schema:reverse`, driving the
 * actual Symfony Console command (not just SchemaReverseManager directly) against a
 * real Postgres testcontainer.
 */
class SchemaReverseCommandTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_reverse_command';

    private ?PDO $pdo = null;
    private string $dsn;
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

        $this->dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME;
        $this->pdo = new PDO($this->dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('DROP TABLE IF EXISTS cmd_rev_widget');
        $this->pdo->exec('CREATE TABLE cmd_rev_widget (id SERIAL PRIMARY KEY, label VARCHAR(80) NOT NULL)');

        $this->outputFile = sys_get_temp_dir() . '/propulsion-schema-reverse-command-test-' . uniqid() . '.xml';
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS cmd_rev_widget');
        }
        if (isset($this->outputFile) && is_file($this->outputFile)) {
            unlink($this->outputFile);
        }
        parent::tearDown();
    }

    public function testCommandReverseEngineersLiveDatabaseToXmlFile(): void
    {
        $application = new Application();
        $application->addCommand(new SchemaReverseCommand());

        $tester = new CommandTester($application->find('schema:reverse'));
        $exitCode = $tester->execute([
            '--dsn' => $this->dsn,
            '--user' => 'propulsion',
            '--password' => 'propulsion',
            '--database' => 'pgsql',
            '--database-name' => 'cmd_reverse_test',
            '--output-file' => $this->outputFile,
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFileExists($this->outputFile);

        $xml = simplexml_load_file($this->outputFile);
        $this->assertNotFalse($xml);

        $names = [];
        foreach ($xml->table as $table) {
            $names[] = (string) $table['name'];
        }
        $this->assertContains('cmd_rev_widget', $names);
    }

    public function testCommandFailsCleanlyWithoutDsn(): void
    {
        $application = new Application();
        $application->addCommand(new SchemaReverseCommand());

        $tester = new CommandTester($application->find('schema:reverse'));
        $exitCode = $tester->execute([
            '--database-name' => 'whatever',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--dsn', $tester->getDisplay());
    }
}
