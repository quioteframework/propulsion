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
 * A raw SQL expression to use as an UPDATE value, so the new value can be computed
 * by the database from the row's current value (e.g. incrementing a counter)
 * instead of being read, modified, and written back from PHP -- which is both an
 * extra round trip and a lost-update race under concurrency.
 *
 * Pass an instance as the value for a column in ModelCriteria::update():
 * <code>
 * BookQuery::create()
 *     ->filterById(123)
 *     ->update(array('ViewCount' => ColumnExpression::raw(BookPeer::VIEW_COUNT . ' + ?', 1)));
 * // SET VIEW_COUNT = book.VIEW_COUNT + 1
 * </code>
 *
 * Only supported on ModelCriteria::update()'s single-query path (the default);
 * $forceIndividualSaves cannot honor it, since that path hydrates rows into PHP
 * objects and re-saves them one at a time.
 */
final class ColumnExpression
{
	private function __construct(
		public readonly string $expression,
		public readonly mixed $value
	) {
	}

	/**
	 * @param      string $expression Raw SQL fragment, referencing the target column (and/or
	 *                                other columns) by its fully-qualified name, e.g.
	 *                                "book.VIEW_COUNT + ?". At most one "?" placeholder is
	 *                                supported; bind it via $value.
	 * @param      mixed $value Optional single value to bind to the "?" placeholder in $expression.
	 *
	 * @return     ColumnExpression
	 */
	public static function raw(string $expression, mixed $value = null): self
	{
		return new self($expression, $value);
	}

	/**
	 * @internal Converts to the array shape BasePeer::doUpdate() expects for a
	 * Criteria::CUSTOM_EQUAL column value (see Criteria::add()).
	 *
	 * @return     array<string, mixed>
	 */
	public function toRawValueArray(): array
	{
		return $this->value === null
			? array('raw' => $this->expression)
			: array('raw' => $this->expression, 'value' => $this->value);
	}
}
