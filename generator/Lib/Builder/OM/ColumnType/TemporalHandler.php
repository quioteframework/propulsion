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
 * DATE/TIME/TIMESTAMP columns -- hydrate to/from a real DateTimeInterface
 * instance. No buildValueExpr() override: buildCriteria()/buildPkeyCriteria()
 * pass the DateTimeInterface object straight through to Criteria::add()
 * unconverted, same as any plain scalar column.
 */
class TemporalHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isTemporalType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		$builder->declareClass('\\DateTimeInterface');
		return '?DateTimeInterface';
	}

	public function isConstructorDeferredDefault(Column $col): bool
	{
		return true;
	}

	public function wrapDefaultValueAssignment(Column $col, string $defaultValueExpr, ObjectBuilder $builder): ?string
	{
		$builder->declareClass('\\DateTime');
		return "new \\DateTime({$defaultValueExpr})";
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		return 'new DateTime($v)';
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		// The mutator accepts DateTimeInterface|string|int|null. A mixed
		// caller-supplied value already of one of those types is passed
		// through unchanged (the common case); anything else scalar is
		// stringified, and non-scalar/non-DateTimeInterface values (which
		// the setter can't handle anyway) become null rather than fataling
		// on an invalid (string) cast of an object.
		return "({$varExpr} === null || {$varExpr} instanceof \\DateTimeInterface || is_int({$varExpr}) || is_string({$varExpr}) ? {$varExpr} : (is_scalar({$varExpr}) ? (string) {$varExpr} : null))";
	}
}
