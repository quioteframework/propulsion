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
 * Oracle adapter.
 *
 * @author     David Giffin <david@giffin.org> (Propel)
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Jon S. Stevens <jon@clearink.com> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Bill Schneider <bschneider@vecna.com> (Torque)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @version    $Revision$
 */

use PDO;
use PDOStatement;
use Propulsion\Query\Criteria;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Connection\PropulsionPDO;

class DBOracle extends DBAdapter
{
	/**
	 * @see       DBAdapter::getDefaultPdoClass()
	 */
	public function getDefaultPdoClass(): string
	{
		return \Propulsion\Adapter\Oracle\OraclePropulsionPDO::class;
	}

	/**
	 * This method is called after a connection was created to run necessary
	 * post-initialization queries or code.
	 * Removes the charset query and adds the date queries
	 *
	 * @see       parent::initConnection()
	 *
	 * @param     PDO    $con
	 * @param     array<string,mixed>  $settings  A $PDO PDO connection instance
	 */
	public function initConnection(PDO $con, array $settings): void
	{
		$con->exec("ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD'");
		$con->exec("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS'");
		if (isset($settings['queries']) && is_array($settings['queries'])) {
			foreach ($settings['queries'] as $queries) {
				foreach ((array)$queries as $query) {
					if (!is_string($query)) {
						continue;
					}
					$con->exec($query);
				}
			}
		}
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
		return "SUBSTR($s, $pos, $len)";
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return "LENGTH($s)";
	}

	/**
	 * @see       DBAdapter::applyLimit()
	 *
	 * @param     string      $sql
	 * @param     int|string  $offset  Expected to be numeric; validated at runtime since
	 *                                 this method has no native parameter type and is part
	 *                                 of a public, pluggable adapter interface.
	 * @param     int|string  $limit   Expected to be numeric; validated at runtime (see $offset).
	 * @param     null|Criteria  $criteria
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if (!is_numeric($offset) || !is_numeric($limit)) {
			throw new PropulsionException('DBOracle::applyLimit() expects a number for argument 2 and 3');
		}

		// Oracle 12c+ supports the ANSI SQL:2008 "OFFSET ... ROWS FETCH NEXT
		// ... ROWS ONLY" clause natively, appended directly to the existing
		// query text with no subquery wrapping at all. This replaces the
		// legacy ROWNUM-based double-nested-subquery rewrite, which had to
		// derive an explicit outer column list to avoid a synthetic
		// PROPEL_ROWNUM column leaking through a "B.*" wildcard, and had to
		// pre-alias the Criteria's select columns (via
		// BasePeer::needsSelectAliases()/turnSelectColumnsToAliases()) to
		// dodge an ORA-00918 "column ambiguously defined" error from a
		// "SELECT A.*" star-expansion over a derived table with duplicate
		// column names (a multi-table join without column aliases). None of
		// that applies once pagination is just a clause appended to the
		// original, unwrapped SELECT.
		//
		// OFFSET/FETCH requires an ORDER BY clause; when the query has none,
		// a no-op scalar subquery satisfies the syntax requirement without
		// imposing a real ordering (Oracle requires a FROM on every query
		// including this one, unlike DBMSSQL::applyLimit()'s own bare
		// "ORDER BY (SELECT NULL)" for the same native clause).
		//
		// The check has to distinguish this query's *own* ORDER BY from one
		// belonging to a nested query (a FROM/IN/EXISTS subquery, a CTE, a set
		// operation branch); see DBAdapter::hasTopLevelOrderBy(), which is where
		// that distinction and the ORA-00907 it prevents are spelled out.
		if (!$this->hasTopLevelOrderBy($sql, $criteria)) {
			$sql .= ' ORDER BY (SELECT NULL FROM dual)';
		}
		$sql .= ' OFFSET ' . $offset . ' ROWS';
		if ($limit > 0) {
			$sql .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
		}
	}

	/**
	 * Oracle has no "SELECT ... FOR SHARE" equivalent; only FOR UPDATE row locking.
	 *
	 * @see       DBAdapter::supportsForShare()
	 */
	public function supportsForShare(): bool
	{
		return false;
	}

