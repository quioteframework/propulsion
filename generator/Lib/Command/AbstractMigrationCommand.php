<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Propulsion\Generator\Config\GeneratorConfig;
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
            ->addOption('buildtime-conf', null, InputOption::VALUE_REQUIRED, 'Path to a build-time connection config file describing the datasource(s) to migrate: a plain PHP file returning [\'default\' => ..., \'datasources\' => [...]] (recommended), or a legacy buildtime-conf.xml file')
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
        // GeneratorConfig::getBuildConnections() can return null (rather than
        // an empty array) when a buildtimeConfFile is configured/defaulted
        // but no matching file is found anywhere it looks -- normalize to []
        // so downstream code (PropulsionMigrationManager::getOldestDatabaseVersion(),
        // this command's own foreach over getConnections()) sees a consistent,
        // iterable "no connections configured" state instead of null.
        $manager->setConnections($config->getBuildConnections() ?? []);
        $manager->setMigrationTable($input->getOption('migration-table'));
        $manager->setMigrationDir($input->getOption('migration-dir'));

        return $manager;
    }

    private function loadConfiguration(InputInterface $input): GeneratorConfig
    {
        $defaultPropertiesFile = dirname(__DIR__, 2) . '/default.php';

        $overrides = [];
        if ($database = $input->getOption('database')) {
            $overrides['propulsion.database'] = $database;
        }
        if ($buildtimeConf = $input->getOption('buildtime-conf')) {
            $overrides['propulsion.buildtimeConfFile'] = $buildtimeConf;
        }

        return GeneratorConfig::createFromPropertiesFile(
            $defaultPropertiesFile,
            $input->getOption('config'),
            $overrides
        );
    }
}
