<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

/**
 * Shared implementation behind the PropulsionPDO interface -- see that
 * interface's own docblock for what this adds on top of plain PDO.
 *
 * A trait rather than a base class: PHP 8.4's driver-specific PDO subclasses
 * (\Pdo\Pgsql, \Pdo\Mysql, \Pdo\Sqlite, \Pdo\Dblib, ...) each already extend
 * \PDO directly, and PHP has no multiple inheritance, so a class wanting both
 * this behavior and a specific one of those (to keep reaching driver-specific
 * methods like Pdo\Pgsql::copyFromArray() instead of the deprecated
 * PDO::pgsqlCopyFromArray()) has to get this behavior via `use`, not `extends`.
 * See PgsqlPropulsionPDO/MysqlPropulsionPDO/SqlitePropulsionPDO/
 * MssqlPropulsionPDO/OraclePropulsionPDO/GenericPropulsionPDO for the concrete
 * classes that actually `use` this trait.
 *
 * @author     Cameron Brunner <cameron.brunner@gmail.com>
 * @author     Hans Lellelid <hans@xmpl.org>
 * @author     Christian Abegg <abegg.ch@gmail.com>
 * @since      2006-09-22
 */
use Propulsion\Propulsion;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Exception\PropulsionException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

trait PropulsionPDOTrait
{

	/**
	 * The current transaction depth.
	 * @var       integer
	 */
	protected $nestedTransactionCount = 0;

	/**
	 * PDO driver names (PDO::ATTR_DRIVER_NAME) for which real nested transactions
	 * are implemented using SQL SAVEPOINT/RELEASE SAVEPOINT/ROLLBACK TO SAVEPOINT
	 * (see beginTransaction()/commit()/rollBack()). These three are the drivers
	 * this project's own test/deployment matrix targets (see IntegrationDatabase)
	 * and whose SAVEPOINT support is both present and standard-syntax-compatible.
	 *
	 * dblib/MSSQL is the one driver here that doesn't use real transactions at
	 * all (see MssqlPropulsionPDO), so it falls back to the pre-existing
	 * depth-counter/poison-flag emulation instead, where a rollback of a nested
	 * transaction doesn't undo anything by itself but instead poisons the outer
	 * transaction so that its eventual commit() throws instead of silently
	 * discarding the rolled-back work.
	 *
	 * Oracle ("oci") *is* included here -- SAVEPOINT and ROLLBACK TO SAVEPOINT are
	 * both standard Oracle syntax -- even though it has no RELEASE SAVEPOINT
	 * statement of its own; see $releaseSavepointCapableDrivers/
	 * supportsReleaseSavepoint() for how commit() handles that one gap.
	 *
	 * @var       array<int, string>
	 */
	protected static $savepointCapableDrivers = ['pgsql', 'mysql', 'sqlite', 'oci'];

	/**
	 * Subset of $savepointCapableDrivers whose SQL dialect also has an explicit
	 * RELEASE SAVEPOINT statement, used by commit() to release a nested
	 * transaction's savepoint once it's done with it. Oracle is deliberately
	 * excluded: it has no RELEASE SAVEPOINT syntax at all, but doesn't need one
	 * either -- a `SAVEPOINT` statement reusing an already-used name (as
	 * getSavepointName()'s deterministic, depth-keyed names do) simply re-marks
	 * it in place of the old one, and any savepoint still outstanding is released
	 * implicitly by the eventual outer COMMIT/ROLLBACK.
	 *
	 * @var       array<int, string>
	 */
	protected static $releaseSavepointCapableDrivers = ['pgsql', 'mysql', 'sqlite'];

	/**
	 * Cache of prepared statements (PDOStatement) keyed by a digest of the SQL.
	 *
	 * Only successful prepares are stored -- see prepare() for why caching a
	 * `false` return is worse than not caching it.
	 *
	 * Insertion-ordered, and bounded by self::MAX_CACHED_PREPARED_STATEMENTS;
	 * see evictOldestPreparedStatements().
	 *
	 * @var       array<string, \PDOStatement>  [digest(sql) => PDOStatement]
	 */
	protected $preparedStatements = array();

	/**
	 * How many prepared statements one connection will hold at once when
	 * PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES is on.
	 *
	 * A bound is necessary rather than tidy. Connections are process-scoped and,
	 * under a persistent worker, effectively immortal, while the set of distinct
	 * SQL strings a process issues is not bounded by anything: a criterion with
	 * an `IN (...)` list produces one placeholder arity per distinct list length,
	 * so an unbounded cache accumulates statement handles (and server-side
	 * prepared statements behind them) for the life of the process. In PHP-FPM
	 * this was hidden by the connection dying with the request.
	 *
	 * The value is deliberately generous: it is meant to cap pathological growth,
	 * not to make a well-behaved application miss, and an application whose hot
	 * path really does span more than this many distinct statements would be
	 * thrashing the cache rather than benefiting from it.
	 */
	protected const MAX_CACHED_PREPARED_STATEMENTS = 256;

	/**
	 * Whether to cache prepared statements.
	 *
	 * @var       boolean
	 */
	protected $cachePreparedStatements = false;

	/**
	 * Whether the final commit is possible
	 * Is false if a nested transaction is rolled back
	 *
	 * @var       bool
	 */
	protected $isUncommitable = false;

	/**
	 * Count of queries performed.
	 *
	 * @var       integer
	 */
	protected $queryCount = 0;

	/**
	 * SQL code of the latest performed query.
	 *
	 * Initialised rather than left implicitly null: it is only ever assigned
	 * from inside a `if ($this->useDebug)` branch, so on a connection that has
	 * never run with debugging on, getLastExecutedQuery() -- declared
	 * `: string` -- used to hit a TypeError instead of returning "no query
	 * yet". (Untyped property plus a `@var string` docblock is also why static
	 * analysis never flagged it.)
	 *
	 * @var       string
	 */
	protected $lastExecutedQuery = '';

	/**
	 * Whether or not the debug is enabled
	 *
	 * @var       boolean
	 */
	public $useDebug = false;

