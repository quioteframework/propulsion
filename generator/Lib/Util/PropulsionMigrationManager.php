<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Util;

/**
 * Service class for preparing and executing migrations
 *
 * @author     François Zaninotto
 * @version    $Revision$
 * @package    propel.generator.util
 */

 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Model\Database;
 use Propulsion\Generator\Model\IDMethod;
 use \Exception;
 use \PDO;
 use \PDOException;

class PropulsionMigrationManager
{
	protected $connections;
	protected $pdoConnections = array();
	protected $migrationTable = 'propulsion_migration';
	protected $migrationDir;

	/**
	 * Set the database connection settings
	 *
	 * @param array $connections
	 */
	public function setConnections($connections)
	{
		$this->connections = $connections;
	}

	/**
	 * Get the database connection settings
	 *
	 * @return array
	 */
	public function getConnections()
	{
		return $this->connections;
	}

	public function getConnection($datasource)
	{
		if (!isset($this->connections[$datasource])) {
			throw new \InvalidArgumentException(sprintf('Unkown datasource "%s"', $datasource));
		}
		return $this->connections[$datasource];
	}

	public function getPdoConnection($datasource)
	{
		if (!isset($pdoConnections[$datasource])) {
			$pdo = $this->createPdoConnection($datasource);

			$pdoConnections[$datasource] = $pdo;
		}

		return $pdoConnections[$datasource];
	}

	/**
	 * Opens and returns a brand-new PDO connection to the given datasource,
	 * independent of whatever getPdoConnection() may or may not have cached.
	 *
	 * Used by recordMigrationRun() to guarantee the migration ledger insert
	 * happens over a connection that is NOT part of whatever transaction the
	 * caller may have open on its own connection for running the migration's
	 * DDL statements -- see recordMigrationRun()'s doc comment for why that
	 * matters.
	 *
	 * @param string $datasource
	 * @return PDO
	 */
	protected function createPdoConnection($datasource)
	{
		$buildConnection = $this->getConnection($datasource);
		$dsn = str_replace("@DB@", $datasource, $buildConnection['dsn']);

		// Set user + password to null if they are empty strings or missing
		$username = isset($buildConnection['user']) && $buildConnection['user'] ? $buildConnection['user'] : null;
		$password = isset($buildConnection['password']) && $buildConnection['password'] ? $buildConnection['password'] : null;

		$pdo = new PDO($dsn, $username, $password);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		return $pdo;
	}

	public function getPlatform($datasource)
	{
		$params = $this->getConnection($datasource);
		$adapter = $params['adapter'];
		$adapterClass = ucfirst($adapter) . 'Platform';
		// Require the platform file from the bundled Platform directory
		require_once sprintf('%s/../Platform/%s.php',
			dirname(__FILE__),
				$adapterClass
		);

		// Prefer the namespaced class if it exists (Propulsion\Generator\Platform\{Adapter}Platform),
		// fall back to a global class name (legacy). This handles both modern PSR-4 files
		// that declare a namespace and older files that declare classes in the global space.
		$namespaced = 'Propulsion\\Generator\\Platform\\' . $adapterClass;
		if (class_exists($namespaced, false) || class_exists($namespaced)) {
			return new $namespaced();
		}

		if (class_exists($adapterClass, false) || class_exists($adapterClass)) {
			return new $adapterClass();
		}

		throw new \RuntimeException(sprintf('Platform class "%s" not found after requiring file', $adapterClass));
	}

	/**
	 * Set the migration table name
	 *
	 * @param string $migrationTable
	 */
	public function setMigrationTable($migrationTable)
	{
		$this->migrationTable = $migrationTable;
	}

	/**
	 * get the migration table name
	 *
	 * @return string
	 */
	public function getMigrationTable()
	{
		return $this->migrationTable;
	}

	/**
	 * Set the path to the migration classes
	 *
	 * @param string $migrationDir
	 */
	public function setMigrationDir($migrationDir)
	{
		$this->migrationDir = $migrationDir;
	}

	/**
	 * Get the path to the migration classes
	 *
	 * @return string
	 */
	public function getMigrationDir()
	{
		return $this->migrationDir;
	}

