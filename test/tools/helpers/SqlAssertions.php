<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Normalizes a few MySQL/MSSQL-specific SQL-shape differences in a generated SQL
 * string, so hardcoded Postgres-style expected-SQL test assertions can be compared
 * against the *actual* SQL a test produced regardless of which adapter is active.
 * Covers:
 *
 * - Identifier quoting: MySQL is the only built-in adapter whose
 *   DBAdapter::useQuoteIdentifier() returns true (DBMySQL::useQuoteIdentifier()) --
 *   MSSQL/Oracle/Postgres/SQLite all default to no identifier quoting at all in
 *   generated SQL. Backticks are not otherwise meaningful in this codebase's generated
 *   SQL (never part of a string literal value the query builder produces), so stripping
 *   every backtick pair is safe and lossless for comparison purposes.
 * - LIMIT/OFFSET syntax: DBPostgres::applyLimit() emits "LIMIT n OFFSET m"; DBMySQL's
 *   emits the equivalent "LIMIT m, n" instead. Rewritten to the Postgres-style form.
 * - DELETE with a table alias: MySQL requires naming the alias being deleted from
 *   ("DELETE b FROM book AS b WHERE ..."); Postgres doesn't ("DELETE FROM book AS b
 *   WHERE ..."). The leading "DELETE <alias> FROM" is rewritten to "DELETE FROM".
 * - MSSQL LIMIT without OFFSET: DBMSSQL::applyLimit() emits "SELECT TOP n ..."
 *   instead of a trailing "... LIMIT n". Rewritten to the Postgres-style form.
 * - MSSQL aliased UPDATE: "UPDATE table alias SET ..." (every other platform) is
 *   a syntax error in T-SQL, which instead needs "UPDATE alias SET ... FROM
 *   table AS alias ..." (see DBAdapter::getUpdateTargetSql()/
 *   getUpdateFromClauseSql()). Rewritten back to the plain "UPDATE table alias
 *   SET ..." form.
 * - MSSQL LIMIT *with* OFFSET: DBMSSQL::applyLimit() has no native OFFSET/FETCH
 *   support (see KNOWN_ISSUES.md's "Modernize DBMSSQL::applyLimit()" item) and
 *   instead wraps the query in a `ROW_NUMBER() OVER(...)` derived table, aliasing
 *   every selected column to survive the wrapping. Unwrapped back into the
 *   original column list/FROM clause plus a trailing "LIMIT n OFFSET m", computed
 *   from the derived table's "WHERE RowNumber BETWEEN lo AND hi" bounds.
 *
 * Deliberately does *not* attempt to normalize every possible platform SQL-shape
 * difference (e.g. id-generation strategy changing which columns an INSERT's column
 * list contains, or MSSQL's FOR UPDATE/FOR SHARE lock hints being emitted as
 * inline table hints rather than a trailing clause at all) -- those need a
 * genuinely different expected string per platform, not a stable text rewrite; see
 * KNOWN_ISSUES.md's "MySQL parity"/"MSSQL full-suite parity audit" sections for the
 * ones left as-is and why.
 *
 * @param      string $sql
 * @return     string
 */
function normalizeGeneratedSql(string $sql): string
{
	$sql = (string) preg_replace('/`([^`]*)`/', '$1', $sql);
	$sql = (string) preg_replace('/\bLIMIT (\d+), (\d+)/', 'LIMIT $2 OFFSET $1', $sql);
	$sql = (string) preg_replace('/\bDELETE \w+ FROM\b/', 'DELETE FROM', $sql);
	$sql = (string) preg_replace('/^UPDATE (\w+) SET (.+) FROM (\w+) AS \1 WHERE/', 'UPDATE $3 $1 SET $2 WHERE', $sql);
	$sql = normalizeMssqlRowNumberOffset($sql);
	if (preg_match('/^SELECT TOP (\d+) /', $sql, $m)) {
		$sql = (string) preg_replace('/^SELECT TOP \d+ /', 'SELECT ', $sql, 1);
		$sql .= ' LIMIT ' . $m[1];
	}
	// DBMSSQL::applyLimit()'s native-OFFSET/FETCH fallback (for a query it
	// can't rewrite via the ROW_NUMBER derived-table trick, e.g. a
	// UNION/INTERSECT/EXCEPT) -- "OFFSET 0 ROWS" alone (no FETCH NEXT) means
	// an offset with no limit, matching Postgres's plain "OFFSET n" with no
	// LIMIT at all.
	if (preg_match('/ OFFSET (\d+) ROWS(?: FETCH NEXT (\d+) ROWS ONLY)?$/', $sql, $m)) {
		$sql = (string) preg_replace('/ OFFSET \d+ ROWS(?: FETCH NEXT \d+ ROWS ONLY)?$/', '', $sql);
		if (isset($m[2])) {
			$sql .= ' LIMIT ' . $m[2];
		}
		if ((int) $m[1] !== 0) {
			$sql .= ' OFFSET ' . $m[1];
		}
	}
	return $sql;
}

/**
 * Unwraps DBMSSQL::applyLimit()'s ROW_NUMBER()-based derived-table rewrite (used
 * whenever an OFFSET is present) back into a plain "SELECT ... FROM ... LIMIT n
 * OFFSET m" shape -- see normalizeGeneratedSql()'s own docblock. A no-op (returns
 * $sql unchanged) if it isn't in that shape at all, i.e. every non-MSSQL platform
 * and any MSSQL query without an OFFSET.
 *
 * @param      string $sql
 * @return     string
 */
function normalizeMssqlRowNumberOffset(string $sql): string
{
	$pattern = '/^SELECT .*? FROM \(SELECT ROW_NUMBER\(\) OVER\(ORDER BY .*?\) AS \[RowNumber\], (.*?) FROM (.*?)\) AS derived\w+ WHERE RowNumber BETWEEN (\d+) AND (\d+)$/';
	if (!preg_match($pattern, $sql, $m)) {
		return $sql;
	}
	[, $innerColumns, $fromClause, $lowerBound, $upperBound] = $m;
	// Each inner column is aliased as "col AS [alias]" -- except a genuinely
	// empty column (this file's own degenerate "no columns selected at all"
	// unit tests), whose "alias" is the empty string and so isn't
	// bracket-quoted at all (see DBMSSQL::applyLimit()'s own alias-quoting
	// guard), leaving a bare trailing "AS ".
	$columns = (string) preg_replace('/ AS (?:\[[^\]]*\])?/', '', $innerColumns);
	$limit = ((int) $upperBound) - ((int) $lowerBound) + 1;
	$offset = ((int) $lowerBound) - 1;

	return "SELECT $columns FROM $fromClause LIMIT $limit OFFSET $offset";
}
