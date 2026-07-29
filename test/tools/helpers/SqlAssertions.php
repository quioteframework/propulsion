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
 * - Oracle DELETE with a table alias: Oracle has no "AS" keyword for a table
 *   alias at all (see DBOracle::getDeleteFromClause()), so its own form is
 *   "DELETE FROM book b WHERE ..." (no "AS"). Rewritten back to Postgres/
 *   MySQL's "DELETE FROM book AS b WHERE ..." form.
 * - MSSQL LIMIT without OFFSET: DBMSSQL::applyLimit() emits "SELECT TOP n ..."
 *   instead of a trailing "... LIMIT n". Rewritten to the Postgres-style form.
 * - MSSQL aliased UPDATE: "UPDATE table alias SET ..." (every other platform) is
 *   a syntax error in T-SQL, which instead needs "UPDATE alias SET ... FROM
 *   table AS alias ..." (see DBAdapter::getUpdateTargetSql()/
 *   getUpdateFromClauseSql()). Rewritten back to the plain "UPDATE table alias
 *   SET ..." form.
 * - MSSQL LIMIT/OFFSET: DBMSSQL::applyLimit() emits a trailing native
 *   "OFFSET n ROWS [FETCH NEXT m ROWS ONLY]" clause (SQL Server 2012+),
 *   injecting a synthetic "ORDER BY (SELECT NULL)" first when the query has no
 *   ORDER BY of its own (required by T-SQL's OFFSET/FETCH syntax). Both are
 *   stripped/rewritten back to Postgres-style "LIMIT m [OFFSET n]".
 * - Oracle LIMIT/OFFSET: DBOracle::applyLimit() emits the same ANSI SQL:2008
 *   OFFSET/FETCH clause as MSSQL (Oracle 12c+), with the identical synthetic
 *   no-op ORDER BY trick when the query has none of its own -- except Oracle
 *   requires a FROM on every query (including this one), so its version is
 *   "ORDER BY (SELECT NULL FROM dual)", not MSSQL's bare "(SELECT NULL)".
 *   Handled by the same MSSQL OFFSET/FETCH rewrite below.
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
	$sql = (string) preg_replace('/^DELETE FROM (\w+) (\w+) WHERE\b/', 'DELETE FROM $1 AS $2 WHERE', $sql);
	$sql = (string) preg_replace('/^UPDATE (\w+) SET (.+) FROM (\w+) AS \1 WHERE/', 'UPDATE $3 $1 SET $2 WHERE', $sql);
	if (preg_match('/^SELECT TOP (\d+) /', $sql, $m)) {
		$sql = (string) preg_replace('/^SELECT TOP \d+ /', 'SELECT ', $sql, 1);
		$sql .= ' LIMIT ' . $m[1];
	}
	// DBMSSQL::applyLimit()'s native OFFSET/FETCH clause -- "OFFSET 0 ROWS"
	// alone (no FETCH NEXT) means an offset with no limit, matching Postgres's
	// plain "OFFSET n" with no LIMIT at all. The synthetic "ORDER BY (SELECT
	// NULL)" applyLimit() injects when the query has no ORDER BY of its own is
	// stripped along with it, since it carries no real ordering to preserve.
	// DBOracle::applyLimit() emits the same ANSI SQL:2008 OFFSET/FETCH clause
	// (12c+) with the identical no-op-ORDER-BY trick, except Oracle requires a
	// FROM on every query (including this one), so its synthetic ORDER BY is
	// "(SELECT NULL FROM dual)", not MSSQL's bare "(SELECT NULL)" -- both
	// variants are stripped here.
	if (preg_match('/(?: ORDER BY \(SELECT NULL(?: FROM dual)?\))? OFFSET (\d+) ROWS(?: FETCH NEXT (\d+) ROWS ONLY)?$/', $sql, $m)) {
		$sql = (string) preg_replace('/(?: ORDER BY \(SELECT NULL(?: FROM dual)?\))? OFFSET \d+ ROWS(?: FETCH NEXT \d+ ROWS ONLY)?$/', '', $sql);
		if (isset($m[2])) {
			$sql .= ' LIMIT ' . $m[2];
		}
		if ((int) $m[1] !== 0) {
			$sql .= ' OFFSET ' . $m[1];
		}
	}
	return $sql;
}
