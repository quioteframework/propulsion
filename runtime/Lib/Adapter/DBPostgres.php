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
 * This is used to connect to PostgresQL databases.
 *
 * <a href="http://www.pgsql.org">http://www.pgsql.org</a>
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Hakan Tandogan <hakan42@gmx.de> (Torque)
 * @version    $Revision$
 */
use PDO;
use Propulsion\Query\Criteria;
use Propulsion\Exception\PropulsionException;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Query\VectorExpression;
class DBPostgres extends DBAdapter
{
	/**
	 * @see       DBAdapter::getDefaultPdoClass()
	 */
	public function getDefaultPdoClass(): string
	{
		return \Propulsion\Adapter\Pgsql\PgsqlPropulsionPDO::class;
	}

	/**
	 * This method is called after a connection was created to run necessary
	 * post-initialization queries or code.
	 *
	 * Forces `intervalstyle` to `iso_8601` so a native `interval` column's
	 * text output always round-trips through `new DateInterval($v)` the same
	 * way an emulated INTERVAL column (VARCHAR storing an ISO-8601 duration
	 * string, on every other platform) already does -- Postgres's *default*
	 * `intervalstyle` ("postgres", e.g. "1 day 02:03:04") is not ISO-8601 and
	 * would need fuzzy parsing on read instead of this one-line fix on connect.
	 *
	 * @see       parent::initConnection()
	 *
	 * @param     PDO    $con  A PDO connection instance.
	 * @param     array<string,mixed>  $settings  An array of settings.
	 */
	public function initConnection(PDO $con, array $settings): void
	{
		$con->exec("SET intervalstyle = 'iso_8601'");
		parent::initConnection($con, $settings);
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
		return "($s1 || $s2)";
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
		return "substring($s from $pos" . ($len > -1 ? "for $len" : "") . ")";
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return "char_length($s)";
	}

	/**
	 * @see       DBAdapter::getIdMethod()
	 *
	 * @return    integer
	 */
	protected function getIdMethod()
	{
		return DBAdapter::ID_METHOD_SEQUENCE;
	}

	/**
	 * Gets ID for specified sequence name.
	 *
	 * @param     PropulsionPDO  $con
	 * @param     string  $name
	 *
	 * @return    integer
	 */
	public function getId(PropulsionPDO $con, $name = null)
	{
		if ($name === null) {
			throw new PropulsionException("Unable to fetch next sequence ID without sequence name.");
		}
		$stmt = $con->query("SELECT nextval(".$con->quote($name).")");
		if ($stmt === false) {
			throw new PropulsionException("Unable to fetch next sequence ID: query failed for sequence \"$name\".");
		}

		$row = $stmt->fetch(PDO::FETCH_NUM);
		if (!is_array($row) || !isset($row[0]) || !is_numeric($row[0])) {
			throw new PropulsionException("Unable to fetch next sequence ID for sequence \"$name\".");
		}

		return (int) $row[0];
	}

	/**
	 * Postgres (8.2+) supports RETURNING, folding id retrieval into the INSERT
	 * statement itself. getIdMethod() above still reports ID_METHOD_SEQUENCE (the
	 * sequence's own default on the column, not a value this class populates
	 * explicitly -- see getId()'s own pre-INSERT nextval() query, kept for any
	 * caller still relying on isGetIdBeforeInsert()/getId() directly), but
	 * BasePeer::doInsert() checks supportsInsertReturning() first and skips that
	 * separate round trip whenever this is true, the same way it already does for
	 * MSSQL/SQLite.
	 *
	 * @see       DBAdapter::supportsInsertReturning()
	 */
	public function supportsInsertReturning(?PropulsionPDO $con = null): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getInsertReturningSql()
	 */
	public function getInsertReturningSql(string $sql, string $idColumnName): string
	{
		return $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * @see       DBAdapter::getEmptyInsertSql()
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		$sql = 'INSERT INTO ' . $tableName . ' DEFAULT VALUES';
		return $idColumnName === null ? $sql : $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * @see       DBAdapter::extractInsertedId()
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		return $stmt->fetchColumn();
	}

