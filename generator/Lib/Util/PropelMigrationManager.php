<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propel\Generator\Util;

/**
 * Service class for preparing and executing migrations
 *
 * @author     François Zaninotto
 * @version    $Revision$
 * @package    propel.generator.util
 */

 use Propel\Generator\Model\Column;
 use Propel\Generator\Model\Table;
 use Propel\Generator\Model\Database;
 use \Exception;
 use \PDO;

class PropelMigrationManager
{
	protected $connections;
	protected $pdoConnections = array();
	protected $migrationTable = 'propel_migration';
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
			$buildConnection = $this->getConnection($datasource);
			$dsn = str_replace("@DB@", $datasource, $buildConnection['dsn']);

			// Set user + password to null if they are empty strings or missing
			$username = isset($buildConnection['user']) && $buildConnection['user'] ? $buildConnection['user'] : null;
			$password = isset($buildConnection['password']) && $buildConnection['password'] ? $buildConnection['password'] : null;

			$pdo = new PDO($dsn, $username, $password);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			$pdoConnections[$datasource] = $pdo;
		}

		return $pdoConnections[$datasource];
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

		// Prefer the namespaced class if it exists (Propel\Generator\Platform\{Adapter}Platform),
		// fall back to a global class name (legacy). This handles both modern PSR-4 files
		// that declare a namespace and older files that declare classes in the global space.
		$namespaced = 'Propel\\Generator\\Platform\\' . $adapterClass;
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

	public function getOldestDatabaseVersion()
	{
		if (!$connections = $this->getConnections()) {
			throw new Exception('You must define database connection settings in a buildtime-conf.xml file to use migrations');
		}
		$oldestMigrationTimestamp = null;
		$migrationTimestamps = array();
		foreach ($connections as $name => $params) {
			$pdo = $this->getPdoConnection($name);
			$sql = sprintf('SELECT version FROM %s', $this->getMigrationTable());

			try {
				$stmt = $pdo->prepare($sql);
				$stmt->execute();
				if ($migrationTimestamp = $stmt->fetchColumn()) {
					$migrationTimestamps[$name] = $migrationTimestamp;
				}
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
		$sql = sprintf('SELECT version FROM %s', $this->getMigrationTable());
		$stmt = $pdo->prepare($sql);
		try {
			$stmt->execute();
			return true;
		} catch (\PDOException $e) {
			return false;
		}
	}

	public function createMigrationTable($datasource)
	{
		$platform = $this->getPlatform($datasource);
		// modelize the table
		$database = new Database($datasource);
		$database->setPlatform($platform);
		$table = new Table($this->getMigrationTable());
		$database->addTable($table);
		$column = new Column('version');
		$column->getDomain()->copy($platform->getDomainForType('INTEGER'));
		$column->setDefaultValue(0);
		$table->addColumn($column);
		// insert the table into the database
		$statements = $platform->getAddTableDDL($table);
		$pdo = $this->getPdoConnection($datasource);
		$res = PropelSQLParser::executeString($statements, $pdo);
		if (!$res) {
			throw new Exception(sprintf('Unable to create migration table in datasource "%s"', $datasource));
		}
	}

	public function updateLatestMigrationTimestamp($datasource, $timestamp)
	{
		$platform = $this->getPlatform($datasource);
		$pdo = $this->getPdoConnection($datasource);
		$sql = sprintf('DELETE FROM %s', $this->getMigrationTable());
		$pdo->beginTransaction();
		$stmt = $pdo->prepare($sql);
		$stmt->execute();
		$sql = sprintf('INSERT INTO %s (%s) VALUES (?)',
			$this->getMigrationTable(),
			$platform->quoteIdentifier('version')
		);
		$stmt = $pdo->prepare($sql);
		$stmt->bindParam(1, $timestamp, PDO::PARAM_INT);
		$stmt->execute();
		$pdo->commit();
	}

	public function getMigrationTimestamps()
	{
		$path = $this->getMigrationDir();
		$migrationTimestamps = array();

		if (is_dir($path)) {
			$files = scandir($path);
			foreach ($files as $file) {
				if (preg_match('/^PropelMigration_(\d+)\.php$/', $file, $matches)) {
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
		return sprintf('PropelMigration_%d', $timestamp);
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
