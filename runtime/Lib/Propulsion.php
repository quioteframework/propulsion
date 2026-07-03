<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

/**
 * Propulsion's main resource pool and initialization & configuration class.
 *
 * This static class is used to handle Propulsion initialization and to maintain all of the
 * open database connections and instantiated database maps.
 *
 * @author     Hans Lellelid <hans@xmpl.rg> (Propel)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @author     Magnús Þór Torfason <magnus@handtolvur.is> (Torque)
 * @author     Jason van Zyl <jvanzyl@apache.org> (Torque)
 * @author     Rafal Krzewski <Rafal.Krzewski@e-point.pl> (Torque)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @author     Henning P. Schmiedehausen <hps@intermeta.de> (Torque)
 * @author     Kurt Schrader <kschrader@karmalab.org> (Torque)
 * @version    $Revision$
 * @package    propel.runtime
 */
use Propulsion\Config\PropelConfiguration;
use Propulsion\Exception\PropelException;
use Propulsion\Util\PropelAutoloader;
use Propulsion\Map\DatabaseMap;
use Propulsion\Connection\PropelPDO;
use PDO;
use PDOException;
use Propulsion\Adapter\DBAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Propulsion
{
	/**
	 * The Propulsion version.
	 */
	const VERSION = '1.6.2-dev';

	/**
	 * A constant for <code>default</code>.
	 */
	const DEFAULT_NAME = "default";

	/**
	 * A constant defining 'System is unusuable' logging level
	 */
	const LOG_EMERG = LogLevel::EMERGENCY;

	/**
	 * A constant defining 'Immediate action required' logging level
	 */
	const LOG_ALERT = LogLevel::ALERT;

	/**
	 * A constant defining 'Critical conditions' logging level
	 */
	const LOG_CRIT = LogLevel::CRITICAL;

	/**
	 * A constant defining 'Error conditions' logging level
	 */
	const LOG_ERR = LogLevel::ERROR;

	/**
	 * A constant defining 'Warning conditions' logging level
	 */
	const LOG_WARNING = LogLevel::WARNING;

	/**
	 * A constant defining 'Normal but significant' logging level
	 */
	const LOG_NOTICE = LogLevel::NOTICE;

	/**
	 * A constant defining 'Informational' logging level
	 */
	const LOG_INFO = LogLevel::INFO;

	/**
	 * A constant defining 'Debug-level messages' logging level
	 */
	const LOG_DEBUG = LogLevel::DEBUG;

	/**
	 * The class name for a PDO object.
	 */
	const CLASS_PDO = 'PDO';

	/**
	 * The class name for a PropelPDO object.
	 */
	const CLASS_PROPEL_PDO = 'PropelPDO';

	/**
	 * The class name for a DebugPDO object.
	 */
	const CLASS_DEBUG_PDO = 'DebugPDO';

	/**
	 * Constant used to request a READ connection (applies to replication).
	 */
	const CONNECTION_READ = 'read';

	/**
	 * Constant used to request a WRITE connection (applies to replication).
	 */
	const CONNECTION_WRITE = 'write';

	/**
	 * @var        string The db name that is specified as the default in the property file
	 */
	private static $defaultDBName;

	/**
	 * @var        array The global cache of database maps
	 */
	private static $dbMaps = array();

	/**
	 * @var        array The cache of DB adapter keys
	 */
	private static $adapterMap = array();

	/**
	 * @var        array Cache of established connections (to eliminate overhead).
	 */
	private static $connectionMap = array();

	/**
	 * @var        PropelConfiguration Propulsion-specific configuration.
	 */
	private static $configuration;

	/**
	 * @var        bool flag to set to true once this class has been initialized
	 */
	private static $isInit = false;

	/**
	 * @var        LoggerInterface|null optional PSR-3 logger. Propulsion ships no
	 *             concrete implementation -- bring your own (Monolog, etc.) via
	 *             Propulsion::setLogger().
	 */
	private static ?LoggerInterface $logger = null;

	/**
	 * @var        string The name of the database mapper class
	 */
	private static $databaseMapClass = 'Propulsion\Map\DatabaseMap';

	/**
	 * @var        bool Whether the object instance pooling is enabled
	 */
	private static $instancePoolingEnabled = true;

	/**
	 * @var        string Base directory to use for autoloading. Initialized in self::initBaseDir()
	 */
	protected static $baseDir;

	/**
	 * @var        ServiceContainer|null Process-scoped service registry (worker-safety
	 *             rework phase 4a). Lazily created on first access.
	 */
	private static ?ServiceContainer $serviceContainer = null;

	/**
	 * @var        Session|null Request-scoped state (worker-safety rework phase 4a).
	 *             Lazily created on first access. `forceMasterConnection` lives here now
	 *             -- see Session::getForceMasterConnection()/setForceMasterConnection().
	 */
	private static ?Session $session = null;


	/**
	 * Initializes Propulsion
	 *
	 * @throws     PropelException Any exceptions caught during processing will be
	 *                             rethrown wrapped into a PropelException.
	 */
	public static function initialize()
	{
		if (self::$configuration === null) {
			throw new PropelException("Propulsion cannot be initialized without a valid configuration. Please check the log files for further details.");
		}

		self::configureLogging();

		// reset the connection map (this should enable runtime changes of connection params)
		self::$connectionMap = array();

		self::$isInit = true;
	}

	/**
	 * Configure Propulsion a PHP (array) config file.
	 *
	 * @param      string Path (absolute or relative to include_path) to config file.
	 *
	 * @throws     PropelException If configuration file cannot be opened.
	 *                             (E_WARNING probably will also be raised by PHP)
	 */
	public static function configure($configFile)
	{
		$configuration = include($configFile);
		if ($configuration === false) {
			throw new PropelException("Unable to open configuration file: " . var_export($configFile, true));
		}
		self::setConfiguration($configuration);
	}

	/**
	 * Configure the logging system.
	 *
	 * Propulsion does not auto-configure a logger from the runtime configuration
	 * file -- bring your own PSR-3 logger and register it with Propulsion::setLogger()
	 * (typically right after Propulsion::init()). Without one, Propulsion::log() is a no-op.
	 */
	protected static function configureLogging()
	{
	}

	/**
	 * Initialization of Propulsion a PHP (array) configuration file.
	 *
	 * @param      string $c The Propulsion configuration file path.
	 *
	 * @throws     PropelException Any exceptions caught during processing will be
	 *                             rethrown wrapped into a PropelException.
	 */
	public static function init($c)
	{
		self::configure($c);
		self::initialize();
	}

	/**
	 * Determine whether Propulsion has already been initialized.
	 *
	 * @return     bool True if Propulsion is already initialized.
	 */
	public static function isInit()
	{
		return self::$isInit;
	}

	/**
	 * Sets the configuration for Propulsion and all dependencies.
	 *
	 * @param      mixed The Configuration (array or PropelConfiguration)
	 */
	public static function setConfiguration($c)
	{
		if (is_array($c)) {
			if (isset($c['propel']) && is_array($c['propel'])) {
				$c = $c['propel'];
			}
			$c = new PropelConfiguration($c);
		}
		self::$configuration = $c;
	}

	/**
	 * Get the configuration for this component.
	 *
	 * @param      int - PropelConfiguration::TYPE_ARRAY: return the configuration as an array
	 *                   (for backward compatibility this is the default)
	 *                 - PropelConfiguration::TYPE_ARRAY_FLAT: return the configuration as a flat array
	 *                   ($config['name.space.item'])
	 *                 - PropelConfiguration::TYPE_OBJECT: return the configuration as a PropelConfiguration instance
	 * @return     mixed The Configuration (array or PropelConfiguration)
	 */
	public static function getConfiguration($type = PropelConfiguration::TYPE_ARRAY)
	{
		return self::$configuration->getParameters($type);
	}

	/**
	 * Sets the PSR-3 logger to use.
	 *
	 * Propulsion ships no concrete logger implementation -- bring your own
	 * (Monolog, or anything else implementing Psr\Log\LoggerInterface).
	 *
	 * @param      LoggerInterface $logger The new logger to use.
	 */
	public static function setLogger(LoggerInterface $logger)
	{
		self::$logger = $logger;
	}

	/**
	 * Returns true if a PSR-3 logger has been configured, otherwise false.
	 *
	 * @return     bool True if Propulsion uses logging
	 */
	public static function hasLogger()
	{
		return (self::$logger !== null);
	}

	/**
	 * Get the configured logger.
	 *
	 * @return     LoggerInterface|null Configured PSR-3 logger, or null if none was set.
	 */
	public static function logger()
	{
		return self::$logger;
	}

	/**
	 * Logs a message.
	 * If a logger has been configured, the logger will be used, otherwise the
	 * logging message will be discarded without any further action.
	 *
	 * @param      string $message The message that will be logged.
	 * @param      string $level One of the Psr\Log\LogLevel::* constants (also available as Propulsion::LOG_*).
	 * @param      array  $context PSR-3 context array.
	 *
	 * @return     bool True if the message was logged successfully or no logger was used.
	 */
	public static function log($message, $level = LogLevel::DEBUG, array $context = [])
	{
		self::$logger?->log($level, $message, $context);
		return true;
	}

	/**
	 * Returns the database map information. Name relates to the name
	 * of the connection pool to associate with the map.
	 *
	 * The database maps are "registered" by the generated map builder classes.
	 *
	 * @param      string The name of the database corresponding to the DatabaseMap to retrieve.
	 *
	 * @return     DatabaseMap The named <code>DatabaseMap</code>.
	 *
	 * @throws     PropelException - if database map is null or propel was not initialized properly.
	 */
	public static function getDatabaseMap($name = null)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
			if ($name === null) {
				throw new PropelException("DatabaseMap name is null!");
			}
		}

		if (!isset(self::$dbMaps[$name])) {
			$clazz = self::$databaseMapClass;
			self::$dbMaps[$name] = new $clazz($name);
		}

		return self::$dbMaps[$name];
	}

	/**
	 * Sets the database map object to use for specified datasource.
	 *
	 * @param      string $name The datasource name.
	 * @param      DatabaseMap $map The database map object to use for specified datasource.
	 */
	public static function setDatabaseMap($name, DatabaseMap $map)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}
		self::$dbMaps[$name] = $map;
	}

	/**
	 * For replication, set whether to always force the use of a master connection.
	 *
	 * As of the worker-safety rework (phase 4a), this state actually lives on
	 * {@see Session} -- it's request-scoped, not process-scoped, since it must
	 * not leak from one request to the next in a persistent-worker environment.
	 * This method is kept as a thin proxy for backwards compatibility.
	 *
	 * @param      boolean $bit True or False
	 */
	public static function setForceMasterConnection($bit)
	{
		self::getSession()->setForceMasterConnection((bool) $bit);
	}

	/**
	 * For replication, whether to always force the use of a master connection.
	 *
	 * @see        setForceMasterConnection()
	 *
	 * @return     boolean
	 */
	public static function getForceMasterConnection()
	{
		return self::getSession()->getForceMasterConnection();
	}

	/**
	 * Returns the process-scoped service registry (worker-safety rework phase 4a).
	 * Lazily creates one on first access.
	 *
	 * @return     ServiceContainer
	 */
	public static function getServiceContainer(): ServiceContainer
	{
		if (self::$serviceContainer === null) {
			self::$serviceContainer = new ServiceContainer();
		}

		return self::$serviceContainer;
	}

	/**
	 * Overrides the process-scoped service registry. Mainly useful for tests.
	 */
	public static function setServiceContainer(ServiceContainer $serviceContainer): void
	{
		self::$serviceContainer = $serviceContainer;
	}

	/**
	 * Returns the request-scoped session (worker-safety rework phase 4a). Lazily
	 * creates one on first access.
	 *
	 * In a persistent-worker environment, call {@see Session::reset()} on this at
	 * each request boundary.
	 *
	 * @return     Session
	 */
	public static function getSession(): Session
	{
		if (self::$session === null) {
			self::$session = new Session();
		}

		return self::$session;
	}

	/**
	 * Overrides the request-scoped session. Mainly useful for tests, or for a
	 * worker-mode integration explicitly starting a fresh session per request.
	 */
	public static function setSession(Session $session): void
	{
		self::$session = $session;
	}

	/**
	 * @return     array<int, string> The names of every datasource with a
	 *                                registered DatabaseMap.
	 */
	public static function getDatabaseMapNames(): array
	{
		return array_keys(self::$dbMaps);
	}

	/**
	 * @return     array<int, PDO> Every PDO/PropelPDO connection Propulsion currently
	 *                              has open (master and slave, across all
	 *                              datasources), deduplicated.
	 */
	public static function getOpenConnections(): array
	{
		$connections = array();
		foreach (self::$connectionMap as $modes) {
			foreach ($modes as $con) {
				if ($con instanceof PDO) {
					$connections[spl_object_id($con)] = $con;
				}
			}
		}

		return array_values($connections);
	}

	/**
	 * Sets a Connection for specified datasource name.
	 *
	 * @param      string $name The datasource name for the connection being set.
	 * @param      PropelPDO $con The PDO connection.
	 * @param      string $mode Whether this is a READ or WRITE connection (Propulsion::CONNECTION_READ, Propulsion::CONNECTION_WRITE)
	 */
	public static function setConnection($name, PropelPDO $con, $mode = Propulsion::CONNECTION_WRITE)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}
		if ($mode == Propulsion::CONNECTION_READ) {
			self::$connectionMap[$name]['slave'] = $con;
		} else {
			self::$connectionMap[$name]['master'] = $con;
		}
	}

	/**
	 * Gets an already-opened PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN from the runtime configuation file.
	 * @param      string $mode The connection mode (this applies to replication systems).
	 *
	 * @return     PDO|PropelPDO A database connection
	 *
	 * @throws     PropelException - if connection cannot be configured or initialized.
	 */
	public static function getConnection($name = null, $mode = Propulsion::CONNECTION_WRITE)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}

		// IF a WRITE-mode connection was requested
		// or Propulsion is configured to always use the master connection
		// THEN return the master connection.
		if ($mode != Propulsion::CONNECTION_READ || self::getSession()->getForceMasterConnection()) {
			return self::getMasterConnection($name);
		} else {
			return self::getSlaveConnection($name);
		}

	}
	
	/**
	 * Gets an already-opened read or write PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN from the runtime configuation file.
	 *
	 * @return     PDO A database connection
	 *
	 * @throws     PropelException - if connection cannot be configured or initialized.
	 */
	public static function getReadConnection($name = null)
	{
		return self::getConnection($name, Propulsion::CONNECTION_READ);
	}

	/**
	 * Gets an already-opened write PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN from the runtime configuation file.
	 *
	 * @return     PDO A database connection
	 *
	 * @throws     PropelException - if connection cannot be configured or initialized.
	 */
	public static function getWriteConnection($name = null)
	{
		return self::getConnection($name, Propulsion::CONNECTION_WRITE);
	}

	/**
	 * Gets an already-opened write PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN
	 *                          from the runtime configuation file. Empty name not allowed.
	 *
	 * @return     PDO|PropelPDO A database connection
	 *
	 * @throws     PropelException - if connection cannot be configured or initialized.
	 */
	public static function getMasterConnection($name)
	{
		if (!isset(self::$connectionMap[$name]['master'])) {
			// load connection parameter for master connection
			$conparams = isset(self::$configuration['datasources'][$name]['connection']) ? self::$configuration['datasources'][$name]['connection'] : null;
			if (empty($conparams)) {
				throw new PropelException('No connection information in your runtime configuration file for datasource ['.$name.']');
			}
			// initialize master connection
			$con = Propulsion::initConnection($conparams, $name);
			self::$connectionMap[$name]['master'] = $con;

			if (getenv('AGAVI_DEBUG_DATABASE')) {
				self::log('[Propulsion::getMasterConnection] created new connection for ' . $name, LogLevel::DEBUG);
			}
		}

		return self::$connectionMap[$name]['master'];
	}

	/**
	 * Detect whether a PDOException indicates a dropped/stale database connection.
	 * These are transient errors that can be resolved by reconnecting.
	 */
	public static function isConnectionDropped(\Throwable $e): bool
	{
		$sqlState = '';
		if ($e instanceof \PDOException && $e->errorInfo) {
			$sqlState = (string) ($e->errorInfo[0] ?? '');
		}
		// PostgreSQL connection-class errors (08xxx)
		if (str_starts_with($sqlState, '08')) {
			return true;
		}
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'server closed the connection unexpectedly')
			|| str_contains($msg, 'connection reset by peer')
			|| str_contains($msg, 'connection to server')
			|| str_contains($msg, 'no connection to the server')
			|| str_contains($msg, 'has gone away')
			|| str_contains($msg, 'broken pipe')
			|| str_contains($msg, 'connection timed out');
	}

	/**
	 * Force a reconnection for the given datasource by dropping the cached connection.
	 * The next getConnection() call will create a fresh connection.
	 *
	 * @param string $name The datasource name (default: the default datasource).
	 */
	public static function forceReconnect(?string $name = null): void
	{
		$name = $name ?: self::getDefaultDB();
		unset(self::$connectionMap[$name]['master']);
		unset(self::$connectionMap[$name]['slave']);
	}

	/**
	 * Gets an already-opened read PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN
	 *                          from the runtime configuation file. Empty name not allowed.
	 *
	 * @return     PDO A database connection
	 *
	 * @throws     PropelException - if connection cannot be configured or initialized.
	 */
	public static function getSlaveConnection($name)
	{
		if (!isset(self::$connectionMap[$name]['slave'])) {

			$slaveconfigs = isset(self::$configuration['datasources'][$name]['slaves']) ? self::$configuration['datasources'][$name]['slaves'] : null;

			if (empty($slaveconfigs)) {
				// no slaves configured for this datasource
				// fallback to the master connection
				self::$connectionMap[$name]['slave'] = self::getMasterConnection($name);
			} else {
				// Initialize a new slave
				if (isset($slaveconfigs['connection']['dsn'])) {
					// only one slave connection configured
					$conparams = $slaveconfigs['connection'];
				} else {
					// more than one sleve connection configured
					// pickup a random one
					$randkey = array_rand($slaveconfigs['connection']);
					$conparams = $slaveconfigs['connection'][$randkey];
					if (empty($conparams)) {
						throw new PropelException('No connection information in your runtime configuration file for SLAVE ['.$randkey.'] to datasource ['.$name.']');
					}
				}

				// initialize slave connection
				$con = Propulsion::initConnection($conparams, $name);
				self::$connectionMap[$name]['slave'] = $con;
			}

		} // if datasource slave not set

		return self::$connectionMap[$name]['slave'];
	}

	/**
	 * Opens a new PDO connection for passed-in db name.
	 *
	 * @param      array $conparams Connection paramters.
	 * @param      string $name Datasource name.
	 * @param      string $defaultClass The PDO subclass to instantiate if there is no explicit classname
	 * 									specified in the connection params (default is Propulsion::CLASS_PROPEL_PDO)
	 *
	 * @return     PDO|PropelPDO A database connection of the given class (PDO, PropelPDO, SlavePDO or user-defined)
	 *
	 * @throws     PropelException - if lower-level exception caught when trying to connect.
	 */
	public static function initConnection($conparams, $name, $defaultClass = Propulsion::CLASS_PROPEL_PDO)
	{
		$adapter = self::getDB($name);

		$dsn = $conparams['dsn'];
		if ($dsn === null) {
			throw new PropelException('No dsn specified in your connection parameters for datasource ['.$name.']');
		}

		$conparams = $adapter->prepareParams($conparams);

		if (isset($conparams['classname']) && !empty($conparams['classname'])) {
			$classname = $conparams['classname'];
			if (!class_exists($classname)) {
				throw new PropelException('Unable to load specified PDO subclass: ' . $classname);
			}
		} else {
			$classname = $defaultClass;
		}

		$user = isset($conparams['user']) ? $conparams['user'] : null;
		$password = isset($conparams['password']) ? $conparams['password'] : null;

		// load any driver options from the config file
		// driver options are those PDO settings that have to be passed during the connection construction
		$driver_options = array();
		if ( isset($conparams['options']) && is_array($conparams['options']) ) {
			try {
				self::processDriverOptions( $conparams['options'], $driver_options );
			} catch (PropelException $e) {
				throw new PropelException('Error processing driver options for datasource ['.$name.']', $e);
			}
		}

		try {
			$con = new $classname($dsn, $user, $password, $driver_options);
			$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (PDOException $e) {
			throw new PropelException("Unable to open PDO connection", $e);
		}

		// load any connection options from the config file
		// connection attributes are those PDO flags that have to be set on the initialized connection
		if (isset($conparams['attributes']) && is_array($conparams['attributes'])) {
			$attributes = array();
			try {
				self::processDriverOptions( $conparams['attributes'], $attributes );
			} catch (PropelException $e) {
				throw new PropelException('Error processing connection attributes for datasource ['.$name.']', $e);
			}
			foreach ($attributes as $key => $value) {
				$con->setAttribute($key, $value);
			}
		}

		// initialize the connection using the settings provided in the config file. this could be a "SET NAMES <charset>" query for MySQL, for instance
		$adapter->initConnection($con, isset($conparams['settings']) && is_array($conparams['settings']) ? $conparams['settings'] : array());

		return $con;
	}

	/**
	 * Internal function to handle driver options or conneciton attributes in PDO.
	 *
	 * Process the INI file flags to be passed to each connection.
	 *
	 * @param      array Where to find the list of constant flags and their new setting.
	 * @param      array Put the data into here
	 *
	 * @throws     PropelException If invalid options were specified.
	 */
	private static function processDriverOptions(array $source, array &$write_to)
	{
		foreach ($source as $option => $optiondata) {
			if (is_string($option) && strpos($option, '::') !== false) {
				$key = $option;
			} elseif (is_string($option)) {
				$key = 'Propulsion\\Connection\\PropelPDO::' . $option;
			}
			if (!defined($key)) {
				throw new PropelException("Invalid PDO option/attribute name specified: ".$key);
			}
			$key = constant($key);

			$value = $optiondata['value'];
			if (is_string($value) && strpos($value, '::') !== false) {
				if (!defined($value)) {
					throw new PropelException("Invalid PDO option/attribute value specified: ".$value);
				}
				$value = constant($value);
			}

			$write_to[$key] = $value;
		}
	}

	/**
	 * Returns database adapter for a specific datasource.
	 *
	 * @param      string The datasource name.
	 *
	 * @return     DBAdapter The corresponding database adapter.
	 *
	 * @throws     PropelException If unable to find DBdapter for specified db.
	 */
		public static function getDB($name = null)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}

		if (!isset(self::$adapterMap[$name])) {
			if (!isset(self::$configuration['datasources'][$name]['adapter'])) {
				throw new PropelException("Unable to find adapter for datasource [" . $name . "].");
			}
			$db = DBAdapter::factory(self::$configuration['datasources'][$name]['adapter']);
			// register the adapter for this name
			self::$adapterMap[$name] = $db;
		}

		return self::$adapterMap[$name];
	}

	/**
	 * Sets a database adapter for specified datasource.
	 *
	 * @param      string $name The datasource name.
	 * @param      DBAdapter $adapter The DBAdapter implementation to use.
	 */
	public static function setDB($name, DBAdapter $adapter)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}
		self::$adapterMap[$name] = $adapter;
	}

	/**
	 * Returns the name of the default database.
	 *
	 * @return     string Name of the default DB
	 */
	public static function getDefaultDB()
	{
		if (self::$defaultDBName === null) {
			// Determine default database name.
			self::$defaultDBName = isset(self::$configuration['datasources']['default']) && is_scalar(self::$configuration['datasources']['default']) ? self::$configuration['datasources']['default'] : self::DEFAULT_NAME;
		}
		return self::$defaultDBName;
	}

	/**
	 * Closes any associated resource handles.
	 *
	 * This method frees any database connection handles that have been
	 * opened by the getConnection() method.
	 */
	public static function close()
	{
		if (getenv('AGAVI_DEBUG_DATABASE')) {
			self::log('[Propulsion::close] closing ' . count(self::$connectionMap) . ' connection groups', LogLevel::DEBUG);
		}

		foreach (self::$connectionMap as $idx => $cons) {
			if (getenv('AGAVI_DEBUG_DATABASE')) {
				$masterCount = isset($cons['master']) ? 1 : 0;
				$slaveCount = isset($cons['slave']) ? 1 : 0;
				self::log('[Propulsion::close] closing connection group: ' . $idx . ' (master=' . $masterCount . ' slave=' . $slaveCount . ')', LogLevel::DEBUG);
			}
		}

		// Clear the entire connection map to release all PDO references
		self::$connectionMap = array();

		if (getenv('AGAVI_DEBUG_DATABASE')) {
			self::log('[Propulsion::close] all connections closed - connection map cleared', LogLevel::DEBUG);
		}
	}

	/**
	 * Include once a file specified in DOT notation and return unqualified classname.
	 *
	 * Typically, Propulsion uses autoload is used to load classes and expects that all classes
	 * referenced within Propulsion are included in Propulsion's autoload map.  This method is only
	 * called when a specific non-Propulsion classname was specified -- for example, the
	 * classname of a validator in the schema.xml.  This method will attempt to include that
	 * class via autoload and then relative to a location on the include_path.
	 *
	 * @param      string $class dot-path to clas (e.g. path.to.my.ClassName).
	 * @return     string unqualified classname
	 */
	public static function importClass($path) {

		// extract classname
		if (($pos = strrpos($path, '.')) === false) {
			$class = $path;
		} else {
			$class = substr($path, $pos + 1);
		}

		// check if class exists, using autoloader to attempt to load it.
		if (class_exists($class, $useAutoload=true)) {
			return $class;
		}

		// turn to filesystem path
		$path = strtr($path, '.', DIRECTORY_SEPARATOR) . '.php';

		// include class
		$ret = include_once($path);
		if ($ret === false) {
			throw new PropelException("Unable to import class: " . $class . " from " . $path);
		}

		// return qualified name
		return $class;
	}

	/**
	 * Set your own class-name for Database-Mapping. Then
	 * you can change the whole TableMap-Model, but keep its
	 * functionality for Criteria.
	 *
	 * @param      string The name of the class.
	 */
	public static function setDatabaseMapClass($name)
	{
		self::$databaseMapClass = $name;
	}

	/**
	 * Disable instance pooling.
	 *
	 * @return boolean true if the method changed the instance pooling state,
	 *                 false if it was already disabled
	 */
	public static function disableInstancePooling()
	{
		if (!self::$instancePoolingEnabled) {
			return false;
		}
		self::$instancePoolingEnabled = false;
		return true;
	}

	/**
	 * Enable instance pooling (enabled by default).
	 *
	 * @return boolean true if the method changed the instance pooling state,
	 *                 false if it was already enabled
	 */
	public static function enableInstancePooling()
	{
		if (self::$instancePoolingEnabled) {
			return false;
		}
		self::$instancePoolingEnabled = true;
		return true;
	}

	/**
	 *  the instance pooling behaviour. True by default.
	 *
	 * @return     boolean Whether the pooling is enabled or not.
	 */
	public static function isInstancePoolingEnabled()
	{
		return self::$instancePoolingEnabled;
	}
}

