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
use Propulsion\Generator\Manager\GraphvizManager;

/**
 * Coverage for the console-app `graph:build` path (Propulsion\Generator\Manager\GraphvizManager),
 * the plain-PHP replacement for the Phing-based PropulsionGraphvizTask. Pure in-memory
 * schema-to-.dot generation, no database connection needed.
 */
class GraphvizManagerTest extends TestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outDir = sys_get_temp_dir() . '/propulsion-graphviz-manager-test-' . uniqid();
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

    private function buildConfig(): GeneratorConfig
    {
        return GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.php',
            null,
            ['propulsion.database' => 'pgsql']
        );
    }

    public function testGeneratesDotFileWithTablesAndForeignKey(): void
    {
        $manager = new GraphvizManager($this->buildConfig(), $this->outDir);
        $schemaFile = dirname(__DIR__, 3) . '/fixtures/generator-parity/schema.xml';

        $written = $manager->generate([$schemaFile]);
        $this->assertSame(1, $written);

        $dotFile = $this->outDir . '/generator_parity.schema.dot';
        $this->assertFileExists($dotFile);

        $dot = file_get_contents($dotFile);
        $this->assertStringContainsString('digraph G', $dot);
        $this->assertStringContainsString('parity_book', $dot);
        $this->assertStringContainsString('parity_author', $dot);
        $this->assertStringContainsString('[PK]', $dot);
        $this->assertStringContainsString('[FK]', $dot);
        // Edge from the book table to the author table via the FK column.
        $this->assertMatchesRegularExpression('/nodeparity_book:cols -> nodeparity_author:table/', $dot);
    }

    public function testRegeneratingUnchangedSchemaDoesNotRewriteFile(): void
    {
        $manager = new GraphvizManager($this->buildConfig(), $this->outDir);
        $schemaFile = dirname(__DIR__, 3) . '/fixtures/generator-parity/schema.xml';

        $this->assertSame(1, $manager->generate([$schemaFile]));
        $this->assertSame(0, $manager->generate([$schemaFile]), 'Second run with unchanged schema should write 0 files');
    }
}
