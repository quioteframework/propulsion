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
use Propulsion\Generator\Model\PropulsionTypes;

/**
 * BOOLEAN columns. `applies()` uses the broader `isBooleanType()` (BOOLEAN
 * or the Oracle-only emulated BOOLEAN_EMU alias -- see
 * `OraclePlatform::initialize()`), matching the original
 * `getColumnValueCastExpr()` check; `buildHydrateExpr()` narrows to exactly
 * `PropulsionTypes::BOOLEAN` and returns null (deferring to the generic
 * numeric/string fallback) for BOOLEAN_EMU, faithfully preserving the
 * original `addHydrate()`'s narrower check there rather than "fixing" what
 * may be a real pre-existing discrepancy -- out of scope for a pure
 * relocation of existing behavior.
 */
class BooleanHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isBooleanType();
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		if ($col->getType() !== PropulsionTypes::BOOLEAN) {
			return null;
		}
		return '(bool) $v';
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return "({$varExpr} === null ? null : (bool) {$varExpr})";
	}
}
