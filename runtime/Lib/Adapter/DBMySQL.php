<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter;

/**
 * This is used in order to connect to a MySQL database.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Jon S. Stevens <jon@clearink.com> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @version    $Revision$
 */
use PDO;
use PDOStatement;
use PDOException;
use Exception;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Query\Criteria;
use Propulsion\Exception\PropulsionException;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Query\VectorExpression;
class DBMySQL extends DBAdapter
{
	/**
	 * Cached answer of isMariaDb() -- see its own doc comment for why caching
	 * per adapter instance is safe.
	 *
	 * @var       ?bool
	 */
	private ?bool $isMariaDbCache = null;

	/**
	 * @see       DBAdapter::getDefaultPdoClass()
	 */
	public function getDefaultPdoClass(): string
	{
		return \Propulsion\Adapter\Mysql\MysqlPropulsionPDO::class;
	}

	/**
	 * Distinguishes a real MariaDB server (RETURNING support on INSERT/UPDATE/
	 * DELETE since 10.5) from plain MySQL (no RETURNING at all, any version) --
	 * both served by this same adapter class; there is no separate MariadbPlatform/
	 * DBMariadb anywhere in this codebase, so this is the one place that
	 * divergence is handled, gating every RETURNING-related hook below.
	 * Cached per adapter instance: DBAdapter instances are registered
	 * once per datasource for the life of the process (Propulsion::setDb()) and
	 * never repointed at a different backing server, so the version can't change
	 * out from under a cached answer.
	 *
	 * @param     PropulsionPDO  $con
	 * @return    boolean
	 */
	private function isMariaDb(PropulsionPDO $con): bool
	{
		if ($this->isMariaDbCache === null) {
			$rawVersion = $con->getAttribute(PDO::ATTR_SERVER_VERSION);
			$version = is_string($rawVersion) ? $rawVersion : '';
			// MariaDB's version string is sometimes prefixed with a fake "5.5.5-"
			// for backward compatibility with clients that gate features by
			// version number, a well-known MariaDB quirk -- the real version
			// always immediately precedes the "-MariaDB" marker regardless, so
			// this regex finds it either way without special-casing the prefix.
			$this->isMariaDbCache = (bool) preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $m)
				&& version_compare($m[1], '10.5', '>=');
		}
		return $this->isMariaDbCache;
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string to transform to upper case.
	 * @return    string  The upper case string.
	 */
	public function toUpperCase($in)
	{
		return "UPPER(" . $in . ")";
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string whose case to ignore.
	 * @return    string  The string in a case that can be ignored.
	 */
	public function ignoreCase($in)
	{
		return "UPPER(" . $in . ")";
	}

	/**
	 * Returns SQL which concatenates the second string to the first.
	 *
	 * @param     string  $s1  String to concatenate.
	 * @param     string  $s2  String to append.
	 *
	 * @return    string
	 */
	public function concatString($s1, $s2)
	{
		return "CONCAT($s1, $s2)";
	}

	/**
	 * Returns SQL which extracts a substring.
	 *
	 * @param     string   $s  String to extract from.
	 * @param     integer  $pos  Offset to start from.
	 * @param     integer  $len  Number of characters to extract.
	 *
	 * @return    string
	 */
	public function subString($s, $pos, $len)
	{
		return "SUBSTRING($s, $pos, $len)";
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return "CHAR_LENGTH($s)";
	}

	/**
	 * Locks the specified table.
	 *
	 * @param     PDO     $con  The Propulsion connection to use.
	 * @param     string  $table  The name of the table to lock.
	 *
	 * @throws    PDOException  No Statement could be created or executed.
	 */
	public function lockTable(PDO $con, $table): void
	{
		$con->exec("LOCK TABLE " . $table . " WRITE");
	}

	/**
	 * Unlocks the specified table.
	 *
	 * @param     PDO     $con  The PDO connection to use.
	 * @param     string  $table  The name of the table to unlock.
	 *
	 * @throws    PDOException  No Statement could be created or executed.
	 */
	public function unlockTable(PDO $con, $table): void
	{
		$statement = $con->exec("UNLOCK TABLES");
	}

	/**
	 * @see       DBAdapter::quoteIdentifier()
	 *
	 * @param     string  $text
	 * @return    string
	 */
	public function quoteIdentifier($text)
	{
		return '`' . $text . '`';
	}

	/**
	 * @see       DBAdapter::quoteIdentifierTable()
	 *
	 * @param     string  $table
	 * @return    string
	 */
	public function quoteIdentifierTable($table)
	{
		// e.g. 'database.table alias' should be escaped as '`database`.`table` `alias`'
		return '`' . strtr($table, array('.' => '`.`', ' ' => '` `')) . '`';
	}

	/**
	 * @see       DBAdapter::useQuoteIdentifier()
	 *
	 * @return    boolean
	 */
	public function useQuoteIdentifier()
	{
		return true;
	}

	/**
	 * MySQL has no "DEFAULT VALUES" syntax; the equivalent is an explicit empty
	 * column/value list.
	 *
	 * @see       DBAdapter::getEmptyInsertSql()
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		$sql = 'INSERT INTO ' . $tableName . ' () VALUES ()';
		return $idColumnName === null ? $sql : $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * MariaDB (10.5+) supports RETURNING on INSERT/UPDATE/DELETE; plain MySQL has
	 * no such form at all, at any version -- see isMariaDb()'s own doc comment for
	 * why both are served by this one adapter class.
	 *
	 * @see       DBAdapter::supportsInsertReturning()
	 */
	public function supportsInsertReturning(?PropulsionPDO $con = null): bool
	{
		return $con !== null && $this->isMariaDb($con);
	}

	/**
	 * @see       DBAdapter::getInsertReturningSql()
	 */
	public function getInsertReturningSql(string $sql, string $idColumnName): string
	{
		return $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * @see       DBAdapter::extractInsertedId()
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		return $stmt->fetchColumn();
	}

	/**
	 * @see       DBAdapter::supportsRowReturning()
	 */
	public function supportsRowReturning(?PropulsionPDO $con = null): bool
	{
		return $con !== null && $this->isMariaDb($con);
	}

	/**
	 * @see       DBAdapter::getUpdateReturningSql()
	 */
	public function getUpdateReturningSql(string $sql, array $columnNames): string
	{
		return $sql . ' RETURNING ' . implode(', ', $columnNames);
	}

	/**
	 * @see       DBAdapter::getDeleteReturningSql()
	 */
	public function getDeleteReturningSql(string $sql, array $columnNames): string
	{
		return $sql . ' RETURNING ' . implode(', ', $columnNames);
	}

	/**
	 * @see       DBAdapter::applyLimit()
	 *
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 * @param     Criteria $criteria  Unused by this adapter.
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if ( $limit > 0 ) {
			$sql .= " LIMIT " . ($offset > 0 ? $offset . ", " : "") . $limit;
		} else if ( $offset > 0 ) {
			$sql .= " LIMIT " . $offset . ", 18446744073709551615";
		}
	}

	/**
	 * True because of `nativeVector="true"` columns -- see
	 * getColumnBindExpression() below. Every other column type this adapter
	 * maps round-trips through a plain bind unchanged.
	 *
	 * @see       DBAdapter::usesColumnSqlRewriting()
	 */
	public function usesColumnSqlRewriting(): bool
	{
		return true;
	}

	/**
	 * MariaDB 11.7+'s real `VECTOR(n)` column rejects a bound bracket-JSON
	 * string outright ("Incorrect vector value", confirmed live against
	 * MariaDB 11.8) -- the value has to go through `VEC_FromText()` at the SQL
	 * level, which is exactly what this hook exists for.
	 *
	 * Deliberately *not* gated on {@see isMariaDb()}, unlike this adapter's
	 * RETURNING support: the column is only ever native in the first place
	 * because the schema author wrote `nativeVector="true"`, which
	 * MysqlPlatform documents as meaning "this schema targets MariaDB 11.7+"
	 * (same convention as `nativeUuid`/`nativeSequence`). Probing the server
	 * here would silently emit a plain bind against a native column on a
	 * mis-declared schema, which fails at the server anyway but with a much
	 * less obvious error than the DDL step already produced.
	 *
	 * MySQL 9.0+'s own native `VECTOR` spells these `STRING_TO_VECTOR()`/
	 * `VECTOR_TO_STRING()` and is not covered by the `nativeVector` flag; see
	 * PLATFORM_FEATURES.md for why that is left open.
	 *
	 * @see       DBAdapter::getColumnBindExpression()
	 */
	public function getColumnBindExpression(ColumnMap $cMap, string $placeholder): string
	{
		return $cMap->isNativeVector() ? 'VEC_FromText(' . $placeholder . ')' : $placeholder;
	}

	/**
	 * The read counterpart of getColumnBindExpression(): a native `VECTOR`
	 * column selected bare comes back as an opaque binary blob, so it is read
	 * through `VEC_ToText()` to give the generated hydration code the same
	 * bracketed-JSON text every other platform's emulated vector column
	 * produces.
	 *
	 * @see       DBAdapter::getColumnSelectExpression()
	 */
	public function getColumnSelectExpression(ColumnMap $cMap, string $columnExpression): string
	{
		return $cMap->isNativeVector() ? 'VEC_ToText(' . $columnExpression . ')' : $columnExpression;
	}

	/**
	 * @see       DBAdapter::supportsAdvisoryLocks()
	 */
	public function supportsAdvisoryLocks(): bool
	{
		return true;
	}

	/**
	 * `GET_LOCK(name, timeout)` -- the closest thing here to the hook's own
	 * shape, since it is named rather than numbered and takes the timeout
	 * directly. A negative timeout means "wait forever" on both MySQL 8+ and
	 * MariaDB, which is how null is expressed.
	 *
	 * The timeout is whole seconds only (MySQL 5.7+ accepts a fractional
	 * value; MariaDB truncates), so a sub-second wait is rounded **up** to one
	 * second rather than down to zero -- rounding down would silently turn
	 * "wait briefly" into "don't wait", which is a different operation.
	 *
	 * A null result means the lock attempt errored (e.g. the connection was
	 * killed while waiting) rather than merely lost the race, and is reported
	 * as not-acquired, which is the only safe reading.
	 *
	 * MySQL caps lock names at 64 characters and errors above that; the name
	 * is passed through unchanged rather than silently hashed, so an
	 * over-long name surfaces as the server's own error instead of two
	 * different callers colliding on a truncation.
	 *
	 * @see       DBAdapter::acquireAdvisoryLock()
	 */
	public function acquireAdvisoryLock(PropulsionPDO $con, string $name, ?float $timeout = null): bool
	{
		$seconds = $timeout === null ? -1 : ($timeout <= 0.0 ? 0 : (int) ceil($timeout));

		$result = $this->fetchAdvisoryLockResult($con, 'SELECT GET_LOCK(:p1, :p2)', array($name, $seconds));

		return is_numeric($result) && (int) $result === 1;
	}

	/**
	 * @see       DBAdapter::releaseAdvisoryLock()
	 */
	public function releaseAdvisoryLock(PropulsionPDO $con, string $name): bool
	{
		// RELEASE_LOCK() is 1 if released, 0 if the lock is held by another
		// session, and NULL if nobody holds it at all -- all three collapse to
		// this method's "did I have it?" boolean.
		$result = $this->fetchAdvisoryLockResult($con, 'SELECT RELEASE_LOCK(:p1)', array($name));

		return is_numeric($result) && (int) $result === 1;
	}

	/**
	 * MariaDB 11.7+'s vector distance functions. Unlike pgvector's four infix
	 * operators these are ordinary function calls, and only two metrics exist
	 * -- there is no inner-product or L1 function on this platform, so those
	 * throw rather than being silently approximated by a different metric.
	 *
	 * Applies to a `nativeVector="true"` column; on the default text emulation
	 * the column is not a vector to the server at all and the function will
	 * reject it, which is the schema author's declaration to get right the
	 * same way it is for reads and writes (see getColumnBindExpression()).
	 *
	 * @see       DBAdapter::getVectorDistanceSql()
	 */
	public function getVectorDistanceSql(string $column, string $vectorLiteral, string $metric): string
	{
		$function = match ($metric) {
			VectorExpression::L2 => 'VEC_DISTANCE_EUCLIDEAN',
			VectorExpression::COSINE => 'VEC_DISTANCE_COSINE',
			default => throw new PropulsionException(
				'DBMySQL: MariaDB has no vector distance function for the "' . $metric . '" metric; '
				. 'only L2 (VEC_DISTANCE_EUCLIDEAN) and cosine (VEC_DISTANCE_COSINE) exist.'
			),
		};

		return $function . '(' . $column . ", VEC_FromText('" . $vectorLiteral . "'))";
	}

	/**
	 * @see       DBAdapter::supportsUpsert()
	 */
	public function supportsUpsert(): bool
	{
		return true;
	}

	/**
	 * MySQL/MariaDB infer the conflict target from any unique/primary key violation,
	 * so (unlike Postgres/SQLite) $conflictColumnNames is ignored here. There is also
	 * no "do nothing on conflict" form of ON DUPLICATE KEY UPDATE, so an empty
	 * $setClause throws rather than silently producing invalid SQL.
	 *
	 * @see       DBAdapter::getUpsertSql()
	 */
	public function getUpsertSql(string $sql, array $conflictColumnNames, string $setClause): string
	{
		if ($setClause === '') {
			throw new PropulsionException("DBMySQL::getUpsertSql() needs at least one column to update: MySQL's ON DUPLICATE KEY UPDATE has no \"do nothing\" form");
		}
		return $sql . ' ON DUPLICATE KEY UPDATE ' . $setClause;
	}

	/**
	 * @see       DBAdapter::supportsBulkLoad()
	 */
	public function supportsBulkLoad(): bool
	{
		return true;
	}

	/**
	 * Bulk-loads $rows via LOAD DATA LOCAL INFILE, writing them to a temporary file first
	 * (unlike Postgres's pgsqlCopyFromArray(), there is no rows-array variant for MySQL --
	 * LOAD DATA always reads from a file). Requires the connection to have been created
	 * with Pdo\Mysql::ATTR_LOCAL_INFILE enabled (pass it in the datasource's PDO connection
	 * options -- it cannot be toggled on an already-open connection) *and* the server's
	 * `local_infile` global variable set to 1 (defaults to 0/OFF on stock MySQL 8+). Throws
	 * a clear, actionable error up front if the connection-side half of that isn't set,
	 * rather than letting it fail with MySQL's own less obvious error message.
	 *
	 * Uses Pdo\Mysql::ATTR_LOCAL_INFILE rather than the identically-valued
	 * PDO::MYSQL_ATTR_LOCAL_INFILE, which PHP 8.5 deprecates. Every MySQL connection
	 * Propulsion constructs is a MysqlPropulsionPDO, which extends \Pdo\Mysql, so the
	 * driver-specific constant is always available here.
	 *
	 * @see       DBAdapter::bulkLoad()
	 */
	public function bulkLoad(PropulsionPDO $con, string $tableName, array $columns, iterable $rows): int
	{
		if (!$con->getAttribute(\Pdo\Mysql::ATTR_LOCAL_INFILE)) {
			throw new PropulsionException(
				'DBMySQL::bulkLoad() requires the connection to be created with Pdo\Mysql::ATTR_LOCAL_INFILE '
				. "enabled (pass it in the datasource's PDO connection options) and the server's local_infile "
				. 'global variable set to 1 -- both are required, and neither can be toggled on an already-open connection.'
			);
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'propulsion_bulk_');
		if ($tmpFile === false) {
			throw new PropulsionException('DBMySQL::bulkLoad() could not create a temporary file');
		}

		$count = 0;
		try {
			$handle = fopen($tmpFile, 'wb');
			if ($handle === false) {
				throw new PropulsionException('DBMySQL::bulkLoad() could not open its temporary file for writing');
			}
			foreach ($rows as $row) {
				$fields = array();
				foreach ($row as $value) {
					$fields[] = $this->escapeLoadDataValue($value);
				}
				fwrite($handle, implode("\t", $fields) . "\n");
				$count++;
			}
			fclose($handle);

			if ($count > 0) {
				$quotedColumns = implode(',', array_map(array($this, 'quoteIdentifier'), $columns));
				$quotedTable = $this->useQuoteIdentifier() ? $this->quoteIdentifierTable($tableName) : $tableName;
				$sql = 'LOAD DATA LOCAL INFILE ' . $con->quote($tmpFile)
					. ' INTO TABLE ' . $quotedTable
					. " FIELDS TERMINATED BY '\\t'"
					. ' (' . $quotedColumns . ')';
				$con->exec($sql);
			}
		} finally {
			unlink($tmpFile);
		}

		return $count;
	}

	/**
	 * Escapes a single value for LOAD DATA's default FIELDS ESCAPED BY '\\' format:
	 * backslash, tab, newline, and carriage return all need backslash-escaping; null
	 * becomes the literal 2-character "\N" sequence, MySQL's own NULL sentinel for
	 * LOAD DATA (matching the default ESCAPED BY behavior -- no FIELDS ESCAPED BY clause
	 * is passed above, so this relies on MySQL's own default of '\\').
	 *
	 * @param     mixed $value
	 * @return    string
	 */
	private function escapeLoadDataValue($value): string
	{
		if ($value === null) {
			return '\\N';
		}
		if (is_bool($value)) {
			$value = $value ? '1' : '0';
		} elseif (!is_scalar($value)) {
			throw new PropulsionException('DBMySQL::bulkLoad() cannot serialize a non-scalar value');
		}
		return strtr((string) $value, array(
			'\\' => '\\\\',
			"\t" => '\\t',
			"\n" => '\\n',
			"\r" => '\\r',
		));
	}

	/**
	 * @see       DBAdapter::supportsExplain()
	 */
	public function supportsExplain(): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getExplainSql()
	 *
	 * $analyze is accepted but ignored: MySQL's own `EXPLAIN ANALYZE` (8.0.18+)
	 * changes the *output format* to a text execution tree rather than adding
	 * an option to the same tabular result shape plain `EXPLAIN` returns, and
	 * MariaDB's `ANALYZE FORMAT=JSON` (not `EXPLAIN ANALYZE`) differs again --
	 * neither is a drop-in superset of the plain `EXPLAIN` row shape, so
	 * always returning the plain form keeps this method's output uniform
	 * across MySQL/MariaDB versions rather than branching into
	 * differently-shaped results depending on $analyze.
	 */
	public function getExplainSql(string $sql, bool $analyze = false): string
	{
		return 'EXPLAIN ' . $sql;
	}

	/**
	 * @see       DBAdapter::random()
	 *
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed = null): string
	{
		return 'rand('.((int) $seed).')';
	}

	/**
	 * @see       DBAdapter::bindValue()
	 *
	 * @param     PDOStatement  $stmt
	 * @param     string        $parameter
	 * @param     mixed         $value
	 * @param     ColumnMap     $cMap
	 * @param     null|integer  $position
	 *
	 * @return    boolean
	 */
	public function bindValue(PDOStatement $stmt, $parameter, $value, ColumnMap $cMap, $position = null)
	{
		$pdoType = $cMap->getPdoType();
		// FIXME - This is a temporary hack to get around apparent bugs w/ PDO+MYSQL
		// See http://pecl.php.net/bugs/bug.php?id=9919
		if ($pdoType == PDO::PARAM_BOOL) {
			if (!is_scalar($value) && $value !== null) {
				throw new PropulsionException('DBMySQL::bindValue() cannot cast a non-scalar value to int for a boolean column');
			}
			$value = (int) $value;
			$pdoType = PDO::PARAM_INT;
			return $stmt->bindValue($parameter, $value, $pdoType);
		} elseif ($cMap->isTemporal()) {
			$value = $this->formatTemporalValue($value, $cMap);
		} elseif (is_resource($value) && $cMap->isLob()) {
			// we always need to make sure that the stream is rewound, otherwise nothing will
			// get written to database.
			rewind($value);
		}

		return $stmt->bindValue($parameter, $value, $pdoType);
	}

	/**
	 * Prepare connection parameters.
	 * See: http://www.propelorm.org/ticket/1360
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	public function prepareParams($params)
	{
		$params = parent::prepareParams($params);

		if (isset($params['settings']) && is_array($params['settings'])
			&& isset($params['settings']['charset']) && is_array($params['settings']['charset'])
			&& isset($params['settings']['charset']['value']) && is_string($params['settings']['charset']['value'])
			&& isset($params['dsn']) && is_string($params['dsn'])
		) {
			if (strpos($params['dsn'], ';charset=') === false) {
				$params['dsn'] .= ';charset=' . $params['settings']['charset']['value'];
				unset($params['settings']['charset']);
			}
		}

		return $params;
	}

	/**
	 * MySQL/MariaDB report a broken deadlock as error 1213 with SQLSTATE 40001,
	 * which the base implementation already recognises. Added here is 1205,
	 * `ER_LOCK_WAIT_TIMEOUT` -- a transaction that waited out
	 * `innodb_lock_wait_timeout` for a row lock somebody else held. It arrives
	 * under the generic SQLSTATE `HY000`, so only the driver code identifies
	 * it, and it is every bit as retryable as a deadlock: the same statement
	 * run again once the holder has committed simply succeeds.
	 *
	 * @see       DBAdapter::isRetryableError()
	 */
	public function isRetryableError(\PDOException $e): bool
	{
		return parent::isRetryableError($e) || $this->extractDriverErrorCode($e) === 1205;
	}
}
