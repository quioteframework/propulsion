<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Task;

/**
 * This Task lists the migrations yet to be executed
 *
 * @author     Francois Zaninotto
 * @package    propel.generator.task
 */
use Phing\Task;
use Phing\Io\File;
use Phing\Project;
use Phing\Io\IOException;
use Phing\Exception\BuildException;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Util\PropulsionMigrationManager;
use Propulsion\Generator\Util\MigrationExecutionException;
abstract class BasePropulsionMigrationTask extends Task
{
	/**
	 * Destination directory for results of template scripts.
	 * @var        File
	 */
	protected $outputDirectory;

	/**
	 * An initialized GeneratorConfig object containing the converted Phing props.
	 *
	 * @var        GeneratorConfig
	 */
	protected $generatorConfig;

	/**
	 * The migration table name
	 * @var string
	 */
	protected $migrationTable = 'propulsion_migration';

	/**
	 * Set the migration Table name
	 *
	 * @param string $migrationTable
	 */
	public function setMigrationTable($migrationTable)
	{
		$this->migrationTable = $migrationTable;
	}

	/**
	 * Get the migration Table name
	 *
	 * @return string
	 */
	public function getMigrationTable()
	{
		return $this->migrationTable;
	}

	/**
	 * [REQUIRED] Set the output directory. It will be
	 * created if it doesn't exist.
	 * @param      File $outputDirectory
	 * @return     void
	 * @throws     Exception
	 */
	public function setOutputDirectory(File $outputDirectory) {
		try {
			if (!$outputDirectory->exists()) {
				$this->log("Output directory does not exist, creating: " . $outputDirectory->getPath(),Project::MSG_VERBOSE);
				if (!$outputDirectory->mkdirs()) {
					throw new IOException("Unable to create Ouptut directory: " . $outputDirectory->getAbsolutePath());
				}
			}
			$this->outputDirectory = $outputDirectory->getCanonicalPath();
		} catch (IOException $ioe) {
			throw new BuildException($ioe);
		}
	}

	/**
	 * Get the output directory.
	 * @return     string
	 */
	public function getOutputDirectory() {
		return $this->outputDirectory;
	}

	/**
	 * Gets the GeneratorConfig object for this task or creates it on-demand.
	 * @return     GeneratorConfig
	 */
	protected function getGeneratorConfig()
	{
		if ($this->generatorConfig === null) {
			$this->generatorConfig = new GeneratorConfig();
			$this->generatorConfig->setBuildProperties($this->getProject()->getProperties());
		}
		return $this->generatorConfig;
	}

	/**
	 * Executes one migration direction ('up' or 'down') for every datasource
	 * in the given SQL map (a migration class' getUpSQL()/getDownSQL()
	 * return value), recording the outcome in the migration ledger and
	 * failing the Phing build loudly on the first statement failure.
	 *
	 * The actual transaction-wrapping/statement-execution/ledger-recording
	 * logic lives in the single Phing-free
	 * PropulsionMigrationManager::runMigrationDirection() -- this method is
	 * only a thin adapter translating that method's plain
	 * MigrationExecutionException into Phing's own throw/log conventions
	 * (a Phing\Exception\BuildException, since Phing only fails a build on an
	 * uncaught exception -- returning false from Task::main() does NOT fail
	 * the build, which used to let a partially-applied migration exit 0; see
	 * KNOWN_ISSUES.md's migration-ledger redesign notes). The console
	 * migration:up/migration:down commands call
	 * PropulsionMigrationManager::runMigrationDirection() directly, so both
	 * entry points share exactly one implementation of "how a migration
	 * direction actually executes" and can never drift apart.
	 *
	 * @param      PropulsionMigrationManager $manager
	 * @param      int $timestamp The migration's timestamp identifier.
	 * @param      string $direction 'up' or 'down'.
	 * @param      array $sqlByDatasource Keyed by datasource name, as
	 *             returned by a migration class' getUpSQL()/getDownSQL().
	 * @throws     BuildException On the first statement failure for any
	 *             datasource (after recording the failure in the ledger), or
	 *             if a datasource has no SQL statements to execute at all.
	 */
	protected function runMigrationDirection(PropulsionMigrationManager $manager, $timestamp, $direction, array $sqlByDatasource)
	{
		try {
			$manager->runMigrationDirection($timestamp, $direction, $sqlByDatasource, function ($message, $verbose = false) {
				$this->log($message, $verbose ? Project::MSG_VERBOSE : Project::MSG_INFO);
			});
		} catch (MigrationExecutionException $e) {
			throw new BuildException($e->getMessage(), $e);
		}
	}

}