	/**
	 * Derives the "currently applied" migration timestamp for a single
	 * datasource from the append-only migration ledger (see
	 * createMigrationTable()/recordMigrationRun()): for each distinct
	 * migration_timestamp recorded, only its most recent SUCCESSFUL row
	 * (highest id among rows with success = true) matters, and a timestamp
	 * counts as currently applied if that row's direction is 'up'. Returns
	 * the max of all currently-applied timestamps, or 0 if none are applied
	 * -- the same "0 means nothing applied yet" baseline the old single-row
	 * `version` column used.
	 *
	 * Failed attempts (success = false), in either direction, are
	 * deliberately excluded from this derivation entirely -- they're purely
	 * an audit-log entry (see recordMigrationRun()), and never move the
	 * applied-state pointer. This matters most for a failed "down": on a
	 * transactional-DDL platform a failed down attempt rolls back to the
	 * still-applied "up" state in the real schema, and the ledger must agree
	 * -- if the failed down row were allowed to be "the most recent row"
	 * regardless of success, the migration would be incorrectly reported as
	 * no longer applied even though nothing was actually reverted.
	 *
	 * Throws \PDOException (uncaught) if the migration table doesn't exist --
	 * callers (getOldestDatabaseVersion()) rely on this to detect a
	 * not-yet-initialized datasource and create the table on the fly.
	 *
	 * @param string $datasource
	 * @return int
	 */
	public function getCurrentVersion($datasource)
	{
		$pdo = $this->getPdoConnection($datasource);
		$platform = $this->getPlatform($datasource);
		$sql = sprintf(
			'SELECT %s, %s, %s FROM %s ORDER BY %s ASC',
			$platform->quoteIdentifier('migration_timestamp'),
			$platform->quoteIdentifier('direction'),
			$platform->quoteIdentifier('success'),
			$this->getMigrationTable(),
			$platform->quoteIdentifier('id')
		);
		$stmt = $pdo->prepare($sql);
		$stmt->execute();

		// Only the most recent SUCCESSFUL row (by insertion order / id) per
		// timestamp matters -- iterating in ascending id order and
		// overwriting only on success lets the last successful write for a
		// given timestamp win, while failed attempts are skipped entirely
		// and never overwrite a prior successful state.
		$lastSuccessfulDirectionByTimestamp = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if (!self::toBool($row['success'])) {
				continue;
			}
			$timestamp = (int) $row['migration_timestamp'];
			$lastSuccessfulDirectionByTimestamp[$timestamp] = $row['direction'];
		}

		$appliedTimestamps = array();
		foreach ($lastSuccessfulDirectionByTimestamp as $timestamp => $direction) {
			if ($direction === 'up') {
				$appliedTimestamps[] = $timestamp;
			}
		}

