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
use Propulsion\Util\BasePeer;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Util\PropulsionColumnTypes;

class DBOracle extends DBAdapter
{
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
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 * @param     null|Criteria  $criteria
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if ($criteria !== null && BasePeer::needsSelectAliases($criteria)) {
			$crit = clone $criteria;
			$selectSql = $this->createSelectSqlPart($crit, $params, true);
			$sql = $selectSql . substr($sql, strpos($sql, 'FROM') - 1);
		}
		// "SELECT B.*" would also re-expose the synthetic "rownum AS
		// PROPEL_ROWNUM" column added below as part of "A.*" -- an extra
		// trailing column on every row of a paginated result set, which
		// PDO::FETCH_NUM-based hydration/formatters (indexed purely by
		// position, with no idea a pagination rewrite happened) would then
		// misread as belonging to the query's actual last selected column.
		// Deriving the real outer column list (falling back to the old "B.*"
		// wildcard only if the SELECT clause can't be parsed this way, e.g.
		// this method's own no-op-select unit tests) avoids the leak instead
		// of requiring every caller to know to strip a trailing column.
		$outerColumns = $this->deriveOuterColumnList($sql) ?? 'B.*';
		$sql = 'SELECT ' . $outerColumns . ' FROM ('
			. 'SELECT A.*, rownum AS PROPEL_ROWNUM FROM (' . $sql . ') A '
			. ') B WHERE ';

		if ( $offset > 0 ) {
			$sql .= ' B.PROPEL_ROWNUM > ' . $offset;
			if ( $limit > 0 ) {
				$sql .= ' AND B.PROPEL_ROWNUM <= ' . ( $offset + $limit );
			}
		} else {
			$sql .= ' B.PROPEL_ROWNUM <= ' . $limit;
		}
	}

	/**
	 * Derives the explicit "B.col1, B.col2, ..." column list applyLimit()
	 * needs to avoid re-exposing its own synthetic PROPEL_ROWNUM column via a
	 * "B.*" wildcard -- see applyLimit()'s own doc comment. Parses just the
	 * "SELECT ... FROM" column list of the (not yet wrapped) query, splitting
	 * on top-level commas (respecting parens and string literals, so a
	 * function call or a comma inside a string literal isn't mistaken for a
	 * column separator) and, for each entry, using its explicit "AS alias" if
	 * present or its trailing "table.COLUMN"-shaped identifier otherwise --
	 * matching Oracle's own implicit-naming rule for an unaliased expression.
	 * Returns null (caller falls back to the old, leaky "B.*" wildcard) if
	 * the SELECT clause can't be parsed this way at all (e.g. no columns
	 * selected, as in this method's own unit tests) or if any single entry's
	 * name can't be determined.
	 *
	 * @param     string  $sql
	 * @return    ?string
	 */
	private function deriveOuterColumnList(string $sql): ?string
	{
		if (!preg_match('/^SELECT\s+(.*?)\s+FROM\s/is', $sql, $m) || trim($m[1]) === '') {
			return null;
		}

		$names = [];
		foreach ($this->splitTopLevelSql($m[1], ',') as $column) {
			$column = trim($column);
			if ($column === '') {
				return null;
			}
			if (preg_match('/\sAS\s+("(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_]*)\s*$/i', $column, $aliasMatch)) {
				$names[] = 'B.' . $aliasMatch[1];
				continue;
			}
			if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*$/', $column, $identMatch)) {
				$names[] = 'B.' . $identMatch[1];
				continue;
			}
			return null;
		}

		return implode(', ', $names);
	}

	/**
	 * Splits a SQL fragment on a delimiter that appears only at "top level"
	 * -- not inside parens (a function call's own argument list) or a
	 * single-quoted string literal -- used by deriveOuterColumnList() to
	 * split a column list on commas without being fooled by a comma inside
	 * e.g. "COALESCE(a, b)" or a string literal default value.
	 *
	 * @param     string  $sql
	 * @param     string  $delimiter  Single character.
	 * @return    array<int, string>
	 */
	private function splitTopLevelSql(string $sql, string $delimiter): array
	{
		$parts = [];
		$current = '';
		$depth = 0;
		$inString = false;
		for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
			$char = $sql[$i];
			if ($char === "'") {
				$inString = !$inString;
			} elseif (!$inString) {
				if ($char === '(') {
					$depth++;
				} elseif ($char === ')') {
					$depth--;
				} elseif ($char === $delimiter && $depth === 0) {
					$parts[] = $current;
					$current = '';
					continue;
				}
			}
			$current .= $char;
		}
		$parts[] = $current;

		return $parts;
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
	 * @param     PDO     $con
	 * @param     string  $name
	 *
	 * @throws    PropulsionException
	 * @return    integer
	 */
	public function getId(PDO $con, $name = null)
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
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed=NULL): string
	{
		return 'dbms_random.value';
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
		} elseif ($cMap->getType() == PropulsionColumnTypes::CLOB_EMU) {
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
}
