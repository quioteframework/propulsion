<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

use \PDO;
/**
 * PDOStatement that provides some enhanced functionality needed by Propulsion.
 *
 * Simply adds the ability to count the number of queries executed and log the queries/method calls.
 *
 * @author     Oliver Schonrock <oliver@realtsp.com>
 * @author     Jarno Rantanen <jarno.rantanen@tkk.fi>
 * @since      2007-07-12
 */
class DebugPDOStatement extends \PDOStatement
{
	/**
	 * The PDO connection from which this instance was created.
	 *
	 * @var       PropulsionPDO
	 */
	protected $pdo;

	/**
	 * Hashmap for resolving the PDO::PARAM_* class constants to their human-readable names.
	 * This is only used in logging the binding of variables.
	 *
	 * @see       self::bindValue()
	 * @var       array
	 */
	protected static $typeMap = array(
		PDO::PARAM_BOOL => "PDO::PARAM_BOOL",
		PDO::PARAM_INT => "PDO::PARAM_INT",
		PDO::PARAM_STR => "PDO::PARAM_STR",
		PDO::PARAM_LOB => "PDO::PARAM_LOB",
		PDO::PARAM_NULL => "PDO::PARAM_NULL",
	);

	/**
	 * @var       array  The values that have been bound
	 */
	protected $boundValues = array();

	/**
	 * Construct a new statement class with reference to main DebugPDO object from
	 * which this instance was created.
	 *
	 * @param     PropulsionPDO  $pdo  Reference to the parent PDO instance.
	 * @return    DebugPDOStatement
	 */
	protected function __construct(PropulsionPDO $pdo)
	{
		$this->pdo = $pdo;
	}

	/**
	 * @return    string
	 */
	public function getExecutedQueryString()
	{
		$sql = $this->queryString;
		$matches = array();
		if (preg_match_all('/(:p[0-9]+\b)/', $sql, $matches)) {
			$size = count($matches[1]);
			for ($i = $size-1; $i >= 0; $i--) {
				$pos = $matches[1][$i];
				$sql = str_replace($pos, $this->boundValues[$pos], $sql);
			}
		}

		return $sql;
	}

	/**
	 * Executes a prepared statement.  Returns a boolean value indicating success.
	 * Overridden for query counting and logging.
	 *
	 * @param     array|null  $input_parameters
	 * @return    boolean
	 */
	public function execute(array|null $input_parameters = null) : bool
	{
		$debug = $this->pdo->getDebugSnapshot();
		// Logging active if AGAVI_DEBUG_DATABASE=1; FORCE env overrides for deep debugging
		$forced = getenv('AGAVI_DEBUG_DATABASE_FORCE') ?: getenv('AGAVI_DEBUG_DATABASE');
		$start = microtime(true);
		try {
			$return = parent::execute($input_parameters);
		} catch (\Throwable $e) {
			// Transparent reconnect on dropped connections
			if ($e instanceof \PDOException && \Propulsion\Propulsion::isConnectionDropped($e)) {
				error_log('[DebugPDOStatement::execute] connection dropped, reconnecting and retrying');
				\Propulsion\Propulsion::forceReconnect();
				try {
					$return = parent::execute($input_parameters);
				} catch (\Throwable $retryE) {
					if ($forced) {
						$sqlErr = $this->getExecutedQueryString();
						$this->forcedLogChunked('PROPEL_SQL_FORCE execute RETRY EXCEPTION: '.get_class($retryE).': '.$retryE->getMessage().' SQL=', $sqlErr);
					}
					throw $retryE;
				}
			} else {
				if ($forced) {
					$sqlErr = $this->getExecutedQueryString();
					$this->forcedLogChunked('PROPEL_SQL_FORCE execute EXCEPTION: '.get_class($e).': '.$e->getMessage().' SQL=', $sqlErr);
				}
				throw $e;
			}
		}

		$elapsedMs = (microtime(true) - $start) * 1000.0;
		$sql = $this->getExecutedQueryString();
		$this->pdo->log($sql, null, __METHOD__, $debug);
		$this->pdo->setLastExecutedQuery($sql);
		$this->pdo->incrementQueryCount();

		if ($forced) {
			$rows = null;
			try { $rows = $this->rowCount(); } catch (\Throwable) { $rows = 'n/a'; }
			$prefix = sprintf('PROPEL_SQL_FORCE execute: %.2f ms rows=%s SQL=', $elapsedMs, (string)$rows);
			$this->forcedLogChunked($prefix, $sql);
		}

		return $return;
	}

