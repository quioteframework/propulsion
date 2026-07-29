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
 * The contract for a PDO connection with the fixes/additions Propulsion requires on top
 * of plain PDO -- nested transactions (real SAVEPOINTs where the driver supports them,
 * a depth-counter/poison-flag emulation otherwise), query counting/logging, and prepared
 * statement caching. See PropulsionPDOTrait for the shared implementation.
 *
 * This is an interface, not a class: every concrete connection Propulsion actually
 * constructs is a driver-specific class (PgsqlPropulsionPDO, MysqlPropulsionPDO,
 * SqlitePropulsionPDO, MssqlPropulsionPDO, OraclePropulsionPDO, or GenericPropulsionPDO
 * as a driver-agnostic fallback) that extends the matching PHP 8.4+ driver-specific PDO
 * subclass (\Pdo\Pgsql, \Pdo\Mysql, \Pdo\Sqlite, \Pdo\Dblib) where one exists, so that
 * driver-specific PDO methods (e.g. \Pdo\Pgsql::copyFromArray(), the non-deprecated
 * replacement for PDO::pgsqlCopyFromArray()) stay reachable. PHP has no multiple
 * inheritance, so a single concrete class extending \PDO directly (the old shape of this
 * class, pre-refactor) can never simultaneously be an instance of e.g. \Pdo\Pgsql --
 * every place in this codebase that type-hints or instanceof-checks against
 * PropulsionPDO (which is everywhere; every generated save()/delete()/doInsert()/etc.
 * does) keeps working unchanged, since all of those concrete classes implement this
 * same interface.
 *
 * @author     Cameron Brunner <cameron.brunner@gmail.com>
 * @author     Hans Lellelid <hans@xmpl.org>
 * @author     Christian Abegg <abegg.ch@gmail.com>
 * @since      2006-09-22
 */
use Psr\Log\LoggerInterface;

interface PropulsionPDO
{

	/**
	 * Attribute to use to set whether to cache prepared statements.
	 */
	const PROPEL_ATTR_CACHE_PREPARES = -1;

	const DEFAULT_SLOW_THRESHOLD        = 0.1;
	const DEFAULT_ONLYSLOW_ENABLED      = false;

	/**
	 * Inject the runtime configuration
	 *
	 * @param   \Propulsion\Config\PropulsionConfiguration  $configuration
	 */
	public function setConfiguration($configuration): void;

	/**
	 * Get the runtime configuration
	 *
	 * @return    \Propulsion\Config\PropulsionConfiguration
	 */
	public function getConfiguration();

	/**
	 * Gets the current transaction depth.
	 *
	 * @return    integer
	 */
	public function getNestedTransactionCount();

	/**
	 * Is this PDO connection currently in-transaction?
	 * This is equivalent to asking whether the current nested transaction count is greater than 0.
	 *
	 * @return    boolean
	 */
	public function isInTransaction();

	/**
	 * Check whether the connection contains a transaction that can be committed.
	 * To be used in an evironment where Propulsionexceptions are caught.
	 *
	 * @return    boolean  True if the connection is in a committable transaction
	 */
	public function isCommitable(): bool;

	/**
	 * Overrides PDO::beginTransaction() to prevent errors due to already-in-progress transaction.
	 *
	 * @return    boolean
	 */
	public function beginTransaction(): bool;

	/**
	 * Overrides PDO::commit() to only commit the transaction if we are in the outermost
	 * transaction nesting level.
	 *
	 * @return    boolean
	 */
	public function commit(): bool;

	/**
	 * Overrides PDO::rollBack() to only rollback the transaction if we are in the outermost
	 * transaction nesting level
	 *
	 * @return    boolean  Whether operation was successful.
	 */
	public function rollBack(): bool;

	/**
	 * Rollback the whole transaction, even if this is a nested rollback
	 * and reset the nested transaction count to 0.
	 *
	 * @return    boolean  Whether operation was successful.
	 */
	public function forceRollBack(): bool;

	/**
	 * Sets a connection attribute.
	 *
	 * @param     integer  $attribute  The attribute to set (e.g. PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES).
	 * @param     mixed    $value  The attribute value.
	 */
	public function setAttribute($attribute, $value): bool;

	/**
	 * Gets a connection attribute.
	 *
	 * @param     integer  $attribute  The attribute to get (e.g. PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES).
	 * @return    mixed
	 */
	public function getAttribute($attribute): mixed;

	/**
	 * Prepares a statement for execution and returns a statement object.
	 *
	 * @param     string  $sql  This must be a valid SQL statement for the target database server.
	 * @param     array<int, mixed>   $driver_options  One $array or more key => value pairs to set attribute values
	 *                                      for the PDOStatement object that this method returns.
	 *
	 * @return    \PDOStatement|false
	 */
	public function prepare($sql, $driver_options = []): false|\PDOStatement;

	/**
	 * Execute an SQL statement and return the number of affected rows.
	 *
	 * @param     string  $sql
	 * @return    int|false
	 */
	public function exec($sql): false|int;

	/**
	 * Executes an SQL statement, returning a result set as a PDOStatement object.
	 *
	 * @return    \PDOStatement|false
	 */
	public function query($query, $fetchMode = null, mixed ...$args): \PDOStatement|false;

	/**
	 * Clears any stored prepared statements for this connection.
	 */
	public function clearStatementCache(): void;

	/**
	 * Returns the number of queries this instance has performed on the database connection.
	 *
	 * @return     integer
	 */
	public function getQueryCount();

	/**
	 * Increments the number of queries performed by this instance.
	 *
	 * @return    int
	 */
	public function incrementQueryCount(): int;

	/**
	 * Get the SQL code for the latest query executed by Propulsion
	 *
	 * @return string Executable SQL code
	 */
	public function getLastExecutedQuery(): string;

	/**
	 * Set the SQL code for the latest query executed by Propulsion
	 *
	 * @param     string  $query  Executable SQL code
	 */
	public function setLastExecutedQuery($query): void;

	/**
	 * Enable or disable the query debug features
	 *
	 * @param     boolean  $value  True to enable debug (default), false to disable it
	 */
	public function useDebug($value = true): void;

	/**
	 * Sets the logging level to use for logging method calls and SQL statements.
	 *
	 * @param     string  $level  One of the Propulsion::LOG_* / Psr\Log\LogLevel::* constants.
	 */
	public function setLogLevel($level): void;

	/**
	 * Sets a PSR-3 logger to use for this connection, overriding Propulsion::log().
	 *
	 * @param     ?LoggerInterface  $logger
	 */
	public function setLogger(?LoggerInterface $logger): void;

	/**
	 * Gets the per-connection logger override, if any.
	 *
	 * @return    LoggerInterface|null
	 */
	public function getLogger();

	/**
	 * Logs the method call or SQL using the Propulsion::log() method or a registered logger class.
	 *
	 * @param     string   $msg  Message to log.
	 * @param     string   $level  Log level to use; will use self::setLogLevel() specified level by default.
	 * @param     string   $methodName  Name of the method whose execution is being logged.
	 * @param     array<string, float|int>    $debugSnapshot  Previous return value from self::getDebugSnapshot().
	 */
	public function log($msg, $level = null, $methodName = null, ?array $debugSnapshot = null): void;

	/**
	 * Returns a snapshot of the current values of some functions useful in debugging.
	 *
	 * @return    array<string, float|int>
	 */
	public function getDebugSnapshot();
}
