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
 * JSON/JSONB columns decode (via json_decode()) to whatever shape the stored
 * document actually has -- an array, a scalar, or null -- so, like OBJECT,
 * no single PHP type fits every possible value; `mixed` is used for the same
 * reason.
 */
class JsonHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isJsonType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		return 'mixed';
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		// See BaseObject::decodeJsonColumn() -- throws a PropulsionException
		// (rather than silently returning null, as a bare json_decode() call
		// would on malformed input) so bad data in the database surfaces as
		// a loud failure at hydration time instead of a confusing null
		// downstream.
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
