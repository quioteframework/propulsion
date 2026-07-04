<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Propulsion\Generator\Util\PropulsionMigrationManager;
use Propulsion\Generator\Util\MigrationExecutionException;

/**
 * Console replacement for the former Phing-based PropulsionMigrationDownTask
 * (see KNOWN_ISSUES.md -- Phing has since been removed entirely): reverts the
 * most-recently-applied migration's Down SQL against the configured
 * datasource(s).
 *
 * See MigrationUpCommand's doc comment: the actual execution logic lives in
 * PropulsionMigrationManager::runMigrationDirection(), and a statement
 * failure returns Command::FAILURE (non-zero exit), never a silent success.
 */
#[AsCommand(
    name: 'migration:down',
    description: 'Revert the most recently applied migration',
)]
class MigrationDownCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->configureMigrationOptions()
            ->setHelp(<<<'EOT'
The <info>migration:down</info> command executes the most-recently-applied
migration class' Down SQL against the configured datasource(s), recording the
outcome in the migration ledger.

<info>php bin/propulsion migration:down --buildtime-conf=buildtime-conf.php --migration-dir=./migrations</info>

A legacy buildtime-conf.xml file (deprecated) is also still accepted.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Propulsion Migration Down');

        try {
            $manager = $this->buildManager($input);

            $previousTimestamps = $manager->getAlreadyExecutedMigrationTimestamps();
            $nextMigrationTimestamp = array_pop($previousTimestamps);
            if (!$nextMigrationTimestamp) {
                $io->success('No migration was ever executed on this database - nothing to reverse.');
                return Command::SUCCESS;
            }

            $io->section(sprintf('Executing migration %s down', PropulsionMigrationManager::getMigrationClassName($nextMigrationTimestamp)));

            $migration = $manager->getMigrationObject($nextMigrationTimestamp);
            if (false === $migration->preDown($manager)) {
                $io->error('preDown() returned false. Aborting migration.');
                return Command::FAILURE;
            }

            $manager->runMigrationDirection(
                $nextMigrationTimestamp,
                'down',
                $migration->getDownSQL(),
                function ($message, $verbose = false) use ($output) {
                    $output->writeln($message, $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
                }
            );

            $migration->postDown($manager);

            $remainingTimestamps = $manager->getAlreadyExecutedMigrationTimestamps();
            if ($nbRemaining = count($remainingTimestamps)) {
                $io->success(sprintf('Reverse migration complete. %d more migration(s) available for reverse.', $nbRemaining));
            } else {
                $io->success('Reverse migration complete. No more migration available for reverse.');
            }

            return Command::SUCCESS;
        } catch (MigrationExecutionException $e) {
            $io->error($e->getMessage());
            $this->renderStatementLog($io, $e->getStatementLog());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function renderStatementLog(SymfonyStyle $io, array $statementLog): void
    {
        if (!$statementLog) {
            return;
        }
        $io->section('Statement log');
        foreach ($statementLog as $entry) {
            $line = sprintf('[%s] %s', strtoupper($entry['status']), $entry['sql']);
            if (isset($entry['error'])) {
                $line .= ' -- ' . $entry['error'];
            }
            $io->writeln($line);
        }
    }
}
