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
use Propulsion\Generator\Manager\DataDumpManager;
use Propulsion\Generator\Config\GeneratorConfig;

#[AsCommand(
    name: 'data:dump',
    description: 'Dump the rows of a live database into an XML dataset file',
)]
class DataDumpCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('schema', InputArgument::OPTIONAL, 'Schema file or directory', './schema')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output XML dataset file', './dataset.xml')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO connection DSN of the database to dump, e.g. "pgsql:host=localhost;dbname=mydb"')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Database user', null)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Database password', null)
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Only dump the database with this <database name="..."> from the schema (dumps every database in the schema if omitted)')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->setHelp(<<<'EOT'
The <info>data:dump</info> command connects to a live database and dumps the
rows of every table described by a schema.xml into an XML `<dataset>` file --
one child element per row, named after the table's phpName, with column
values as phpName-keyed attributes. Convert the result into INSERT SQL with
<info>data:sql</info>.

<info>php bin/propulsion data:dump schema.xml --dsn="pgsql:host=localhost;dbname=mydb" --user=me --password=secret -o dataset.xml</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Propulsion Data Dump');

        $dsn = $input->getOption('dsn');
        if (!$dsn) {
            $io->error('The --dsn option is required, e.g. --dsn="pgsql:host=localhost;dbname=mydb"');
            return Command::FAILURE;
        }

        try {
            $schemaPath = $input->getArgument('schema');
            $schemas = $this->findSchemaFiles($schemaPath);

            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }

            $config = $this->loadConfiguration($input);
            $manager = new DataDumpManager(
                $config,
                $dsn,
                $input->getOption('user'),
                $input->getOption('password'),
            );
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Dumping Data');
            $rowCount = $manager->dump($schemas, $input->getOption('output'), $input->getOption('database'));

            $io->success("Data dump complete. $rowCount rows written to " . $input->getOption('output'));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to dump data: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function loadConfiguration(InputInterface $input): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.php';

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $input->getOption('config'),
            []
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