	/**
	 * Optional per-connection PSR-3 logger override. Falls back to Propulsion::log()
	 * (and whatever logger is registered there) when unset.
	 *
	 * @var       LoggerInterface|null
	 */
	protected ?LoggerInterface $logger = null;

	/**
	 * The log level to use for logging.
	 *
	 * @var       string
	 */
	private $logLevel = Propulsion::LOG_DEBUG;

	/**
	 * The runtime configuration
	 *
	 * @var       PropulsionConfiguration
	 */
	protected $configuration;

	/**
	 * The default value for runtime config item "debugpdo.logging.methods".
	 *
	 * @var       array<int, string>
	 */
	protected static $defaultLogMethods = array(
		'PropulsionPDO::exec',                     // legacy (pre-namespace) identifier
		'PropulsionPDO::query',                    // legacy identifier
		'DebugPDOStatement::execute',          // legacy identifier
		'Propulsion\\Connection\\PropulsionPDO::exec',    // namespaced runtime __METHOD__ form
		'Propulsion\\Connection\\PropulsionPDO::query',   // namespaced runtime __METHOD__ form
		'Propulsion\\Connection\\DebugPDOStatement::execute', // namespaced runtime __METHOD__ form
	);

	/**
	 * Creates a PropulsionPDO instance representing a connection to a database.
	 *.
	 * If so configured, specifies a custom PDOStatement class and makes an entry
	 * to the log with the state of this object just after its initialization.
	 * Add PropulsionPDO::__construct to $defaultLogMethods to see this message
	 *
	 * @param     string  $dsn  Connection DSN.
	 * @param     string  $username  The user name for the DSN string.
	 * @param     string  $password  The password for the DSN string.
	 * @param     array<int, mixed>   $driver_options  A key=>value array of driver-specific connection options.
	 *
	 * @throws     \PDOException if there is an error during connection initialization.
	 */
	public function __construct($dsn, $username = null, $password = null, $driver_options = array())
	{
		$debug = null;
		if ($this->useDebug) {
			$debug = $this->getDebugSnapshot();
		}

		parent::__construct($dsn, $username, $password, $driver_options);

		if ($this->useDebug) {
			// Use fully-qualified statement class to satisfy PDO strict validation
			$this->configureStatementClass('\\Propulsion\\Connection\\DebugPDOStatement', true);
			$this->log('Opening connection', null, PropulsionPDO::class . '::' . __FUNCTION__, $debug);
		}
	}

	/**
	 * Inject the runtime configuration
	 *
	 * @param   PropulsionConfiguration  $configuration
	 */
	public function setConfiguration($configuration): void
	{
		$this->configuration = $configuration;
	}

	/**
	 * Get the runtime configuration
	 *
	 * @return    PropulsionConfiguration
	 */
	public function getConfiguration()
	{
		if (null === $this->configuration) {
			$config = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
			if (!$config instanceof PropulsionConfiguration) {
				// Propulsion::getConfiguration()'s return type is polymorphic on its
				// $type argument (array or PropulsionConfiguration) and so can't be
				// narrowed from its signature alone -- TYPE_OBJECT guarantees this
				// in practice, checked explicitly here rather than asserted away.
				throw new PropulsionException('Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT) unexpectedly returned a ' . get_debug_type($config));
			}
			$this->configuration = $config;
		}
		return $this->configuration;
	}

	/**
	 * Gets the current transaction depth.
	 *
	 * @return    integer
	 */
	public function getNestedTransactionCount()
	{
		return $this->nestedTransactionCount;
	}

	/**
	 * Set the current transaction depth.
	 * @param     int $v The new depth.
	 */
	protected function setNestedTransactionCount($v): void
	{
		$this->nestedTransactionCount = $v;
	}

	/**
	 * Is this PDO connection currently in-transaction?
	 * This is equivalent to asking whether the current nested transaction count is greater than 0.
	 *
	 * @return    boolean
	 */
	public function isInTransaction()
	{
		return ($this->getNestedTransactionCount() > 0);
	}

	/**
	 * Check whether the connection contains a transaction that can be committed.
	 * To be used in an evironment where Propulsionexceptions are caught.
	 *
	 * @return    boolean  True if the connection is in a committable transaction
	 */
	public function isCommitable(): bool
	{
		return $this->isInTransaction() && !$this->isUncommitable;
	}

	/**
	 * Overrides PDO::beginTransaction() to prevent errors due to already-in-progress transaction.
	 *
	 * For nested calls (transaction depth going from N to N+1, N >= 1) on a platform that
	 * supports it (see supportsSavepoints()), this issues a real SQL SAVEPOINT so that a
	 * later rollBack() of just this nesting level can genuinely undo only the work done
	 * since this call, rather than merely poisoning the whole outer transaction.
	 *
	 * @return    boolean
	 */
	public function beginTransaction(): bool
	{
		$return = true;
		if (!$this->nestedTransactionCount) {
			$return = parent::beginTransaction();
			if ($this->useDebug) {
				$this->log('Begin transaction', null, PropulsionPDO::class . '::' . __FUNCTION__);
			}
			$this->isUncommitable = false;
		} elseif ($this->supportsSavepoints()) {
			$return = parent::exec('SAVEPOINT ' . $this->getSavepointName($this->nestedTransactionCount + 1)) !== false;
		}
		$this->nestedTransactionCount++;

		return $return;
	}

