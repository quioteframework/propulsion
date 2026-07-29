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
 * VECTOR columns (pgvector, MariaDB/MySQL VECTOR, JSON-array-emulated
 * elsewhere) -- a vector's wire format is a bracketed, comma-separated
 * number list, which is already valid JSON, so this reuses the same
 * decode/encode helper JSON/JSONB columns use rather than PHP_ARRAY's
 * `" | "`-delimited format or a dedicated codec.
 *
 * No getPhpTypeHint()/getEmptyValueLiteral() override: a VECTOR column's
 * native PHP type is already plain `array` via the generic
 * Column::getPhpType() mapping, and (unlike PHP_ARRAY/SET) it has never had
 * an "empty array by default" property-declaration special case -- an
 * unset VECTOR column defaults to null, same as any other nullable scalar.
 * Preserved here as-is rather than "fixed", to keep this refactor a pure
 * relocation of existing behavior.
 */
class VectorHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isVectorType();
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		return "self::decodeJsonColumn(\$v, '" . $col->getName() . "')";
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return "{$phpAccessExpr} === null ? null : self::encodeJsonColumn({$phpAccessExpr}, '" . $col->getName() . "')";
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return $varExpr;
	}
}
