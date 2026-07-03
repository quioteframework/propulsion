<?php

namespace Propel\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Propel\Generator\Manager\ModelManager;
use Propel\Generator\Config\PropelConfiguration;

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
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file', './propel.json')
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Database platform', 'mysql')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Base namespace for generated classes')
            ->setHelp(<<<'EOT'
The <info>model:build</info> command generates Object Model classes from XML schema files.

<info>php bin/propel model:build</info>
<info>php bin/propel model:build schema.xml</info>
<info>php bin/propel model:build --output-dir=src/Model</info>
<info>php bin/propel model:build --namespace=App\\Model</info>

You can also use the shorter alias:
<info>php bin/propel om</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Propel Model Builder');
        
        try {
            $config = $this->loadConfiguration($input);
            $manager = new ModelManager($config);
            
            $schemaPath = $input->getArgument('schema');
            $schemas = $this->findSchemaFiles($schemaPath);
            
            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }
            
            $io->section('Found Schema Files');
            $io->listing(array_map('basename', $schemas));
            
            $io->section('Generating Models');
            $io->progressStart(count($schemas));
            
            foreach ($schemas as $schema) {
                $io->text("Processing: " . basename($schema));
                $manager->generateModels($schema);
                $io->progressAdvance();
            }
            
            $io->progressFinish();
            $io->success('Model classes generated successfully!');
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Failed to generate models: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
    
    private function loadConfiguration(InputInterface $input): PropelConfiguration
    {
        $configFile = $input->getOption('config');
        
        if ($configFile && file_exists($configFile)) {
            return PropelConfiguration::loadFromFile($configFile);
        }
        
        // Create configuration from command line options
        $config = new PropelConfiguration();
        $config->setOutputDir($input->getOption('output-dir'));
        $config->setPlatform($input->getOption('platform'));
        
        if ($namespace = $input->getOption('namespace')) {
            $config->setNamespace($namespace);
        }
        
        return $config;
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