	/**
	 * Overrides PDO::commit() to only commit the transaction if we are in the outermost
	 * transaction nesting level.
	 *
	 * For nested calls on a savepoint-capable platform, this releases the SAVEPOINT that
	 * was created by the matching beginTransaction() call instead.
	 *
	 * **Once a statement has actually been issued, the depth counter is decremented
	 * even if that statement fails.** It used to be decremented only on the
	 * success path, so a PDOException out of `parent::commit()` (a deadlock, a
	 * serialization failure, a deferred constraint firing at COMMIT) or out of the
	 * `RELEASE SAVEPOINT` left a pooled connection permanently claiming a depth it
	 * no longer had. Two things then went wrong for the rest of the request, both
	 * silent: `isInTransaction()` kept returning true, so
	 * `TieredQueryCache::sharedTierFor()` refused to consult the shared cache at
	 * all (it declines inside a transaction, to avoid publishing uncommitted
	 * rows); and a caller that handled the failure and retried unwound one level
	 * too few. Decrementing is right because the level really is over either way:
	 * a failed COMMIT is still terminal for the transaction, the driver having
	 * already resolved or aborted it, so there is nothing left here to unwind.
	 *
	 * The `isUncommitable` guard is deliberately *outside* that: it throws without
	 * issuing anything, so the transaction genuinely is still open on the server
	 * and the depth must stay to say so. That is what lets the caller's own
	 * rollBack() -- or, failing that, `Session::rollBackDanglingTransactions()` at
	 * the request boundary -- find it and unwind it. Decrementing here instead
	 * would strand an open transaction that nothing could see, which on Postgres
	 * poisons the connection for everything that reuses it.
	 *
	 * @return    boolean
	 */
	public function commit(): bool
	{

		$return = true;
		$opcount = $this->nestedTransactionCount;

		if ($opcount > 0) {
			// Checked before the try/finally below: this path issues no
			// statement, so the transaction stays open and the depth stays with
			// it. See the docblock.
			if ($opcount === 1 && $this->isUncommitable) {
				throw new PropulsionException('Cannot commit because a nested transaction was rolled back');
			}

			try {
				if ($opcount === 1) {
					$return = parent::commit();
					if ($this->useDebug) {
						$this->log('Commit transaction', null, PropulsionPDO::class . '::' . __FUNCTION__);
					}
					// Only now may the shared query cache learn that the tables
					// written in this transaction changed. Publishing earlier
					// would let another process cache our uncommitted rows
					// under an already-current version token, and nothing would
					// bump again to dislodge it.
					$this->publishQueryCacheInvalidations();
				} elseif ($this->supportsSavepoints()) {
					$return = !$this->supportsReleaseSavepoint()
						|| parent::exec('RELEASE SAVEPOINT ' . $this->getSavepointName($opcount)) !== false;
				}
			} finally {
				$this->nestedTransactionCount--;
			}
		}
		return $return;
	}

	/**
	 * Overrides PDO::rollBack() to only rollback the transaction if we are in the outermost
	 * transaction nesting level
	 *
	 * On a savepoint-capable platform (see supportsSavepoints()), a rollback of a nested
	 * transaction (depth > 1) issues a real SQL ROLLBACK TO SAVEPOINT, undoing only the
	 * work done since the matching beginTransaction() call -- the outer transaction is left
	 * open and can go on to commit() normally afterwards.
	 *
	 * On platforms without savepoint support, the pre-existing emulation is used instead:
	 * nothing is actually undone here, but the whole (outer) transaction is marked
	 * uncommitable, so that its eventual commit() throws instead of silently discarding the
	 * rolled-back nested work.
	 *
	 * @return    boolean  Whether operation was successful.
	 */
	public function rollBack(): bool
	{
		$return = true;
		$opcount = $this->nestedTransactionCount;

		if ($opcount > 0) {
			if ($opcount === 1) {
				$return = parent::rollBack();
				if ($this->useDebug) {
					$this->log('Rollback transaction', null, PropulsionPDO::class . '::' . __FUNCTION__);
				}
				$this->discardQueryCacheInvalidations();
			} elseif ($this->supportsSavepoints()) {
				$return = parent::exec('ROLLBACK TO SAVEPOINT ' . $this->getSavepointName($opcount)) !== false;
			} else {
				$this->isUncommitable = true;
			}

			$this->nestedTransactionCount--;
		}

		return $return;
	}

	/**
	 * Whether this connection's underlying PDO driver is one for which Propulsion
	 * implements real nested transactions via SQL SAVEPOINT/RELEASE SAVEPOINT/
	 * ROLLBACK TO SAVEPOINT (see self::$savepointCapableDrivers).
	 *
	 * Subclasses that connect via a driver with its own quirks (e.g.
	 * MssqlPropulsionPDO, which overrides beginTransaction()/commit()/rollBack()
	 * entirely because the underlying dblib driver doesn't support real
	 * transactions at all) don't need to override this, since they never call it.
	 *
	 * @return    boolean
	 */
	protected function supportsSavepoints(): bool
	{
		$driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);

