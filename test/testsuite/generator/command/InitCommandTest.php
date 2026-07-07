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
use Propulsion\Generator\Command\InitCommand;

/**
 * End-to-end CommandTester coverage for `bin/propulsion init`, which had no
 * test at all. Pure filesystem scaffolding (no DB needed) -- runs against a
 * temp working directory so it doesn't pollute the repo.
 */
class InitCommandTest extends TestCase
{
    private string $workDir;
    private string $previousCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/propulsion-init-command-test-' . uniqid();
        mkdir($this->workDir, 0777, true);
        $this->previousCwd = getcwd();
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
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

    public function testInitCreatesProjectStructureWithGivenNameAndPlatform(): void
    {
        $application = new Application();
        $application->addCommand(new InitCommand());

        $tester = new CommandTester($application->find('init'));
        // 'name' is answered via the argument; the platform ChoiceQuestion has
        // no non-interactive equivalent, so it's answered via setInputs().
        $tester->setInputs(['postgresql']);
        $exitCode = $tester->execute(['name' => 'my_test_project']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertDirectoryExists('my_test_project');
        $this->assertDirectoryExists('my_test_project/schema');
        $this->assertDirectoryExists('my_test_project/generated-classes');
        $this->assertDirectoryExists('my_test_project/generated-sql');
        $this->assertFileExists('my_test_project/propel.json');
        $this->assertFileExists('my_test_project/schema/schema.xml');

        $config = json_decode(file_get_contents('my_test_project/propel.json'), true);
        $this->assertSame('postgresql', $config['propel']['database']['connections']['default']['adapter']);
        $this->assertStringContainsString('pgsql:host=localhost', $config['propel']['database']['connections']['default']['dsn']);

        $schema = file_get_contents('my_test_project/schema/schema.xml');
        $this->assertStringContainsString('<database name="my_test_project"', $schema);
        $this->assertStringContainsString('<table name="user"', $schema);
        $this->assertStringContainsString('<table name="post"', $schema);
    }

    public function testInitPromptsForNameWhenArgumentOmitted(): void
    {
        $application = new Application();
        $application->addCommand(new InitCommand());

        $tester = new CommandTester($application->find('init'));
        $tester->setInputs(['prompted_project', 'sqlite']);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertDirectoryExists('prompted_project');

        $config = json_decode(file_get_contents('prompted_project/propel.json'), true);
        $this->assertSame('sqlite', $config['propel']['database']['connections']['default']['adapter']);
        $this->assertSame('sqlite:prompted_project.db', $config['propel']['database']['connections']['default']['dsn']);
    }

    public function testGenerateDsnForEachSupportedPlatform(): void
    {
        $cases = [
            'mysql' => 'mysql:host=localhost;dbname=dsn_test',
            'postgresql' => 'pgsql:host=localhost;dbname=dsn_test',
            'sqlite' => 'sqlite:dsn_test.db',
            'oracle' => 'oci:dbname=//localhost:1521/dsn_test',
            'mssql' => 'sqlsrv:Server=localhost;Database=dsn_test',
        ];

        foreach ($cases as $platform => $expectedDsn) {
            $application = new Application();
            $application->addCommand(new InitCommand());
            $tester = new CommandTester($application->find('init'));
            $tester->setInputs([$platform]);
            $tester->execute(['name' => 'dsn_test_' . $platform]);

            $config = json_decode(file_get_contents('dsn_test_' . $platform . '/propel.json'), true);
            $expected = str_replace('dsn_test', 'dsn_test_' . $platform, $expectedDsn);
            $this->assertSame($expected, $config['propel']['database']['connections']['default']['dsn'], "DSN mismatch for platform $platform");
        }
    }
}
