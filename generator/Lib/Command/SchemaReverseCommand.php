<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Propulsion\Generator\Manager\SchemaReverseManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'schema:reverse',
    description: 'Reverse-engineer a schema.xml from a live database',
    aliases: ['reverse']
)]
class SchemaReverseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO connection DSN of the live database to reverse-engineer, e.g. "pgsql:host=localhost;dbname=mydb"')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Database user', null)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Database password', null)
            ->addOption('database-name', null, InputOption::VALUE_REQUIRED, 'Name to use for the <database name=""> attribute in the generated schema.xml')
            ->addOption('output-file', 'o', InputOption::VALUE_REQUIRED, 'Path to write the generated schema.xml to', './schema.xml')
            ->addOption('add-validators', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of validators to add: none,maxlength,maxvalue,type,required,unique,all', 'none')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Source database adapter (mysql, pgsql, sqlite, ...) -- selects which SchemaParser/Platform to use')
            ->setHelp(<<<'EOT'
The <info>schema:reverse</info> command connects to a live database and reverse-engineers
its tables, columns, types, foreign keys, and indices into a schema.xml file.

<info>php bin/propulsion schema:reverse --dsn="pgsql:host=localhost;dbname=mydb" --database=pgsql --database-name=mydb --user=me --password=secret</info>
<info>php bin/propulsion reverse --dsn="mysql:host=localhost;dbname=mydb" --database=mysql --database-name=mydb -o schema.xml</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion Schema Reverse Engineering');

        $dsn = $input->getOption('dsn');
        if (!$dsn) {
            $io->error('The --dsn option is required, e.g. --dsn="pgsql:host=localhost;dbname=mydb"');
            return Command::FAILURE;
        }

        $databaseName = $input->getOption('database-name');
        if (!$databaseName) {
            $io->error('The --database-name option is required (used as the <database name=""> value in the generated schema.xml)');
            return Command::FAILURE;
        }

        try {
            $config = $this->loadConfiguration($input);

            $manager = new SchemaReverseManager(
                $config,
                $dsn,
                $input->getOption('user'),
                $input->getOption('password'),
            );
            $manager->setLogger(new ConsoleLogger($output));

            $validatorBits = SchemaReverseManager::parseValidatorBits($input->getOption('add-validators'));
            $outputFile = $input->getOption('output-file');

            $io->section('Reverse Engineering Database');
            $manager->generate($databaseName, $outputFile, $validatorBits);

            $io->success("Schema reverse engineered successfully! Written to: $outputFile");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to reverse-engineer schema: ' . $e->getMessage());
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

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $input->getOption('config'),
            $overrides
        );
    }
}
