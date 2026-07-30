<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Reverse;

/**
 * Base class for reverse engineering a database schema.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 * @method     void setMigrationTable(string $migrationTable)
 */
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Config\GeneratorConfigInterface;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\VendorInfo;
use Propulsion\Generator\Platform\PropulsionPlatformInterface;
use PDOStatement;

abstract class BaseSchemaParser implements SchemaParser
{

	/**
	 * The database connection.
	 * @var        \PDO|null
	 */
	protected $dbh;

	/**
	 * Stack of warnings.
	 *
	 * @var        array<int, string>
	 */
	protected $warnings = array();

	/**
	 * GeneratorConfig object holding build properties.
	 *
	 * @var        GeneratorConfigInterface|null
	 */
	private $generatorConfig;

	/**
	 * Map native DB types to Propulsion types.
	 * (Override in subclasses.)
	 * @var        array<string, string>|null
	 */
	protected $nativeToPropulsionTypeMap;

	/**
	 * Map to hold reverse type mapping (initialized on-demand).
	 *
	 * @var        array<string, string>|null
	 */
	protected $reverseTypeMap;

	/**
	 * Name of the propel migration table - to be ignored in reverse
	 *
	 * @var string
	 */
	protected $migrationTable = 'propulsion_migration';

	protected ?PropulsionPlatformInterface $platform = null;

	/**
	 * @param      \PDO $dbh Optional database connection
	 */
	public function __construct(?\PDO $dbh = null)
	{
		if ($dbh) $this->setConnection($dbh);
	}

	/**
	 * Sets the database connection.
	 *
	 * @param      \PDO|null $dbh
	 */
	public function setConnection(?\PDO $dbh): void
	{
		$this->dbh = $dbh;
	}

	/**
	 * Gets the database connection.
	 * @return     \PDO|null
	 */
	public function getConnection()
	{
		return $this->dbh;
	}

	/**
	 * Gets the database connection, failing loudly if none has been set.
	 *
	 * Reverse-engineering methods are only ever invoked (via parse()) once a
	 * connection has been attached via the constructor or setConnection(); a
	 * null connection at that point means the parser was used incorrectly
	 * rather than a normal "not found" condition, hence the exception here.
	 *
	 * @return     \PDO
	 */
	protected function requireConnection(): \PDO
	{
		if ($this->dbh === null) {
			throw new EngineException(sprintf(
				'%s has no database connection; call setConnection() before parsing.',
				static::class
			));
		}
		return $this->dbh;
	}

	/**
	 * Runs a query against the current connection, failing loudly instead of
	 * returning the PDO::query() false sentinel on error (this project runs
	 * with PDO::ERRMODE_EXCEPTION, so a false return here would itself
	 * indicate a driver inconsistency worth surfacing clearly).
	 */
	protected function queryOrFail(string $sql): \PDOStatement
	{
		$stmt = $this->requireConnection()->query($sql);
		if ($stmt === false) {
			throw new EngineException(sprintf('Query failed: %s', $sql));
		}
		return $stmt;
	}

	/**
	 * Prepares a statement against the current connection, failing loudly
	 * instead of returning the PDO::prepare() false sentinel on error.
	 */
	protected function prepareOrFail(string $sql): \PDOStatement
	{
		$stmt = $this->requireConnection()->prepare($sql);
		if ($stmt === false) {
			throw new EngineException(sprintf('Failed to prepare statement: %s', $sql));
		}
		return $stmt;
	}

	/**
	 * Optional verbose-logging hook for parse(): historically a Phing\Task
	 * (behind an `if ($task) $task->log(...)` guard), passed through the
	 * $task parameter, which is intentionally typed `mixed` since parse()'s
	 * only real caller (SchemaReverseManager) always passes null. Narrows
	 * with is_object()/method_exists() rather than an unconditional call so
	 * static analysis at level 9 can verify the call is safe.
	 */
	protected function logTask(mixed $task, string $msg, int $level = 4): void
	{
		if (is_object($task) && method_exists($task, 'log')) {
			$task->log($msg, $level);
		}
	}

