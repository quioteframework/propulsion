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
 * SET columns -- a comma-joined string of selected labels on every platform
 * (MySQL's own native SET included -- PDO returns it as a plain string, not
 * an array), so no platform branching is needed, unlike PHP_ARRAY's
 * native-vs-emulated split.
 */
class SetHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isSetType();
	}

	public function getEmptyValueLiteral(Column $col): ?string
	{
		return 'array()';
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		return "(\$v === '' ? array() : explode(',', \$v))";
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return "implode(',', {$phpAccessExpr})";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return $varExpr;
	}

	public function hasArrayElementConvenienceMethods(Column $col): bool
	{
		return true;
	}
}
