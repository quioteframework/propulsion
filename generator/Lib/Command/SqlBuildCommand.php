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
use Propulsion\Generator\Manager\SqlManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'sql:build',
    description: 'Build SQL DDL from schema files',
    aliases: ['sql']
)]
class SqlBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for SQL files', './generated-sql')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Build properties file overriding generator/default.properties')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion SQL Builder');

        try {
            $schemaPath = $input->getArgument('schema');
            $schemas = $this->findSchemaFiles($schemaPath);

            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }

            $io->section('Found Schema Files');
            $io->listing(array_map('basename', $schemas));

            $config = $this->loadConfiguration($input);
            $manager = new SqlManager($config, $input->getOption('output-dir'));
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Generating SQL');
            $written = $manager->generate($schemas);

            $io->success($written > 0
                ? "SQL DDL files generated successfully! ($written files written)"
                : 'SQL DDL files already up to date.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to generate SQL: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function loadConfiguration(InputInterface $input): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.properties';

        $overrides = [];
        if ($database = $input->getOption('database')) {
            $overrides['propel.database'] = $database;
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
