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
 * A `phpType="\BcMath\Number"` DECIMAL/NUMERIC column (PHP 8.4+) -- hydrates
 * to/from BcMath\Number instead of a plain string.
 *
 * No wrapDefaultValueAssignment() override: `getDefaultValueString()`
 * already returns a full `new \BcMath\Number(...)` instantiation expression
 * for this column (its phpType isn't a PHP primitive), so the plain
 * assignment `applyDefaultValues()` uses by default is already correct.
 */
class BcMathNumberHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isBcMathNumberType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		$builder->declareClass('\\BcMath\\Number');
		return '?Number';
	}

	public function isConstructorDeferredDefault(Column $col): bool
	{
		return true;
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		// isNumericType()'s generic cast builds a `($castType) $v` cast
		// expression -- "(BcMath\Number) $v" isn't legal cast syntax, so
		// this needs its own branch checked before it.
		return 'new Number((string) $v)';
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return "{$phpAccessExpr} === null ? null : (string) {$phpAccessExpr}";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		// The mutator already accepts BcMath\Number|string|int|float|null
		// and normalizes internally, so the raw value passes straight
		// through -- the generic numeric cast would otherwise build an
		// invalid "(\BcMath\Number) $expr" cast.
		return $varExpr;
	}
}
