<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Manager;

use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\AppData;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseComparator;
use Propulsion\Generator\Util\PropulsionMigrationManager;

/**
 * Plain-PHP replacement for the Phing-based PropulsionSQLDiffTask: connects to
 * every configured buildtime datasource, reverse-engineers its live structure,
 * compares it against the given schema.xml file(s), and -- if any datasource
 * differs -- writes a new PropulsionMigration_<timestamp>.php migration class
 * with the up/down SQL for every changed datasource.
 *
 * Deliberately supports only "live database vs schema.xml" mode, same as the
 * original Task (which reads connections from a buildtime-conf and has no
 * "two schema.xml files" mode) -- see KNOWN_ISSUES.md.
 *
 * Extends AbstractSchemaManager purely to reuse loadDataModels() for the
 * schema.xml side of the comparison; the live-database side has no schema
 * file to load, so it is built directly from GeneratorConfig's configured
 * platform/schema parser, mirroring PropulsionSQLDiffTask::main().
 */
class SqlDiffManager extends AbstractSchemaManager
{
    public function __construct(
        GeneratorConfig $generatorConfig,
        private readonly string $migrationDir,
        private readonly bool $caseInsensitive = false,
        ?string $defaultPackage = null,
        string $dbEncoding = 'utf-8',
    ) {
        parent::__construct($generatorConfig, $defaultPackage, $dbEncoding);
    }

    /**
     * @param string[] $schemaFiles
     * @return string|null Absolute/relative path to the generated migration
     *                      file, or null if there was no structural
     *                      difference to record for any datasource.
     */
    public function generate(array $schemaFiles): ?string
    {
        $connections = $this->generatorConfig->getBuildConnections();
        if (!$connections) {
            throw new EngineException('You must define database connection settings (e.g. via --buildtime-conf pointing at a buildtime-conf.php file) to use sql:diff');
        }

        $this->logger->info('Reading database structures...');
        $liveAppData = new AppData();
        $totalNbTables = 0;
        foreach ($connections as $name => $params) {
            $this->logger->debug('Connecting to database "{name}" using DSN "{dsn}"', ['name' => $name, 'dsn' => $params['dsn']]);
            $pdo = $this->generatorConfig->getBuildPDO($name);
            $database = new Database($name);
            $platform = $this->generatorConfig->getConfiguredPlatform($pdo);
            if (!$platform->supportsMigrations()) {
                $this->logger->info('Skipping database "{name}" since vendor "{type}" does not support migrations', ['name' => $name, 'type' => $platform->getDatabaseType()]);
                continue;
            }
            $database->setPlatform($platform);
            $database->setDefaultIdMethod(IDMethod::NATIVE);
            $parser = $this->generatorConfig->getConfiguredSchemaParser($pdo);
            $nbTables = $parser->parse($database, null);
            $liveAppData->addDatabase($database);
            $totalNbTables += $nbTables;
            $this->logger->debug('{count} tables found in database "{name}"', ['count' => $nbTables, 'name' => $name]);
        }
        $this->logger->info($totalNbTables
            ? "$totalNbTables tables found in all databases."
            : 'No table found in all databases');

        $dataModels = $this->loadDataModels($schemaFiles);
        $appDataFromXml = array_pop($dataModels);

        $this->logger->info('Comparing models...');
        $migrationsUp = [];
        $migrationsDown = [];
        foreach ($liveAppData->getDatabases() as $database) {
            $name = $database->getName();
            if (!$appDataFromXml->hasDatabase($name)) {
                // Tables present in the live database but not in the XML are
                // out of scope, matching the original Task's FIXME comment.
                continue;
            }

            $databaseDiff = PropulsionDatabaseComparator::computeDiff($database, $appDataFromXml->getDatabase($name), $this->caseInsensitive);
            if (!$databaseDiff) {
                $this->logger->debug('Same XML and database structures for datasource "{name}" - no diff to generate', ['name' => $name]);
                continue;
            }

            $this->logger->info('Structure of database was modified in datasource "{name}": {description}', [
                'name' => $name,
                'description' => $databaseDiff->getDescription(),
            ]);
            $diffPlatform = $this->generatorConfig->getConfiguredPlatform(null, $name);
            $migrationsUp[$name] = $diffPlatform->getModifyDatabaseDDL($databaseDiff);
            $migrationsDown[$name] = $diffPlatform->getModifyDatabaseDDL($databaseDiff->getReverseDiff());
        }

        if (!$migrationsUp) {
            $this->logger->info('Same XML and database structures for all datasource - no diff to generate');
            return null;
        }

        if (!is_dir($this->migrationDir) && !mkdir($this->migrationDir, 0777, true) && !is_dir($this->migrationDir)) {
            throw new EngineException("Error creating directory: {$this->migrationDir}");
        }

        $timestamp = time();
        $migrationManager = new PropulsionMigrationManager();
        $migrationManager->setConnections($connections);
        $migrationManager->setMigrationDir($this->migrationDir);

        $migrationFileName = PropulsionMigrationManager::getMigrationFileName($timestamp);
        $migrationClassBody = $migrationManager->getMigrationClassBody($migrationsUp, $migrationsDown, $timestamp);

        $outputPath = rtrim($this->migrationDir, '/\\') . DIRECTORY_SEPARATOR . $migrationFileName;
        file_put_contents($outputPath, $migrationClassBody);
        $this->logger->info('"{file}" file successfully created in {dir}', ['file' => $migrationFileName, 'dir' => $this->migrationDir]);

        return $outputPath;
    }
}