		return is_string($driver) && in_array($driver, self::$savepointCapableDrivers, true);
	}

	/**
	 * Whether this savepoint-capable connection's driver also has an explicit
	 * RELEASE SAVEPOINT statement -- see $releaseSavepointCapableDrivers.
	 * Only meaningful when supportsSavepoints() is already true; commit() is the
	 * only caller.
	 *
	 * @return    boolean
	 */
	protected function supportsReleaseSavepoint(): bool
	{
		$driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);

		return is_string($driver) && in_array($driver, self::$releaseSavepointCapableDrivers, true);
	}

	/**
	 * Builds the SAVEPOINT identifier used for the given nesting depth (i.e. the
	 * value of getNestedTransactionCount() while that depth's transaction is
	 * open). Deliberately deterministic (rather than e.g. uniqid()-based): reusing
	 * the same name for a given depth is safe, since SAVEPOINT with an
	 * already-used name replaces the earlier savepoint on every supported driver
	 * (PostgreSQL, MySQL, SQLite) -- and determinism is what makes rollBack() and
	 * commit() able to independently recompute the same name a matching
	 * beginTransaction() used, without having to keep a separate name stack.
	 *
	 * @param     int  $depth  Nesting depth (as returned by getNestedTransactionCount()).
	 *
	 * @return    string
	 */
	protected function getSavepointName(int $depth): string
	{
		return 'PROPULSION_SAVEPOINT_LEVEL' . $depth;
	}

	/**
	* Rollback the whole transaction, even if this is a nested rollback
	* and reset the nested transaction count to 0.
	 *
	* @return    boolean  Whether operation was successful.
	*/
	public function forceRollBack(): bool
	{
		$return = true;

		if ($this->nestedTransactionCount) {
			// If we're in a transaction, always roll it back
			// regardless of nesting level.
			$return = parent::rollBack();

			// reset nested transaction count to 0 so that we don't
			// try to commit (or rollback) the transaction outside this scope.
			$this->nestedTransactionCount = 0;

			if ($this->useDebug) {
				$this->log('Rollback transaction', null, PropulsionPDO::class . '::' . __FUNCTION__);
			}

			$this->discardQueryCacheInvalidations();
		}

		return $return;
	}

	/**
	 * Publish the shared-query-cache table version bumps that writes in the
	 * just-committed transaction buffered up.
	 *
	 * Best-effort by design: a cache that cannot record an invalidation must
	 * not turn a successful commit into a failure. The consequence of failing
	 * here is bounded -- other processes keep serving the pre-write entry until
	 * its TTL lapses -- and it is the trade the whole cache is built on.
	 *
	 * **Protected, not private, because a driver that overrides commit() must
	 * call this itself.** MssqlPropulsionPDO replaces commit()/rollBack()/
	 * forceRollBack() wholesale (dblib has no real transactions), so it never
	 * reaches the trait's versions; without an explicit call there, every
	 * version bump buffered during a transaction is silently dropped and an ORM
	 * write stops invalidating the shared cache at all. Any future driver that
	 * overrides these has the same obligation.
	 */
	protected function publishQueryCacheInvalidations(): void
	{
		if (!$this instanceof PropulsionPDO) {
			return;
		}

		try {
			Propulsion::getSession()->getQueryCache()->onCommit($this);
		} catch (\Throwable $e) {
			Propulsion::log(
				'Failed to publish query cache invalidations after commit: ' . $e->getMessage(),
				Propulsion::LOG_WARNING
			);
		}
	}

	/**
	 * Drop the buffered version bumps for a rolled-back transaction. Nothing
	 * was ever published, so there is nothing to undo -- this only stops the
	 * rest of the request from needlessly bypassing the shared tier for tables
	 * whose writes were discarded.
	 */
	protected function discardQueryCacheInvalidations(): void
	{
		if (!$this instanceof PropulsionPDO) {
			return;
		}

		try {
			Propulsion::getSession()->getQueryCache()->onRollBack($this);
		} catch (\Throwable $e) {
			Propulsion::log(
				'Failed to discard query cache invalidations after rollback: ' . $e->getMessage(),
				Propulsion::LOG_WARNING
			);
		}
	}

	/**
	 * Bring this object's bookkeeping back in line with reality after the
	 * server-side session behind it has gone away (see
	 * {@see Propulsion::isConnectionDropped()}), and evict it from the pool so
	 * the next {@see Propulsion::getConnection()} builds a fresh one.
	 *
	 * **This does not retry, and cannot.** PDO has no reconnect: a dropped
	 * connection's object stays dropped for its whole lifetime. The code this
	 * replaced caught the dropped-connection PDOException, called
	 * Propulsion::forceReconnect(), and then re-issued the very same statement
	 * through `parent::exec()`/`parent::query()` on `$this` -- the same dead
	 * handle -- so the "retry" could only ever fail a second time, and did so
	 * with a fresh exception that nothing was catching. Worse,
	 * forceReconnect() takes a datasource *name* and defaulted to the default
	 * one, so a dropped connection on any other datasource silently evicted an
	 * unrelated, perfectly healthy pair of connections instead of itself.
	 *
	 * Retrying transparently one level up would not be safe either, which is
	 * why this doesn't attempt it: losing the connection loses any open
	 * transaction with it, so re-running one statement on a fresh connection
	 * would execute it outside the transaction the caller believes it is in.
	 * Recovery has to happen where the transaction boundary is known -- i.e. in
	 * the caller -- so the exception is rethrown unchanged and this method
	 * confines itself to leaving no stale state behind:
	 *
	 *  - the transaction depth counter is zeroed (whatever the server had open
	 *    is gone, so a later commit()/rollBack() must not try to unwind levels
	 *    that no longer exist, and Session::reset()'s dangling-transaction
	 *    sweep must not try to roll one back on a dead handle),
	 *  - buffered shared-query-cache version bumps for this connection are
	 *    discarded, exactly as on a rollback -- the writes that generated them
	 *    were never committed, and left in place they would keep this request
	 *    bypassing the shared tier for those tables (and leak in the registry,
	 *    since neither commit() nor rollBack() will ever be reached for them),
	 *  - the prepared-statement cache is dropped, since every handle in it
	 *    belongs to the dead connection.
	 *
	 * @param     \PDOException  $e  The exception that revealed the drop.
	 * @param     string         $methodName  Origin, for the log line.
	 */
	public function handleDroppedConnection(\PDOException $e, string $methodName = ''): void
	{
		$this->nestedTransactionCount = 0;
		$this->isUncommitable = false;
		$this->clearStatementCache();
		$this->discardQueryCacheInvalidations();

		if ($this instanceof PropulsionPDO) {
			Propulsion::discardConnection($this);
		}

		$message = ($methodName !== '' ? '[' . $methodName . '] ' : '')
			. 'Database connection dropped (' . $e->getMessage() . '); it has been evicted from the pool, so the '
			. 'next Propulsion::getConnection() will open a fresh one. The failing statement is not retried: any '
			. 'transaction that was open is gone with the connection, so retrying here would silently run outside it.';

		// Deliberately not routed through $this->log(): that one is the *debug*
		// channel, gated on `debugpdo.logging.methods` containing the calling
		// method, so a warning this consequential would be silently dropped in
		// every default configuration. This mirrors only log()'s delegation
		// tail -- per-connection logger if one is registered, the global one
		// otherwise -- which stays a no-op when neither is, per the
		// "Propulsion ships no logger and never writes anywhere implicitly"
		// rule in README.md. (The code this replaced used a bare error_log(),
		// which broke that rule outright.)
		if ($this->logger !== null) {
			$this->logger->log(Propulsion::LOG_WARNING, $message);
		} else {
			Propulsion::log($message, Propulsion::LOG_WARNING);
		}
	}

	/**
	 * Sets a connection attribute.
	 *
	 * This is overridden here to provide support for setting Propulsion-specific attributes too.
	 *
	 * @param     integer  $attribute  The attribute to set (e.g. PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES).
	 * @param     mixed    $value  The attribute value.
	 */
	public function setAttribute($attribute, $value): bool
	{
		switch($attribute) {
			case PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES:
				if (!is_bool($value)) {
					throw new PropulsionException('PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES expects a boolean value, got ' . get_debug_type($value));
				}
				$this->cachePreparedStatements = $value;
				return true;
			default:
				return parent::setAttribute($attribute, $value);
		}
	}

	/**
	 * Gets a connection attribute.
	 *
	 * This is overridden here to provide support for setting Propulsion-specific attributes too.
	 *
	 * @param     integer  $attribute  The attribute to get (e.g. PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES).
	 * @return    mixed
	 */
	public function getAttribute($attribute): mixed
	{
		switch($attribute) {
			case PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES:
				return $this->cachePreparedStatements;
			default:
				return parent::getAttribute($attribute);
		}
	}

	/**
	 * Prepares a statement for execution and returns a statement object.
	 *
	 * Overrides PDO::prepare() in order to:
	 *  - Add logging and query counting if logging is true.
	 *  - Add query caching support if the PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES was set to true.
	 *
	 * @param     string  $sql  This must be a valid SQL statement for the target database server.
	 * @param     array<int, mixed>   $driver_options  One $array or more key => value pairs to set attribute values
	 *                                      for the PDOStatement object that this method returns.
	 *
	 * @return    \PDOStatement
	 */
	public function prepare($sql, $driver_options = []): false|\PDOStatement
	{
		// Initialised rather than only assigned inside the `useDebug` branch:
		// log() takes ?array for exactly this, and the two `if ($this->useDebug)`
		// blocks are only correlated by inspection -- any call on $this between
		// them (evictOldestPreparedStatements(), below) legitimately stops static
		// analysis from proving the second implies the first.
		$debug = null;
		if ($this->useDebug) {
			$debug = $this->getDebugSnapshot();
		}

		if (getenv('AGAVI_DEBUG_DATABASE_FORCE') || getenv('AGAVI_DEBUG_DATABASE')) {
			@error_log('PROPEL_SQL '.(getenv('AGAVI_DEBUG_DATABASE_FORCE') ? 'FORCE ' : '') .'prepare: '.preg_replace('/\s+/', ' ', substr($sql,0,600)));
		}

		if ($this->cachePreparedStatements) {
			// Digest rather than the SQL itself, to keep the key small and the
			// lookup cheap over the multi-kilobyte SQL a joined query produces.
			// xxh128 rather than md5: faster over exactly those payloads, and
			// 128 bits puts a collision -- which here would silently hand back a
			// statement prepared for *different* SQL -- out of reach.
			$cacheKey = hash('xxh128', $sql);
			if (!isset($this->preparedStatements[$cacheKey])) {
				$return = parent::prepare($sql, $driver_options);
				// A failure is not cached. PDO::prepare() returns false rather
				// than throwing when the error mode is ERRMODE_SILENT or
				// ERRMODE_WARNING, and `isset()` is true for a stored false (it
				// is only false for null), so a single transient failure -- a
				// dropped connection, a table not yet created by a migration
				// running concurrently -- used to be memoised and handed back for
				// that SQL for the rest of the connection's life, with no further
				// attempt to prepare it. Callers see false either way; the
				// difference is whether the next attempt can succeed.
				if ($return !== false) {
					$this->preparedStatements[$cacheKey] = $return;
					$this->evictOldestPreparedStatements();
				}
			} else {
				$return = $this->preparedStatements[$cacheKey];
			}
		} else {
			$return = parent::prepare($sql, $driver_options);
		}

		if ($this->useDebug) {
			$this->log($sql, null, PropulsionPDO::class . '::' . __FUNCTION__, $debug);
		}

		return $return;
	}

	/**
	 * Execute an SQL statement and return the number of affected rows.
	 * Overrides PDO::exec() to log queries when required
	 *
	 * @param     string  $sql
	 * @return    integer
	 */
	public function exec($sql): false|int
	{
		if ($this->useDebug) {
			$debug = $this->getDebugSnapshot();
		}
		if (getenv('AGAVI_DEBUG_DATABASE_FORCE') || getenv('AGAVI_DEBUG_DATABASE')) {
			@error_log('PROPEL_SQL '.(getenv('AGAVI_DEBUG_DATABASE_FORCE') ? 'FORCE ' : '') .'exec: '.preg_replace('/\s+/', ' ', substr($sql,0,600)));
		}
		try {
			$return = parent::exec($sql);
		} catch (\PDOException $e) {
			if (Propulsion::isConnectionDropped($e)) {
				$this->handleDroppedConnection($e, PropulsionPDO::class . '::' . __FUNCTION__);
			}
			throw $e;
		}
		if ($this->useDebug) {
			$this->log($sql, null, PropulsionPDO::class . '::' . __FUNCTION__, $debug);
			$this->setLastExecutedQuery($sql);
			$this->incrementQueryCount();
		}

		return $return;
	}

	/**
	 * Executes an SQL statement, returning a result set as a PDOStatement object.
	 * Despite its signature here, this method takes a variety of parameters.
	 *
	 * Overrides PDO::query() to log queries when required
	 *
	 * @see       http://php.net/manual/en/pdo.query.php for a description of the possible parameters.
	 *
	 * @return    \PDOStatement
	 */
	public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
	{
		if ($this->useDebug) {
			$debug = $this->getDebugSnapshot();
		}
		if (getenv('AGAVI_DEBUG_DATABASE_FORCE') || getenv('AGAVI_DEBUG_DATABASE')) {
			@error_log('PROPEL_SQL '.(getenv('AGAVI_DEBUG_DATABASE_FORCE') ? 'FORCE ' : '') .'query: '.preg_replace('/\s+/', ' ', substr($query,0,600)));
		}

		try {
			$return = parent::query($query, $fetchMode, ...$args);
		} catch (\PDOException $e) {
			if (Propulsion::isConnectionDropped($e)) {
				$this->handleDroppedConnection($e, PropulsionPDO::class . '::' . __FUNCTION__);
			}
			throw $e;
		}

		if ($this->useDebug) {
			$this->log($query, null, PropulsionPDO::class . '::' . __FUNCTION__, $debug);
			$this->setLastExecutedQuery($query);
			$this->incrementQueryCount();
		}

		return $return;
	}

	/**
	 * Clears any stored prepared statements for this connection.
	 */
	public function clearStatementCache(): void
	{
		$this->preparedStatements = array();
	}

	/**
	 * Drop the oldest cached statements until the cache is back within
	 * self::MAX_CACHED_PREPARED_STATEMENTS.
	 *
	 * Insertion order, not least-recently-used: PHP arrays preserve insertion
	 * order, so the first key is the oldest *stored* entry, and promoting on
	 * every read (unset + re-add, as ArrayCache does) would add work to the hot
	 * lookup path to sharpen a bound that only exists to stop pathological
	 * growth. An application with a stable working set never reaches the bound
	 * at all; one that does is issuing more distinct SQL than any eviction
	 * policy could cache usefully.
	 *
	 * Normally evicts a single entry per call, since it runs on every insert.
	 * The loop is there so that lowering the bound in a subclass still converges.
	 */
	private function evictOldestPreparedStatements(): void
	{
		while (count($this->preparedStatements) > static::MAX_CACHED_PREPARED_STATEMENTS) {
			$oldest = array_key_first($this->preparedStatements);
			if ($oldest === null) {
				return;
			}
			unset($this->preparedStatements[$oldest]);
		}
	}

	/**
	 * Configures the PDOStatement class for this connection.
	 *
	 * @param     string   $class
	 * @param     boolean  $suppressError  Whether to suppress an exception if the statement class cannot be set.
	 *
	 * @throws    PropulsionException if the statement class cannot be set (and $suppressError is false).
	 */
	protected function configureStatementClass($class = 'PDOStatement', $suppressError = true): void
	{
		// If a short (unqualified) class name was provided, attempt to resolve it within
		// the current namespace (Propulsion\Connection) before handing it to PDO. This fixes
		// usage like configureStatementClass('DebugPDOStatement') under PHP 8.2+ where
		// PDO::ATTR_STATEMENT_CLASS now performs stricter validation and requires the
		// fully-qualified, autoloadable class name.
		if ($class && str_contains($class, '\\') === false) {
			$nsCandidate = 'Propulsion\\Connection' . '\\' . $class;
			if (class_exists($nsCandidate, true)) {
				$class = $nsCandidate;
			}
		}
		// extending PDOStatement is only supported with non-persistent connections
		if (!$this->getAttribute(\PDO::ATTR_PERSISTENT)) {
			$this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, array($class, array($this)));
		} elseif (!$suppressError) {
			throw new PropulsionException('Extending PDOStatement is not supported with persistent connections.');
		}
	}

	/**
	 * Returns the number of queries this DebugPDO instance has performed on the database connection.
	 *
	 * When using DebugPDOStatement as the statement class, any queries by DebugPDOStatement instances
	 * are counted as well.
	 *
	 * @throws     PropulsionException if persistent connection is used (since unable to override PDOStatement in that case).
	 * @return     integer
	 */
	public function getQueryCount()
	{
		// extending PDOStatement is not supported with persistent connections
		if ($this->getAttribute(\PDO::ATTR_PERSISTENT)) {
			throw new PropulsionException('Extending PDOStatement is not supported with persistent connections. Count would be inaccurate, because we cannot count the PDOStatment::execute() calls. Either don\'t use persistent connections or don\'t call PropulsionPDO::getQueryCount()');
		}
		return $this->queryCount;
	}

	/**
	 * Increments the number of queries performed by this DebugPDO instance.
	 *
	 * Returns the original number of queries (ie the value of $this->queryCount before calling this method).
	 *
	 * @return    int
	 */
	public function incrementQueryCount(): int
	{
		$qCount = $this->queryCount;
		$this->queryCount++;
		return $qCount;
	}

	/**
	 * Get the SQL code for the latest query executed by Propulsion
	 *
	 * @return string Executable SQL code
	 */
	public function getLastExecutedQuery(): string
	{
		return $this->lastExecutedQuery;
	}

	/**
	 * Forget the debug counters -- the query count and the last executed query.
	 *
	 * Called by {@see \Propulsion\Session::reset()} at a worker request boundary.
	 * Connections are process-scoped and reused across requests, so without this
	 * getQueryCount() reports how many queries this *process* has run since it
	 * booted, and getLastExecutedQuery() can return a statement issued while
	 * serving somebody else's request. Both are read as per-request figures --
	 * that is what they mean under PHP-FPM, where the connection died with the
	 * request -- so a worker has to say so explicitly.
	 *
	 * Deliberately separate from useDebug(false), which resets the same two
	 * fields as part of *turning debugging off*; this leaves the debug mode
	 * exactly as it found it.
	 */
	public function resetDebugCounters(): void
	{
		$this->queryCount = 0;
		$this->lastExecutedQuery = '';
	}

	/**
	 * Set the SQL code for the latest query executed by Propulsion
	 *
	 * @param     string  $query  Executable SQL code
	 */
	public function setLastExecutedQuery($query): void
	{
		$this->lastExecutedQuery = $query;
	}

	/**
	 * Enable or disable the query debug features
	 *
	 * @param     boolean  $value  True to enable debug (default), false to disable it
	 */
	public function useDebug($value = true): void
	{
		if ($value) {
			// Use fully-qualified statement class to satisfy PDO strict validation
			$this->configureStatementClass('\\Propulsion\\Connection\\DebugPDOStatement', true);
		} else {
			// reset query logging
			$this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, array('PDOStatement'));
			$this->setLastExecutedQuery('');
			$this->queryCount = 0;
		}
		$this->clearStatementCache();
		$this->useDebug = $value;
	}

	/**
	 * Sets the logging level to use for logging method calls and SQL statements.
	 *
	 * @param     string  $level  One of the Propulsion::LOG_* / Psr\Log\LogLevel::* constants.
	 */
	public function setLogLevel($level): void
	{
		$this->logLevel = $level;
	}

	/**
	 * Sets a PSR-3 logger to use for this connection, overriding Propulsion::log().
	 *
	 * @param     ?LoggerInterface  $logger A null value clears the per-connection logger
	 *             override (falling back to Propulsion::log()), which is a legitimate
	 *             state -- getLogger() already documents (and returns) ?LoggerInterface
	 *             for exactly this reason.
	 */
	public function setLogger(?LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Gets the per-connection logger override, if any.
	 *
	 * @return    LoggerInterface|null
	 */
	public function getLogger()
	{
		return $this->logger;
	}

	/**
	 * Logs the method call or SQL using the Propulsion::log() method or a registered logger class.
	 *
	 * @uses      self::getLogPrefix()
	 * @see       self::setLogger()
	 *
	 * @param     string   $msg  Message to log.
	 * @param     string   $level  Log level to use; will use self::setLogLevel() specified level by default.
	 * @param     string   $methodName  Name of the method whose execution is being logged.
	 * @param     array{microtime: float, memory_get_usage: int, memory_get_peak_usage: int}|null    $debugSnapshot  Previous return value from self::getDebugSnapshot().
	 */
	public function log($msg, $level = null, $methodName = null, ?array $debugSnapshot = null): void
	{
		// If logging has been specifically disabled, this method won't do anything
		if (!$this->getLoggingConfigBool('enabled', true)) {
			return;
		}

		// If the method being logged isn't one of the ones to be logged, bail
		if (!in_array($methodName, $this->getLoggingConfigArray('methods', self::$defaultLogMethods))) {
			return;
		}

		// If a logging level wasn't provided, use the default one
		if ($level === null) {
			$level = $this->logLevel;
		}

		// Determine if this query is slow enough to warrant logging. There's no
		// baseline to compare against if no $debugSnapshot was given, so the
		// slow-check is skipped (i.e. always logged) in that case.
		if ($debugSnapshot !== null && $this->getLoggingConfigBool("onlyslow", PropulsionPDO::DEFAULT_ONLYSLOW_ENABLED)) {
			$now = $this->getDebugSnapshot();
			if ($now['microtime'] - $debugSnapshot['microtime'] < $this->getLoggingConfigFloat("details.slow.threshold", PropulsionPDO::DEFAULT_SLOW_THRESHOLD)) return;
		}

		// If the necessary additional parameters were given, get the debug log prefix for the log line
		if ($methodName && $debugSnapshot) {
			$msg = $this->getLogPrefix($methodName, $debugSnapshot) . $msg;
		}

		// We won't log empty messages
		if (!$msg) {
			return;
		}

		// Delegate the actual logging forward
		if ($this->logger) {
			$this->logger->log($level, $msg);
		} else {
			Propulsion::log($msg, $level);
		}
	}

	/**
	 * Returns a snapshot of the current values of some functions useful in debugging.
	 *
	 * @return    array{microtime: float, memory_get_usage: int, memory_get_peak_usage: int}
	 */
	public function getDebugSnapshot()
	{
		if ($this->useDebug) {
			$realMemoryUsage = $this->getLoggingConfigBool('realmemoryusage', false);
			return array(
				'microtime'             => microtime(true),
				'memory_get_usage'      => memory_get_usage($realMemoryUsage),
				'memory_get_peak_usage' => memory_get_peak_usage($realMemoryUsage),
				);
		} else {
			throw new PropulsionException('Should not get debug snapshot when not debugging');
		}
	}

	/**
	 * Returns a named configuration item from the Propulsion runtime configuration, from under the
	 * 'debugpdo.logging' prefix.  If such a configuration setting hasn't been set, the given default
	 * value will be returned.
	 *
	 * @param     string  $key  Key for which to return the value.
	 * @param     mixed   $defaultValue  Default value to apply if config item hasn't been set.
	 *
	 * @return    mixed
	 */
	protected function getLoggingConfig($key, $defaultValue)
	{
		return $this->getConfiguration()->getParameter("debugpdo.logging.$key", $defaultValue);
	}

	/**
	 * Typed wrapper around getLoggingConfig() for logging-config keys documented as
	 * booleans (e.g. 'enabled', 'onlyslow', 'realmemoryusage'). The underlying
	 * configuration container is a generic, untyped key/value store (see
	 * PropulsionConfiguration::getParameter()), so a misconfigured value (e.g. a
	 * typo'd config file passing a string where a bool is expected) falls back to
	 * $defaultValue rather than being passed on as-is.
	 */
	protected function getLoggingConfigBool(string $key, bool $defaultValue): bool
	{
		$value = $this->getLoggingConfig($key, $defaultValue);
		return is_bool($value) ? $value : $defaultValue;
	}

	/**
	 * Typed wrapper around getLoggingConfig() for logging-config keys documented as
	 * integers (pad widths, decimal precisions, ...). See getLoggingConfigBool() for
	 * why a fallback to $defaultValue is used instead of passing through as-is.
	 */
	protected function getLoggingConfigInt(string $key, int $defaultValue): int
	{
		$value = $this->getLoggingConfig($key, $defaultValue);
		return is_int($value) ? $value : $defaultValue;
	}

	/**
	 * Typed wrapper around getLoggingConfig() for logging-config keys documented as
	 * floats (the 'slow' threshold). See getLoggingConfigBool() for why a fallback
	 * to $defaultValue is used instead of passing through as-is.
	 */
	protected function getLoggingConfigFloat(string $key, float $defaultValue): float
	{
		$value = $this->getLoggingConfig($key, $defaultValue);
		return is_int($value) || is_float($value) ? (float) $value : $defaultValue;
	}

	/**
	 * Typed wrapper around getLoggingConfig() for logging-config keys documented as
	 * strings (the inner/outer glue strings). See getLoggingConfigBool() for why a
	 * fallback to $defaultValue is used instead of passing through as-is.
	 */
	protected function getLoggingConfigString(string $key, string $defaultValue): string
	{
		$value = $this->getLoggingConfig($key, $defaultValue);
		return is_string($value) ? $value : $defaultValue;
	}

	/**
	 * Typed wrapper around getLoggingConfig() for logging-config keys documented as
	 * arrays (the 'methods' allow-list). See getLoggingConfigBool() for why a
	 * fallback to $defaultValue is used instead of passing through as-is.
	 *
	 * @param     array<int|string, mixed>  $defaultValue
	 * @return    array<int|string, mixed>
	 */
	protected function getLoggingConfigArray(string $key, array $defaultValue): array
	{
		$value = $this->getLoggingConfig($key, $defaultValue);
		return is_array($value) ? $value : $defaultValue;
	}

	/**
	 * Returns a prefix that may be prepended to a log line, containing debug information according
	 * to the current configuration.
	 *
	 * Uses a given $debugSnapshot to calculate how much time has passed since the call to self::getDebugSnapshot(),
	 * how much the memory consumption by PHP has changed etc.
	 *
	 * @see       self::getDebugSnapshot()
	 *
	 * @param     string  $methodName  Name of the method whose execution is being logged.
	 * @param     array{microtime: float, memory_get_usage: int, memory_get_peak_usage: int}   $debugSnapshot  A previous return value from self::getDebugSnapshot().
	 *
	 * @return    string
	 */
	protected function getLogPrefix($methodName, array $debugSnapshot)
	{
		$config = $this->getConfiguration()->getParameters();
		if (!is_array($config) || !isset($config['debugpdo']) || !is_array($config['debugpdo'])) {
			return '';
		}
		$loggingConfig = $config['debugpdo']['logging'] ?? null;
		if (!is_array($loggingConfig) || !isset($loggingConfig['details']) || !is_array($loggingConfig['details'])) {
			return '';
		}
		$prefix     = '';
		$logDetails = $loggingConfig['details'];
		$now        = $this->getDebugSnapshot();
		$innerGlue  = $this->getLoggingConfigString('innerglue', ': ');
		$outerGlue  = $this->getLoggingConfigString('outerglue', ' | ');

		// Iterate through each detail that has been configured to be enabled
		foreach ($logDetails as $detailName => $details) {
			$detailName = (string) $detailName;

			if (!$this->getLoggingConfigBool("details.$detailName.enabled", false)) {
				continue;
			}

			switch ($detailName) {

				case 'slow':
					$value = $now['microtime'] - $debugSnapshot['microtime'] >= $this->getLoggingConfigFloat('details.slow.threshold', PropulsionPDO::DEFAULT_SLOW_THRESHOLD) ? 'YES' : ' NO';
					break;

				case 'time':
					$value = number_format($now['microtime'] - $debugSnapshot['microtime'], $this->getLoggingConfigInt('details.time.precision', 3)) . ' sec';
					$value = str_pad($value, $this->getLoggingConfigInt('details.time.pad', 10), ' ', STR_PAD_LEFT);
					break;

				case 'mem':
					$value = self::getReadableBytes($now['memory_get_usage'], $this->getLoggingConfigInt('details.mem.precision', 1));
					$value = str_pad($value, $this->getLoggingConfigInt('details.mem.pad', 9), ' ', STR_PAD_LEFT);
					break;

				case 'memdelta':
					$value = $now['memory_get_usage'] - $debugSnapshot['memory_get_usage'];
					$value = ($value > 0 ? '+' : '') . self::getReadableBytes($value, $this->getLoggingConfigInt('details.memdelta.precision', 1));
					$value = str_pad($value, $this->getLoggingConfigInt('details.memdelta.pad', 10), ' ', STR_PAD_LEFT);
					break;

				case 'mempeak':
					$value = self::getReadableBytes($now['memory_get_peak_usage'], $this->getLoggingConfigInt('details.mempeak.precision', 1));
					$value = str_pad($value, $this->getLoggingConfigInt('details.mempeak.pad', 9), ' ', STR_PAD_LEFT);
					break;

				case 'querycount':
					$value = str_pad((string) $this->getQueryCount(), $this->getLoggingConfigInt('details.querycount.pad', 2), ' ', STR_PAD_LEFT);
					break;

				case 'method':
					$value = str_pad($methodName, $this->getLoggingConfigInt('details.method.pad', 28), ' ', STR_PAD_RIGHT);
					break;

				default:
					$value = 'n/a';
					break;

			}

			$prefix .= $detailName . $innerGlue . $value . $outerGlue;

		}

		return $prefix;
	}

	/**
	 * Returns a human-readable representation of the given byte count.
	 *
	 * @param     float|int  $bytes  Byte count to convert.
	 * @param     integer  $precision  How many decimals to include.
	 *
	 * @return    string
	 */
	protected function getReadableBytes($bytes, $precision)
	{
		$suffix = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$total = count($suffix);

		for ($i = 0; $bytes > 1024 && $i < $total; $i++) {
			$bytes /= 1024;
		}

		return number_format($bytes, $precision) . ' ' . $suffix[$i];
	}

	/**
	 * If so configured, makes an entry to the log of the state of this object just prior to its destruction.
	 * Add PropulsionPDO::__destruct to $defaultLogMethods to see this message
	 *
	 * @see       self::log()
	 */
	public function __destruct()
	{
		if ($this->useDebug) {
			$this->log('Closing connection', null, PropulsionPDO::class . '::' . __FUNCTION__, $this->getDebugSnapshot());
		}
	}
}
