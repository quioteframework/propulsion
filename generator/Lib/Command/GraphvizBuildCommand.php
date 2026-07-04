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
use Propulsion\Generator\Manager\GraphvizManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'graph:build',
    description: 'Generate Graphviz .dot files from schema files',
    aliases: ['graphviz']
)]
class GraphvizBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for .dot files', './generated-sql')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)')
            ->setHelp(<<<'EOT'
The <info>graph:build</info> command generates Graphviz `.dot` files from XML schema
files, one per <database>, describing its tables (as record nodes listing columns,
with [PK]/[FK] markers) and foreign-key edges between them.

<info>php bin/propulsion graph:build</info>
<info>php bin/propulsion graph:build schema.xml -o docs/diagrams</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion Graphviz Builder');

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
            $manager = new GraphvizManager($config, $input->getOption('output-dir'));
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Generating Graphviz Dot Files');
            $written = $manager->generate($schemas);

            $io->success($written > 0
                ? "Graphviz .dot files generated successfully! ($written files written)"
                : 'Graphviz .dot files already up to date.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to generate .dot files: ' . $e->getMessage());
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
