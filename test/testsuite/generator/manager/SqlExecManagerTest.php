<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Manager\SqlExecManager;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Integration coverage for the console-app `sql:exec` path (Propulsion\Generator\Manager\SqlExecManager),
 * the plain-PHP replacement for the Phing-based PropulsionSQLExec -- minus its Phing
 * `sqldbmap` multi-database file-routing machinery (see the class docblock).
 */
class SqlExecManagerTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_sqlexec_manager';

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
        $this->pdo->exec('DROP TABLE IF EXISTS sqlexec_widget');

        $this->outDir = sys_get_temp_dir() . '/propulsion-sqlexec-manager-test-' . uniqid();
        mkdir($this->outDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS sqlexec_widget');
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

    public function testExecutesStatementsFromSqlFileAgainstRealDatabase(): void
    {
        $sqlFile = $this->outDir . '/create.sql';
        file_put_contents($sqlFile, "CREATE TABLE sqlexec_widget (id INTEGER PRIMARY KEY, name VARCHAR(50));\nINSERT INTO sqlexec_widget (id, name) VALUES (1, 'Gadget');\n");

        $manager = new SqlExecManager($this->dsn, 'propulsion', 'propulsion', autocommit: true);
        $executed = $manager->execute([$sqlFile]);

        $this->assertSame(2, $executed);

        $row = $this->pdo->query('SELECT name FROM sqlexec_widget WHERE id = 1')->fetch();
        $this->assertSame('Gadget', $row['name']);
    }

    public function testAbortOnErrorStopsAndRollsBackFailingStatement(): void
    {
        $sqlFile = $this->outDir . '/broken.sql';
        file_put_contents($sqlFile, "CREATE TABLE sqlexec_widget (id INTEGER PRIMARY KEY, name VARCHAR(50));\nINSERT INTO sqlexec_widget (id, name) VALUES ('not-an-integer-and-no-such-column', 1, 2, 3);\nINSERT INTO sqlexec_widget (id, name) VALUES (2, 'Unreached');\n");

        $manager = new SqlExecManager($this->dsn, 'propulsion', 'propulsion', autocommit: false, onError: 'abort');

        try {
            $manager->execute([$sqlFile]);
            $this->fail('Expected an EngineException to be thrown for the invalid statement');
        } catch (\Propulsion\Generator\Exception\EngineException $e) {
            // expected
        }

        // Table was created (first statement succeeded and committed on its own),
        // but neither INSERT after the broken one ran.
        $count = $this->pdo->query('SELECT COUNT(*) AS c FROM sqlexec_widget')->fetch()['c'];
        $this->assertEquals(0, $count);
    }

    public function testContinueOnErrorSkipsFailingStatementAndContinues(): void
    {
        $sqlFile = $this->outDir . '/broken.sql';
        file_put_contents($sqlFile, "CREATE TABLE sqlexec_widget (id INTEGER PRIMARY KEY, name VARCHAR(50));\nINSERT INTO sqlexec_widget (id, name, bogus) VALUES (1, 'Gadget', 'x');\nINSERT INTO sqlexec_widget (id, name) VALUES (2, 'Widget');\n");

        $manager = new SqlExecManager($this->dsn, 'propulsion', 'propulsion', autocommit: false, onError: 'continue');
        $executed = $manager->execute([$sqlFile]);

        $this->assertSame(2, $executed, 'CREATE TABLE and the second, valid INSERT should have succeeded');

        $names = $this->pdo->query('SELECT name FROM sqlexec_widget ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Widget'], $names);
    }
}
