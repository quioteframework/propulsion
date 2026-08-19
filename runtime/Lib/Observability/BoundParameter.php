<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

/**
 * One value bound to a statement, together with the column it was bound for
 * -- when that is known.
 *
 * `$table`/`$column` are null in exactly the cases
 * {@see \Propulsion\Adapter\DBAdapter::bindValues()} itself has no column to
 * report: a value with no table association at all (a computed/raw value in
 * a `Criterion` that never named a column), or a value bound directly via
 * `PDOStatement::bindValue()` from outside the ORM's own SQL-building path
 * (raw/manual PDO code). A `null` *value* still reports its column normally
 * -- only the column identity, not the bound value, can be genuinely
 * unknown.
 */
final readonly class BoundParameter
{
	public function __construct(
		public mixed $value,
		public ?string $table = null,
		public ?string $column = null,
	) {
	}
}
