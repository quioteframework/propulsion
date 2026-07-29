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
 * Postgres range columns (int4range, tstzrange, ...) -- stored as a range
 * literal string (e.g. "[1,10)") on every platform, hydrate to/from a real
 * Propulsion\Type\Range value object.
 */
class RangeHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isRangeType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		$builder->declareClass('\\Propulsion\\Type\\Range');
		return '?Range';
	}

	public function isConstructorDeferredDefault(Column $col): bool
	{
		return true;
	}

	public function wrapDefaultValueAssignment(Column $col, string $defaultValueExpr, ObjectBuilder $builder): ?string
	{
		// $defaultValueExpr is a quoted range literal string (e.g. "[1,10)").
		$builder->declareClass('\\Propulsion\\Type\\Range');
		return "Range::parse({$defaultValueExpr})";
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		// $v is a Postgres range literal on every platform -- emulated
		// platforms store the same text Column::isRangeType()-driven columns
		// write.
		return 'Range::parse($v)';
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return "{$phpAccessExpr} === null ? null : (string) {$phpAccessExpr}";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		// The setter is strictly typed ?Range.
		return "({$varExpr} instanceof \\Propulsion\\Type\\Range ? {$varExpr} : (is_string({$varExpr}) ? \\Propulsion\\Type\\Range::parse({$varExpr}) : null))";
	}
}
