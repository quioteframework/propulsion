<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Propulsion\Generator\Util\PropulsionMigrationManager;

/**
 * Console replacement for the Phing-based PropulsionMigrationStatusTask: lists
 * migrations already executed and migrations still pending against the
 * configured datasource(s).
 */
#[AsCommand(
    name: 'migration:status',
    description: 'List pending and already-applied migrations',
    aliases: ['migration-status']
)]
class MigrationStatusCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->configureMigrationOptions()
            ->setHelp(<<<'EOT'
The <info>migration:status</info> command lists which migration classes (in
--migration-dir) have already been applied to the configured datasource(s) and
which are still pending.

<info>php bin/propulsion migration:status --buildtime-conf=buildtime-conf.php --migration-dir=./migrations</info>

A legacy buildtime-conf.xml file (deprecated) is also still accepted.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Propulsion Migration Status');

        try {
            $manager = $this->buildManager($input);
        } catch (\Throwable $e) {
            $io->error('Failed to determine datasource connections: ' . $e->getMessage());
            return Command::FAILURE;
        }

        try {
            $io->section('Checking Database Versions');
            foreach ($manager->getConnections() as $datasource => $params) {
                $output->writeln(
                    sprintf('Connecting to database "%s" using DSN "%s"', $datasource, $params['dsn']),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                if (!$manager->migrationTableExists($datasource)) {
                    $output->writeln(
                        sprintf('Migration table does not exist in datasource "%s"; creating it.', $datasource),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    $manager->createMigrationTable($datasource);
                }
            }

            $oldestMigrationTimestamp = $manager->getOldestDatabaseVersion();
            if ($oldestMigrationTimestamp) {
                $output->writeln(
                    sprintf('Latest migration was executed on %s (timestamp %d)', date('Y-m-d H:i:s', $oldestMigrationTimestamp), $oldestMigrationTimestamp),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            } else {
                $output->writeln('No migration was ever executed on these connection settings.', OutputInterface::VERBOSITY_VERBOSE);
            }

            $io->section('Migration Files');
            $dir = $manager->getMigrationDir();
            $migrationTimestamps = $manager->getMigrationTimestamps();

            if (!$migrationTimestamps) {
                $io->warning(sprintf('No migration file found in "%s". Run the sql:diff command to generate one.', $dir));
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('%d migration classes found in "%s"', count($migrationTimestamps), $dir));

            $validTimestamps = $manager->getValidMigrationTimestamps();
            foreach ($migrationTimestamps as $timestamp) {
                $executed = $timestamp <= $oldestMigrationTimestamp;
                $io->writeln(sprintf(
                    ' %s %s %s',
                    $timestamp == $oldestMigrationTimestamp ? '>' : ' ',
                    PropulsionMigrationManager::getMigrationClassName($timestamp),
                    $executed ? '(executed)' : ''
                ));
            }

            if (!$validTimestamps) {
                $io->success('All migration files were already executed - nothing to migrate.');
                return Command::SUCCESS;
            }

            $io->note(sprintf(
                'Run "migration:up" to execute %s.',
                count($validTimestamps) === 1 ? 'it' : 'them'
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to determine migration status: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
