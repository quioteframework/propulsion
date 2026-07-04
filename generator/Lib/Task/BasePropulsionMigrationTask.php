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
use Propulsion\Generator\Util\PropulsionSQLParser;
use PDOException;
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
	 * Fixes two historical bugs shared by PropulsionMigrationUpTask and
	 * PropulsionMigrationDownTask:
	 *  - PropulsionMigrationDownTask used to catch a failed statement's
	 *    PDOException, log it, and keep going -- if any statement in a
	 *    direction's migration succeeded, the version was still marked fully
	 *    reverted/applied even though a later statement failed.
	 *  - Both tasks used to signal failure via `return false` from main(),
	 *    which does NOT fail a Phing build/target (Phing only fails on an
	 *    uncaught exception) -- so a partially-applied migration exited 0.
	 *
	 * Statements are executed sequentially; on the first failure, execution
	 * stops immediately (remaining statements are recorded as
	 * 'not_attempted', not attempted). On a platform whose DDL is genuinely
	 * transactional (see PropulsionPlatformInterface::supportsTransactionalDDL()),
	 * the whole batch is wrapped in a transaction that gets rolled back on
	 * failure, so nothing partially applied survives in the real schema; on a
	 * non-transactional platform, whatever succeeded before the failure
	 * remains applied for real -- this is an inherent limitation of
	 * non-transactional DDL, not papered over here, and the ledger's
	 * per-statement log plus success=false record it accurately so a human
	 * can reconcile before retrying.
	 *
	 * Every attempt (success or failure) gets exactly one ledger row via
	 * PropulsionMigrationManager::recordMigrationRun() -- see that method's
	 * doc comment for why the insert always goes through a separate
	 * connection from the one used to run the DDL statements.
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
		foreach ($sqlByDatasource as $datasource => $sql) {
			$connection = $manager->getConnection($datasource);
			$this->log(sprintf(
				'Connecting to database "%s" using DSN "%s"',
				$datasource,
				$connection['dsn']
			), Project::MSG_VERBOSE);

			$platform = $manager->getPlatform($datasource);
			$pdo = $manager->getPdoConnection($datasource);
			$statements = PropulsionSQLParser::parseString($sql);

			if (!$statements) {
				$this->log('No statement was executed. The version was not updated.');
				$this->log(sprintf(
					'Please review the code in "%s"',
					$manager->getMigrationDir() . DIRECTORY_SEPARATOR . $manager->getMigrationClassName($timestamp)
				));
				throw new BuildException(sprintf(
					'Migration %s aborted: no SQL statements found for datasource "%s".',
					$manager->getMigrationClassName($timestamp),
					$datasource
				));
			}

			$transactional = $platform->supportsTransactionalDDL();
			if ($transactional) {
				$pdo->beginTransaction();
			}

			$statementLog = array();
			$failed = false;
			$failureMessage = null;

			foreach ($statements as $statement) {
				if ($failed) {
					$statementLog[] = array('sql' => $statement, 'status' => 'not_attempted');
					continue;
				}
				try {
					$this->log(sprintf('Executing statement "%s"', $statement), Project::MSG_VERBOSE);
					$stmt = $pdo->prepare($statement);
					$stmt->execute();
					$statementLog[] = array('sql' => $statement, 'status' => 'success');
				} catch (PDOException $e) {
					$this->log(sprintf('Failed to execute SQL "%s": %s', $statement, $e->getMessage()), Project::MSG_ERR);
					$statementLog[] = array('sql' => $statement, 'status' => 'failed', 'error' => $e->getMessage());
					$failed = true;
					$failureMessage = $e->getMessage();
				}
			}

			if ($transactional) {
				if ($failed) {
					$pdo->rollBack();
				} else {
					$pdo->commit();
				}
			}

			$success = !$failed;

			$manager->recordMigrationRun($datasource, $timestamp, $direction, $sql, $success, $statementLog);

			if ($failed) {
				throw new BuildException(sprintf(
					'Migration %s failed on datasource "%s": %s. See the migration ledger ("%s") for the full per-statement log.',
					$manager->getMigrationClassName($timestamp),
					$datasource,
					$failureMessage,
					$manager->getMigrationTable()
				));
			}

			$this->log(sprintf(
				'%d of %d SQL statements executed successfully on datasource "%s"',
				count($statements),
				count($statements),
				$datasource
			));
		}
	}

}