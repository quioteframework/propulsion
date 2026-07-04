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
use Propulsion\Generator\Manager\ModelManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'model:build',
    description: 'Build Object Model classes from schema',
    aliases: ['om']
)]
class ModelBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for generated classes', './generated-classes')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)')
            ->addOption('target-platform', null, InputOption::VALUE_REQUIRED, 'Codegen dialect: php5 (legacy) or php84 (current)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Default package/namespace for generated classes')
            ->setHelp(<<<'EOT'
The <info>model:build</info> command generates Object Model classes from XML schema files.

<info>php bin/propulsion model:build</info>
<info>php bin/propulsion model:build schema.xml</info>
<info>php bin/propulsion model:build --output-dir=src/Model</info>
<info>php bin/propulsion model:build --target-platform=php84</info>

You can also use the shorter alias:
<info>php bin/propulsion om</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion Model Builder');

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
            $outputDir = $input->getOption('output-dir');

            $manager = new ModelManager($config, $outputDir, $input->getOption('namespace'));
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Generating Models');
            $written = $manager->generate($schemas);

            $io->success($written > 0
                ? "Model classes generated successfully! ($written files written)"
                : 'Model classes already up to date.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to generate models: ' . $e->getMessage());
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
            $overrides['propel.database'] = $database;
        }
        if ($targetPlatform = $input->getOption('target-platform')) {
            $overrides['propel.targetPlatform'] = $targetPlatform;
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
