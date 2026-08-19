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
        if (!is_string($dsn) || $dsn === '') {
            $io->error('The --dsn option is required, e.g. --dsn="pgsql:host=localhost;dbname=mydb"');
            return Command::FAILURE;
        }

        try {
            $schemaPathArg = $input->getArgument('schema');
            $schemaPath = is_string($schemaPathArg) ? $schemaPathArg : './schema';
            $schemas = $this->findSchemaFiles($schemaPath);

            if (empty($schemas)) {
                $io->error("No schema files found in: $schemaPath");
                return Command::FAILURE;
            }

            $outputOption = $input->getOption('output');
            $output_file = is_string($outputOption) ? $outputOption : './dataset.xml';
            $user = $input->getOption('user');
            $user = is_string($user) ? $user : null;
            $password = $input->getOption('password');
            $password = is_string($password) ? $password : null;
            $database = $input->getOption('database');
            $database = is_string($database) ? $database : null;

            $config = $this->loadConfiguration($input, $dsn);
            $manager = new DataDumpManager(
                $config,
                $dsn,
                $user,
                $password,
            );
            $manager->setLogger(new ConsoleLogger($output));

            $io->section('Dumping Data');
            $rowCount = $manager->dump($schemas, $output_file, $database);

            $io->success("Data dump complete. $rowCount rows written to $output_file");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to dump data: ' . $e->getMessage());
            if ($output->isVeryVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function loadConfiguration(InputInterface $input, string $dsn): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.php';

        // Unlike data:sql's --database flag (DataSqlCommand::loadConfiguration()),
        // there is no separate CLI option naming the target SQL platform here:
        // --database/-d already means something else for this command (which
        // <database name="..."> element of the *schema* to dump). --dsn is the
        // only thing this command is always given that says what the platform
        // actually is, so its driver prefix is what sets propulsion.database --
        // otherwise it silently stays at the hardcoded pgsql default
        // (default.php's propulsion.database), regardless of what --dsn says.
        $overrides = [];
        $database = self::databaseFromDsn($dsn);
        if ($database !== null) {
            $overrides['propulsion.database'] = $database;
        }

        $configOption = $input->getOption('config');
        $configFiles = is_array($configOption) ? array_values(array_filter($configOption, 'is_string')) : [];

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $configFiles,
            $overrides
        );
    }

    /**
     * Maps a PDO DSN's driver prefix (the part before the first ":") to the
     * propulsion.database value naming the same platform in this codebase's
     * build properties. Two of them differ from PDO's own driver name --
     * "dblib" is "mssql" here, "oci" is "oracle" -- the same mapping
     * PropulsionPDOTrait::$savepointCapableDrivers's docblock and
     * OpenTelemetryQueryObserver::DB_SYSTEM_BY_DRIVER already encode, for the
     * same underlying reason, elsewhere in this codebase.
     *
     * Null for anything unrecognized (including a DSN with no ":" at all),
     * which callers should treat as "no override" -- falling through to
     * whatever propulsion.database already resolves to, exactly as before
     * this method existed, rather than forcing a wrong platform from a
     * malformed DSN PDO itself is about to fail on anyway.
     */
    private static function databaseFromDsn(string $dsn): ?string
    {
        $driver = strtolower(explode(':', $dsn, 2)[0]);

        return match ($driver) {
            'pgsql', 'mysql', 'sqlite', 'sqlsrv' => $driver,
            'dblib' => 'mssql',
            'oci' => 'oracle',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function findSchemaFiles(string $path): array
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
