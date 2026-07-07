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
use Propulsion\Generator\Command\SqlBuildCommand;

/**
 * End-to-end CommandTester coverage for `bin/propulsion sql:build`, which had
 * no test at all. Pure codegen (no live DB needed).
 */
class SqlBuildCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/propulsion-sql-build-command-test-' . uniqid();
        mkdir($this->workDir . '/schema', 0777, true);
        mkdir($this->workDir . '/out', 0777, true);

        file_put_contents($this->workDir . '/schema/widget-schema.xml', <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="sql_build_command_test" defaultIdMethod="native">
  <table name="sb_widget" phpName="SbWidget">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
  </table>
</database>
EOT
        );
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->workDir);
        parent::tearDown();
    }

    public function testBuildsSqlFromSingleSchemaFile(): void
    {
        $application = new Application();
        $application->addCommand(new SqlBuildCommand());

        $tester = new CommandTester($application->find('sql:build'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/schema/widget-schema.xml',
            '--output-dir' => $this->workDir . '/out',
            '--database' => 'sqlite',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $files = glob($this->workDir . '/out/*.sql');
        $this->assertNotEmpty($files);
        $sql = file_get_contents($files[0]);
        $this->assertStringContainsString('sb_widget', $sql);
    }

    public function testBuildsSqlFromSchemaDirectory(): void
    {
        $application = new Application();
        $application->addCommand(new SqlBuildCommand());

        $tester = new CommandTester($application->find('sql:build'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/schema',
            '--output-dir' => $this->workDir . '/out',
            '--database' => 'sqlite',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertNotEmpty(glob($this->workDir . '/out/*.sql'));
    }

    public function testFailsCleanlyWhenNoSchemaFilesFound(): void
    {
        $application = new Application();
        $application->addCommand(new SqlBuildCommand());

        $tester = new CommandTester($application->find('sql:build'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/empty-dir-that-does-not-exist',
            '--output-dir' => $this->workDir . '/out',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No schema files found', $tester->getDisplay());
    }

    public function testFailsCleanlyOnInvalidSchema(): void
    {
        file_put_contents($this->workDir . '/schema/broken-schema.xml', 'not valid xml at all <<<');

        $application = new Application();
        $application->addCommand(new SqlBuildCommand());

        $tester = new CommandTester($application->find('sql:build'));
        $exitCode = $tester->execute([
            'schema' => $this->workDir . '/schema/broken-schema.xml',
            '--output-dir' => $this->workDir . '/out',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to generate SQL', $tester->getDisplay());
    }
}
