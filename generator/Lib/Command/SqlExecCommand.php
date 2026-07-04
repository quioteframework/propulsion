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
use Propulsion\Generator\Manager\SqlExecManager;

#[AsCommand(
    name: 'sql:exec',
    description: 'Execute .sql files against a live database',
)]
class SqlExecCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('sql-files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more .sql files to execute, in order')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO connection DSN of the target database, e.g. "pgsql:host=localhost;dbname=mydb"')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Database user', null)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Database password', null)
            ->addOption('autocommit', null, InputOption::VALUE_NONE, 'Run each statement outside of an explicit transaction')
            ->addOption('on-error', null, InputOption::VALUE_REQUIRED, 'Action to take on a failing statement: "abort" or "continue"', 'abort')
            ->setHelp(<<<'EOT'
The <info>sql:exec</info> command executes one or more `.sql` files against a live
database over a single PDO connection.

<info>php bin/propulsion sql:exec generated-sql/bookstore.sql --dsn="pgsql:host=localhost;dbname=mydb" --user=me --password=secret</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion SQL Exec');

        $dsn = $input->getOption('dsn');
        if (!$dsn) {
            $io->error('The --dsn option is required, e.g. --dsn="pgsql:host=localhost;dbname=mydb"');
            return Command::FAILURE;
        }

        $sqlFiles = $input->getArgument('sql-files');

        try {
            $manager = new SqlExecManager(
                $dsn,
                $input->getOption('user'),
                $input->getOption('password'),
                (bool) $input->getOption('autocommit'),
                $input->getOption('on-error'),
            );
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Executing SQL Files');
            $io->listing(array_map('basename', $sqlFiles));

            $executed = $manager->execute($sqlFiles);

            $io->success("SQL execution complete. $executed statements successfully executed.");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to execute SQL: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
