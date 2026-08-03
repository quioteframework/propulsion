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
 */
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Exception\PropulsionException;
use Propulsion\Util\PropulsionAutoloader;
use Propulsion\Map\DatabaseMap;
use Propulsion\Connection\PropulsionPDO;
use PDO;
use PDOException;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Query\RawQuery;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;

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
	 * The class name for a PropulsionPDO object.
	 */
	const CLASS_PROPEL_PDO = 'PropulsionPDO';

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
	 * @var        array<string, DatabaseMap> The global cache of database maps
	 */
	private static $dbMaps = array();

	/**
	 * @var        array<string, DBAdapter> The cache of DB adapter keys
	 */
	private static $adapterMap = array();

	/**
	 * @var        array<string, array<string, PDO|PropulsionPDO>> Cache of established connections (to eliminate overhead).
	 *             initConnection() guarantees each value is a PDO|PropulsionPDO instance
	 *             (it throws if the configured connection `classname` doesn't produce one).
	 */
	private static $connectionMap = array();

	/**
	 * Propulsion-specific configuration, or null until {@see setConfiguration()}
	 * has been called (which {@see initialize()} already checks for, and which
	 * generator commands and unit tests routinely never do).
	 */
	private static ?PropulsionConfiguration $configuration = null;

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
	 * @var        EventDispatcherInterface|null optional PSR-14 event dispatcher.
	 *             Propulsion ships no concrete implementation -- bring your own
	 *             (Symfony EventDispatcher, League\Event, etc.) via
	 *             Propulsion::setEventDispatcher().
	 */
	private static ?EventDispatcherInterface $eventDispatcher = null;

	/**
	 * @var        string The name of the database mapper class
	 */
	private static $databaseMapClass = 'Propulsion\Map\DatabaseMap';

	/**
	 * @var        bool Whether the object instance pooling is enabled.
	 *
	 *             This is the *explicit* application-level switch, toggled by
	 *             disableInstancePooling()/enableInstancePooling(). It stays
	 *             process-scoped on purpose: turning pooling off is deployment
	 *             configuration (a batch/import worker means it for the whole
	 *             process), not per-request state.
	 *
	 *             The transient, nestable "suspend pooling for the duration of
	 *             this streamed result set" case is a different thing and lives
	 *             on {@see Session} -- see Session::$instancePoolingSuspendCount
	 *             for why conflating the two was a bug.
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
	 * @throws     PropulsionException Any exceptions caught during processing will be
	 *                             rethrown wrapped into a PropulsionException.
	 */
	public static function initialize(): void
	{
		if (self::$configuration === null) {
			throw new PropulsionException("Propulsion cannot be initialized without a valid configuration. Please check the log files for further details.");
		}

		// reset the connection map (this should enable runtime changes of connection params)
		self::$connectionMap = array();

		self::$isInit = true;
	}

	/**
	 * Configure Propulsion a PHP (array) config file.
	 *
	 * @param      string $configFile Path (absolute or relative to include_path) to config file.
	 *
	 * @throws     PropulsionException If configuration file cannot be opened.
	 *                             (E_WARNING probably will also be raised by PHP)
	 */
	public static function configure($configFile): void
	{
		$configuration = include($configFile);
		if ($configuration === false) {
			throw new PropulsionException("Unable to open configuration file: " . var_export($configFile, true));
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
	/**
	 * Initialization of Propulsion a PHP (array) configuration file.
	 *
	 * @param      string $c The Propulsion configuration file path.
	 *
	 * @throws     PropulsionException Any exceptions caught during processing will be
	 *                             rethrown wrapped into a PropulsionException.
	 */
	public static function init($c): void
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
	 * @param      mixed $c The Configuration (array or PropulsionConfiguration)
	 */
	public static function setConfiguration($c): void
	{
		if (is_array($c)) {
			if (isset($c['propel']) && is_array($c['propel'])) {
				$c = $c['propel'];
			}
			$c = new PropulsionConfiguration($c);
		}
		if (!$c instanceof PropulsionConfiguration) {
			throw new PropulsionException('Propulsion configuration must be an array or a PropulsionConfiguration instance');
		}
		self::$configuration = $c;

		// A new configuration may name a different cache driver, so drop any
		// pool already built from the old one. Note this only *invalidates* --
		// it deliberately does not build the replacement, since constructing a
		// file-backed driver creates directories and setConfiguration() is
		// called from tests and generator commands that will never cache
		// anything. The pool is rebuilt lazily on first use.

		// Likewise drop every compiled SELECT. That cache is process-scoped, and
		// the SQL text in it is specific to the adapter its datasource was using
		// when it was compiled -- identifier quoting and the LIMIT/OFFSET dialect
		// both come from there -- so a configuration naming a different adapter
		// for the same datasource name must not leave the old dialect's SQL
		// reachable. (While the cache was request-scoped this could not bite,
		// because the entries never outlived the request that built them.)
		self::$serviceContainer?->clearCompiledQueryCache();
		self::$serviceContainer?->clearQueryCachePool();
	}

	/**
	 * Get the configuration for this component.
	 *
	 * @param      int $type One of:
	 *                   - PropulsionConfiguration::TYPE_ARRAY: return the configuration as an array
	 *                     (for backward compatibility this is the default)
	 *                   - PropulsionConfiguration::TYPE_ARRAY_FLAT: return the configuration as a flat array
	 *                     ($config['name.space.item'])
	 *                   - PropulsionConfiguration::TYPE_OBJECT: return the configuration as a PropulsionConfiguration instance
	 * @return     mixed The Configuration (array or PropulsionConfiguration)
	 * @throws     PropulsionException if no configuration has been set yet.
	 */
	public static function getConfiguration($type = PropulsionConfiguration::TYPE_ARRAY)
	{
		if (self::$configuration === null) {
			// Previously this was an unhelpful "call to a member function on
			// null" fatal; the same message initialize() uses is clearer about
			// what the caller actually forgot to do.
			throw new PropulsionException('Propulsion cannot be used without a configuration; call Propulsion::setConfiguration() or Propulsion::init() first.');
		}

		return self::$configuration->getParameters($type);
	}

	/**
	 * The whole runtime configuration as a plain array, or an empty array if
	 * none has been set yet.
	 *
	 * Unlike {@see getConfiguration()}, this is safe to call before
	 * {@see setConfiguration()} -- which matters because the query cache
	 * resolves its configuration lazily, and may well be asked about it by code
	 * running in a process that never configured Propulsion at all (a
	 * generator command, a unit test).
	 *
	 * @return     array<string, mixed>
	 */
	public static function getConfigurationArray(): array
	{
		if (self::$configuration === null) {
			return array();
		}

		return self::asConfigArray(self::$configuration->getParameters(PropulsionConfiguration::TYPE_ARRAY));
	}

	/**
	 * Normalizes an arbitrary (mixed) config value into a string-keyed array,
	 * or an empty array if it isn't array-like.
	 *
	 * @return     array<string, mixed>
	 */
	private static function asConfigArray(mixed $value): array
	{
		if (!is_array($value)) {
			return array();
		}
		$result = array();
		foreach ($value as $key => $item) {
			$result[(string) $key] = $item;
		}
		return $result;
	}

	/**
	 * Returns the 'datasources' section of the runtime configuration, or an empty
	 * array if it is missing or malformed.
	 *
	 * @return     array<string, mixed>
	 */
	private static function getDatasourcesConfig(): array
	{
		if (self::$configuration === null) {
			return array();
		}

		return self::asConfigArray(self::$configuration['datasources'] ?? null);
	}

	/**
	 * Returns the configuration for a single named datasource, or an empty array
	 * if it is missing or malformed.
	 *
	 * @return     array<string, mixed>
	 */
	private static function getDatasourceConfig(string $name): array
	{
		return self::asConfigArray(self::getDatasourcesConfig()[$name] ?? null);
	}

	/**
	 * Sets the PSR-3 logger to use.
	 *
	 * Propulsion ships no concrete logger implementation -- bring your own
	 * (Monolog, or anything else implementing Psr\Log\LoggerInterface).
	 *
	 * @param      LoggerInterface $logger The new logger to use.
	 */
	public static function setLogger(LoggerInterface $logger): void
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
	 * @param      array<string,mixed>  $context PSR-3 context array.
	 *
	 * @return     bool True if the message was logged successfully or no logger was used.
	 */
	public static function log($message, $level = LogLevel::DEBUG, array $context = [])
	{
		self::$logger?->log($level, $message, $context);
		return true;
	}

	/**
	 * Sets the PSR-14 event dispatcher to use for model lifecycle events
	 * (PreSaveEvent, PostSaveEvent, PreInsertEvent, PostInsertEvent,
	 * PreUpdateEvent, PostUpdateEvent, PreDeleteEvent, PostDeleteEvent -- see
	 * the Propulsion\Event namespace), dispatched from
	 * {@see \Propulsion\OM\BaseObject}'s preSave()/postSave()/etc. hook
	 * methods.
	 *
	 * Propulsion ships no concrete dispatcher implementation -- bring your
	 * own (Symfony's EventDispatcher, League\Event, or anything else
	 * implementing Psr\EventDispatcher\EventDispatcherInterface).
	 *
	 * @param      EventDispatcherInterface $eventDispatcher The new event dispatcher to use.
	 */
	public static function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
	{
		self::$eventDispatcher = $eventDispatcher;
	}

	/**
	 * Returns true if a PSR-14 event dispatcher has been configured, otherwise false.
	 *
	 * @return     bool True if Propulsion dispatches model lifecycle events
	 */
	public static function hasEventDispatcher()
	{
		return (self::$eventDispatcher !== null);
	}

	/**
	 * Get the configured event dispatcher.
	 *
	 * @return     EventDispatcherInterface|null Configured PSR-14 event dispatcher, or null if none was set.
	 */
	public static function eventDispatcher()
	{
		return self::$eventDispatcher;
	}

	/**
	 * Dispatches a model lifecycle event.
	 * If an event dispatcher has been configured, the event is handed to it,
	 * otherwise this is a no-op: the event is returned unchanged and nothing
	 * is notified anywhere. Mirrors {@see log()}'s "no logger registered ->
	 * nothing happens" convention.
	 *
	 * Exceptions thrown by a listener are not caught here -- they propagate
	 * out of dispatch() (and therefore out of whichever preSave()/postSave()/
	 * etc. hook triggered the dispatch, and the save()/delete() call that
	 * invoked the hook). Generated save()/delete() code catches \Throwable
	 * around the hook calls specifically so that any listener exception,
	 * regardless of type, still rolls back the transaction save()/delete()
	 * began before propagating.
	 *
	 * @param      object $event
	 * @return     object The event, potentially mutated by listeners (same
	 *             object instance that was passed in).
	 */
	public static function dispatch(object $event): object
	{
		return self::$eventDispatcher?->dispatch($event) ?? $event;
	}

	/**
	 * Registers the PSR-16 pool backing the global (cross-process) query result
	 * cache.
	 *
	 * Propulsion ships no Redis or Memcached client -- both protocols already
	 * have several mature PSR-16 implementations, and reimplementing
	 * reconnection, cluster topologies and TLS to duplicate them would be pure
	 * maintenance cost. Pass any third-party pool instead:
	 *
	 *     Propulsion::setQueryCachePool(new Psr16Cache(new RedisAdapter($redis)));
	 *
	 * The first-party drivers under `Propulsion\Cache\Driver` (array, apcu,
	 * file, null) are ordinary PSR-16 implementations and work here too, though
	 * they are more usually selected by name in the `cache.query` section of
	 * the runtime configuration.
	 *
	 * A pool registered here always takes precedence over that configuration.
	 * State lives on {@see ServiceContainer} (this cache is process-scoped, not
	 * request-scoped); this is a convenience delegator, named to match
	 * {@see setLogger()} and {@see setEventDispatcher()}.
	 *
	 * @param      CacheInterface $pool Any PSR-16 implementation.
	 */
	public static function setQueryCachePool(CacheInterface $pool): void
	{
		self::getServiceContainer()->setQueryCachePool($pool);
	}

	/**
	 * Returns true if a query cache pool has been registered or already built
	 * from configuration.
	 */
	public static function hasQueryCachePool(): bool
	{
		return self::getServiceContainer()->hasQueryCachePool();
	}

	/**
	 * The PSR-16 pool backing the global query result cache. Never null: an
	 * unconfigured deployment gets a {@see \Propulsion\Cache\Driver\NullCache},
	 * so callers need no null checks.
	 */
	public static function queryCachePool(): CacheInterface
	{
		return self::getServiceContainer()->queryCachePool();
	}

	/**
	 * The parsed `cache.query` section of the runtime configuration.
	 */
	public static function getQueryCacheConfig(): QueryCacheConfig
	{
		return self::getServiceContainer()->getQueryCacheConfig();
	}

	/**
	 * Run hand-written SQL through Propulsion's query result cache.
	 *
	 * For the complicated queries that are easier to write by hand than to
	 * express as a Criteria -- which tend to be the expensive ones, and so the
	 * ones most worth caching:
	 *
	 *     $books = Propulsion::rawQuery($sql, [$authorId])
	 *         ->dependsOn('book', 'author')
	 *         ->cache(ttl: 300)
	 *         ->hydrate(BookPeer::class);
	 *
	 * See {@see RawQuery} for the terminal methods and for why the tables have
	 * to be declared rather than inferred.
	 *
	 * @param      string             $sql    SQL with `?` placeholders
	 * @param      array<int, mixed>  $params positional bound values
	 * @param      string|null        $dbName datasource, defaulting to the default one
	 */
	public static function rawQuery(string $sql, array $params = array(), ?string $dbName = null): RawQuery
	{
		return new RawQuery($sql, $params, $dbName);
	}

	/**
	 * Invalidate every cached query that reads any of the given tables.
	 *
	 * The escape hatch for writes Propulsion cannot see: raw SQL, another
	 * application sharing the database, a migration, a DBA at a console. The
	 * ORM's own write paths call this for you; anything that bypasses them has
	 * to say so, or the affected entries stay served until their TTL lapses.
	 *
	 * @param      list<string> $tableNames
	 */
	public static function invalidateQueryCacheForTables(array $tableNames, ?string $dbName = null): void
	{
		$cache = self::getSession()->getQueryCache();
		foreach ($tableNames as $tableName) {
			$cache->invalidateTable($tableName, null, $dbName);
		}
	}

	/**
	 * Returns the database map information. Name relates to the name
	 * of the connection pool to associate with the map.
	 *
	 * The database maps are "registered" by the generated map builder classes.
	 *
	 * @param      string $name The name of the database corresponding to the DatabaseMap to retrieve.
	 *
	 * @return     DatabaseMap The named <code>DatabaseMap</code>.
	 *
	 * @throws     PropulsionException - if database map is null or propel was not initialized properly.
	 */
	public static function getDatabaseMap($name = null)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}

		if (!isset(self::$dbMaps[$name])) {
			$clazz = self::$databaseMapClass;
			$dbMap = new $clazz($name);
			if (!$dbMap instanceof DatabaseMap) {
				throw new PropulsionException(sprintf(
					'Configured database map class (%s) does not extend %s.',
					get_class($dbMap),
					DatabaseMap::class
				));
			}
			self::$dbMaps[$name] = $dbMap;
		}

		return self::$dbMaps[$name];
	}

	/**
	 * Sets the database map object to use for specified datasource.
	 *
	 * @param      string|null $name The datasource name.
	 * @param      DatabaseMap $map The database map object to use for specified datasource.
	 */
	public static function setDatabaseMap($name, DatabaseMap $map): void
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
	public static function setForceMasterConnection($bit): void
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
	 * @return     array<int, PDO> Every PDO/PropulsionPDO connection Propulsion currently
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
	 * @param      string|null $name The datasource name for the connection being set.
	 * @param      PropulsionPDO $con The PDO connection.
	 * @param      string $mode Whether this is a READ or WRITE connection (Propulsion::CONNECTION_READ, Propulsion::CONNECTION_WRITE)
	 */
	public static function setConnection($name, PropulsionPDO $con, $mode = Propulsion::CONNECTION_WRITE): void
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
	 * @return     PDO|PropulsionPDO A database connection
	 *
	 * @throws     PropulsionException - if connection cannot be configured or initialized.
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
	 * @return     PDO|PropulsionPDO A database connection
	 *
	 * @throws     PropulsionException - if connection cannot be configured or initialized.
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
	 * @return     PDO|PropulsionPDO A database connection
	 *
	 * @throws     PropulsionException - if connection cannot be configured or initialized.
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
	 * @return     PDO|PropulsionPDO A database connection
	 *
	 * @throws     PropulsionException - if connection cannot be configured or initialized.
	 */
	public static function getMasterConnection($name)
	{
		if (!isset(self::$connectionMap[$name]['master'])) {
			// load connection parameter for master connection
			$conparams = self::asConfigArray(self::getDatasourceConfig($name)['connection'] ?? null);
			if (empty($conparams)) {
				throw new PropulsionException('No connection information in your runtime configuration file for datasource ['.$name.']');
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
	 * Note this is *name*-based and drops both the master and the slave entry
	 * for that datasource. Code that has a dead connection object in hand and
	 * wants to evict exactly that one should use {@see discardConnection()}
	 * instead -- it needs no datasource name (which the connection itself does
	 * not carry) and won't take an unrelated, still-healthy sibling connection
	 * down with it.
	 *
	 * Neither method can revive an existing connection object: PDO has no
	 * reconnect, so "reconnecting" here only ever means "make the *next*
	 * getConnection() build a fresh one". Any caller still holding the old
	 * object keeps holding a dead one.
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
	 * Evict one specific connection object from the pool, whichever datasource
	 * and mode (master/slave) it happens to be registered under, so the next
	 * getConnection() for that slot builds a fresh one.
	 *
	 * Matching is by object identity rather than by datasource name because
	 * that is the only thing the caller reliably knows: a PropulsionPDO does not
	 * carry the name it was registered under, and the same object can be
	 * registered under more than one slot (getSlaveConnection() stores the
	 * master under 'slave' when a datasource has no slaves configured), so all
	 * matching slots are removed.
	 *
	 * @param  PDO $con The connection to evict.
	 * @return bool Whether it was found in the pool at all.
	 */
	public static function discardConnection(PDO $con): bool
	{
		$found = false;
		foreach (self::$connectionMap as $name => $modes) {
			foreach ($modes as $mode => $pooled) {
				if ($pooled === $con) {
					unset(self::$connectionMap[$name][$mode]);
					$found = true;
				}
			}
			if (self::$connectionMap[$name] === array()) {
				unset(self::$connectionMap[$name]);
			}
		}

		return $found;
	}

	/**
	 * Gets an already-opened read PDO connection or opens a new one for passed-in db name.
	 *
	 * @param      string $name The datasource name that is used to look up the DSN
	 *                          from the runtime configuation file. Empty name not allowed.
	 *
	 * @return     PDO|PropulsionPDO A database connection
	 *
	 * @throws     PropulsionException - if connection cannot be configured or initialized.
	 */
	public static function getSlaveConnection($name)
	{
		if (!isset(self::$connectionMap[$name]['slave'])) {

			$slaveconfigs = self::asConfigArray(self::getDatasourceConfig($name)['slaves'] ?? null);

			if (empty($slaveconfigs)) {
				// no slaves configured for this datasource
				// fallback to the master connection
				self::$connectionMap[$name]['slave'] = self::getMasterConnection($name);
			} else {
				$slaveConnections = self::asConfigArray($slaveconfigs['connection'] ?? null);
				if (empty($slaveConnections)) {
					throw new PropulsionException('No connection information in your runtime configuration file for SLAVEs to datasource ['.$name.']');
				}
				// Initialize a new slave
				if (isset($slaveConnections['dsn'])) {
					// only one slave connection configured
					$conparams = $slaveConnections;
				} else {
					// more than one sleve connection configured
					// pickup a random one
					$randkey = array_rand($slaveConnections);
					$conparams = self::asConfigArray($slaveConnections[$randkey] ?? null);
					if (empty($conparams)) {
						throw new PropulsionException('No connection information in your runtime configuration file for SLAVE ['.$randkey.'] to datasource ['.$name.']');
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
	 * @param      array<string,mixed> $conparams Connection paramters.
	 * @param      string $name Datasource name.
	 * @param      ?string $defaultClass The PDO subclass to instantiate if there is no explicit
	 * 									classname specified in the connection params. Defaults to
	 * 									null, meaning $adapter->getDefaultPdoClass() decides --
	 * 									the driver-specific PropulsionPDO implementation matching
	 * 									this datasource's own adapter (e.g. PgsqlPropulsionPDO for a
	 * 									DBPostgres datasource). An explicit override here is only
	 * 									meaningful for callers that need to bypass that per-adapter
	 * 									dispatch entirely.
	 *
	 * @return     PDO|PropulsionPDO A database connection of the given class (PDO, PropulsionPDO, SlavePDO or user-defined)
	 *
	 * @throws     PropulsionException - if lower-level exception caught when trying to connect.
	 */
	public static function initConnection($conparams, $name, ?string $defaultClass = null)
	{
		$adapter = self::getDB($name);

		$dsn = $conparams['dsn'];
		if ($dsn === null) {
			throw new PropulsionException('No dsn specified in your connection parameters for datasource ['.$name.']');
		}

		$conparams = $adapter->prepareParams($conparams);

		if (isset($conparams['classname']) && !empty($conparams['classname'])) {
			$classname = $conparams['classname'];
			if (!is_string($classname) || !class_exists($classname)) {
				throw new PropulsionException('Unable to load specified PDO subclass: ' . var_export($classname, true));
			}
		} else {
			$classname = $defaultClass ?? $adapter->getDefaultPdoClass();
		}

		$user = isset($conparams['user']) ? $conparams['user'] : null;
		$password = isset($conparams['password']) ? $conparams['password'] : null;

		// load any driver options from the config file
		// driver options are those PDO settings that have to be passed during the connection construction
		/** @var array<int|string, mixed> $driver_options */
		$driver_options = array();
		if ( isset($conparams['options']) && is_array($conparams['options']) ) {
			try {
				self::processDriverOptions( $conparams['options'], $driver_options );
			} catch (PropulsionException $e) {
				throw new PropulsionException('Error processing driver options for datasource ['.$name.']', $e);
			}
		}

		try {
			$con = new $classname($dsn, $user, $password, $driver_options);
			if (!$con instanceof PDO) {
				throw new PropulsionException(sprintf('Configured PDO subclass (%s) does not extend PDO.', get_class($con)));
			}
			$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (PDOException $e) {
			throw new PropulsionException("Unable to open PDO connection", $e);
		}

		// load any connection options from the config file
		// connection attributes are those PDO flags that have to be set on the initialized connection
		if (isset($conparams['attributes']) && is_array($conparams['attributes'])) {
			/** @var array<int|string, mixed> $attributes */
			$attributes = array();
			try {
				self::processDriverOptions( $conparams['attributes'], $attributes );
			} catch (PropulsionException $e) {
				throw new PropulsionException('Error processing connection attributes for datasource ['.$name.']', $e);
			}
			foreach ($attributes as $key => $value) {
				if (!is_int($key)) {
					throw new PropulsionException("Invalid PDO attribute name specified: $key");
				}
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
	 * @param      array<int|string, mixed> $source Where to find the list of constant flags and their new setting.
	 *                                      Each entry is expected to be an array with a 'value' key.
	 * @param      array<int|string, mixed> $write_to Put the data into here
	 *
	 * @throws     PropulsionException If invalid options were specified.
	 */
	private static function processDriverOptions(array $source, array &$write_to): void
	{
		foreach ($source as $option => $optiondata) {
			if (is_string($option) && strpos($option, '::') !== false) {
				$key = $option;
			} elseif (is_string($option)) {
				$key = 'Propulsion\\Connection\\PropulsionPDO::' . $option;
			} else {
				throw new PropulsionException("Invalid PDO option/attribute name specified: " . var_export($option, true));
			}
			if (!defined($key)) {
				throw new PropulsionException("Invalid PDO option/attribute name specified: ".$key);
			}
			$key = constant($key);
			if (!is_int($key) && !is_string($key)) {
				throw new PropulsionException("Invalid PDO option/attribute name specified: " . var_export($key, true));
			}

			if (!is_array($optiondata) || !array_key_exists('value', $optiondata)) {
				throw new PropulsionException("Invalid PDO option/attribute data specified for " . $key . ": expected an array with a 'value' key");
			}
			$value = $optiondata['value'];
			if (is_string($value) && strpos($value, '::') !== false) {
				if (!defined($value)) {
					throw new PropulsionException("Invalid PDO option/attribute value specified: ".$value);
				}
				$value = constant($value);
			}

			$write_to[$key] = $value;
		}
	}

	/**
	 * Returns database adapter for a specific datasource.
	 *
	 * @param      string $name The datasource name.
	 *
	 * @return     DBAdapter The corresponding database adapter.
	 *
	 * @throws     PropulsionException If unable to find DBdapter for specified db.
	 */
		public static function getDB($name = null)
	{
		if ($name === null) {
			$name = self::getDefaultDB();
		}

		if (!isset(self::$adapterMap[$name])) {
			$driver = self::getDatasourceConfig($name)['adapter'] ?? null;
			if (!is_string($driver) || $driver === '') {
				throw new PropulsionException("Unable to find adapter for datasource [" . $name . "].");
			}
			$db = DBAdapter::factory($driver);
			// register the adapter for this name
			self::$adapterMap[$name] = $db;
		}

		return self::$adapterMap[$name];
	}

	/**
	 * Sets a database adapter for specified datasource.
	 *
	 * @param      string|null $name The datasource name.
	 * @param      DBAdapter $adapter The DBAdapter implementation to use.
	 */
	public static function setDB($name, DBAdapter $adapter): void
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
			$default = self::getDatasourcesConfig()['default'] ?? null;
			self::$defaultDBName = is_string($default) ? $default : self::DEFAULT_NAME;
		}
		return self::$defaultDBName;
	}

	/**
	 * Closes any associated resource handles.
	 *
	 * This method frees any database connection handles that have been
	 * opened by the getConnection() method.
	 */
	public static function close(): void
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
	 * @param      string $path dot-path to clas (e.g. path.to.my.ClassName).
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
			throw new PropulsionException("Unable to import class: " . $class . " from " . $path);
		}

		// return qualified name
		return $class;
	}

	/**
	 * Set your own class-name for Database-Mapping. Then
	 * you can change the whole TableMap-Model, but keep its
	 * functionality for Criteria.
	 *
	 * @param      string $name The name of the class.
	 */
	public static function setDatabaseMapClass($name): void
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
	 * Whether objects hydrated right now should be pooled.
	 *
	 * Two independent gates, both of which must allow it:
	 *
	 *  - the explicit application-level switch (see $instancePoolingEnabled and
	 *    disableInstancePooling()), which is process-scoped;
	 *  - no scope currently having pooling *suspended* (see
	 *    Session::suspendInstancePooling()), which is request-scoped and
	 *    nestable -- this is what streamed/on-demand result sets use so they
	 *    don't retain every row they pass over.
	 *
	 * @return     boolean Whether the pooling is enabled or not.
	 */
	public static function isInstancePoolingEnabled()
	{
		return self::$instancePoolingEnabled && !self::getSession()->isInstancePoolingSuspended();
	}
}

// Generated Object Model classes (both the archived PHP5 builders and the current
// PHP84 ones) are emitted unnamespaced and reference runtime classes by their bare
// historic name (Propulsion::, TableMap, PropulsionException, ...) -- that was their actual
// global name before this fork renamed Propulsion\ to Propulsion\. Alias them eagerly
// (not lazily via spl_autoload_register) because `catch (PropulsionException $e)` --
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
				// (e.g. PropulsionYAMLParser expects a bundled sfYaml.php that isn't part of
				// this fork). Don't let one broken/unused legacy class -- or even just a
				// warning it emits while loading -- take down every other alias, and by
				// extension Propulsion::init() itself, for it.
			}
		}
	}
} finally {
	restore_error_handler();
}

