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
use Propulsion\Generator\Command\DataSqlCommand;

/**
 * End-to-end CommandTester coverage for `bin/propulsion data:sql`, which had
 * no test at all. Unlike data:dump, this needs no live DB connection --
 * transform() only reads an XML dataset file and a schema.
 */
class DataSqlCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/propulsion-data-sql-command-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        file_put_contents($this->workDir . '/schema.xml', <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<database name="data_sql_command_test" defaultIdMethod="native">
  <table name="ds_widget" phpName="DsWidget">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="name" type="VARCHAR" size="50" required="true"/>
  </table>
</database>
EOT
        );
        file_put_contents($this->workDir . '/dataset.xml', <<<'EOT'
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
  <DsWidget Id="1" Name="Gadget"/>
  <DsWidget Id="2" Name="Gizmo"/>
</dataset>
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

    public function testConvertsDatasetXmlToInsertSql(): void
    {
        $application = new Application();
        $application->addCommand(new DataSqlCommand());

        $tester = new CommandTester($application->find('data:sql'));
        $exitCode = $tester->execute([
            'dataset' => $this->workDir . '/dataset.xml',
            'schema' => $this->workDir . '/schema.xml',
            '--output' => $this->workDir . '/dataset.sql',
            '--database' => 'mysql',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFileExists($this->workDir . '/dataset.sql');
        $sql = file_get_contents($this->workDir . '/dataset.sql');
        $this->assertStringContainsString('ds_widget', $sql);
        $this->assertStringContainsString('Gadget', $sql);
        $this->assertStringContainsString('2 rows converted', $tester->getDisplay());
    }

    public function testFailsCleanlyWhenNoSchemaFilesFound(): void
    {
        $application = new Application();
        $application->addCommand(new DataSqlCommand());

        $tester = new CommandTester($application->find('data:sql'));
        $exitCode = $tester->execute([
            'dataset' => $this->workDir . '/dataset.xml',
            'schema' => $this->workDir . '/does-not-exist',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No schema files found', $tester->getDisplay());
    }

    public function testFailsCleanlyWhenDatasetFileMissing(): void
    {
        $application = new Application();
        $application->addCommand(new DataSqlCommand());

        $tester = new CommandTester($application->find('data:sql'));
        $exitCode = $tester->execute([
            'dataset' => $this->workDir . '/no-such-dataset.xml',
            'schema' => $this->workDir . '/schema.xml',
            '--database' => 'mysql',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to convert data', $tester->getDisplay());
    }
}