		return $appliedTimestamps ? max($appliedTimestamps) : 0;
	}

	/**
	 * Returns every ledger row recorded for the given datasource, ordered by
	 * insertion order (id ascending) -- i.e. the full audit trail of every
	 * migration run/reversion attempt, successful or not. For
	 * reporting/debugging use; the "currently applied" state itself is
	 * derived by getCurrentVersion(), not read from here.
	 *
	 * @param string $datasource
	 * @return array
	 */
	public function getMigrationLedger($datasource)
	{
		$pdo = $this->getPdoConnection($datasource);
		$sql = sprintf('SELECT * FROM %s ORDER BY id ASC', $this->getMigrationTable());
		$stmt = $pdo->prepare($sql);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getOldestDatabaseVersion()
	{
		if (!$connections = $this->getConnections()) {
			throw new Exception('You must define database connection settings in a build-time connection config file (a buildtime-conf.php returning [\'default\' => ..., \'datasources\' => [...]], or a legacy buildtime-conf.xml) to use migrations');
		}
		$oldestMigrationTimestamp = null;
		$migrationTimestamps = array();
		foreach ($connections as $name => $params) {
			try {
				// Use !== false (not a truthy check) so a legitimate version of 0 --
				// the documented "no migrations applied yet" baseline, and what a
				// full "down" back to the start leaves behind -- isn't mistaken
				// for "no row fetched", which would incorrectly return null here
				// instead of 0.
				$migrationTimestamps[$name] = $this->getCurrentVersion($name);
			} catch (\PDOException $e) {
				$this->createMigrationTable($name);
				$oldestMigrationTimestamp = 0;
			}
		}
		if ($oldestMigrationTimestamp === null && $migrationTimestamps) {
			sort($migrationTimestamps);
			$oldestMigrationTimestamp = array_shift($migrationTimestamps);
		}

		return $oldestMigrationTimestamp;
	}

	public function migrationTableExists($datasource)
	{
		$pdo = $this->getPdoConnection($datasource);
		$platform = $this->getPlatform($datasource);
		$sql = sprintf('SELECT %s FROM %s', $platform->quoteIdentifier('id'), $this->getMigrationTable());
		$stmt = $pdo->prepare($sql);
		try {
			$stmt->execute();
			return true;
		} catch (\PDOException $e) {
			return false;
		}
	}

	/**
	 * Creates the migration ledger table.
	 *
	 * NOTE: this is a breaking schema change from the old single-row
	 * `propel_migration` table (a single `version` column, mutated via
	 * DELETE + INSERT on every migration run). The new table is an
	 * append-only ledger -- every migration run/reversion attempt gets a new
	 * row, never updated or deleted -- so a project with an existing
	 * old-shape migration table needs to drop it and let this method
	 * recreate it in the new shape; there is no automatic migration-of-the-
	 * migration-table. See KNOWN_ISSUES.md.
	 *
	 * @param string $datasource
	 */
	public function createMigrationTable($datasource)
	{
		$platform = $this->getPlatform($datasource);
		// modelize the table
		$database = new Database($datasource);
		$database->setPlatform($platform);
		$table = new Table($this->getMigrationTable());
		$database->addTable($table);
		$table->setIdMethod(IDMethod::NATIVE);

		$idColumn = new Column('id');
		$idColumn->getDomain()->copy($platform->getDomainForType('INTEGER'));
		$idColumn->setNotNull(true);
		$idColumn->setAutoIncrement(true);
		$idColumn->setPrimaryKey(true);
		$table->addColumn($idColumn);

		$timestampColumn = new Column('migration_timestamp');
		$timestampColumn->getDomain()->copy($platform->getDomainForType('INTEGER'));
		$timestampColumn->setNotNull(true);
		$table->addColumn($timestampColumn);

		$nameColumn = new Column('migration_name');
		$nameColumn->getDomain()->copy($platform->getDomainForType('VARCHAR'));
		$nameColumn->setSize(255);
		$nameColumn->setNotNull(true);
		$table->addColumn($nameColumn);

		$directionColumn = new Column('direction');
		$directionColumn->getDomain()->copy($platform->getDomainForType('VARCHAR'));
		$directionColumn->setSize(4);
		$directionColumn->setNotNull(true);
		$table->addColumn($directionColumn);

		$checksumColumn = new Column('checksum');
		$checksumColumn->getDomain()->copy($platform->getDomainForType('VARCHAR'));
		$checksumColumn->setSize(64);
		$checksumColumn->setNotNull(true);
		$table->addColumn($checksumColumn);

		$appliedAtColumn = new Column('applied_at');
		$appliedAtColumn->getDomain()->copy($platform->getDomainForType('TIMESTAMP'));
		$appliedAtColumn->setNotNull(true);
		$table->addColumn($appliedAtColumn);

		$successColumn = new Column('success');
		$successColumn->getDomain()->copy($platform->getDomainForType('BOOLEAN'));
		$successColumn->setNotNull(true);
		$table->addColumn($successColumn);

		$statementLogColumn = new Column('statement_log');
		$statementLogColumn->getDomain()->copy($platform->getDomainForType('LONGVARCHAR'));
		$table->addColumn($statementLogColumn);

		// insert the table into the database
		$statements = $platform->getAddTableDDL($table);
		$pdo = $this->getPdoConnection($datasource);
		$res = PropulsionSQLParser::executeString($statements, $pdo);
		if (!$res) {
			throw new Exception(sprintf('Unable to create migration table in datasource "%s"', $datasource));
		}
	}

	/**
	 * Appends a new row to the migration ledger recording one migration
	 * run/reversion attempt -- never updates or deletes an existing row, so
	 * the ledger is a complete, permanent audit trail (see
	 * createMigrationTable()'s doc comment).
	 *
	 * Always writes through a brand-new, dedicated PDO connection (see
	 * createPdoConnection()) rather than whatever connection the caller used
	 * to run the migration's DDL statements. This is deliberate: on a
	 * transactional-DDL platform (see
	 * PropulsionPlatformInterface::supportsTransactionalDDL()), the caller
	 * wraps the DDL statements in a transaction and rolls the whole thing
	 * back on failure -- if the ledger insert were part of that same
	 * transaction, a failed attempt's ledger row would vanish along with the
	 * rollback, silently defeating the "record every attempt, successful or
	 * not" requirement this table exists to satisfy. A fresh connection is
	 * always in its own autocommit transaction, so this insert commits
	 * immediately and independently of whatever happens to the DDL
	 * connection/transaction.
	 *
	 * @param string $datasource
	 * @param int $timestamp The migration's timestamp identifier.
	 * @param string $direction 'up' or 'down'.
	 * @param string $sql The exact (pre-statement-splitting) SQL string that
	 *                    was executed for this direction -- checksummed via
	 *                    sha256 and stored so a future validate/status command
	 *                    could detect a migration file edited after it ran.
	 * @param bool $success Whether this attempt fully succeeded.
	 * @param array $statementLog List of
	 *                    ['sql' => ..., 'status' => 'success'|'failed'|'not_attempted', 'error' => ...]
	 *                    entries, one per statement in this direction's
	 *                    migration ('error' only present when status is
	 *                    'failed').
	 */
	public function recordMigrationRun($datasource, $timestamp, $direction, $sql, $success, array $statementLog)
	{
		$platform = $this->getPlatform($datasource);
		$migrationName = self::getMigrationClassName($timestamp);
		$checksum = hash('sha256', (string) $sql);
		$statementLogJson = json_encode($statementLog);

		$pdo = $this->createPdoConnection($datasource);

		$sqlInsert = sprintf(
			'INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s) VALUES (?, ?, ?, ?, ?, ?, ?)',
			$this->getMigrationTable(),
			$platform->quoteIdentifier('migration_timestamp'),
			$platform->quoteIdentifier('migration_name'),
			$platform->quoteIdentifier('direction'),
			$platform->quoteIdentifier('checksum'),
			$platform->quoteIdentifier('applied_at'),
			$platform->quoteIdentifier('success'),
			$platform->quoteIdentifier('statement_log')
		);
		$stmt = $pdo->prepare($sqlInsert);
		$stmt->bindValue(1, $timestamp, PDO::PARAM_INT);
		$stmt->bindValue(2, $migrationName, PDO::PARAM_STR);
		$stmt->bindValue(3, $direction, PDO::PARAM_STR);
		$stmt->bindValue(4, $checksum, PDO::PARAM_STR);
		$stmt->bindValue(5, date('Y-m-d H:i:s'), PDO::PARAM_STR);
		$stmt->bindValue(6, $success ? 1 : 0, PDO::PARAM_INT);
		$stmt->bindValue(7, $statementLogJson, PDO::PARAM_STR);
		$stmt->execute();
	}

	/**
	 * Executes one migration direction ('up' or 'down') for every datasource in
	 * the given SQL map (a migration class' getUpSQL()/getDownSQL() return
	 * value), recording the outcome of every attempt in the migration ledger.
	 *
	 * This is the single implementation of "how a migration direction
	 * actually executes". It was originally shared between this class and a
	 * Phing task adapter (BasePropulsionMigrationTask, which wrapped the
	 * MigrationExecutionException this throws into a
	 * Phing\Exception\BuildException) during the migration off Phing; that
	 * task has since been deleted entirely (see KNOWN_ISSUES.md), and the
	 * console migration:up/migration:down commands are now this method's
	 * only caller.
	 *
	 * Statements are executed sequentially; on the first failure, execution
	 * stops immediately (remaining statements are recorded as
	 * 'not_attempted', never run). On a platform whose DDL is genuinely
	 * transactional (see PropulsionPlatformInterface::supportsTransactionalDDL()),
	 * the whole batch is wrapped in a transaction that gets rolled back on
	 * failure, so nothing partially applied survives in the real schema; on a
	 * non-transactional platform, whatever succeeded before the failure
	 * remains applied for real -- an inherent limitation of non-transactional
	 * DDL, not papered over here. The ledger's per-statement log plus
	 * success=false records it accurately either way.
	 *
	 * Every attempt (success or failure) gets exactly one ledger row via
	 * recordMigrationRun() -- see that method's doc comment for why the
	 * insert always goes through a separate connection from the one used to
	 * run the DDL statements.
	 *
	 * @param      int $timestamp The migration's timestamp identifier.
	 * @param      string $direction 'up' or 'down'.
	 * @param      array $sqlByDatasource Keyed by datasource name, as
	 *             returned by a migration class' getUpSQL()/getDownSQL().
	 * @param      ?callable $logger Optional callback invoked as
	 *             `function(string $message, bool $verbose = false): void`
	 *             for progress/diagnostic messages; $verbose distinguishes
	 *             detail-level messages (e.g. per-statement SQL) from
	 *             summary-level ones.
	 * @throws     MigrationExecutionException On the first statement failure
	 *             for any datasource (after recording the failure in the
	 *             ledger), or if a datasource has no SQL statements to
	 *             execute at all.
	 */
	public function runMigrationDirection($timestamp, $direction, array $sqlByDatasource, ?callable $logger = null)
	{
		$logger = $logger ?? function ($message, $verbose = false) {};

		foreach ($sqlByDatasource as $datasource => $sql) {
			$connection = $this->getConnection($datasource);
			$logger(sprintf(
				'Connecting to database "%s" using DSN "%s"',
				$datasource,
				$connection['dsn']
			), true);

			$platform = $this->getPlatform($datasource);
			$pdo = $this->getPdoConnection($datasource);
			$statements = PropulsionSQLParser::parseString($sql);

			if (!$statements) {
				$logger('No statement was executed. The version was not updated.', false);
				$logger(sprintf(
					'Please review the code in "%s"',
					$this->getMigrationDir() . DIRECTORY_SEPARATOR . self::getMigrationClassName($timestamp)
				), false);
				throw new MigrationExecutionException(sprintf(
					'Migration %s aborted: no SQL statements found for datasource "%s".',
					self::getMigrationClassName($timestamp),
					$datasource
				), $datasource, $timestamp, $direction, array());
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
					$logger(sprintf('Executing statement "%s"', $statement), true);
					$stmt = $pdo->prepare($statement);
					$stmt->execute();
					$statementLog[] = array('sql' => $statement, 'status' => 'success');
				} catch (PDOException $e) {
					$logger(sprintf('Failed to execute SQL "%s": %s', $statement, $e->getMessage()), false);
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

			$this->recordMigrationRun($datasource, $timestamp, $direction, $sql, $success, $statementLog);

			if ($failed) {
				throw new MigrationExecutionException(sprintf(
					'Migration %s failed on datasource "%s": %s. See the migration ledger ("%s") for the full per-statement log.',
					self::getMigrationClassName($timestamp),
					$datasource,
					$failureMessage,
					$this->getMigrationTable()
				), $datasource, $timestamp, $direction, $statementLog);
			}

			$logger(sprintf(
				'%d of %d SQL statements executed successfully on datasource "%s"',
				count($statements),
				count($statements),
				$datasource
			), false);
		}
	}

	public function getMigrationTimestamps()
	{
		$path = $this->getMigrationDir();
		$migrationTimestamps = array();

		if (is_dir($path)) {
			$files = scandir($path);
			foreach ($files as $file) {
				if (preg_match('/^PropulsionMigration_(\d+)\.php$/', $file, $matches)) {
					$migrationTimestamps[] = (int) $matches[1];
				}
			}
		}

		return $migrationTimestamps;
	}

	public function getValidMigrationTimestamps()
	{
		$oldestMigrationTimestamp = $this->getOldestDatabaseVersion();
		$migrationTimestamps = $this->getMigrationTimestamps();
		// removing already executed migrations
		foreach ($migrationTimestamps as $key => $timestamp) {
			if ($timestamp <= $oldestMigrationTimestamp) {
				unset($migrationTimestamps[$key]);
			}
		}
		sort($migrationTimestamps);

		return $migrationTimestamps;
	}

	public function getAlreadyExecutedMigrationTimestamps()
	{
		$oldestMigrationTimestamp = $this->getOldestDatabaseVersion();
		$migrationTimestamps = $this->getMigrationTimestamps();
		// removing already executed migrations
		foreach ($migrationTimestamps as $key => $timestamp) {
			if ($timestamp > $oldestMigrationTimestamp) {
				unset($migrationTimestamps[$key]);
			}
		}
		sort($migrationTimestamps);

		return $migrationTimestamps;
	}

	public function getFirstUpMigrationTimestamp()
	{
		$validTimestamps = $this->getValidMigrationTimestamps();
		return array_shift($validTimestamps);
	}

	public function getFirstDownMigrationTimestamp()
	{
		return $this->getOldestDatabaseVersion();
	}

	public static function getMigrationClassName($timestamp)
	{
		return sprintf('PropulsionMigration_%d', $timestamp);
	}

	/**
	 * Normalizes a value fetched back from a BOOLEAN-domain column into a
	 * real PHP bool. Necessary because different PDO drivers represent a
	 * fetched boolean differently -- e.g. PDO_PGSQL commonly returns the
	 * native textual form ('t'/'f') rather than PHP true/false, and a naive
	 * `(bool) $value` cast would treat the non-empty string 'f' as truthy.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	protected static function toBool($value)
	{
		if (is_bool($value)) {
			return $value;
		}
		if ($value === null) {
			return false;
		}
		$normalized = strtolower(trim((string) $value));

		return in_array($normalized, array('1', 't', 'true', 'y', 'yes'), true);
	}

	public function getMigrationObject($timestamp)
	{
		$className = $this->getMigrationClassName($timestamp);
		require_once sprintf('%s/%s.php',
			$this->getMigrationDir(),
			$className
		);
		return new $className();
	}

	public function getMigrationClassBody($migrationsUp, $migrationsDown, $timestamp)
	{
		$timeInWords = date('Y-m-d H:i:s', $timestamp);
		$migrationAuthor = ($author = $this->getUser()) ? 'by ' . $author : '';
		$migrationClassName = $this->getMigrationClassName($timestamp);
		$migrationUpString = var_export($migrationsUp, true);
		$migrationDownString = var_export($migrationsDown, true);
		$migrationClassBody = <<<EOP
<?php

/**
 * Data object containing the SQL and PHP code to migrate the database
 * up to version $timestamp.
 * Generated on $timeInWords $migrationAuthor
 */
class $migrationClassName
{

	public function preUp(\$manager)
	{
		// add the pre-migration code here
	}

	public function postUp(\$manager)
	{
		// add the post-migration code here
	}

	public function preDown(\$manager)
	{
		// add the pre-migration code here
	}

	public function postDown(\$manager)
	{
		// add the post-migration code here
	}

	/**
	 * Get the SQL statements for the Up migration
	 *
	 * @return array list of the SQL strings to execute for the Up migration
	 *               the keys being the datasources
	 */
	public function getUpSQL()
	{
		return $migrationUpString;
	}

	/**
	 * Get the SQL statements for the Down migration
	 *
	 * @return array list of the SQL strings to execute for the Down migration
	 *               the keys being the datasources
	 */
	public function getDownSQL()
	{
		return $migrationDownString;
	}

}
EOP;
		return $migrationClassBody;
	}

	public static function getMigrationFileName($timestamp)
	{
		return sprintf('%s.php', self::getMigrationClassName($timestamp));
	}

	public static function getUser()
	{
		if (function_exists('posix_getuid')) {
			$currentUser = posix_getpwuid(posix_getuid());
			if (isset($currentUser['name'])) {
				return $currentUser['name'];
			}
		}
		return '';
	}
}
