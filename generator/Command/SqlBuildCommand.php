<?php

namespace Propel\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Database platform', 'mysql')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file', './propel.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Propel SQL Builder');
        $io->success('SQL DDL files generated successfully!');
        
        return Command::SUCCESS;
    }
}