	/**
	 * Safely stringifies a value read from a PDO result row (declared
	 * `array<string, mixed>` since column values could in principle be any
	 * scalar type, or null). Non-scalar values (should not occur for a
	 * database row) fall back to the empty string rather than raising a
	 * TypeError from an unguarded (string) cast.
	 */
	protected static function rowValueToString(mixed $value): string
	{
		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Whether a row value is "present" in the sense used by fallback logic
	 * like `strlen(trim($row['x'])) > 0 ? $row['x'] : $fallback` -- i.e. not
	 * null and not an empty string once stringified.
	 */
	protected static function rowValueIsPresent(mixed $value): bool
	{
		return $value !== null && self::rowValueToString($value) !== '';
	}

	/**
	 * Interprets a PDO row value as a Postgres-style boolean flag. Different
	 * PDO drivers/configurations represent Postgres `boolean` columns
	 * differently -- native PHP bool, or the wire-protocol 't'/'f' strings --
	 * so this checks both rather than assuming one representation (a plain
	 * `$value == 't'` happens to work for either representation because PHP
	 * coerces a non-empty string to true when compared against a bool, but
	 * that same coercion breaks once the value has already been stringified
	 * -- rowValueToString(true) is "1", and "1" == 't' is false).
	 */
	protected static function rowValueIsPgTrue(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value !== 0;
		}
		if (is_string($value)) {
			return $value === 't' || $value === 'true' || $value === '1';
		}
		return (bool) $value;
	}

	/**
	 * Narrows a value read from a PDO result row to the int|string|null shape
	 * Domain::replaceSize()/replaceScale() expect, dropping anything else
	 * (should not occur for a database row) to null.
	 *
	 * @return     int|string|null
	 */
	protected static function rowValueToIntStringOrNull(mixed $value): int|string|null
	{
		if ($value === null || is_int($value) || is_string($value)) {
			return $value;
		}
		return is_scalar($value) ? (string) $value : null;
	}