	/**
	 * Reserved words Oracle rejects as an unquoted identifier (ORA-03050/
	 * ORA-00904 and friends) -- kept in sync with
	 * Propulsion\Generator\Platform\OraclePlatform::RESERVED_WORDS (the DDL
	 * side, which already quotes these when creating the table); this is its
	 * runtime counterpart, so a column/table name that collides with one of
	 * these (e.g. a fixture column literally named "uid") is generated
	 * *and* queried consistently instead of only the former.
	 *
	 * @var       array<int, string>
	 */
	private const RESERVED_WORDS = [
		'ACCESS', 'ADD', 'ALL', 'ALTER', 'AND', 'ANY', 'AS', 'ASC', 'AUDIT',
		'BETWEEN', 'BY', 'CHAR', 'CHECK', 'CLUSTER', 'COLUMN', 'COLUMN_VALUE',
		'COMMENT', 'COMPRESS', 'CONNECT', 'CREATE', 'CURRENT', 'DATE', 'DECIMAL',
		'DEFAULT', 'DELETE', 'DESC', 'DISTINCT', 'DROP', 'ELSE', 'EXCLUSIVE',
		'EXISTS', 'FILE', 'FLOAT', 'FOR', 'FROM', 'GRANT', 'GROUP', 'HAVING',
		'IDENTIFIED', 'IMMEDIATE', 'IN', 'INCREMENT', 'INDEX', 'INITIAL',
		'INSERT', 'INTEGER', 'INTERSECT', 'INTO', 'IS', 'LEVEL', 'LIKE', 'LOCK',
		'LONG', 'MAXEXTENTS', 'MINUS', 'MLSLABEL', 'MODE', 'MODIFY',
		'NESTED_TABLE_ID', 'NOAUDIT', 'NOCOMPRESS', 'NOT', 'NOWAIT', 'NULL',
		'NUMBER', 'OF', 'OFFLINE', 'ON', 'ONLINE', 'OPTION', 'OR', 'ORDER',
		'PCTFREE', 'PRIOR', 'PUBLIC', 'RAW', 'RENAME', 'RESOURCE', 'REVOKE',
		'ROW', 'ROWID', 'ROWNUM', 'ROWS', 'SELECT', 'SESSION', 'SET', 'SHARE',
		'SIZE', 'SMALLINT', 'START', 'SUCCESSFUL', 'SYNONYM', 'SYSDATE',
		'TABLE', 'THEN', 'TO', 'TRIGGER', 'UID', 'UNION', 'UNIQUE', 'UPDATE',
		'USER', 'VALIDATE', 'VALUES', 'VARCHAR', 'VARCHAR2', 'VIEW',
		'WHENEVER', 'WHERE', 'WITH',
	];

	/**
	 * @see       DBAdapter::useQuoteIdentifier()
	 */
	public function useQuoteIdentifier()
	{
		return true;
	}

	/**
	 * Deliberately does NOT unconditionally quote every identifier the way
	 * DBMySQL's own override does (see
	 * Propulsion\Generator\Platform\OraclePlatform::quoteIdentifier()'s
	 * matching doc comment for the full rationale: a quoted Oracle identifier
	 * is case-sensitive forever after, while an unquoted one is folded to
	 * uppercase, and code elsewhere -- e.g. OracleSchemaParser
	 * reverse-engineering -- assumes the latter for every identifier that
	 * isn't a reserved word). Quoting (and uppercasing, matching the same
	 * folding convention) only identifiers that are actual reserved words
	 * fixes the real, confirmed failure (a fixture column named "uid")
	 * with zero behavioral change for every other identifier.
	 *
	 * @see       DBAdapter::quoteIdentifier()
	 *
	 * @param     string  $text
	 * @return    string
	 */
	public function quoteIdentifier($text)
	{
		if (str_contains($text, '.')) {
			return implode('.', array_map(fn (string $part): string => $this->quoteIdentifier($part), explode('.', $text)));
		}
		return in_array(strtoupper($text), self::RESERVED_WORDS, true)
			? '"' . strtoupper($text) . '"'
			: $text;
	}

