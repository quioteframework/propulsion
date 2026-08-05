<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

/**
 * A vector *distance* expression -- the thing an embedding column exists to be
 * queried with, and the half of VECTOR support PLATFORM_FEATURES.md's own
 * "Vector types" entry scoped out as query-layer work ("`<=>`/`<->` distance
 * operators ... are explicitly out of scope here -- this item is DDL +
 * hydration only").
 *
 * Every platform spells the same four metrics differently -- pgvector as
 * infix operators (`<->`, `<=>`, `<#>`, `<+>`), MariaDB as function calls
 * (`VEC_DISTANCE_EUCLIDEAN(a, b)`) -- so the SQL is produced by
 * {@see \Propulsion\Adapter\DBAdapter::getVectorDistanceSql()} rather than
 * hard-coded here, and this class is the fluent front end plus the literal
 * formatting:
 *
 * <code>
 * $query = DocumentQuery::create()
 *     ->withColumn(VectorExpression::l2Distance('Document.Embedding', $needle), 'Distance')
 *     ->orderBy('Distance')
 *     ->limit(10);
 * </code>
 *
 * **The query vector is a literal, not a bound parameter.** That is a
 * deliberate departure from how this query builder treats every other value,
 * and it is safe for the specific reason that makes it necessary: an ANN index
 * scan needs the operand visible to the planner, and the operand here is a
 * fixed-length list of numbers this class formats itself from PHP floats.
 * Non-numeric input is rejected outright rather than escaped, so there is no
 * string for a caller to smuggle anything through -- see {@see literal()}.
 */
final class VectorExpression
{
	/** Euclidean / L2 distance. pgvector `<->`, MariaDB `VEC_DISTANCE_EUCLIDEAN`. */
	public const L2 = 'l2';

	/** Cosine distance (1 - cosine similarity). pgvector `<=>`, MariaDB `VEC_DISTANCE_COSINE`. */
	public const COSINE = 'cosine';

	/**
	 * Negative inner product. pgvector `<#>` -- negated so that "smaller is
	 * closer" holds for it as it does for the other metrics, which is what
	 * lets an index use it; the actual inner product is its negation.
	 */
	public const INNER_PRODUCT = 'inner_product';

	/** Taxicab / L1 distance. pgvector `<+>` (0.7+). */
	public const L1 = 'l1';

	/**
	 * @param     string $column A column reference: a fully-qualified
	 *                           `table.COLUMN` for a plain Criteria, or a
	 *                           `Model.Property` name when handed to
	 *                           {@see ModelCriteria::withColumn()}, which
	 *                           resolves those the same way it does inside any
	 *                           other raw clause.
	 * @param     string $vector The already-formatted vector literal.
	 * @param     string $metric One of the class constants above.
	 */
	private function __construct(
		private readonly string $column,
		private readonly string $vector,
		private readonly string $metric,
	) {
	}

	/**
	 * @param     string                        $column
	 * @param     array<int, float|int>|string  $vector A list of numbers, or an
	 *                                          already-formatted `"[1,2,3]"` literal.
	 */
	public static function l2Distance(string $column, array|string $vector): self
	{
		return new self($column, self::literal($vector), self::L2);
	}

	/**
	 * @param     array<int, float|int>|string $vector
	 */
	public static function cosineDistance(string $column, array|string $vector): self
	{
		return new self($column, self::literal($vector), self::COSINE);
	}

	/**
	 * @param     array<int, float|int>|string $vector
	 */
	public static function innerProduct(string $column, array|string $vector): self
	{
		return new self($column, self::literal($vector), self::INNER_PRODUCT);
	}

	/**
	 * @param     array<int, float|int>|string $vector
	 */
	public static function l1Distance(string $column, array|string $vector): self
	{
		return new self($column, self::literal($vector), self::L1);
	}

	/**
	 * Formats a vector as the bracketed comma-separated literal every platform
	 * here reads and writes (`"[0.1,0.2,0.3]"`) -- the same wire format
	 * VectorHandler already hydrates through, which is why an
	 * already-formatted string is accepted and passed along unchanged.
	 *
	 * A list input is validated element by element and re-formatted from the
	 * parsed float, so nothing a caller supplies survives into the SQL as
	 * text. A string input is validated against the literal's own grammar for
	 * the same reason -- it is a convenience for a value that came back out of
	 * a hydrated column, not an escape hatch.
	 *
	 * The array element type is `mixed` rather than `float|int` on purpose:
	 * these checks are the class's whole safety story, and typing the
	 * parameter as already-validated would make a static analyser treat them
	 * as dead code. The public factory methods above advertise the narrow
	 * type, which is where callers read it from.
	 *
	 * @param     array<array-key, mixed>|string $vector
	 *
	 * @throws    PropulsionException If any element is not a number.
	 */
	public static function literal(array|string $vector): string
	{
		if (is_string($vector)) {
			if (preg_match('/^\[\s*(-?\d+(\.\d+)?([eE][-+]?\d+)?\s*(,\s*)?)*\]$/', $vector) !== 1) {
				throw new PropulsionException(
					'VectorExpression: "' . $vector . '" is not a vector literal. Pass an array of numbers, '
					. 'or a bracketed comma-separated number list such as "[0.1,0.2]".'
				);
			}

			return $vector;
		}

		$parts = array();
		foreach ($vector as $i => $element) {
			if (!is_int($element) && !is_float($element)) {
				throw new PropulsionException(
					'VectorExpression: element ' . $i . ' of the query vector is a ' . get_debug_type($element)
					. ', not a number. The vector is written into the SQL as a literal, so every element must be numeric.'
				);
			}
			if (is_float($element) && (is_nan($element) || is_infinite($element))) {
				throw new PropulsionException(
					'VectorExpression: element ' . $i . ' of the query vector is ' . var_export($element, true)
					. ', which no vector type can represent.'
				);
			}
			// var_export round-trips a float without locale-dependent decimal
			// separators, which (string) casting does not.
			$parts[] = is_int($element) ? (string) $element : var_export((float) $element, true);
		}

		return '[' . implode(',', $parts) . ']';
	}

	public function getColumn(): string
	{
		return $this->column;
	}

	public function getMetric(): string
	{
		return $this->metric;
	}

	public function getVectorLiteral(): string
	{
		return $this->vector;
	}

	/**
	 * Renders this expression for $dbName's platform.
	 *
	 * @param     ?string $dbName Datasource, defaulting to the default one --
	 *                            supplied by {@see ModelCriteria::withColumn()}
	 *                            when it converts one of these, so a query
	 *                            against a non-default datasource gets that
	 *                            platform's spelling rather than the default
	 *                            one's.
	 *
	 * @throws    PropulsionException If the platform has no vector distance operator.
	 */
	public function toSql(?string $dbName = null): string
	{
		return Propulsion::getDB($dbName)->getVectorDistanceSql($this->column, $this->vector, $this->metric);
	}

	public function __toString(): string
	{
		return $this->toSql();
	}
}
