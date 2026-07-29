<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

/**
 * A fluent builder for "<function>(...) OVER (PARTITION BY ... ORDER BY ... <frame>)"
 * window-function expressions, so callers no longer have to hand-write the OVER clause
 * as a raw string. Pass the built expression (or its toSql()/(string) form) to
 * ModelCriteria::withColumn(), the same escape hatch a raw window-function string
 * already worked through -- this only adds ergonomics and Model.Column name-replacement
 * on top, not new SQL-generation capability withColumn() didn't already have:
 * <code>
 * $c = BookQuery::create()->withColumn(
 *     WindowExpression::rowNumber()->partitionBy('Book.PublisherId')->orderBy('Book.Price', 'DESC'),
 *     'PriceRank'
 * );
 * // ROW_NUMBER() OVER (PARTITION BY book.PUBLISHER_ID ORDER BY book.PRICE DESC) AS PriceRank
 * </code>
 *
 * Column/expression arguments (the function's own argument, partitionBy()/orderBy()'s
 * column names) are plain SQL fragments, not bound parameters -- the same trust model
 * ColumnExpression::raw()'s $expression already has, since a window function's argument
 * list and OVER clause can only ever reference columns/expressions, never literal values
 * a caller would want parameterized.
 */
final class WindowExpression
{
	/** @var array<int, string> */
	private array $partitionBy = array();

	/** @var array<int, string> */
	private array $orderBy = array();

	private ?string $frame = null;

	private function __construct(private readonly string $function)
	{
	}

	/**
	 * Builds an arbitrary window function call from a raw SQL fragment, for anything
	 * without its own named factory method below (e.g. a vendor-specific function, or
	 * a plain aggregate not listed here).
	 *
	 * @param      string $function Raw SQL, e.g. "SUM(book.PRICE)" or "PERCENT_RANK()".
	 * @return     self
	 */
	public static function raw(string $function): self
	{
		return new self($function);
	}

	public static function rowNumber(): self
	{
		return new self('ROW_NUMBER()');
	}

	public static function rank(): self
	{
		return new self('RANK()');
	}

	public static function denseRank(): self
	{
		return new self('DENSE_RANK()');
	}

	public static function percentRank(): self
	{
		return new self('PERCENT_RANK()');
	}

	public static function ntile(int $buckets): self
	{
		return new self("NTILE($buckets)");
	}

	/**
	 * @param      string $column Fully-qualified column name, e.g. "book.PRICE".
	 * @param      int $offset Number of rows back to look, defaults to 1.
	 */
	public static function lag(string $column, int $offset = 1): self
	{
		return new self("LAG($column, $offset)");
	}

	/**
	 * @param      string $column Fully-qualified column name, e.g. "book.PRICE".
	 * @param      int $offset Number of rows ahead to look, defaults to 1.
	 */
	public static function lead(string $column, int $offset = 1): self
	{
		return new self("LEAD($column, $offset)");
	}

	public static function firstValue(string $column): self
	{
		return new self("FIRST_VALUE($column)");
	}

	public static function lastValue(string $column): self
	{
		return new self("LAST_VALUE($column)");
	}

	public static function sum(string $column): self
	{
		return new self("SUM($column)");
	}

	public static function avg(string $column): self
	{
		return new self("AVG($column)");
	}

	public static function count(string $column = '*'): self
	{
		return new self("COUNT($column)");
	}

	public static function min(string $column): self
	{
		return new self("MIN($column)");
	}

	public static function max(string $column): self
	{
		return new self("MAX($column)");
	}

	/**
	 * Adds column(s) to PARTITION BY. Chainable/cumulative -- repeated calls append
	 * rather than replace.
	 *
	 * @param      string ...$columns Fully-qualified column names.
	 * @return     $this
	 */
	public function partitionBy(string ...$columns): static
	{
		$this->partitionBy = array_values(array(...$this->partitionBy, ...$columns));
		return $this;
	}

	/**
	 * Adds a column to ORDER BY. Chainable/cumulative -- repeated calls append further
	 * ORDER BY columns, same as Criteria::addAscendingOrderByColumn() would.
	 *
	 * @param      string $column Fully-qualified column name.
	 * @param      string $direction "ASC" or "DESC".
	 * @return     $this
	 */
	public function orderBy(string $column, string $direction = 'ASC'): static
	{
		$this->orderBy[] = trim($column . ' ' . $direction);
		return $this;
	}

	/**
	 * Sets a "ROWS BETWEEN $start AND $end" frame clause. Common bounds: "UNBOUNDED
	 * PRECEDING", "CURRENT ROW", "UNBOUNDED FOLLOWING", or "<n> PRECEDING"/"<n> FOLLOWING"
	 * (see preceding()/following()). Mutually exclusive with rangeBetween() -- the later
	 * call wins.
	 *
	 * @param      string $start
	 * @param      string $end
	 * @return     $this
	 */
	public function rowsBetween(string $start, string $end): static
	{
		$this->frame = "ROWS BETWEEN $start AND $end";
		return $this;
	}

	/**
	 * Sets a "RANGE BETWEEN $start AND $end" frame clause. See rowsBetween() for the
	 * common bound values; RANGE compares peer rows by logical value rather than
	 * physical row position.
	 *
	 * @param      string $start
	 * @param      string $end
	 * @return     $this
	 */
	public function rangeBetween(string $start, string $end): static
	{
		$this->frame = "RANGE BETWEEN $start AND $end";
		return $this;
	}

	/** @return     string "<n> PRECEDING", for use as a rowsBetween()/rangeBetween() bound. */
	public static function preceding(int $n): string
	{
		return "$n PRECEDING";
	}

	/** @return     string "<n> FOLLOWING", for use as a rowsBetween()/rangeBetween() bound. */
	public static function following(int $n): string
	{
		return "$n FOLLOWING";
	}

	public const UNBOUNDED_PRECEDING = 'UNBOUNDED PRECEDING';
	public const UNBOUNDED_FOLLOWING = 'UNBOUNDED FOLLOWING';
	public const CURRENT_ROW = 'CURRENT ROW';

	public function toSql(): string
	{
		$over = array();
		if ($this->partitionBy) {
			$over[] = 'PARTITION BY ' . implode(', ', $this->partitionBy);
		}
		if ($this->orderBy) {
			$over[] = 'ORDER BY ' . implode(', ', $this->orderBy);
		}
		if ($this->frame !== null) {
			$over[] = $this->frame;
		}

		return $this->function . ' OVER (' . implode(' ', $over) . ')';
	}

	public function __toString(): string
	{
		return $this->toSql();
	}
}
