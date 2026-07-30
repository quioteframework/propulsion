<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Util\PropulsionMigrationManager;

/**
 * Shared option wiring for the migration:status/migration:up/migration:down
 * console commands: all three need the same "which datasource(s), which
 * migration directory/table" configuration to build a PropulsionMigrationManager.
 */
abstract class AbstractMigrationCommand extends Command
{
    protected function configureMigrationOptions(): static
    {
        $this
            ->addOption('migration-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory containing PropulsionMigration_<timestamp>.php migration classes', './migrations')
            ->addOption('migration-table', null, InputOption::VALUE_REQUIRED, 'Migration ledger table name', 'propulsion_migration')
            ->addOption('buildtime-conf', null, InputOption::VALUE_REQUIRED, 'Path to a build-time connection config file describing the datasource(s) to migrate: a plain PHP file returning [\'default\' => ..., \'datasources\' => [...]]')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Build properties file overriding generator/default.php (repeatable; later files win)', [])
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Target database adapter (mysql, pgsql, sqlite, ...)');

        return $this;
    }

    /**
     * @throws \Throwable if connection settings can't be resolved at all.
     */
    protected function buildManager(InputInterface $input): PropulsionMigrationManager
    {
        $config = $this->loadConfiguration($input);

        $manager = new PropulsionMigrationManager();
        // GeneratorConfig::getBuildConnections() always normalizes to [] itself
        // when no buildtimeConfFile/array/string source is configured or found.
        $manager->setConnections($config->getBuildConnections());
        $manager->setMigrationTable($this->requireStringOption($input, 'migration-table'));
        $manager->setMigrationDir($this->requireStringOption($input, 'migration-dir'));

        return $manager;
    }

    private function loadConfiguration(InputInterface $input): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.php';

        $overrides = [];
        $database = $input->getOption('database');
        if (is_string($database) && $database !== '') {
            $overrides['propulsion.database'] = $database;
        }
        $buildtimeConf = $input->getOption('buildtime-conf');
        if (is_string($buildtimeConf) && $buildtimeConf !== '') {
            $overrides['propulsion.buildtimeConfFile'] = $buildtimeConf;
        }

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $this->configOptionFiles($input),
            $overrides
        );
    }

    /**
     * Narrows a required, string-typed console option (one that was
     * registered with InputOption::VALUE_REQUIRED and a string default) to
     * a non-empty string.
     *
     * @throws EngineException if the option's value is missing or not a string.
     */
    private function requireStringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || $value === '') {
            throw new EngineException("The --$name option must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @return array<string>
     */
    private function configOptionFiles(InputInterface $input): array
    {
        $config = $input->getOption('config');

        return is_array($config) ? array_values(array_filter($config, 'is_string')) : [];
    }
}
