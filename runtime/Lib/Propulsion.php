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
use Propulsion\Exception\AdvisoryLockTimeoutException;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\DatabaseMap;
use Propulsion\Connection\PropulsionPDO;
use PDO;
use PDOException;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Connection\ConnectionConfig;
use Propulsion\Connection\PropulsionPDOTrait;
use Propulsion\Connection\RetryPolicy;
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
	const VERSION = '2.0.0';

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
	 * Memo of the datasource named as the default in the configuration, or null
	 * before it has been read (and again after a reconfiguration drops it -- see
	 * {@see forgetConfigurationDerivedState()}). {@see getDefaultDB()} has always
	 * treated null as "not resolved yet"; the `@var string` this used to carry
	 * was simply wrong about an untyped static, which defaults to null.
	 */
	private static ?string $defaultDBName = null;

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
	 * Initializes Propulsion against the current configuration, discarding
	 * everything derived from the previous one.
	 *
	 * This is the "apply a new configuration to a live process" entry point --
	 * multi-tenant hosts switching datasources, test harnesses swapping one
	 * fixture database for another. It used to reset only `$connectionMap`, so
	 * the adapters and the memoised default datasource name from the *old*
	 * configuration survived: a reconfigured process kept talking to the new
	 * DSN through the old adapter, and `getDefaultDB()` kept naming the old
	 * default. Both are now dropped and rebuilt lazily from whatever
	 * configuration is current.
	 *
	 * `$dbMaps` is deliberately *not* dropped, and does not belong on that list
	 * even though KNOWN_ISSUES.md used to group it with them. It is not derived
	 * from the configuration at all: a DatabaseMap describes the *schema* -- what
	 * tables exist and what columns they have -- which is a property of the
	 * generated model classes, not of the DSN they are reached through.
	 * Re-pointing a datasource at another server does not change what columns
	 * `book` has.
	 *
	 * Clearing it would also be unrecoverable rather than merely wasteful. Each
	 * generated Peer registers its TableMap from a `Foo::buildTableMap();`
	 * statement at the bottom of its own class file, which runs once, when the
	 * class is autoloaded. Nothing re-runs it, and `DatabaseMap::getTable()`
	 * has only the table name to work from -- it cannot dynamically resolve a
	 * TableMap class the way `getTableByPhpName()` can. So a cleared map would
	 * stay empty for every already-loaded class, and every `getTableMap()` call
	 * would throw "Cannot fetch TableMap for undefined table" for the rest of
	 * the process.
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
		self::forgetConfigurationDerivedState();

		self::$isInit = true;
	}

	/**
	 * Drop the process-global state that is a cache of, or a memo over, the
	 * runtime configuration, so the next access rebuilds it from whatever
	 * configuration is current.
	 *
	 * Both entries are lazily rebuilt by their own accessors -- {@see getDB()}
	 * re-reads `datasources.<name>.adapter` and calls `DBAdapter::factory()`,
	 * {@see getDefaultDB()} re-reads `datasources.default` -- so dropping them
	 * is always safe, never merely deferred work.
	 *
	 * Note this also discards an adapter registered explicitly with
	 * {@see setDB()}: such a registration describes the configuration the
	 * process was running under, and a new configuration supersedes it. Register
	 * it again after reconfiguring if it was meant to override the new
	 * configuration too.
	 */
	private static function forgetConfigurationDerivedState(): void
	{
		self::$adapterMap = array();
		self::$defaultDBName = null;
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

		// The adapters and the memoised default datasource name describe the
		// configuration being replaced, so they go with it -- otherwise a caller
		// that reconfigures without also calling initialize() keeps the old
		// default datasource and the old adapters, silently. initialize() calls
		// this too; it is cheap, and doing it here as well means the two entry
		// points cannot disagree about which one is responsible.
		self::forgetConfigurationDerivedState();

		// A new configuration may name a different cache driver, so drop any
		// pool already built from the old one. Note this only *invalidates* --
		// it deliberately does not build the replacement, since constructing a
		// file-backed driver creates directories and setConfiguration() is
		// called from tests and generator commands that will never cache
		// anything. The pool is rebuilt lazily on first use.
		self::$serviceContainer?->clearQueryCachePool();

		// Same for the execution-strategy settings, which are memoised for the
		// same "consulted on every connection checkout" reason.
		self::$serviceContainer?->clearConnectionConfig();

		// Likewise drop every compiled SELECT. That cache is process-scoped, and
		// the SQL text in it is specific to the adapter its datasource was using
		// when it was compiled -- identifier quoting and the LIMIT/OFFSET dialect
		// both come from there -- so a configuration naming a different adapter
		// for the same datasource name must not leave the old dialect's SQL
		// reachable. (While the cache was request-scoped this could not bite,
		// because the entries never outlived the request that built them.)
		self::$serviceContainer?->clearCompiledQueryCache();
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

			// A connection built one statement ago needs no liveness check.
			return self::$connectionMap[$name]['master'];
		}

		return self::checkOutPooled(self::$connectionMap[$name]['master'], $name, self::CONNECTION_WRITE);
	}

	/**
	 * Guards {@see checkOutPooled()} against re-entering itself: the rebuilt
	 * connection it returns has just been opened, and pinging *that* would at
	 * best waste a round trip and at worst loop.
	 */
	private static bool $rebuildingConnection = false;

	/**
	 * The pre-checkout liveness check: hand back a pooled connection only after
	 * establishing it still has a server on the other end, replacing it if not.
	 *
	 * Off unless `connection.liveness.enabled` is set, because it is not free
	 * and not always worth it -- see {@see ConnectionConfig}. Where it pays for
	 * itself is the deployment shape this ORM targets: under a persistent
	 * worker a connection outlives the request that opened it, so it can be
	 * reaped by a server-side idle timeout, a load balancer, a failover or a
	 * database restart in between two requests, and without this check the
	 * *next* request discovers that by failing. Under PHP-FPM, where the
	 * connection died with the request that made it, there is nothing here to
	 * catch.
	 *
	 * Only genuinely idle connections are pinged (`connection.liveness.idle_threshold`
	 * seconds, default 5): a connection that ran a statement moments ago is
	 * evidence of its own liveness, so under sustained traffic this collapses to
	 * approximately zero extra round trips while still covering the gap after a
	 * quiet period, which is where connections actually get reaped.
	 *
	 * A dead connection has already evicted itself from the pool by the time
	 * ping() returns false (via {@see PropulsionPDOTrait::handleDroppedConnection()}),
	 * so the rebuild below is an ordinary cache miss rather than special-case
	 * surgery on the pool.
	 *
	 * @param      PDO|PropulsionPDO $con  The pooled connection about to be handed out.
	 * @param      string            $name The datasource it is registered under.
	 * @param      string            $mode Propulsion::CONNECTION_READ or ::CONNECTION_WRITE.
	 *
	 * @return     PDO|PropulsionPDO The same connection, or a fresh replacement.
	 */
	private static function checkOutPooled(PDO|PropulsionPDO $con, string $name, string $mode)
	{
		if (self::$rebuildingConnection || !$con instanceof PropulsionPDO) {
			return $con;
		}

		$config = self::getServiceContainer()->getConnectionConfig();
		if (!$config->livenessEnabled || $con->getIdleSeconds() < $config->idleThreshold) {
			return $con;
		}

		if ($con->ping()) {
			return $con;
		}

		self::log(
			'[Propulsion::checkOutPooled] pooled ' . $mode . ' connection for datasource [' . $name . '] failed its '
			. 'liveness check after ' . sprintf('%.1f', $con->getIdleSeconds()) . 's idle; opening a replacement.',
			self::LOG_INFO
		);

		// Belt and braces: ping() only evicts via handleDroppedConnection(),
		// which runs for a *dropped* connection. Anything else that made the
		// ping fail leaves the pool entry in place, and returning it here would
		// defeat the whole check.
		self::discardConnection($con);

		self::$rebuildingConnection = true;
		try {
			return $mode === self::CONNECTION_READ
				? self::getSlaveConnection($name)
				: self::getMasterConnection($name);
		} finally {
			self::$rebuildingConnection = false;
		}
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
	 * @param  PDO|PropulsionPDO $con The connection to evict. Accepts the
	 *         interface as well as the class because PropulsionPDO does not
	 *         extend PDO -- it is implemented by driver-specific subclasses that
	 *         do -- so a caller holding one typed as the interface (as
	 *         {@see checkOutPooled()} does) has nothing narrower to pass.
	 * @return bool Whether it was found in the pool at all.
	 */
	public static function discardConnection(PDO|PropulsionPDO $con): bool
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
	 * Run a closure inside a transaction, retrying the whole thing if the
	 * database aborts it for a transient reason.
	 *
	 *     $book = Propulsion::transaction(function ($con) {
	 *         $book = BookQuery::create()->filterByISBN($isbn)->findOne($con);
	 *         $book->setStock($book->getStock() - 1);
	 *         $book->save($con);
	 *         return $book;
	 *     });
	 *
	 * The transaction is begun, the closure is called with the connection, and
	 * the transaction is committed if it returns or rolled back if it throws.
	 * The closure's return value is this method's return value.
	 *
	 * **What gets retried.** Only failures the adapter classifies as transient
	 * ({@see DBAdapter::isRetryableError()}: deadlock victim, serialization
	 * failure, lock-wait timeout) and connection drops -- and drops only when
	 * the connection was lost *before* the COMMIT was issued. A connection lost
	 * while the commit is in flight leaves the transaction's outcome genuinely
	 * unknown: the server may have committed it and died before saying so, so
	 * re-running the closure could apply the work twice. That case is rethrown
	 * for the caller to resolve, which is the only place the information to do
	 * so exists. Anything the closure itself throws is a business failure, not
	 * a transient one, and is rolled back and rethrown without retrying.
	 *
	 * **The closure must be safe to run more than once.** That is the price of
	 * retrying and it cannot be paid on the caller's behalf: database work is
	 * undone by the rollback, but anything the closure does *outside* the
	 * transaction -- sending mail, charging a card, incrementing a counter in
	 * Redis, mutating objects the caller kept a reference to -- is not. Keep
	 * side effects out of the closure, or pass {@see RetryPolicy::none()}.
	 *
	 * **Nested calls do not retry.** If the connection is already in a
	 * transaction, this runs the closure inside a nested one (a real SAVEPOINT
	 * where the platform has them) and never retries it, because it cannot: the
	 * failures this retries abort the *entire* transaction on most platforms,
	 * so the outer transaction the caller is in the middle of is already dead,
	 * and re-running the inner scope inside it would only fail again. Retrying
	 * has to happen at the outermost boundary, which is where the whole unit of
	 * work can actually be replayed.
	 *
	 * @param      callable(PropulsionPDO): mixed $work   Called with the connection.
	 * @param      string|null                    $name   Datasource, defaulting to the default one.
	 * @param      RetryPolicy|null               $policy Overrides the `connection.retry`
	 *                                                    configuration; {@see RetryPolicy::none()}
	 *                                                    to opt one call out of retrying.
	 *
	 * @return     mixed Whatever $work returned.
	 *
	 * @throws     \Throwable Whatever $work threw, or the last database failure
	 *                        if every attempt was exhausted.
	 */
	public static function transaction(callable $work, ?string $name = null, ?RetryPolicy $policy = null)
	{
		$con = self::getWriteConnection($name);
		if (!$con instanceof PropulsionPDO) {
			throw new PropulsionException(
				'Propulsion::transaction() needs a PropulsionPDO connection for datasource ['
				. ($name ?? self::getDefaultDB()) . '], got a ' . get_debug_type($con)
			);
		}

		if ($con->isInTransaction()) {
			return self::runInTransaction($con, $work);
		}

		$policy ??= self::getServiceContainer()->getConnectionConfig()->getRetryPolicy() ?? RetryPolicy::none();
		$adapter = self::getDB($name);
		$attempts = 0;

		while (true) {
			$attempts++;
			// Re-fetched each attempt rather than reused: a dropped connection
			// has evicted itself from the pool, so this is how the next attempt
			// gets a live one. (On a deadlock the connection is fine and this
			// hands back the very same object.)
			$con = self::getWriteConnection($name);
			if (!$con instanceof PropulsionPDO) {
				throw new PropulsionException(
					'Propulsion::transaction() needs a PropulsionPDO connection for datasource ['
					. ($name ?? self::getDefaultDB()) . '], got a ' . get_debug_type($con)
				);
			}

			$committing = false;
			try {
				$con->beginTransaction();
				$result = $work($con);
				$committing = true;
				$con->commit();

				return $result;
			} catch (PDOException $e) {
				self::rollBackQuietly($con);

				$retryable = $committing
					? false
					: ($adapter->isRetryableError($e) || self::isConnectionDropped($e));

				if (!$retryable || !$policy->shouldRetry($attempts)) {
					throw $e;
				}

				$delay = $policy->delayMicrosecondsFor($attempts);
				self::log(
					'[Propulsion::transaction] attempt ' . $attempts . ' of ' . $policy->maxAttempts
					. ' failed with a retryable error (' . $e->getMessage() . '); retrying in '
					. sprintf('%.1f', $delay / 1000) . 'ms.',
					self::LOG_INFO
				);
				if ($delay > 0) {
					usleep($delay);
				}
			} catch (\Throwable $e) {
				// The closure's own failure. Undo the transaction, but do not
				// second-guess the caller by re-running it.
				self::rollBackQuietly($con);

				throw $e;
			}
		}
	}

	/**
	 * The nested case of {@see transaction()}: the caller is already inside a
	 * transaction, so this opens a nested one (a SAVEPOINT where the platform
	 * supports it) and never retries.
	 *
	 * @param      callable(PropulsionPDO): mixed $work
	 * @return     mixed
	 * @throws     \Throwable
	 */
	private static function runInTransaction(PropulsionPDO $con, callable $work)
	{
		$con->beginTransaction();
		try {
			$result = $work($con);
		} catch (\Throwable $e) {
			self::rollBackQuietly($con);

			throw $e;
		}
		$con->commit();

		return $result;
	}

	/**
	 * Registers a predicate applied to every query on $modelName unless the
	 * query opts out -- soft delete and multi-tenancy being the two cases this
	 * exists for.
	 *
	 *     Propulsion::addGlobalQueryFilter('Book', 'not-deleted', function ($q) {
	 *         $q->filterByDeletedAt(null);
	 *     });
	 *     Propulsion::addGlobalQueryFilter('Book', 'tenant', function ($q) {
	 *         $q->filterByTenantId(CurrentTenant::id());
	 *     });
	 *
	 * A callable rather than a stored condition, because the interesting
	 * filters are not knowable at registration time: the tenant is a property
	 * of the request, not of the bootstrap. The closure runs when the query is
	 * built and receives the query itself, so it can use the generated
	 * `filterByX()` methods and read whatever it needs then.
	 *
	 * Filters are **process-scoped configuration** and survive
	 * `Session::reset()`, which is what a persistent worker needs -- register
	 * the closure once and let it read request state on each run. Do not
	 * capture a tenant id *by value* inside a request; capture the lookup.
	 *
	 * Applied to SELECT, COUNT, UPDATE and DELETE alike. An unfiltered UPDATE
	 * or DELETE would write across the boundary the filtered SELECT exists to
	 * enforce, which is a worse failure than reading across it.
	 * `deleteAll()` is deliberately exempt: it is the explicit "empty this
	 * table" operation and has no WHERE clause to narrow.
	 *
	 * @param      string $modelName  The model's name as its query class reports it
	 *                                ({@see ModelCriteria::getModelName()}), namespace
	 *                                included if it has one.
	 * @param      string $filterName A name unique per model. Re-registering a name
	 *                                replaces rather than stacks, so a bootstrap that
	 *                                runs twice cannot apply its filters twice --
	 *                                and it is what a query names to drop this one
	 *                                filter without dropping the others
	 *                                ({@see ModelCriteria::withoutGlobalFilter()}).
	 * @param      callable(\Propulsion\Query\ModelCriteria): void $filter
	 */
	public static function addGlobalQueryFilter(string $modelName, string $filterName, callable $filter): void
	{
		self::getServiceContainer()->getGlobalQueryFilters()->add($modelName, $filterName, $filter);
	}

	/**
	 * Unregisters one global query filter. A no-op if it was never registered.
	 */
	public static function removeGlobalQueryFilter(string $modelName, string $filterName): void
	{
		self::getServiceContainer()->getGlobalQueryFilters()->remove($modelName, $filterName);
	}

	/**
	 * Unregisters every global query filter on $modelName, or on every model
	 * when it is null. Mostly for test isolation.
	 */
	public static function clearGlobalQueryFilters(?string $modelName = null): void
	{
		self::getServiceContainer()->getGlobalQueryFilters()->clear($modelName);
	}

	/**
	 * The global query filter registry itself, for a caller that wants to
	 * inspect it (which names are registered for a model, say) rather than go
	 * through the three convenience methods above.
	 */
	public static function getGlobalQueryFilters(): \Propulsion\Query\GlobalQueryFilters
	{
		return self::getServiceContainer()->getGlobalQueryFilters();
	}

	/**
	 * Run a closure while holding a named application-level ("advisory") lock,
	 * releasing it afterwards whatever happens.
	 *
	 *     Propulsion::withAdvisoryLock('nightly-invoice-run', function () {
	 *         // at most one process in the cluster is in here
	 *     });
	 *
	 * The lock is a mutex the database only stores and arbitrates; it is
	 * attached to no row and no table, and the database cares nothing about
	 * what the name means. That is what makes it the right tool for "only one
	 * worker should do this at a time" -- a job queue's dispatcher, a cron
	 * entry running on three app servers, a migration guard -- where there is
	 * no row to take a `FOR UPDATE` on, or where the work is not a database
	 * write at all.
	 *
	 * **Scoped to the connection, not the transaction.** Every platform's
	 * primitive is used in its session-scoped form, so a COMMIT inside the
	 * closure does not drop the lock. It is released in a `finally`, and by
	 * the connection closing if the process dies -- the database is the one
	 * component that can be relied on to clean up after a crashed holder,
	 * which is the main reason to prefer this over a lock table.
	 *
	 * **Re-entrancy is not provided.** Nesting two calls with the same name on
	 * one connection behaves differently per platform (MySQL 5.7+ and Oracle
	 * grant it again, Postgres counts it, MSSQL grants it) and the inner
	 * release would then free a lock the outer scope still believes it holds.
	 * Don't nest the same name.
	 *
	 * @param      string   $name    The lock name. Platform limits apply and are
	 *                               not papered over: MySQL rejects names over 64
	 *                               characters, Oracle over 128. Postgres has no
	 *                               limit because the name is hashed to an integer
	 *                               there -- see DBAdapter::advisoryLockKey().
	 * @param      callable(PropulsionPDO): mixed $work Called with the connection
	 *                               holding the lock. Use *that* connection for
	 *                               work that must be covered by it.
	 * @param      ?float   $timeout Seconds to wait for the lock: null (the
	 *                               default) waits indefinitely, 0.0 gives up
	 *                               immediately if it is held, and a positive
	 *                               value waits at most that long. Sub-second
	 *                               values are rounded *up* on the platforms
	 *                               whose primitive takes whole seconds
	 *                               (MySQL, Oracle), because rounding down would
	 *                               turn "wait briefly" into "don't wait".
	 * @param      ?string  $dbName  Datasource, defaulting to the default one.
	 *
	 * @return     mixed Whatever $work returned.
	 *
	 * @throws     AdvisoryLockTimeoutException If the lock could not be acquired
	 *                               within $timeout. Distinct from a generic
	 *                               failure on purpose: "someone else has it" is
	 *                               an ordinary, expected outcome a caller may
	 *                               well want to catch and skip on, rather than
	 *                               an error.
	 * @throws     PropulsionException If the platform has no advisory locks
	 *                               (SQLite). Deliberately fatal rather than a
	 *                               silent pass-through: running the closure
	 *                               unserialised is precisely the outcome the
	 *                               caller is trying to prevent.
	 * @throws     \Throwable Whatever $work threw.
	 */
	public static function withAdvisoryLock(string $name, callable $work, ?float $timeout = null, ?string $dbName = null)
	{
		$adapter = self::getDB($dbName);
		// Capability first, connection second: "this platform cannot do it at
		// all" is the more useful error, and opening a connection to find that
		// out would bury it behind a configuration one.
		self::requireAdvisoryLockSupport($adapter, $dbName);
		$con = self::advisoryLockConnection($dbName);

		if (!$adapter->acquireAdvisoryLock($con, $name, $timeout)) {
			throw new AdvisoryLockTimeoutException($name, $timeout);
		}

		try {
			return $work($con);
		} finally {
			$adapter->releaseAdvisoryLock($con, $name);
		}
	}

	/**
	 * Takes out a named lock and leaves it held, for the cases
	 * {@see withAdvisoryLock()}'s scope doesn't fit -- a lock spanning several
	 * top-level operations, or one whose release is driven by something other
	 * than a closure returning.
	 *
	 * The caller then owns releasing it via {@see releaseAdvisoryLock()}, on
	 * the same datasource. Prefer withAdvisoryLock() where it fits: its
	 * `finally` is the difference between a crashed request releasing the lock
	 * and it being held until the connection is reaped.
	 *
	 * @param      string  $name
     * @param      ?float  $timeout See withAdvisoryLock().
	 * @param      ?string $dbName
	 *
	 * @return     bool Whether the lock is now held.
	 * @throws     PropulsionException If the platform has no advisory locks.
	 */
	public static function acquireAdvisoryLock(string $name, ?float $timeout = null, ?string $dbName = null): bool
	{
		$adapter = self::getDB($dbName);
		self::requireAdvisoryLockSupport($adapter, $dbName);

		return $adapter->acquireAdvisoryLock(self::advisoryLockConnection($dbName), $name, $timeout);
	}

	/**
	 * Releases a lock taken with {@see acquireAdvisoryLock()}.
	 *
	 * @param      string  $name
	 * @param      ?string $dbName
	 *
	 * @return     bool Whether this datasource's connection was holding it. False
	 *                  is informational rather than an error -- see
	 *                  DBAdapter::releaseAdvisoryLock().
	 * @throws     PropulsionException If the platform has no advisory locks.
	 */
	public static function releaseAdvisoryLock(string $name, ?string $dbName = null): bool
	{
		$adapter = self::getDB($dbName);
		self::requireAdvisoryLockSupport($adapter, $dbName);

		return $adapter->releaseAdvisoryLock(self::advisoryLockConnection($dbName), $name);
	}

	/**
	 * Whether $dbName's platform has advisory locks at all, for a caller that
	 * wants to degrade gracefully (say, to a single-instance deployment
	 * assumption on SQLite) rather than catch the exception the three methods
	 * above throw.
	 */
	public static function supportsAdvisoryLocks(?string $dbName = null): bool
	{
		return self::getDB($dbName)->supportsAdvisoryLocks();
	}

	/**
	 * The write connection, which is where an advisory lock is taken.
	 *
	 * Always the write connection even for read-only work, and never a slave:
	 * an advisory lock lives on one session, so acquiring and releasing it
	 * have to land on the same connection, and the read pool makes no such
	 * promise. Callers that need their queries covered by the lock get that
	 * connection handed to them for exactly this reason.
	 */
	private static function advisoryLockConnection(?string $dbName): PropulsionPDO
	{
		$con = self::getWriteConnection($dbName);
		if (!$con instanceof PropulsionPDO) {
			throw new PropulsionException(
				'Advisory locks need a PropulsionPDO connection for datasource ['
				. ($dbName ?? self::getDefaultDB()) . '], got a ' . get_debug_type($con)
			);
		}

		return $con;
	}

	private static function requireAdvisoryLockSupport(DBAdapter $adapter, ?string $dbName): void
	{
		if ($adapter->supportsAdvisoryLocks()) {
			return;
		}

		throw new PropulsionException(sprintf(
			'%s (datasource [%s]) has no advisory locks. SQLite locks the whole database and has no '
			. 'named-lock primitive; use Propulsion::supportsAdvisoryLocks() to branch if the '
			. 'deployment can tolerate running unserialised.',
			get_class($adapter),
			$dbName ?? self::getDefaultDB()
		));
	}

	/**
	 * Roll back, tolerating a connection that can no longer be rolled back.
	 *
	 * Two ways that happens, both normal here rather than exceptional: the
	 * connection was dropped, in which case handleDroppedConnection() has
	 * already zeroed the transaction depth and the server-side transaction died
	 * with the session anyway; or the server aborted the transaction itself
	 * (Postgres does this on a serialization failure) and the rollback races
	 * with that. Either way the failure being handled is the one worth
	 * reporting, so a rollback failure must not replace it -- it is logged and
	 * dropped.
	 */
	private static function rollBackQuietly(PropulsionPDO $con): void
	{
		if (!$con->isInTransaction()) {
			return;
		}

		try {
			$con->rollBack();
		} catch (\Throwable $e) {
			self::log(
				'[Propulsion::transaction] rollback after a failed transaction itself failed ('
				. $e->getMessage() . '); the original failure is being rethrown.',
				self::LOG_WARNING
			);
		}
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

			return self::$connectionMap[$name]['slave'];
		} // if datasource slave not set

		return self::checkOutPooled(self::$connectionMap[$name]['slave'], $name, self::CONNECTION_READ);
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
			if (!is_string($classname)) {
				throw new PropulsionException('Configured PDO classname must be a string, got ' . get_debug_type($classname));
			}
			if (!class_exists($classname)) {
				// A configuration naming PropulsionPDO itself -- or the legacy
				// `PropelPDO`, which legacy-class-map.php aliases to it -- is
				// asking for "Propulsion's PDO", which is exactly what the
				// adapter's default class is. It only fails the class_exists()
				// check because that name became an *interface* in Propulsion,
				// implemented by the driver-specific subclasses; in Propel 1.6 it
				// was the concrete class you instantiated. `<classname>PropelPDO</classname>`
				// is what Propel's own convert-conf emitted, so refusing it would
				// reject essentially every migrated configuration over a rename
				// that was never the operator's business.
				//
				// Honoured rather than merely tolerated: the substituted class is
				// the driver-specific PropulsionPDO for this datasource's adapter,
				// which is a strictly better answer than the interface could have
				// been even when it was a class.
				if (interface_exists($classname) && is_a($classname, PropulsionPDO::class, true)) {
					$classname = $defaultClass ?? $adapter->getDefaultPdoClass();
				} else {
					throw new PropulsionException(
						'Unable to load the PDO class configured for datasource [' . $name . ']: '
						. var_export($conparams['classname'], true) . ' is not a class. It must be a concrete '
						. 'class extending PDO (Propulsion\\Connection\\GenericPropulsionPDO, or one of the '
						. 'driver-specific subclasses); omit `classname` entirely to get the right one for this '
						. "datasource's adapter automatically."
					);
				}
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
				if (!defined($key)) {
					throw new PropulsionException("Invalid PDO option/attribute name specified: ".$key);
				}
			} elseif (is_string($option)) {
				// A bare name is resolved against the PropulsionPDO interface
				// first, then against PDO itself.
				//
				// The fallback is not a convenience, it is the whole of what
				// makes a bare name work at all. In Propel 1.6 the same name was
				// a *class* -- `PropelPDO extends PDO` -- so `PropelPDO::ATTR_PERSISTENT`
				// resolved through inheritance, and a bare `ATTR_PERSISTENT` in a
				// config file (which is exactly what Propel's own convert-conf
				// emitted, `<option id="ATTR_PERSISTENT">`) found it. Propulsion
				// made PropulsionPDO an interface, and an interface inherits
				// nothing from PDO: it declares only PROPEL_ATTR_CACHE_PREPARES
				// and the two DEFAULT_* constants, so *every* real PDO constant
				// name became undefined under that prefix and every migrated
				// configuration using one failed to connect at all.
				//
				// The two sets do not overlap -- PROPEL_ATTR_CACHE_PREPARES (-1)
				// exists only on the interface, the ATTR_*/MYSQL_ATTR_* names only
				// on PDO -- so trying the interface first is unambiguous and keeps
				// Propulsion's own attribute working.
				$key = 'Propulsion\\Connection\\PropulsionPDO::' . $option;
				if (!defined($key)) {
					$key = 'PDO::' . $option;
				}
				if (!defined($key)) {
					throw new PropulsionException(
						'Invalid PDO option/attribute name specified: "' . $option . '" is not a constant on '
						. 'Propulsion\\Connection\\PropulsionPDO or on PDO. Qualify it explicitly '
						. '(e.g. "PDO::ATTR_PERSISTENT") if it belongs to some other class.'
					);
				}
			} else {
				throw new PropulsionException("Invalid PDO option/attribute name specified: " . var_export($option, true));
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
// Skippable, because aliasing all of these eagerly costs every process roughly
// 3.2 MB and ~176 loaded classes (measured; see docs/WORKER_MODE.md), and an
// application whose *generated* model classes are namespaced never needs any of
// it -- namespaced generated code imports the runtime classes properly, and as of
// the OMBuilder::getUseStatements() rework so does newly generated flat code.
//
// It stays opt-*out* rather than opt-in because what it also covers is
// hand-written application code still using the bare historic names (`new
// Criteria()`, `catch (PropelException $e)`, `$con instanceof PropelPDO`), and
// those break *silently* without the alias: PHP does not consult the autoloader
// for `catch`, `instanceof`, `is_a()` or a parameter type check, so the failure
// is a wrong answer -- an unmatched catch, a false instanceof -- rather than an
// error. Defaulting to on means nobody is broken by upgrading; defining the
// constant is a deliberate statement that your own code does not rely on the bare
// names either.
//
// Define it before anything loads this class, e.g. at the top of your front
// controller:
//
//     define('PROPULSION_SKIP_LEGACY_CLASS_ALIASES', true);
//
if (!defined('PROPULSION_SKIP_LEGACY_CLASS_ALIASES') || !constant('PROPULSION_SKIP_LEGACY_CLASS_ALIASES')) {
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
} // PROPULSION_SKIP_LEGACY_CLASS_ALIASES

