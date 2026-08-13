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
		// $v is a raw PDO row value, so mixed as far as static analysis is
		// concerned, and the pipe-delimited decoding below is string work. A
		// non-string cannot be a serialized array, and the empty-string case
		// already means "no elements", so both collapse to an empty array.
		return "(is_string(\$v) ? (\$v === '' ? array() : (preg_match('/^ \\| (.*) \\| $/s', \$v, \$matches) ? explode(' | ', \$matches[1]) : explode(' | ', \$v))) : array())";
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
		// $varExpr is a mixed value (a setByPosition()/fromArray() element), and
		// the setter takes ?array. There is no meaningful cast from a scalar to
		// an array column's value, so anything that is not already an array
		// becomes null -- the same shape the generic fallback in
		// ObjectBuilder::getColumnValueCastExpr() uses for its own types.
		return "(is_array($varExpr) ? $varExpr : null)";
	}

	public function hasArrayElementConvenienceMethods(Column $col): bool
	{
		return true;
	}
}
