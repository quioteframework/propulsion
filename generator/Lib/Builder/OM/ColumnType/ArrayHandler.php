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
 * PHP_ARRAY columns, covering both the default emulated `" | "`-delimited
 * text format and the opt-in Postgres-native `type[]` format
 * (`Column::isNativeArrayStorage()`).
 */
class ArrayHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->getType() === PropulsionTypes::PHP_ARRAY;
	}

	public function getEmptyValueLiteral(Column $col): ?string
	{
		// See ObjectBuilder::addProperties() -- array columns default to an
		// empty array, not null, absent an explicit schema default.
		return 'array()';
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		if ($col->isNativeArrayStorage()) {
			// $v is a Postgres array literal (e.g. "{a,\"b,c\",NULL}").
			$builder->declareClass('\\Propulsion\\Type\\PgArray');
			return 'PgArray::decode($v)';
		}
		return "(\$v === '' ? array() : (preg_match('/^ \\| (.*) \\| $/s', \$v, \$matches) ? explode(' | ', \$matches[1]) : explode(' | ', \$v)))";
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		if ($col->isNativeArrayStorage()) {
			$builder->declareClass('\\Propulsion\\Type\\PgArray');
			return "PgArray::encode({$phpAccessExpr})";
		}
		return "{$phpAccessExpr} ? ' | ' . implode(' | ', {$phpAccessExpr}) . ' | ' : ''";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		// The setter already accepts a broader union or needs value-specific
		// handling; passing the raw value through preserves existing
		// behavior for this rare/exotic primary key type.
		return $varExpr;
	}

	public function hasArrayElementConvenienceMethods(Column $col): bool
	{
		return true;
	}
}
