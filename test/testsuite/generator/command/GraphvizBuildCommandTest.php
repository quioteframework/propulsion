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
use Propulsion\Generator\Command\GraphvizBuildCommand;

/**
 * End-to-end CommandTester coverage for `bin/propulsion graph:build`.
 */
class GraphvizBuildCommandTest extends TestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outDir = sys_get_temp_dir() . '/propulsion-graphviz-command-test-' . uniqid();
        mkdir($this->outDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->outDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->outDir);
        parent::tearDown();
    }

    public function testCommandGeneratesDotFileFromSchema(): void
    {
        $application = new Application();
        $application->addCommand(new GraphvizBuildCommand());

        $schemaFile = dirname(__DIR__, 3) . '/fixtures/generator-parity/schema.xml';

        $tester = new CommandTester($application->find('graph:build'));
        $exitCode = $tester->execute([
            'schema' => $schemaFile,
            '--output-dir' => $this->outDir,
            '--database' => 'pgsql',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());

        $dotFile = $this->outDir . '/generator_parity.schema.dot';
        $this->assertFileExists($dotFile);
        $this->assertStringContainsString('digraph G', file_get_contents($dotFile));
    }

    public function testCommandFailsCleanlyWhenNoSchemaFilesFound(): void
    {
        $application = new Application();
        $application->addCommand(new GraphvizBuildCommand());

        $tester = new CommandTester($application->find('graph:build'));
        $exitCode = $tester->execute([
            'schema' => $this->outDir, // empty dir, no *schema.xml
            '--output-dir' => $this->outDir,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No schema files found', $tester->getDisplay());
    }
}