	/**
	 * Fetches one row as a string-keyed array (PDO::FETCH_ASSOC), or null once
	 * the result set is exhausted (or, defensively, if the driver ever
	 * returned something else). PDOStatement::fetch()'s own return type is
	 * `mixed` (its actual shape depends on the fetch-mode argument, which
	 * PHPStan cannot resolve statically), so this validates the shape for
	 * real -- keeping only genuinely string-keyed entries -- rather than
	 * asserting the type away.
	 *
	 * @return     array<string, mixed>|null
	 */
	protected function fetchAssoc(\PDOStatement $stmt): ?array
	{
		$raw = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!is_array($raw)) {
			return null;
		}
		$row = [];
		foreach ($raw as $key => $value) {
			if (is_string($key)) {
				$row[$key] = $value;
			}
		}
		return $row;
	}

	/**
	 * Fetches one row as a list (PDO::FETCH_NUM), or null once the result set
	 * is exhausted. See fetchAssoc() for why this validates the shape rather
	 * than asserting PDOStatement::fetch()'s `mixed` return type away.
	 *
	 * @return     array<int, mixed>|null
	 */
	protected function fetchNum(\PDOStatement $stmt): ?array
	{
		$raw = $stmt->fetch(\PDO::FETCH_NUM);
		if (!is_array($raw)) {
			return null;
		}
		$row = [];
		foreach ($raw as $key => $value) {
			if (is_int($key)) {
				$row[$key] = $value;
			}
		}
		return $row;
	}

	/**
	 * Setter for the migrationTable property
	 *
	 * @param string $migrationTable
	 */
	public function setMigrationTable(string $migrationTable): void
	{
		$this->migrationTable = $migrationTable;
	}

	/**
	 * Getter for the migrationTable property
	 *
	 * @return string
	 */
	public function getMigrationTable()
	{
		return $this->migrationTable;
	}

	/**
	 * Pushes a message onto the stack of warnings.
	 *
	 * @param      string $msg The warning message.
	 */
	protected function warn(string $msg): void
	{
		$this->warnings[] = $msg;
	}

	/**
	 * Gets array of warning messages.
	 *
	 * @return     array<int, string>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}

	/**
	 * Sets the GeneratorConfig to use in the parsing.
	 *
	 * @param      GeneratorConfigInterface $config
	 */
	public function setGeneratorConfig(GeneratorConfigInterface $config): void
	{
		$this->generatorConfig = $config;
	}

	/**
	 * Gets the GeneratorConfig option.
	 *
	 * @return     GeneratorConfigInterface|null
	 */
	public function getGeneratorConfig()
	{
		return $this->generatorConfig;
	}

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
	 */
	public function getBuildProperty($name)
	{
		if ($this->generatorConfig !== null) {
			return $this->generatorConfig->getBuildProperty($name);
		}
		return null;
	}

	/**
	 * Gets a type mapping from native type to Propulsion type.
	 *
	 * @return     array<string, string> The mapped Propulsion type.
	 */
	abstract protected function getTypeMapping(): array;

	/**
	 * Gets a mapped Propulsion type for specified native type.
	 *
	 * @param      string $nativeType
	 * @return     string|null The mapped Propulsion type.
	 */
	protected function getMappedPropulsionType($nativeType)
	{
		if ($this->nativeToPropulsionTypeMap === null) {
			$this->nativeToPropulsionTypeMap = $this->getTypeMapping();
		}
		if (isset($this->nativeToPropulsionTypeMap[$nativeType])) {
			return $this->nativeToPropulsionTypeMap[$nativeType];
		}
		return null;
	}

	/**
	 * Give a best guess at the native type.
	 *
	 * @param      string $propelType
	 * @return     string|null The native SQL type that best matches the specified Propulsion type.
	 */
	protected function getMappedNativeType($propelType)
	{
		if ($this->reverseTypeMap === null) {
			$this->reverseTypeMap = array_flip($this->getTypeMapping());
		}
		return isset($this->reverseTypeMap[$propelType]) ? $this->reverseTypeMap[$propelType] : null;
	}

	/**
	 * `\PDO::query()`'s return type is `PDOStatement|false` -- `false` means the
	 * query failed outright (a malformed catalog query is a real programming
	 * error in a schema parser, not a recoverable condition), so every
	 * `$this->dbh->query(...)` call site in a schema parser routes its result
	 * through this guard instead of silently assuming success.
	 */
	protected function requireStatement(PDOStatement|false $stmt, string $query): PDOStatement
	{
		if ($stmt === false) {
			throw new EngineException("Query failed: $query");
		}
		return $stmt;
	}

	/**
	 * Gets a new VendorInfo object for this platform with specified params.
	 *
	 * @param      array<string, mixed> $params
	 */
	protected function getNewVendorInfoObject(array $params): VendorInfo
	{
		$platform = $this->getPlatform();
		if ($platform === null) {
			throw new EngineException('Cannot build a VendorInfo object: no platform is configured for this schema parser.');
		}
		$type = $platform->getDatabaseType();
		$vi = new VendorInfo($type);
		$vi->setParameters($params);
		return $vi;
	}

	public function setPlatform(PropulsionPlatformInterface $platform): void
	{
	  $this->platform = $platform;
	}

	public function getPlatform(): ?PropulsionPlatformInterface
	{
	  if (null === $this->platform)
	  {
	    $generatorConfig = $this->getGeneratorConfig();
	    if (!$generatorConfig instanceof GeneratorConfig) {
	      throw new EngineException(sprintf(
	        "Cannot auto-configure the platform: the configured GeneratorConfig (%s) does not support getConfiguredPlatform(). Call setPlatform() explicitly instead.",
	        $generatorConfig === null ? 'none' : get_class($generatorConfig)
	      ));
	    }
	    $this->platform = $generatorConfig->getConfiguredPlatform();
	  }
	  return $this->platform;
	}
}