	/**
	 * Emit long SQL in manageable chunks to avoid truncation by loggers / line limits.
	 * @param string $prefix
	 * @param string $sql
	 * @return void
	 */
	private function forcedLogChunked(string $prefix, string $sql): void
	{
		$max = 900; // stay well below typical 1024/1k line caps
		$len = strlen($sql);
		if ($len <= $max) {
			@error_log($prefix.$sql);
			return;
		}
		$parts = (int)ceil($len / $max);
		@error_log($prefix.'[len='.$len.' parts='.$parts.']');
		for ($i = 0; $i < $parts; $i++) {
			$chunk = substr($sql, $i * $max, $max);
			@error_log('PROPEL_SQL_FORCE part '.($i+1).'/'.$parts.': '.$chunk);
		}
	}

	/**
	 * Binds a value to a corresponding named or question mark placeholder in the SQL statement
	 * that was use to prepare the statement. Returns a boolean value indicating success.
	 *
	 * @param     integer  $pos  Parameter identifier (for determining what to replace in the query).
	 * @param     mixed    $value  The value to bind to the parameter.
	 * @param     integer  $type  Explicit data type for the parameter using the PDO::PARAM_* constants. Defaults to PDO::PARAM_STR.
	 *
	 * @return    boolean
	 */
	public function bindValue($pos, $value, $type = PDO::PARAM_STR) : bool
	{
		$debug    = $this->pdo->getDebugSnapshot();
		$typestr  = isset(self::$typeMap[$type]) ? self::$typeMap[$type] : '(default)';
		$return   = parent::bindValue($pos, $value, $type);
		$valuestr = $type == PDO::PARAM_LOB ? '[LOB value]' : var_export($value, true);
		$msg      = sprintf('Binding %s at position %s w/ PDO type %s', $valuestr, $pos, $typestr);

		$this->boundValues[$pos] = $valuestr;

		$this->pdo->log($msg, null, __METHOD__, $debug);

		return $return;
	}

	/**
	 * Binds a PHP variable to a corresponding named or question mark placeholder in the SQL statement
	 * that was use to prepare the statement. Unlike PDOStatement::bindValue(), the variable is bound
	 * as a reference and will only be evaluated at the time that PDOStatement::execute() is called.
	 * Returns a boolean value indicating success.
	 *
	 * @param     integer  $pos  Parameter identifier (for determining what to replace in the query).
	 * @param     mixed    $value  The value to bind to the parameter.
	 * @param     integer  $type  Explicit data type for the parameter using the PDO::PARAM_* constants. Defaults to PDO::PARAM_STR.
	 * @param     integer  $length  Length of the data type. To indicate that a parameter is an OUT parameter from a stored procedure, you must explicitly set the length.
	 * @param     mixed    $driver_options
	 *
	 * @return    boolean
	 */
	public function bindParam($pos, &$value, $type = PDO::PARAM_STR, $length = 0, $driver_options = null) : bool
	{
		$debug    = $this->pdo->getDebugSnapshot();
		$typestr  = isset(self::$typeMap[$type]) ? self::$typeMap[$type] : '(default)';
		$return   = parent::bindParam($pos, $value, $type, $length, $driver_options);
		$valuestr = $length > 100 ? '[Large value]' : var_export($value, true);
		$msg      = sprintf('Binding %s at position %s w/ PDO type %s', $valuestr, $pos, $typestr);

		$this->boundValues[$pos] = $valuestr;

		$this->pdo->log($msg, null, __METHOD__, $debug);

		return $return;
	}
}
