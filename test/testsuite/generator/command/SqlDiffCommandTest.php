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
use Propulsion\Generator\Command\SqlDiffCommand;

require_once dirname(__DIR__, 3) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end CommandTester coverage for `bin/propulsion sql:diff` -- console
 * replacement for the Phing-based PropulsionSQLDiffTask (see
 * PropulsionSQLDiffTaskTest for the Task-side coverage of the same shared
 * comparator engine).
 *
 * Deliberately supports only "live database vs schema.xml" mode, matching the
 * original Task's scope -- see KNOWN_ISSUES.md.
 */
class SqlDiffCommandTest extends TestCase
{
    private string $fixtureDir;
    private string $migrationDir;
    private string $buildtimeConfFile;
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = dirname(__DIR__, 3) . '/fixtures/generator-parity-diff';

        $this->migrationDir = sys_get_temp_dir() . '/propulsion-sqldiff-command-test-' . uniqid();
        mkdir($this->migrationDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS diff_book');
            $this->pdo->exec('DROP TABLE IF EXISTS diff_author');
        }
        $this->removeDir($this->migrationDir);
        parent::tearDown();
    }

    public function testCommandGeneratesMigrationClassForLiveDatabaseDrift(): void
    {
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->dbName = 'propulsion_test_sqldiff_command';
        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote($this->dbName))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . $this->dbName);
        }

        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname={$this->dbName}";
        $this->pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Deliberately out-of-date: the live DB only has schema-v1's shape (no
        // author_id/price columns, no FK) -- schema-v2.xml is the "desired" state.
        $this->pdo->exec('DROP TABLE IF EXISTS diff_book');
        $this->pdo->exec('DROP TABLE IF EXISTS diff_author');
        $this->pdo->exec('CREATE TABLE diff_author (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $this->pdo->exec('CREATE TABLE diff_book (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL)');

        $this->buildtimeConfFile = $this->migrationDir . '/buildtime-conf.xml';
        file_put_contents($this->buildtimeConfFile, sprintf(
            '<config><propel><datasources default="diff_parity">'
            . '<datasource id="diff_parity"><adapter>pgsql</adapter>'
            . '<connection><dsn>%s</dsn><user>propulsion</user><password>propulsion</password></connection>'
            . '</datasource></datasources></propel></config>',
            htmlspecialchars($dsn, ENT_XML1)
        ));

        try {
            $application = new Application();
            $application->addCommand(new SqlDiffCommand());
            $tester = new CommandTester($application->find('sql:diff'));

            $exitCode = $tester->execute([
                'schema' => $this->fixtureDir . '/schema-v2.xml',
                '--buildtime-conf' => $this->buildtimeConfFile,
                '--migration-dir' => $this->migrationDir,
                '--database' => 'pgsql',
            ]);

            $this->assertSame(0, $exitCode, $tester->getDisplay());

            $migrationFiles = glob($this->migrationDir . '/PropulsionMigration_*.php');
            $this->assertNotEmpty($migrationFiles, 'A migration class file should have been generated');

            $body = file_get_contents($migrationFiles[0]);
            $this->assertStringContainsString('getUpSQL', $body);
            $this->assertStringContainsString('getDownSQL', $body);
            $this->assertMatchesRegularExpression('/author_id/i', $body);
            $this->assertMatchesRegularExpression('/price/i', $body);
            $this->assertMatchesRegularExpression('/FOREIGN KEY/i', $body);
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS diff_book');
            $this->pdo->exec('DROP TABLE IF EXISTS diff_author');
        }
    }

    /**
     * Includes a DECIMAL(10,2) column deliberately: this is the regression
     * case for the PgsqlSchemaParser NUMERIC-typmod-decoding bug documented
     * in KNOWN_ISSUES.md (a reversed NUMERIC(p,s) column's `size` didn't
     * decode Postgres's packed typmod correctly, e.g. reporting 655362
     * instead of 10) -- before that fix, this test would have reported a
     * spurious diff on the `price` column even though the live table and
     * schema.xml genuinely match.
     */
    public function testCommandReportsNoDiffWhenSchemaAlreadyMatches(): void
    {
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $dbName = 'propulsion_test_sqldiff_command_nodiff';
        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote($dbName))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . $dbName);
        }

        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=$dbName";
        $this->pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('DROP TABLE IF EXISTS diff_book');
        $this->pdo->exec('DROP TABLE IF EXISTS diff_author');
        $this->pdo->exec('CREATE TABLE diff_author (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE diff_book (id SERIAL PRIMARY KEY, title VARCHAR(255) NOT NULL, author_id INTEGER, '
            . 'price NUMERIC(10,2), '
            . 'CONSTRAINT diff_book_author_fk FOREIGN KEY (author_id) REFERENCES diff_author (id) ON DELETE CASCADE)'
        );

        $noDiffSchemaFile = $this->migrationDir . '/nodiff-schema.xml';
        file_put_contents($noDiffSchemaFile, <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="diff_parity" defaultIdMethod="native">
  <table name="diff_author" phpName="DiffAuthor">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="100" required="true"/>
  </table>
  <table name="diff_book" phpName="DiffBook">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true"/>
    <column name="title" type="VARCHAR" size="255" required="true"/>
    <column name="author_id" type="INTEGER"/>
    <column name="price" type="DECIMAL" size="10" scale="2"/>
    <foreign-key foreignTable="diff_author" onDelete="cascade">
      <reference local="author_id" foreign="id"/>
    </foreign-key>
  </table>
</database>
EOT
        );

        $this->buildtimeConfFile = $this->migrationDir . '/buildtime-conf.xml';
        file_put_contents($this->buildtimeConfFile, sprintf(
            '<config><propel><datasources default="diff_parity">'
            . '<datasource id="diff_parity"><adapter>pgsql</adapter>'
            . '<connection><dsn>%s</dsn><user>propulsion</user><password>propulsion</password></connection>'
            . '</datasource></datasources></propel></config>',
            htmlspecialchars($dsn, ENT_XML1)
        ));

        $application = new Application();
        $application->addCommand(new SqlDiffCommand());
        $tester = new CommandTester($application->find('sql:diff'));

        $exitCode = $tester->execute([
            'schema' => $noDiffSchemaFile,
            '--buildtime-conf' => $this->buildtimeConfFile,
            '--migration-dir' => $this->migrationDir,
            '--database' => 'pgsql',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('nothing to migrate', $tester->getDisplay());
        $this->assertEmpty(glob($this->migrationDir . '/PropulsionMigration_*.php'), 'No migration class file should have been generated when there is no diff');
    }

    public function testCommandFailsCleanlyWhenSchemaFileNotFound(): void
    {
        $application = new Application();
        $application->addCommand(new SqlDiffCommand());
        $tester = new CommandTester($application->find('sql:diff'));

        $exitCode = $tester->execute([
            'schema' => $this->migrationDir . '/does-not-exist.xml',
            '--migration-dir' => $this->migrationDir,
        ]);

        $this->assertNotSame(0, $exitCode);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