	/**
	 * Postgres (8.2+) also supports RETURNING on UPDATE/DELETE, for affected-row
	 * hydration without a separate re-SELECT.
	 *
	 * @see       DBAdapter::supportsRowReturning()
	 */
	public function supportsRowReturning(?PropulsionPDO $con = null): bool
	{
		return true;
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
	 * Returns timestamp formatter string for use in date() function.
	 * @return    string
	 */
	public function getTimestampFormatter()
	{
		return "Y-m-d H:i:s O";
	}

	/**
	 * Returns timestamp formatter string for use in date() function.
	 *
	 * @return    string
	 */
	public function getTimeFormatter()
	{
		return "H:i:s O";
	}

	/**
	 * @see       DBAdapter::applyLimit()
	 *
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if ( $limit > 0 ) {
			$sql .= " LIMIT ".$limit;
		}
		if ( $offset > 0 ) {
			$sql .= " OFFSET ".$offset;
		}
	}

	/**
	 * @see       DBAdapter::supportsUpsert()
	 */
	public function supportsUpsert(): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getUpsertSql()
	 */
	public function getUpsertSql(string $sql, array $conflictColumnNames, string $setClause): string
	{
		if (empty($conflictColumnNames)) {
			throw new PropulsionException('DBPostgres::getUpsertSql() needs at least one conflict-target column');
		}
		$sql .= ' ON CONFLICT (' . implode(', ', $conflictColumnNames) . ')';
		$sql .= $setClause === '' ? ' DO NOTHING' : ' DO UPDATE SET ' . $setClause;
		return $sql;
	}

	/**
	 * @see       DBAdapter::supportsBulkLoad()
	 */
	public function supportsBulkLoad(): bool
	{
		return true;
	}

	/**
	 * Bulk-loads $rows via COPY FROM STDIN -- a PDO_PGSQL-specific operation, not part
	 * of the base \PDO class, only present on an actual pgsql-driver connection.
	 *
	 * `\Pdo\Pgsql::copyFromArray()` is the only working way to reach it: the old
	 * bolted-on-\PDO method (`PDO::pgsqlCopyFromArray()`) this used to fall back to
	 * doesn't exist anywhere in PHP 8.4+ (confirmed via Reflection against a live PHP
	 * 8.5 build -- neither \PDO nor \Pdo\Pgsql declare it, deprecated-and-removed
	 * rather than merely deprecated), so that fallback was dead code that would have
	 * thrown a fatal "call to undefined method" if a non-\Pdo\Pgsql connection ever
	 * actually reached it. $con is always a PgsqlPropulsionPDO (which extends
	 * \Pdo\Pgsql) unless a schema's `classname` override replaces it with something
	 * else -- that unsupported case now fails with a clear PropulsionException
	 * instead of a fatal error.
	 *
	 * @see       DBAdapter::bulkLoad()
	 */
	public function bulkLoad(PropulsionPDO $con, string $tableName, array $columns, iterable $rows): int
	{
		if (!$con instanceof \Pdo\Pgsql) {
			throw new PropulsionException('DBPostgres::bulkLoad() requires a \Pdo\Pgsql connection (e.g. PgsqlPropulsionPDO); got ' . get_class($con) . '.');
		}

		$lines = array();
		foreach ($rows as $row) {
			$fields = array();
			foreach ($row as $value) {
				$fields[] = $this->escapeCopyValue($value);
			}
			$lines[] = implode("\t", $fields);
		}

		$count = count($lines);
		if ($count === 0) {
			return 0;
		}

		// Empirically verified quirk (not documented in the PHP manual): copyFromArray()'s
		// $nullAs parameter must be passed in its own doubly-escaped form -- to recognize the
		// literal 2-character "\N" sequence escapeCopyValue() writes into the row data below as
		// the null marker, $nullAs itself must be the 3-character string "\\N" (backslash,
		// backslash, N), not the 2-character "\N" one might expect from the row data alone.
		// Passing the "obvious" 2-character value here throws
		// "invalid input syntax for type integer" on the first NULL in a non-text column.
		$result = $con->copyFromArray($tableName, $lines, "\t", '\\\\N', implode(',', $columns));
		if ($result === false) {
			throw new PropulsionException('DBPostgres::bulkLoad() failed: ' . implode('; ', array_map(strval(...), $con->errorInfo())));
		}

		return $count;
	}

	/**
	 * Escapes a single value for COPY's TEXT format: backslash, tab, newline, and
	 * carriage return all need backslash-escaping; null becomes the literal 2-character
	 * "\N" sentinel (see the $null_as note on pgsqlCopyFromArray() above for why that
	 * parameter itself needs a *3*-character value to match this one).
	 *
	 * @param     mixed $value
	 * @return    string
	 */
	private function escapeCopyValue($value): string
	{
		if ($value === null) {
			return '\\N';
		}
		if (is_bool($value)) {
			// (string) false is "" (not "0"), which COPY would reject as an invalid
			// boolean literal -- use Postgres's own canonical short forms instead.
			$value = $value ? 't' : 'f';
		} elseif (!is_scalar($value)) {
			throw new PropulsionException('DBPostgres::bulkLoad() cannot serialize a non-scalar value');
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
	 */
	public function getExplainSql(string $sql, bool $analyze = false): string
	{
		return 'EXPLAIN ' . ($analyze ? 'ANALYZE ' : '') . $sql;
	}

	/**
	 * @see       DBAdapter::supportsAdvisoryLocks()
	 */
	public function supportsAdvisoryLocks(): bool
	{
		return true;
	}

	/**
	 * `pg_advisory_lock` family, keyed by the 63-bit hash of the lock name
	 * (Postgres's advisory locks are numbered, not named).
	 *
	 * Session-scoped (`pg_advisory_lock`, not `pg_advisory_xact_lock`) so the
	 * lock outlives a COMMIT, matching the contract every other adapter here
	 * implements and the one the cron-singleton/job-queue use cases need.
	 *
	 * Three timeout shapes, three mechanisms:
	 *
	 *  - **0.0**: `pg_try_advisory_lock`, which returns immediately.
	 *  - **null**: `pg_advisory_lock`, which blocks until it wins.
	 *  - **finite**: `pg_advisory_lock` with `lock_timeout` set around it.
	 *    Postgres has no timeout-taking advisory-lock function, and the
	 *    alternative -- polling `pg_try_advisory_lock` until a deadline --
	 *    trades a server-side wait for a busy loop that also cannot be woken
	 *    the instant the lock frees. `lock_timeout` genuinely applies to
	 *    advisory-lock waits, and is restored to its previous value
	 *    afterwards rather than reset to DEFAULT, so this cannot clobber a
	 *    deployment that sets its own.
	 *
	 * @see       DBAdapter::acquireAdvisoryLock()
	 */
	public function acquireAdvisoryLock(PropulsionPDO $con, string $name, ?float $timeout = null): bool
	{
		$key = $this->advisoryLockKey($name);

		if ($timeout !== null && $timeout <= 0.0) {
			return (bool) $this->fetchAdvisoryLockResult($con, 'SELECT pg_try_advisory_lock(:p1)', array($key));
		}

		if ($timeout === null) {
			$this->fetchAdvisoryLockResult($con, 'SELECT pg_advisory_lock(:p1)', array($key));

			return true;
		}

		$previous = $this->fetchAdvisoryLockResult($con, 'SHOW lock_timeout');
		$con->exec('SET lock_timeout = ' . (int) ceil($timeout * 1000));
		try {
			$this->fetchAdvisoryLockResult($con, 'SELECT pg_advisory_lock(:p1)', array($key));

			return true;
		} catch (\PDOException $e) {
			// 55P03 lock_not_available is what lock_timeout raises; anything
			// else is a real failure and must not be reported as "busy".
			if ($this->extractSqlState($e) !== '55P03') {
				throw $e;
			}

			return false;
		} finally {
			$con->exec('SET lock_timeout = ' . $con->quote(is_string($previous) ? $previous : '0'));
		}
	}

	/**
	 * @see       DBAdapter::releaseAdvisoryLock()
	 */
	public function releaseAdvisoryLock(PropulsionPDO $con, string $name): bool
	{
		return (bool) $this->fetchAdvisoryLockResult(
			$con,
			'SELECT pg_advisory_unlock(:p1)',
			array($this->advisoryLockKey($name))
		);
	}

	/**
	 * pgvector's distance operators. All four are infix and all four are
	 * "smaller is closer", which is what lets an HNSW/IVFFlat index serve an
	 * `ORDER BY ... LIMIT n` from them -- including `<#>`, which is the
	 * *negative* inner product for exactly that reason.
	 *
	 * The literal is emitted single-quoted and cast to `vector` explicitly:
	 * without the cast Postgres has to infer the type of an untyped literal
	 * next to an operator that is overloaded across `vector`, `halfvec` and
	 * `sparsevec`, and picks wrong often enough to be worth ruling out here.
	 * Quoting is safe without escaping because VectorExpression validated the
	 * literal as numbers-and-punctuation before this was called.
	 *
	 * @see       DBAdapter::getVectorDistanceSql()
	 */
	public function getVectorDistanceSql(string $column, string $vectorLiteral, string $metric): string
	{
		$operator = match ($metric) {
			VectorExpression::L2 => '<->',
			VectorExpression::COSINE => '<=>',
			VectorExpression::INNER_PRODUCT => '<#>',
			VectorExpression::L1 => '<+>',
			default => throw new PropulsionException('DBPostgres: unknown vector distance metric "' . $metric . '"'),
		};

		return $column . ' ' . $operator . " '" . $vectorLiteral . "'::vector";
	}

	/**
	 * @see       DBAdapter::random()
	 *
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed=NULL): string
	{
		return 'random()';
	}

	/**
	 * @see        DBAdapter::getDeleteFromClause()

	 * @param     Criteria  $criteria
	 * @param     string    $tableName
	 *
	 * @return    string
	 */
	public function getDeleteFromClause($criteria, $tableName)
	{
		$sql = 'DELETE ';
		if ($queryComment = $criteria->getComment()) {
			$sql .= '/* ' . $queryComment . ' */ ';
		}
		if ($realTableName = $criteria->getTableForAlias($tableName)) {
			if ($this->useQuoteIdentifier()) {
				$realTableName = $this->quoteIdentifierTable($realTableName);
			}
			$sql .= 'FROM ' . $realTableName . ' AS ' . $tableName;
		} else {
			if ($this->useQuoteIdentifier()) {
				$tableName = $this->quoteIdentifierTable($tableName);
			}
			$sql .= 'FROM ' . $tableName;
		}

		return $sql;
	}

	/**
	 * @see        DBAdapter::quoteIdentifierTable()
	 *
	 * @param     string  $table
	 * @return    string
	 */
	public function quoteIdentifierTable($table)
	{
		// e.g. 'database.table alias' should be escaped as '"database"."table" "alias"'
		return '"' . strtr($table, array('.' => '"."', ' ' => '" "')) . '"';
	}
}
