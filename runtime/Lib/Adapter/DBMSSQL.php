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
 * This is used to connect to a MSSQL database.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @version    $Revision$
 */
use PDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Query\Criteria;
use Propulsion\Map\DatabaseMap;
use Propulsion\Connection\PropulsionPDO;

class DBMSSQL extends DBAdapter
{
	/**
	 * MS SQL Server does not support SET NAMES
	 *
	 * @see       DBAdapter::setCharset()
	 *
	 * @param     PDO     $con
	 * @param     string  $charset
	 */
	public function setCharset(PDO $con, $charset): void
	{
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string $in The string to transform to upper case.
	 * @return    string The upper case string.
	 */
	public function toUpperCase($in)
	{
		return $this->ignoreCase($in);
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string $in The string whose case to ignore.
	 * @return    string The string in a case that can be ignored.
	 */
	public function ignoreCase($in)
	{
		return 'UPPER(' . $in . ')';
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
		return '(' . $s1 . ' + ' . $s2 . ')';
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
		return 'SUBSTRING(' . $s . ', ' . $pos . ', ' . $len . ')';
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return 'LEN(' . $s . ')';
	}

	/**
	 * @see       DBAdapter::quoteIdentifier()
	 *
	 * @param     string  $text
	 * @return    string
	 */
	public function quoteIdentifier($text)
	{
		return '[' . $text . ']';
	}

	/**
	 * @see       DBAdapter::quoteIdentifierTable()
	 *
	 * @param     string  $table
	 * @return    string
	 */
	public function quoteIdentifierTable($table)
	{
		// e.g. 'database.table alias' should be escaped as '[database].[table] [alias]'
		return '[' . strtr($table, array('.' => '].[', ' ' => '] [')) . ']';
	}

	/**
	 * @see       DBAdapter::random()
	 *
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed = null): string
	{
		return 'RAND(' . ((int)$seed) . ')';
	}

	/**
	 * Simulated Limit/Offset
	 *
	 * This rewrites the $sql query to apply the offset and limit.
	 * some of the ORDER BY logic borrowed from Doctrine MsSqlPlatform
	 *
	 * @see       DBAdapter::applyLimit()
	 * @author    Benjamin Runnels <kraven@kraven.org>
	 *
	 * @param     string      $sql
	 * @param     int|string  $offset  Expected to be numeric; validated at runtime since
	 *                                 this method has no native parameter type and is part
	 *                                 of a public, pluggable adapter interface.
	 * @param     int|string  $limit   Expected to be numeric; validated at runtime (see $offset).
	 *
	 * @return    void
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		// make sure offset and limit are numeric (defends against non-numeric values being
		// interpolated directly into the SQL string below)
		if(! is_numeric($offset) || ! is_numeric($limit))
		{
			throw new PropulsionException('DBMSSQL::applyLimit() expects a number for argument 2 and 3');
		}

		//split the select and from clauses out of the original query
		$selectSegment = array();

		$selectText = 'SELECT ';

		preg_match('/\Aselect(.*)from(.*)/si', $sql, $selectSegment);
		if(count($selectSegment) == 3) {
			$selectStatement = trim($selectSegment[1]);
			$fromStatement = trim($selectSegment[2]);
		} else {
			// Not a single, plain "SELECT ... FROM ..." this regex-based
			// rewriter can parse structurally (e.g. a UNION/INTERSECT/EXCEPT-
			// combined query, which has no single FROM clause to hoist a
			// ROW_NUMBER() window function into) -- fall back to SQL Server's
			// native OFFSET/FETCH syntax instead, which applies to any
			// complete query regardless of its internal shape, as long as it
			// has an ORDER BY (which T-SQL requires for OFFSET/FETCH anyway).
			if (!preg_match('/\bORDER BY\b/i', $sql)) {
				throw new \Exception('DBMSSQL::applyLimit() requires an ORDER BY clause to paginate a query it cannot rewrite structurally (e.g. UNION/INTERSECT/EXCEPT).');
			}
			$sql .= ' OFFSET ' . $offset . ' ROWS';
			if ($limit > 0) {
				$sql .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
			}
			return;
		}

		if (preg_match('/\Aselect(\s+)distinct/i', $sql)) {
			$selectText .= 'DISTINCT ';
			$selectStatement = str_ireplace('distinct ', '', $selectStatement);
		}

		// if we're starting at offset 0 then theres no need to simulate limit,
		// just grab the top $limit number of rows
		if($offset == 0) {
			$sql = $selectText . 'TOP ' . $limit . ' ' . $selectStatement . ' FROM ' . $fromStatement;
			return;
		}

		//get the ORDER BY clause if present
		$orderStatement = stristr($fromStatement, 'ORDER BY');
		$orders = '';

		if($orderStatement !== false) {
			//remove order statement from the from statement
			$fromStatement = trim(str_replace($orderStatement, '', $fromStatement));

			$order = str_ireplace('ORDER BY', '', $orderStatement);
			$orders = explode(',', $order);

			for($i = 0; $i < count($orders); $i ++) {
				$normalizedOrder = preg_replace('/\s+(ASC|DESC)$/i', '', $orders[$i]) ?? $orders[$i];
				$orderArr[trim($normalizedOrder)] = array(
					'sort' => (stripos($orders[$i], ' DESC') !== false) ? 'DESC' : 'ASC',
					'key' => $i
				);
			}
		}

		//setup inner and outer select selects
		$innerSelect = '';
		$outerSelect = '';
		foreach(explode(', ', $selectStatement) as $selCol) {
			$selColArr = explode(' ', $selCol);
			$selColCount = count($selColArr) - 1;

			//make sure the current column isn't * or an aggregate
			if($selColArr[0] != '*' && ! strstr($selColArr[0], '(')) {
				if(isset($orderArr[$selColArr[0]])) {
					$orders[$orderArr[$selColArr[0]]['key']] = $selColArr[0] . ' ' . $orderArr[$selColArr[0]]['sort'];
				}

				//use the alias if one was present otherwise use the column name
				$alias = (! stristr($selCol, ' AS ')) ? $selColArr[0] : $selColArr[$selColCount];
				//don't quote the identifier if it is already quoted
				if($alias !== '' && $alias[0] != '[') $alias = $this->quoteIdentifier($alias);

				//save the first non-aggregate column for use in ROW_NUMBER() if required
				if(! isset($firstColumnOrderStatement)) {
					$firstColumnOrderStatement = 'ORDER BY ' . $selColArr[0];
				}

				//add an alias to the inner select so all columns will be unique
				$innerSelect .= $selColArr[0] . ' AS ' . $alias . ', ';
				$outerSelect .= $alias . ', ';
			} else {
				//agregate columns must always have an alias clause
				if(! stristr($selCol, ' AS ')) {
					throw new \Exception('DBMSSQL::applyLimit() requires aggregate columns to have an Alias clause');
				}

				//aggregate column alias can't be used as the count column you must use the entire aggregate statement
				if(isset($orderArr[$selColArr[$selColCount]])) {
					$orders[$orderArr[$selColArr[$selColCount]]['key']] = str_replace($selColArr[$selColCount - 1] . ' ' . $selColArr[$selColCount], '', $selCol) . $orderArr[$selColArr[$selColCount]]['sort'];
				}

				//quote the alias
				$alias = $selColArr[$selColCount];
				//don't quote the identifier if it is already quoted
				if($alias !== '' && $alias[0] != '[') $alias = $this->quoteIdentifier($alias);
				$innerSelect .= str_replace($selColArr[$selColCount], $alias, $selCol) . ', ';
				$outerSelect .= $alias . ', ';
			}
		}

		if(is_array($orders)) {
			$orderStatement = 'ORDER BY ' . implode(', ', $orders);
		} else {
			//use the first non aggregate column in our select statement if no ORDER BY clause present
			if(isset($firstColumnOrderStatement)) {
				$orderStatement = $firstColumnOrderStatement;
			} else {
				throw new \Exception('DBMSSQL::applyLimit() unable to find column to use with ROW_NUMBER()');
			}
		}

		//substring the select strings to get rid of the last comma and add our FROM and SELECT clauses
		$innerSelect = $selectText . 'ROW_NUMBER() OVER(' . $orderStatement . ') AS [RowNumber], ' . substr($innerSelect, 0, - 2) . ' FROM';
		//outer select can't use * because of the RowNumber column
		$outerSelect = 'SELECT ' . substr($outerSelect, 0, - 2) . ' FROM';

		//ROW_NUMBER() starts at 1 not 0
		$derivedTable = $outerSelect . ' (' . $innerSelect . ' ' . $fromStatement . ') AS derivedb WHERE RowNumber ';
		// A limit <= 0 means "no limit" (Criteria::$limit's own default) -- with
		// only an offset and no cap, there's no upper bound to add at all;
		// "BETWEEN offset+1 AND limit+offset" would otherwise collapse into an
		// inverted, always-empty range (e.g. offset 10, limit 0 -> BETWEEN 11
		// AND 10).
		$sql = $limit > 0
			? $derivedTable . 'BETWEEN ' . ($offset + 1) . ' AND ' . ($limit + $offset)
			: $derivedTable . '>= ' . ($offset + 1);
	}

	/**
	 * MSSQL has no NOWAIT table hint (the closest equivalent, SET LOCK_TIMEOUT, is
	 * session-level rather than per-query), so it isn't supported here.
	 *
	 * @see       DBAdapter::supportsNoWait()
	 */
	public function supportsNoWait(): bool
	{
		return false;
	}

	/**
	 * MSSQL expresses locking via table hints (see applyLockHints()), not a trailing
	 * clause, so the base implementation must not append one here. NOWAIT is validated
	 * against here since it has no table-hint equivalent.
	 *
	 * @see       DBAdapter::applyLock()
	 */
	public function applyLock(string &$sql, Criteria $criteria): void
	{
		if ($criteria->isLockNoWait()) {
			throw new PropulsionException('DBMSSQL does not support NOWAIT locking');
		}
		// Locking is expressed via table hints, spliced in by applyLockHints(); nothing to append here.
	}

	/**
	 * Splices SQL Server locking table hints ("WITH (UPDLOCK, ROWLOCK)" for FOR UPDATE,
	 * "WITH (HOLDLOCK, ROWLOCK)" for FOR SHARE, plus READPAST for SKIP LOCKED) onto every
	 * table referenced in the FROM/JOIN clauses.
	 *
	 * @see       DBAdapter::applyLockHints()
	 *
	 * @param     array<int,string|null> $fromClause
	 * @param     array<int,string|null> $joinClause
	 * @param     Criteria               $criteria
	 */
	public function applyLockHints(array &$fromClause, array &$joinClause, Criteria $criteria): void
	{
		$hints = array($criteria->getLockMode() === Criteria::LOCK_FOR_SHARE ? 'HOLDLOCK' : 'UPDLOCK', 'ROWLOCK');
		if ($criteria->isLockSkipLocked()) {
			$hints[] = 'READPAST';
		}
		$hintSql = ' WITH (' . implode(', ', $hints) . ')';

		$fromClause = array_map(function (?string $table) use ($hintSql): ?string {
			return $table === null ? $table : $table . $hintSql;
		}, $fromClause);

		$joinClause = array_map(function (?string $join) use ($hintSql): ?string {
			return $join === null ? $join : preg_replace('/\sON\s/i', $hintSql . ' ON ', $join, 1);
		}, $joinClause);
	}

	/**
	 * MSSQL can return the generated IDENTITY value directly from the INSERT statement
	 * via an OUTPUT clause. This is also more reliable than lastInsertId(), whose
	 * behavior varies across the PDO drivers used to talk to SQL Server (pdo_sqlsrv vs
	 * pdo_dblib).
	 *
	 * @see       DBAdapter::supportsInsertReturning()
	 */
	public function supportsInsertReturning(?PropulsionPDO $con = null): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::supportsInsertNullPk()
	 */
	public function supportsInsertNullPk(): bool
	{
		return false;
	}

	/**
	 * @see       DBAdapter::getInsertReturningSql()
	 */
	public function getInsertReturningSql(string $sql, string $idColumnName): string
	{
		if (!preg_match('/\)\s*VALUES\s*\(/i', $sql)) {
			throw new PropulsionException('DBMSSQL::getInsertReturningSql() could not locate the VALUES clause in: ' . $sql);
		}
		$withOutput = preg_replace('/\)\s*VALUES\s*\(/i', ') OUTPUT INSERTED.' . $idColumnName . ' VALUES (', $sql, 1);
		if ($withOutput === null) {
			throw new PropulsionException('DBMSSQL::getInsertReturningSql() failed to splice the OUTPUT clause into: ' . $sql);
		}
		return $withOutput;
	}

	/**
	 * SQL Server has no `ON CONFLICT`/`ON DUPLICATE KEY UPDATE` clause; upserts need
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
	 * Builds a `MERGE` statement: the insert columns are aliased into a one-row
	 * derived table ("USING (SELECT :p1 AS col1, ...) AS s") so the "WHEN NOT
	 * MATCHED THEN INSERT" branch can reference them as "s.col1" without rebinding
	 * the same ":pN" placeholder a second time (PDO doesn't support reusing a named
	 * placeholder more than once per prepared statement on every driver). The SET
	 * clause's own placeholders are untouched -- they're independent parameters,
	 * not tied to the source row.
	 *
	 * The target is deliberately left un-aliased (no "AS t") -- $setClause may
	 * contain a raw ColumnExpression referencing the real, unqualified table name
	 * (e.g. "book.PRICE + ?", from a caller building "col = col + n" via
	 * BookPeer::PRICE, the same fully-qualified constant plain doUpdate() uses),
	 * and that only resolves if the target keeps its real name in scope rather
	 * than being hidden behind an alias only this method would know about.
	 *
	 * T-SQL requires a MERGE statement to end with a semicolon (SQL Server error
	 * 10713 otherwise).
	 *
	 * @see       DBAdapter::getMergeUpsertSql()
	 */
	public function getMergeUpsertSql(string $tableName, array $insertColumns, array $conflictColumnNames, string $setClause): string
	{
		if (empty($conflictColumnNames)) {
			throw new PropulsionException('DBMSSQL::getMergeUpsertSql() needs at least one conflict-target column');
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
			. ' USING (SELECT ' . implode(', ', $selectList) . ') AS s'
			. ' ON (' . implode(' AND ', $onClause) . ')';
		if ($setClause !== '') {
			$sql .= ' WHEN MATCHED THEN UPDATE SET ' . $setClause;
		}
		$sql .= ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')'
			. ';';

		return $sql;
	}

	/**
	 * SQL Server (2005+) can return the rows an UPDATE/DELETE affected directly
	 * from that statement via an OUTPUT clause -- see getUpdateReturningSql()/
	 * getDeleteReturningSql().
	 *
	 * @see       DBAdapter::supportsRowReturning()
	 */
	public function supportsRowReturning(?PropulsionPDO $con = null): bool
	{
		return true;
	}

	/**
	 * Splices an "OUTPUT INSERTED.col1, ..." clause right after the SET clause --
	 * T-SQL's OUTPUT on UPDATE goes between SET and any FROM/WHERE clause, not at
	 * the very end the way Postgres/MariaDB's trailing RETURNING does. Finds
	 * whichever of " FROM "/" WHERE " occurs first in the already-fully-built SQL
	 * (FROM only appears at all for an aliased update -- see
	 * DBAdapter::getUpdateFromClauseSql() -- and always precedes WHERE when it
	 * does), matching the same "regex-splice into a complete statement" approach
	 * getInsertReturningSql() already uses for MSSQL's OUTPUT on INSERT.
	 *
	 * @see       DBAdapter::getUpdateReturningSql()
	 */
	public function getUpdateReturningSql(string $sql, array $columnNames): string
	{
		$outputClause = ' OUTPUT ' . implode(', ', array_map(fn (string $c): string => 'INSERTED.' . $c, $columnNames));
		if (!preg_match('/\s(FROM|WHERE)\s/i', $sql)) {
			return $sql . $outputClause;
		}
		return (string) preg_replace('/\s(FROM|WHERE)\s/i', $outputClause . ' $1 ', $sql, 1);
	}

	/**
	 * Splices an "OUTPUT DELETED.col1, ..." clause right after the FROM clause,
	 * before WHERE -- same regex-splice approach as getUpdateReturningSql().
	 *
	 * @see       DBAdapter::getDeleteReturningSql()
	 */
	public function getDeleteReturningSql(string $sql, array $columnNames): string
	{
		$outputClause = ' OUTPUT ' . implode(', ', array_map(fn (string $c): string => 'DELETED.' . $c, $columnNames));
		if (!preg_match('/\sWHERE\s/i', $sql)) {
			return $sql . $outputClause;
		}
		return (string) preg_replace('/\sWHERE\s/i', $outputClause . ' WHERE ', $sql, 1);
	}

	/**
	 * @see       DBAdapter::getEmptyInsertSql()
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		$sql = 'INSERT INTO ' . $tableName;
		if ($idColumnName !== null) {
			$sql .= ' OUTPUT INSERTED.' . $idColumnName;
		}
		return $sql . ' DEFAULT VALUES';
	}

	/**
	 * @see       DBAdapter::getIdentityInsertOnSql()
	 */
	public function getIdentityInsertOnSql(string $tableName): ?string
	{
		return 'SET IDENTITY_INSERT ' . $tableName . ' ON';
	}

	/**
	 * @see       DBAdapter::getIdentityInsertOffSql()
	 */
	public function getIdentityInsertOffSql(string $tableName): ?string
	{
		return 'SET IDENTITY_INSERT ' . $tableName . ' OFF';
	}

	/**
	 * @see       DBAdapter::getUpdateTargetSql()
	 */
	public function getUpdateTargetSql(string $tableName, string $alias): string
	{
		return $alias;
	}

	/**
	 * @see       DBAdapter::getUpdateFromClauseSql()
	 */
	public function getUpdateFromClauseSql(string $tableName, string $alias): string
	{
		return ' FROM ' . $tableName . ' AS ' . $alias;
	}

	/**
	 * @see       DBAdapter::extractInsertedId()
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		$id = $stmt->fetchColumn();
		// FreeTDS/pdo_dblib has no MARS support: every INSERT here goes
		// through the OUTPUT INSERTED.<col> clause (see getInsertReturningSql()),
		// and this single-value fetch otherwise leaves its result set open --
		// the next statement on the same connection (including something as
		// simple as a later ROLLBACK/COMMIT TRANSACTION) can fail with
		// "Attempt to initiate a new Adaptive Server operation with results
		// pending" before PHP's GC gets around to closing it.
		$stmt->closeCursor();

		return $id;
	}

	/**
	 * @see       parent::cleanupSQL()
	 *
	 * @param     string       $sql
	 * @param     array<int,array<string,mixed>>        $params
	 * @param     Criteria     $values
	 * @param     DatabaseMap  $dbMap
	 */
	public function cleanupSQL(&$sql, array &$params, Criteria $values, DatabaseMap $dbMap): void
	{
		$i = 1;
		$paramCols = array();
		foreach ($params as $param) {
			$table = $param['table'];
			if (null !== $table) {
				if (!is_string($table) || !is_string($param['column'])) {
					throw new PropulsionException('DBMSSQL::cleanupSQL() expected param table/column names to be strings');
				}
				$column = $dbMap->getTable($table)->getColumn($param['column']);
				/* MSSQL pdo_dblib and pdo_mssql blob values must be converted to hex and then the hex added
				 * to the query string directly.  If it goes through PDOStatement::bindValue quotes will cause
				 * an error with the insert or update.
				 */
				if (is_resource($param['value']) && $column->isLob()) {
					// we always need to make sure that the stream is rewound, otherwise nothing will
					// get written to database.
					rewind($param['value']);
					$hexArr = unpack('H*hex', stream_get_contents($param['value']));
					if ($hexArr === false) {
						throw new PropulsionException('DBMSSQL::cleanupSQL() failed to hex-encode a blob value');
					}
					$sql = str_replace(":p$i", '0x' . $hexArr['hex'], $sql);
					unset($hexArr);
					fclose($param['value']);
				} else {
					$paramCols[] = $param;
				}
			}
			$i++;
		}

		//if we made changes re-number the params
		if($params != $paramCols)
		{
			$params = $paramCols;
			unset($paramCols);
			preg_match_all('/:p\d/', $sql, $matches);
			foreach($matches[0] as $key => $match)
			{
				$sql = str_replace($match, ':p'.($key+1), $sql);
			}
		}
	}
}
