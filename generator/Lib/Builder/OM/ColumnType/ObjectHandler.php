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
 * OBJECT columns store an arbitrary, caller-supplied PHP object (serialized
 * on save, unserialized on hydrate). PropulsionTypes::getPhpNative() maps
 * OBJECT to an empty string (no single PHP class fits every possible stored
 * object), so `mixed` is used for the property/getter/setter.
 */
class ObjectHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->getType() === PropulsionTypes::OBJECT;
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		return 'mixed';
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		return 'unserialize($v)';
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return "{$phpAccessExpr} === null ? null : serialize({$phpAccessExpr})";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return $varExpr;
	}
}
