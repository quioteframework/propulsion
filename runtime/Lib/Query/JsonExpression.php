<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

use Propulsion\Propulsion;

/**
 * Reads a value out of a JSON column at query time -- the query-layer support
 * PLATFORM_FEATURES.md recorded as missing ("JSON columns exist at the DDL
 * level (Pg/MySQL/Oracle/SQLite) but there is no query-side support for
 * `->>`/`JSON_EXTRACT`/`JSON_VALUE`").
 *
 * <code>
 * BookQuery::create()
 *     ->withColumn(JsonExpression::text('Book.Meta', '$.author.name'), 'AuthorName')
 *     ->whereJsonPath('Book.Meta', '$.published', true);
 * </code>
 *
 * One path syntax for all five platforms. The JSONPath-ish spelling
 * (`$.author.name`, `$.tags[0]`) is the one MySQL/MariaDB, SQLite, MSSQL and
 * Oracle already take natively; Postgres, whose operators want a text array,
 * re-renders the same parsed path into `'{author,name}'`. The path is parsed
 * rather than pasted, so a malformed one is an error here and not a syntax
 * error from the server three layers down -- see
 * {@see \Propulsion\Adapter\DBAdapter::parseJsonPath()} for the deliberately
 * small subset supported and why it is small.
 *
 * **text() versus json() is the decision that matters.** `text()` yields the
 * SQL value -- a scalar, unquoted, directly comparable against a bound
 * parameter. `json()` yields the JSON value, which keeps an object or array
 * intact but renders a string *with its quotes*. Comparing a `json()`
 * expression against `'foo'` therefore never matches, which is the single
 * most common JSON-query bug; `text()` is the default everywhere here for
 * that reason.
 */
final class JsonExpression
{
	private function __construct(
		private readonly string $column,
		private readonly string $path,
		private readonly bool $asText,
	) {
	}

	/**
	 * The value at $path as a plain SQL scalar -- unquoted, comparable against
	 * a bound parameter. What you want almost always.
	 *
	 * @param     string $column A column reference: `Model.Property` when handed to
	 *                           {@see ModelCriteria::withColumn()}, which resolves it
	 *                           like any other clause, or a plain `table.COLUMN`.
	 * @param     string $path   e.g. `$.author.name`, `$.tags[0]`, or `$` for the
	 *                           whole document.
	 */
	public static function text(string $column, string $path = '$'): self
	{
		return new self($column, $path, true);
	}

	/**
	 * The value at $path as a JSON value: an object or array stays a document
	 * instead of collapsing, and a string keeps its quotes. Use this to pull
	 * out a nested structure, not to compare against a scalar.
	 */
	public static function json(string $column, string $path = '$'): self
	{
		return new self($column, $path, false);
	}

	public function getColumn(): string
	{
		return $this->column;
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function isText(): bool
	{
		return $this->asText;
	}

	/**
	 * Renders this expression for $dbName's platform.
	 *
	 * @param     ?string $dbName Datasource, defaulting to the default one.
	 *                            {@see ModelCriteria::withColumn()} passes its own,
	 *                            so a query against a non-default datasource is
	 *                            rendered for that platform rather than this one.
	 *
	 * @throws    \Propulsion\Exception\PropulsionException If the platform has no
	 *                            JSON path support, or the path is malformed.
	 */
	public function toSql(?string $dbName = null): string
	{
		return Propulsion::getDB($dbName)->getJsonExtractSql($this->column, $this->path, $this->asText);
	}

	public function __toString(): string
	{
		return $this->toSql();
	}
}
