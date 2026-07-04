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
use Propulsion\Generator\Manager\DataSqlManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'data:sql',
    description: 'Convert an XML dataset file (from data:dump) into INSERT SQL',
)]
class DataSqlCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('dataset', InputArgument::REQUIRED, 'XML dataset file to convert (from data:dump)')
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output SQL file', './dataset.sql')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)')
            ->addOption('data-database', null, InputOption::VALUE_REQUIRED, 'Only use the <database name="..."> from the schema (uses the first database in the schema if omitted)')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->setHelp(<<<'EOT'
The <info>data:sql</info> command converts an XML dataset file (as produced by
<info>data:dump</info>) into a file of INSERT SQL statements, using the
per-platform DataSQLBuilder for the target database.

<info>php bin/propulsion data:sql dataset.xml schema.xml --database=pgsql -o dataset.sql</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion Data SQL');

        try {
            $schemaPath = $input->getArgument('schema');
            $schemas = $this->findSchemaFiles($schemaPath);

            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }

            $config = $this->loadConfiguration($input);
            $manager = new DataSqlManager($config);
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Converting Data XML to SQL');
            $rowCount = $manager->transform(
                $schemas,
                $input->getArgument('dataset'),
                $input->getOption('output'),
                $input->getOption('data-database')
            );

            $io->success("Conversion complete. $rowCount rows converted to " . $input->getOption('output'));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to convert data: ' . $e->getMessage());
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
        if ($database = $input->getOption('database')) {
            $overrides['propulsion.database'] = $database;
        }

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $input->getOption('config'),
            $overrides
        );
    }

    private function findSchemaFiles($path): array
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