// Generated Object Model classes (both the archived PHP5 builders and the current
// PHP84 ones) are emitted unnamespaced and reference runtime classes by their bare
// historic name (Propulsion::, TableMap, PropelException, ...) -- that was their actual
// global name before this fork renamed Propulsion\ to Propulsion\. Alias them eagerly
// (not lazily via spl_autoload_register) because `catch (PropelException $e)` --
// used throughout this codebase and any already-generated model code -- does NOT
// trigger autoloading in PHP the way `new`/`instanceof`/class_exists() do; an alias
// created only on first *reference* would still be missing the first time a catch
// block needs it. class_alias() autoloads its target class itself, so this eagerly
// loads all of them once, whenever Propulsion\Propulsion is first loaded (i.e. always,
// since Propulsion::init() is the mandatory bootstrap call).
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
	throw new \ErrorException($message, 0, $severity, $file, $line);
});
try {
	foreach (require __DIR__ . '/legacy-class-map.php' as $legacyName => $fqcn) {
		if (!class_exists($legacyName, false) && !interface_exists($legacyName, false)) {
			try {
				class_alias($fqcn, $legacyName);
			} catch (\Throwable $e) {
				// A handful of runtime classes have optional dependencies of their own
				// (e.g. PropelYAMLParser expects a bundled sfYaml.php that isn't part of
				// this fork). Don't let one broken/unused legacy class -- or even just a
				// warning it emits while loading -- take down every other alias, and by
				// extension Propulsion::init() itself, for it.
			}
		}
	}
} finally {
	restore_error_handler();
}

