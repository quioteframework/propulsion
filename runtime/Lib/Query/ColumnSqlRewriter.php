<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

use Propulsion\Adapter\DBAdapter;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Propulsion;

/**
 * Resolves a query-builder column reference back to its {@see ColumnMap} and
 * asks the adapter whether that column's SQL needs rewriting -- the shared
 * "which ColumnMap is this?" half of {@see DBAdapter::getColumnBindExpression()}
 * /{@see DBAdapter::getColumnSelectExpression()}, used from all four places
 * that build SQL around a column: {@see \Propulsion\Util\BasePeer}'s INSERT
 * value list, its UPDATE/upsert SET clause, {@see DBAdapter::createSelectSqlPart()}'s
 * SELECT list, and {@see Criterion}'s WHERE comparisons.
 *
 * Why this exists at all: a handful of native column types cannot be read or
 * written through a plain parameterized bind. MariaDB's real `VECTOR(n)`
 * rejects a bound bracket-JSON string outright and needs `VEC_FromText(?)`
 * around the value going in and `VEC_ToText(col)` around the column coming
 * back; every platform's real geometry type needs the same treatment with its
 * own `ST_GeomFromText()`/`ST_AsText()` pair. Both were previously shipped as
 * text emulations *specifically* because this codebase had nowhere to hook
 * that rewrite -- see the VECTOR and GEOMETRY entries in PLATFORM_FEATURES.md.
 *
 * Everything here is gated behind {@see DBAdapter::usesColumnSqlRewriting()},
 * which is false for every adapter that has no such column type, so a query on
 * an ordinary schema pays one bool check per statement and never reaches this
 * class at all.
 *
 * A reference this class cannot resolve to a real mapped column -- a computed
 * expression, an alias, a table not in the DatabaseMap -- is returned
 * unchanged rather than treated as an error: the query builder legitimately
 * carries raw SQL fragments in all the same slots as plain column names, and
 * "leave it alone" is the correct answer for every one of them.
 */
final class ColumnSqlRewriter
{
	/**
	 * A plain, fully-qualified column reference and nothing else: identifier
	 * segments separated by dots, with at least one dot. Deliberately rejects
	 * anything carrying a space, parenthesis, operator or quote, since those
	 * are the raw-SQL escape hatches (`MAX(book.PRICE)`, `book.A || book.B`,
	 * `"book"."TITLE"`) whose ColumnMap -- if they even have exactly one --
	 * cannot be read off the string.
	 */
	private const QUALIFIED_COLUMN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+$/';

	/**
	 * Wraps $placeholder (a `:pN` bound-parameter reference) in whatever
	 * conversion function the adapter needs in order to write a value into
	 * $table.$column, or returns it unchanged when no rewriting applies.
	 *
	 * @param     string  $dbName Datasource name, for the DatabaseMap lookup.
	 * @param     ?string $table  Real (alias-resolved) table name, or null for
	 *                            an unqualified/aliased column reference, which
	 *                            can't be resolved and is left alone.
	 */
	public static function bind(DBAdapter $db, string $dbName, ?string $table, ?string $column, string $placeholder): string
	{
		$cMap = self::resolve($dbName, $table, $column);

		return $cMap === null ? $placeholder : $db->getColumnBindExpression($cMap, $placeholder);
	}

	/**
	 * Wraps a SELECT-list entry in whatever conversion function the adapter
	 * needs in order to read $expression back as the wire format the generated
	 * hydration code expects, or returns it unchanged.
	 *
	 * $criteria is consulted only to resolve a table *alias* ("b.EMBEDDING")
	 * to the real table the DatabaseMap knows; the emitted SQL keeps the alias,
	 * since that is what is actually in scope in the query.
	 */
	public static function select(DBAdapter $db, Criteria $criteria, string $expression): string
	{
		if (preg_match(self::QUALIFIED_COLUMN, $expression) !== 1) {
			return $expression;
		}

		$dotPos = strrpos($expression, '.');
		if ($dotPos === false) {
			return $expression;
		}

		$tableRef = substr($expression, 0, $dotPos);
		$column = substr($expression, $dotPos + 1);
		$table = $criteria->getTableForAlias($tableRef) ?? $tableRef;

		$cMap = self::resolve($criteria->getDbName(), $table, $column);

		return $cMap === null ? $expression : $db->getColumnSelectExpression($cMap, $expression);
	}

	/**
	 * The ColumnMap for $table.$column, or null when there isn't exactly one to
	 * be had -- see the class docblock on why that is a normal outcome and not
	 * an error.
	 */
	private static function resolve(string $dbName, ?string $table, ?string $column): ?ColumnMap
	{
		if ($table === null || $column === null || $table === '' || $column === '') {
			return null;
		}

		try {
			$dbMap = Propulsion::getDatabaseMap($dbName);
		} catch (PropulsionException) {
			// No map registered for this datasource (e.g. a Criteria built
			// against a name nothing was initialized under). Nothing to rewrite.
			return null;
		}

		if (!$dbMap->hasTable($table)) {
			return null;
		}

		$tableMap = $dbMap->getTable($table);

		return $tableMap->hasColumn($column, false) ? $tableMap->getColumn($column, false) : null;
	}
}