	/**
	 * Oracle's DELETE syntax doesn't take a table alias the way Postgres/MySQL's
	 * "DELETE <alias> FROM <table> AS <alias>" does -- an alias straight after
	 * the DELETE keyword isn't valid Oracle syntax at all (ORA-00942, "table or
	 * view ... does not exist", since Oracle parses that leading identifier as
	 * the table name itself, not an alias), and Oracle also rejects the "AS"
	 * keyword before a table alias generally (ORA-03048). Oracle's own alias
	 * syntax is "DELETE FROM <table> <alias> WHERE <alias>.col = ..." -- the
	 * alias still needs to appear (a self-referencing WHERE clause built
	 * against $tableName -- the alias, not the real table name -- would
	 * otherwise reference an undefined table), just after the table name
	 * instead of after DELETE, and with no "AS".
	 *
	 * @see       DBAdapter::getDeleteFromClause()
	 *
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
			$sql .= 'FROM ' . $realTableName . ' ' . $tableName;
		} else {
			if ($this->useQuoteIdentifier()) {
				$tableName = $this->quoteIdentifierTable($tableName);
			}
			$sql .= 'FROM ' . $tableName;
		}
		return $sql;
	}

	/**
	 * @return int
	 */
	protected function getIdMethod()
	{
		return DBAdapter::ID_METHOD_SEQUENCE;
	}

