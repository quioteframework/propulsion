<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Normalizes a few MySQL-specific SQL-shape differences in a generated SQL string, so
 * hardcoded Postgres-style expected-SQL test assertions can be compared against the
 * *actual* SQL a test produced regardless of which adapter is active. Covers:
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
 *
 * Deliberately does *not* attempt to normalize every possible platform SQL-shape
 * difference (e.g. id-generation strategy changing which columns an INSERT's column
 * list contains) -- those need a genuinely different expected string per platform, not
 * a stable text rewrite; see KNOWN_ISSUES.md's "MySQL parity" section for the ones
 * left as-is and why.
 *
 * @param      string $sql
 * @return     string
 */
function normalizeGeneratedSql(string $sql): string
{
	$sql = (string) preg_replace('/`([^`]*)`/', '$1', $sql);
	$sql = (string) preg_replace('/\bLIMIT (\d+), (\d+)/', 'LIMIT $2 OFFSET $1', $sql);
	$sql = (string) preg_replace('/\bDELETE \w+ FROM\b/', 'DELETE FROM', $sql);
	return $sql;
}
