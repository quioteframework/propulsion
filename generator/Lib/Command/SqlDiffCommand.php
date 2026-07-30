<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Propulsion\Generator\Manager\SqlDiffManager;
use Propulsion\Generator\Config\GeneratorConfig;

/**
 * Console replacement for the Phing-based PropulsionSQLDiffTask: compares a
 * live database (via a buildtime-conf connection) against a schema.xml
 * file and generates a PropulsionMigration_<timestamp>.php migration class
 * with the resulting up/down SQL.
 *
 * Deliberately supports only "live database vs schema.xml" mode, matching the
 * original Task's scope -- see KNOWN_ISSUES.md.
 */
#[AsCommand(
    name: 'sql:diff',
    description: 'Compare a live database against schema.xml and generate a migration class',
    aliases: ['diff']
)]
class SqlDiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('migration-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the generated migration class', './migrations')
            ->addOption('buildtime-conf', null, InputOption::VALUE_REQUIRED, 'Path to a build-time connection config file describing the datasource(s) to diff against: a plain PHP file returning [\'default\' => ..., \'datasources\' => [...]]')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)')
            ->addOption('case-insensitive', null, InputOption::VALUE_NONE, 'Perform a case-insensitive structure comparison')
            ->setHelp(<<<'EOT'
The <info>sql:diff</info> command connects to a live database (via
--buildtime-conf) and compares its structure against a schema.xml file,
generating a <info>PropulsionMigration_&lt;timestamp&gt;.php</info> migration
class capturing the up/down SQL for any difference found.

<info>php bin/propulsion sql:diff schema.xml --buildtime-conf=buildtime-conf.php --database=pgsql --migration-dir=./migrations</info>

Where buildtime-conf.php is a plain PHP file returning:
<comment>  return [
      'default' => 'bookstore',
      'datasources' => [
          'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
      ],
  ];</comment>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Propulsion SQL Diff');

        try {
            $schemaPathArg = $input->getArgument('schema');
            $schemaPath = is_string($schemaPathArg) ? $schemaPathArg : './schema';
            $schemas = $this->findSchemaFiles($schemaPath);

            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }

            $io->section('Comparing Against Schema Files');
            $io->listing(array_map('basename', $schemas));

            $migrationDirOption = $input->getOption('migration-dir');
            $migrationDir = is_string($migrationDirOption) ? $migrationDirOption : './migrations';

            $config = $this->loadConfiguration($input);
            $manager = new SqlDiffManager(
                $config,
                $migrationDir,
                (bool) $input->getOption('case-insensitive'),
            );
            $manager->setLogger(new ConsoleLogger($output));

            $migrationFile = $manager->generate($schemas);

            if ($migrationFile === null) {
                $io->success('Database structure matches schema.xml - nothing to migrate.');
            } else {
                $io->success("Migration class generated: $migrationFile");
                $io->note('Review the generated SQL statements, add data migration code if necessary, then run "migration:up" to execute it.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to generate diff: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function loadConfiguration(InputInterface $input): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.php';

        $overrides = [];
        $database = $input->getOption('database');
        if (is_string($database) && $database !== '') {
            $overrides['propulsion.database'] = $database;
        }
        $buildtimeConf = $input->getOption('buildtime-conf');
        if (is_string($buildtimeConf) && $buildtimeConf !== '') {
            $overrides['propulsion.buildtimeConfFile'] = $buildtimeConf;
        }

        $configOption = $input->getOption('config');
        $configFiles = is_array($configOption) ? array_values(array_filter($configOption, 'is_string')) : [];

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $configFiles,
            $overrides
        );
    }

    /**
     * @return array<int, string>
     */
    private function findSchemaFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (is_dir($path)) {
            return glob($path . '/*schema.xml') ?: [];
        }

        return [];
    }
}