	/**
	 * @param     PropulsionPDO  $con
	 * @param     string  $name
	 *
	 * @throws    PropulsionException
	 * @return    integer
	 */
	public function getId(PropulsionPDO $con, $name = null)
	{
		if ($name === null) {
			throw new PropulsionException("Unable to fetch next sequence ID without sequence name.");
		}

		$stmt = $con->query("SELECT " . $name . ".nextval FROM dual");
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
	 * Holds the OUT-bound value from the most recent insert-returning statement's
	 * "RETURNING col INTO :ret_id" -- see prepareInsertReturning()/
	 * extractInsertedId(). Oracle's RETURNING ... INTO populates a bound PHP
	 * variable directly; unlike Postgres/MariaDB's RETURNING or MSSQL's OUTPUT
	 * (both a normal, post-execute-readable result set), there is no statement
	 * result set for extractInsertedId(\PDOStatement $stmt) to read from, so the
	 * value has to be stashed somewhere else for it to pick back up. Safe as
	 * instance state (not thread-local/request-local) because PHP has no real
	 * concurrency within one process: doInsert() calls prepareInsertReturning(),
	 * execute(), then extractInsertedId() strictly in sequence, with no other
	 * doInsert() call on this same adapter instance able to interleave in between.
	 *
	 * @var       mixed
	 */
	private mixed $lastInsertReturningId = null;

	/**
	 * Oracle has supported `RETURNING ... INTO` since 8i -- no version gating
	 * needed the way MariaDB-vs-MySQL detection is (see DBMySQL::isMariaDb()).
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
		return $sql . ' RETURNING ' . $idColumnName . ' INTO :ret_id';
	}

	/**
	 * Oracle has no "DEFAULT VALUES" syntax (the DBAdapter default this would
	 * otherwise inherit) -- every column needs to appear somewhere in the
	 * statement. Explicitly naming just the id column and inserting NULL into it
	 * is enough: id generation here is trigger-based, not a column DEFAULT (see
	 * getAddAutoIncrementTriggerDDL()'s "WHEN (new.<pk> IS NULL)" on the generator
	 * side), and an explicit NULL satisfies that condition exactly the same way
	 * omitting the column from a non-empty column list already does.
	 *
	 * @see       DBAdapter::getEmptyInsertSql()
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		if ($idColumnName === null) {
			throw new PropulsionException(static::class . ' cannot build an INSERT with no columns and no id to return -- Oracle has no DEFAULT VALUES syntax');
		}
		return 'INSERT INTO ' . $tableName . ' (' . $idColumnName . ') VALUES (NULL)'
			. ' RETURNING ' . $idColumnName . ' INTO :ret_id';
	}

	/**
	 * Binds the ":ret_id" OUT parameter getInsertReturningSql() added to the
	 * statement -- must happen before execute() (PDO_OCI populates it by
	 * reference as part of executing the RETURNING ... INTO clause, the same
	 * "bind by reference, read the variable back afterward" shape
	 * DBOracle::bindValue()'s own CLOB/LOB length-hinted bindParam() calls already
	 * use elsewhere in this class, just for an OUT rather than an IN parameter).
	 * 40 chars comfortably covers any NUMBER-typed surrogate PK.
	 *
	 * @see       DBAdapter::prepareInsertReturning()
	 */
	public function prepareInsertReturning(\PDOStatement $stmt, string $idColumnName): void
	{
		$this->lastInsertReturningId = null;
		$stmt->bindParam(':ret_id', $this->lastInsertReturningId, PDO::PARAM_INT, 40);
	}

	/**
	 * @see       DBAdapter::extractInsertedId()
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		return $this->lastInsertReturningId;
	}

	/**
	 * @see       DBAdapter::supportsAdvisoryLocks()
	 */
	public function supportsAdvisoryLocks(): bool
	{
		return true;
	}

	/**
	 * `DBMS_LOCK.ALLOCATE_UNIQUE` + `DBMS_LOCK.REQUEST`, in one anonymous
	 * PL/SQL block so the allocated handle never has to make a round trip.
	 *
	 * **This needs `GRANT EXECUTE ON DBMS_LOCK` and does not have it by
	 * default.** Oracle restricts the package because a lock taken with
	 * `release_on_commit => FALSE` (which is what this hook's contract
	 * requires) survives until the session ends. There is nothing this
	 * adapter can do about that beyond failing loudly, which it does: the
	 * ORA-06550/ORA-00904 an ungranted schema produces is passed straight
	 * through rather than swallowed into a "lock busy" false, because those
	 * are not the same situation and only one of them is worth retrying.
	 *
	 * `ALLOCATE_UNIQUE` maps an arbitrary name onto a handle and commits its
	 * own bookkeeping transaction as a side effect -- an Oracle-specific
	 * wrinkle worth knowing about before calling this mid-transaction. The
	 * name is truncated to `ALLOCATE_UNIQUE`'s own 128-character limit by
	 * Oracle itself; it is not pre-hashed here for the same reason MySQL's
	 * isn't (a silent truncation that collides is worse than an error).
	 *
	 * `REQUEST`'s timeout is whole seconds, with `DBMS_LOCK.MAXWAIT`
	 * (2^31 - 1) standing in for "indefinitely"; 0 returns immediately.
	 * Status 0 is "acquired", 4 is "already held by this session" (also a
	 * success -- the caller has it either way), and 1/2/3/5 are timeout,
	 * deadlock, parameter error and illegal handle.
	 *
	 * @see       DBAdapter::acquireAdvisoryLock()
	 */
	public function acquireAdvisoryLock(PropulsionPDO $con, string $name, ?float $timeout = null): bool
	{
		$seconds = $timeout === null ? 2147483647 : ($timeout <= 0.0 ? 0 : (int) ceil($timeout));

		$status = $this->runLockBlock(
			$con,
			'DECLARE l_handle VARCHAR2(128); BEGIN '
			. 'DBMS_LOCK.ALLOCATE_UNIQUE(:lock_name, l_handle); '
			. ':lock_status := DBMS_LOCK.REQUEST(lockhandle => l_handle, lockmode => DBMS_LOCK.X_MODE, '
			. 'timeout => :lock_timeout, release_on_commit => FALSE); END;',
			$name,
			$seconds
		);

		return in_array($status, array(0, 4), true);
	}

	/**
	 * @see       DBAdapter::releaseAdvisoryLock()
	 */
	public function releaseAdvisoryLock(PropulsionPDO $con, string $name): bool
	{
		$status = $this->runLockBlock(
			$con,
			'DECLARE l_handle VARCHAR2(128); BEGIN '
			. 'DBMS_LOCK.ALLOCATE_UNIQUE(:lock_name, l_handle); '
			. ':lock_status := DBMS_LOCK.RELEASE(lockhandle => l_handle); END;',
			$name,
			null
		);

		return $status === 0;
	}

	/**
	 * Runs one of the two DBMS_LOCK PL/SQL blocks above and returns the status
	 * it wrote to `:lock_status`.
	 *
	 * An anonymous block has no result set, so the status comes back through
	 * an OUT bind -- the same mechanism (and the same reason for it) as
	 * prepareInsertReturning()'s `:ret_id`, which is why this cannot use the
	 * base class's fetchAdvisoryLockResult() helper the other three adapters
	 * share.
	 */
	private function runLockBlock(PropulsionPDO $con, string $sql, string $name, ?int $timeoutSeconds): int
	{
		$stmt = $con->prepare($sql);
		if ($stmt === false) {
			throw new PropulsionException('PropulsionPDO::prepare() returned false for a DBMS_LOCK block');
		}
		$status = 0;
		$stmt->bindValue(':lock_name', $name, PDO::PARAM_STR);
		if ($timeoutSeconds !== null) {
			$stmt->bindValue(':lock_timeout', $timeoutSeconds, PDO::PARAM_INT);
		}
		$stmt->bindParam(':lock_status', $status, PDO::PARAM_INT, 40);
		$stmt->execute();

		return (int) $status;
	}

	/**
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed=NULL): string
	{
		return 'dbms_random.value';
	}

	/**
	 * Oracle accepts a self-referencing CTE under a plain "WITH name (cols) AS (...)"
	 * with no "RECURSIVE" keyword at all (has since 11gR2) -- the explicit column list
	 * Criteria::withCte() already requires for a recursive CTE is what Oracle actually
	 * needs to resolve the self-reference, not a keyword.
	 *
	 * @see       DBAdapter::supportsRecursiveCteKeyword()
	 */
	public function supportsRecursiveCteKeyword(): bool
	{
		return false;
	}

	/**
	 * Oracle has no `ON CONFLICT`/`ON DUPLICATE KEY UPDATE` clause; upserts need
	 * `MERGE` instead, built from scratch by getMergeUpsertSql().
	 *
	 * @see       DBAdapter::supportsUpsert()
	 */
	public function supportsUpsert(): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::usesMergeUpsert()
	 */
	public function usesMergeUpsert(): bool
	{
		return true;
	}

	/**
	 * Builds a `MERGE` statement. Same shape as DBMSSQL::getMergeUpsertSql() (see
	 * its doc comment for why the insert columns are aliased into a one-row source
	 * row rather than rebinding their ":pN" placeholders a second time, and why the
	 * target is deliberately left un-aliased -- a raw ColumnExpression in
	 * $setClause may reference the real, unqualified table name), except Oracle's
	 * USING clause needs a real FROM ("FROM dual", since Oracle has no bare
	 * "SELECT ... " without one) and the statement must NOT end with a semicolon --
	 * unlike T-SQL, a trailing ";" on a single statement executed directly via OCI
	 * is a syntax error (ORA-00911) here, not a requirement.
	 *
	 * @see       DBAdapter::getMergeUpsertSql()
	 */
	public function getMergeUpsertSql(string $tableName, array $insertColumns, array $conflictColumnNames, string $setClause): string
	{
		if (empty($conflictColumnNames)) {
			throw new PropulsionException('DBOracle::getMergeUpsertSql() needs at least one conflict-target column');
		}

		$selectList = array();
		foreach ($insertColumns as $p => $col) {
			$selectList[] = ':p' . ($p + 1) . ' AS ' . $col;
		}

		$onClause = array();
		foreach ($conflictColumnNames as $col) {
			$onClause[] = $tableName . '.' . $col . ' = s.' . $col;
		}

		$insertValues = array();
		foreach ($insertColumns as $col) {
			$insertValues[] = 's.' . $col;
		}

		$sql = 'MERGE INTO ' . $tableName
			. ' USING (SELECT ' . implode(', ', $selectList) . ' FROM dual) s'
			. ' ON (' . implode(' AND ', $onClause) . ')';
		if ($setClause !== '') {
			$sql .= ' WHEN MATCHED THEN UPDATE SET ' . $setClause;
		}
		$sql .= ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')';

		return $sql;
	}

	/**
	 * Ensures uniqueness of select column names by turning them all into aliases
	 * This is necessary for queries on more than one table when the tables share a column name
	 *
	 *
	 * @param     Criteria  $criteria
	 * @return    Criteria  The input, with Select columns replaced by aliases
	 */
	public function turnSelectColumnsToAliases(Criteria $criteria)
	{
		$selectColumns = $criteria->getSelectColumns();
		// clearSelectColumns also clears the aliases, so get them too
		$asColumns = $criteria->getAsColumns();
		$criteria->clearSelectColumns();
		$columnAliases = $asColumns;
		// add the select columns back
		foreach ($selectColumns as $id => $clause) {
			// Generate a unique alias
			$baseAlias = "ORA_COL_ALIAS_".$id;
			$alias = $baseAlias;
			// If it already exists, add a unique suffix
			$i = 0;
			while (isset($columnAliases[$alias])) {
				$i++;
				$alias = $baseAlias . '_' . $i;
			}
			// Add it as an alias
			$criteria->addAsColumn($alias, $clause);
			$columnAliases[$alias] = $clause;
		}
		// Add the aliases back, don't modify them
		foreach ($asColumns as $name => $clause) {
			$criteria->addAsColumn($name, $clause);
		}

		return $criteria;
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
		if ($cMap->isTemporal()) {
			$value = $this->formatTemporalValue($value, $cMap);
		} elseif ($cMap->getType() == PropulsionTypes::CLOB_EMU) {
			if (!is_string($value)) {
				throw new PropulsionException('DBOracle::bindValue() expected a string value for a CLOB_EMU column');
			}
			return $stmt->bindParam(':p'.$position, $value, $cMap->getPdoType(), strlen($value));
		} elseif ($cMap->isLob()) {
			// pdo_oci's PDO::PARAM_LOB bind (the dynamic-bind/OCILobWrite
			// path in its own C source) reproducibly writes an empty LOB --
			// silently, no error, confirmed both with a plain string wrapped
			// in a stream and a real stream resource, via bindValue() and
			// bindParam(), with and without an explicit "RETURNING col INTO"
			// clause. cleanupSQL() below works around this entirely
			// differently: it hex-encodes this same value and rewrites the
			// SQL to wrap this parameter in HEXTORAW(...), so by the time
			// this runs the value in hand is already that hex string, not
			// the original binary content -- bind it as a plain string, we
			// don't want PDO::PARAM_LOB (the broken path) at all here.
			//
			// bindParam(), not bindValue(), and with an explicit length --
			// same as the CLOB_EMU branch just above, and for the same
			// reason: pdo_oci's own C source only sizes its bind buffer to
			// the parameter's max_value_len, defaulting to a mere 1332
			// bytes when that isn't supplied (i.e. via bindValue(), which
			// passes no length at all) -- (ORA-01461, "value ... exceeded
			// the maximum VARCHAR2 length") for any hex-encoded value
			// longer than that, e.g. this project's own BLOB test fixtures.
			if (!is_string($value)) {
				throw new PropulsionException('DBOracle::bindValue() expected a string (hex-encoded by cleanupSQL()) value for a LOB column');
			}
			return $stmt->bindParam($parameter, $value, PDO::PARAM_STR, strlen($value));
		}

		return $stmt->bindValue($parameter, $value, $cMap->getPdoType());
	}

	/**
	 * Handles two unrelated Oracle-specific final-SQL-text adjustments:
	 *
	 * - Quoting a reserved-word column reference. The generated column-name
	 *   constants every Peer's addSelectColumns()/buildCriteria() etc. use
	 *   (e.g. AcctAuditLogPeer::UID = 'acct_audit_log.UID') are plain,
	 *   always-unquoted "table.COLUMN" strings -- the same for every
	 *   platform (see PeerBuilder::addColumnNameConstants(), which never
	 *   calls Platform::quoteIdentifier() at all) -- and every SQL-building
	 *   method that uses them (select column lists, WHERE clauses via
	 *   Criterion, ...) does so with no quoting step either. That's a real
	 *   gap for any column whose name collides with an Oracle reserved word
	 *   (see quoteIdentifier()'s own doc comment; the DDL that creates the
	 *   table already quotes these via
	 *   Propulsion\Generator\Platform\OraclePlatform's own quoteIdentifier()).
	 *   Matches any "identifier.identifier" reference anywhere in the final
	 *   SQL string (not just a bare "table.column"), so a reserved-word
	 *   column still gets quoted inside a function call or a multi-column
	 *   expression, e.g. "MAX(t.UID)" -- everywhere else in the string
	 *   (including inside a quoted string literal delimited by non-word
	 *   characters, which this pattern can't match into) is left untouched.
	 *
	 * - Rewriting every bound LOB (BLOB/VARBINARY/LONGVARBINARY -- not
	 *   CLOB_EMU, which already works via its own bindValue() branch above)
	 *   parameter's SQL placeholder into "HEXTORAW(:pN)" and replacing its
	 *   bound value with its own hex-encoded form, working around pdo_oci's
	 *   broken PDO::PARAM_LOB bind (see bindValue()'s own doc comment for
	 *   how that was confirmed). Oracle's implicit string -> BLOB conversion
	 *   otherwise expects (and, given anything else, throws ORA-01465
	 *   "invalid hex number" on) a hex-encoded string in the first place, so
	 *   this is also what makes binding a LOB value as a plain string valid
	 *   Oracle SQL at all, independent of the pdo_oci bug -- confirmed
	 *   working for content up to several KB (comfortably more than this
	 *   project's own BLOB test fixtures need); Oracle's own bind-variable-
	 *   length limits would still apply for a much larger value, same as any
	 *   other platform has its own equivalent limits.
	 *
	 * Both fixed here -- as the one cleanupSQL() hook DBAdapter already
	 * defines for exactly this kind of "adjust the final SQL text before
	 * it's prepared" adapter-specific need (see DBMSSQL's own override) --
	 * rather than upstream in shared code, to avoid any risk of changing
	 * Postgres/MySQL/MSSQL's already-correct, already-tested SQL output.
	 *
	 * @see       DBAdapter::cleanupSQL()
	 *
	 * @param     string       $sql
	 * @param     array<int,array<string,mixed>>  $params
	 * @param     Criteria     $values
	 * @param     DatabaseMap  $dbMap
	 */
	public function cleanupSQL(&$sql, array &$params, Criteria $values, DatabaseMap $dbMap): void
	{
		$sql = preg_replace_callback(
			'/\b([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)\b/',
			function (array $m): string {
				return in_array(strtoupper($m[2]), self::RESERVED_WORDS, true)
					? $m[1] . '.' . $this->quoteIdentifier($m[2])
					: $m[0];
			},
			$sql
		) ?? $sql;

		foreach ($params as $position => $param) {
			$tableName = $param['table'] ?? null;
			$columnName = $param['column'] ?? null;
			if (!is_string($tableName) || !is_string($columnName) || $param['value'] === null) {
				continue;
			}
			$cMap = $dbMap->getTable($tableName)->getColumn($columnName);
			if (!$cMap->isLob()) {
				continue;
			}

			$value = $param['value'];
			if (is_resource($value)) {
				rewind($value);
				$value = stream_get_contents($value);
				if ($value === false) {
					throw new PropulsionException('DBOracle::cleanupSQL() was unable to read a LOB column value\'s stream.');
				}
			} elseif (!is_string($value)) {
				throw new PropulsionException('DBOracle::cleanupSQL() expected a string or stream resource for a LOB column value.');
			}

			$params[$position]['value'] = bin2hex($value);

			// Word-boundary-terminated so e.g. ":p1" doesn't also match as a
			// prefix of ":p10"/":p11"/etc.
			$placeholder = ':p' . ($position + 1);
			$sql = (string) preg_replace(
				'/' . preg_quote($placeholder, '/') . '\b/',
				'HEXTORAW(' . $placeholder . ')',
				$sql,
				1
			);
		}
	}

	/**
	 * Oracle reports both of its retryable concurrency failures under the
	 * generic SQLSTATE `HY000`, so neither is visible to the base
	 * implementation's SQLSTATE check and both have to be matched on the ORA
	 * number instead:
	 *
	 *  - ORA-00060: deadlock detected while waiting for resource
	 *  - ORA-08177: can't serialize access for this transaction (the
	 *    SERIALIZABLE-isolation failure other platforms report as 40001)
	 *
	 * @see       DBAdapter::isRetryableError()
	 */
	public function isRetryableError(\PDOException $e): bool
	{
		return parent::isRetryableError($e)
			|| in_array($this->extractDriverErrorCode($e), [60, 8177], true);
	}
}
