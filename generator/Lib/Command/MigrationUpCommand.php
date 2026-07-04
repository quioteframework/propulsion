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
 * Console replacement for the former Phing-based PropulsionMigrationUpTask
 * (Phing itself has since been removed from this project entirely -- see
 * KNOWN_ISSUES.md): executes the next pending migration's Up SQL against the
 * configured datasource(s).
 *
 * The actual statement-execution/transaction/ledger-recording logic lives in
 * PropulsionMigrationManager::runMigrationDirection() (shared with
 * migration:down) -- see that method's doc comment for the full
 * transaction/ledger semantics.
 *
 * On a statement failure, this command returns Command::FAILURE (a non-zero
 * process exit code) -- this is the console-world equivalent of a bug fixed
 * during the migration ledger redesign (see KNOWN_ISSUES.md), where the old
 * Phing task signaled failure via `return false` from Task::main(), which
 * does not fail a Phing build: a half-applied migration must never exit 0.
 */
#[AsCommand(
    name: 'migration:up',
    description: 'Execute the next pending migration up',
)]
class MigrationUpCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->configureMigrationOptions()
            ->setHelp(<<<'EOT'
The <info>migration:up</info> command executes the next pending migration class'
Up SQL against the configured datasource(s), recording the outcome in the
migration ledger.

<info>php bin/propulsion migration:up --buildtime-conf=buildtime-conf.xml --migration-dir=./migrations</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Propulsion Migration Up');

        try {
            $manager = $this->buildManager($input);

            $nextMigrationTimestamp = $manager->getFirstUpMigrationTimestamp();
            if (!$nextMigrationTimestamp) {
                $io->success('All migrations were already executed - nothing to migrate.');
                return Command::SUCCESS;
            }

            $io->section(sprintf('Executing migration %s up', PropulsionMigrationManager::getMigrationClassName($nextMigrationTimestamp)));

            $migration = $manager->getMigrationObject($nextMigrationTimestamp);
            if (false === $migration->preUp($manager)) {
                $io->error('preUp() returned false. Aborting migration.');
                return Command::FAILURE;
            }

            $manager->runMigrationDirection(
                $nextMigrationTimestamp,
                'up',
                $migration->getUpSQL(),
                function ($message, $verbose = false) use ($output) {
                    $output->writeln($message, $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
                }
            );

            $migration->postUp($manager);

            if ($remaining = $manager->getValidMigrationTimestamps()) {
                $io->success(sprintf('Migration complete. %d migration(s) left to execute.', count($remaining)));
            } else {
                $io->success('Migration complete. No further migration to execute.');
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
