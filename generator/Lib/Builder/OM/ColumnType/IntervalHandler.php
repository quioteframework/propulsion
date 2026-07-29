<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM\ColumnType;

use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Model\Column;

/**
 * INTERVAL columns -- stored as an ISO-8601 duration string on every
 * platform, hydrate to/from a real DateInterval object.
 */
class IntervalHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isIntervalType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		$builder->declareClass('\\DateInterval');
		return '?DateInterval';
	}

	public function isConstructorDeferredDefault(Column $col): bool
	{
		return true;
	}

	public function wrapDefaultValueAssignment(Column $col, string $defaultValueExpr, ObjectBuilder $builder): ?string
	{
		// $defaultValueExpr is a quoted ISO-8601 duration string literal --
		// DateInterval's constructor parses that directly.
		$builder->declareClass('\\DateInterval');
		return "new \\DateInterval({$defaultValueExpr})";
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		// $v is an ISO-8601 duration string on every platform.
		return 'new DateInterval($v)';
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		// Fixed-specifier format() always yields a valid ISO-8601 duration
		// string (leading zero components, e.g. "P0Y0M1DT2H3M4S", are legal
		// ISO-8601 and round-trip cleanly through `new DateInterval()`).
		return "{$phpAccessExpr} === null ? null : {$phpAccessExpr}->format('P%yY%mM%dDT%hH%iM%sS')";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		// The setter is strictly typed ?DateInterval -- unlike temporal
		// columns, DateInterval has no int-timestamp input mode, so the
		// generic numeric/string casts would build invalid PHP here. An
		// already-DateInterval value passes through; an ISO-8601 duration
		// string is parsed into one; anything else becomes null.
		return "({$varExpr} instanceof \\DateInterval ? {$varExpr} : (is_string({$varExpr}) ? new \\DateInterval({$varExpr}) : null))";
	}
}
