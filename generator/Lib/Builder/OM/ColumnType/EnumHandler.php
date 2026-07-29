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
 * ENUM columns, covering all four combinations of `enumClass`/`nativeEnum`:
 *
 * - Plain (neither set): the property holds the emulated integer index as a
 *   string; hydrate/buildCriteria have no special case at all here (the
 *   generic string/passthrough fallback already does the right thing, since
 *   PHP array-indexes a numeric-string key the same as the equivalent int --
 *   see `ObjectBuilder::addEnumAccessor()`'s `$valueSet[$this->$phpname]`
 *   lookup) -- every hook below returns null for this combination, on
 *   purpose.
 * - `nativeEnum` only: same emulated-index property representation, but the
 *   DB column stores the label text directly, so hydrate/buildCriteria need
 *   to convert between the two.
 * - `enumClass` only: the property holds the backed-enum instance directly;
 *   the DB column stores the emulated integer index.
 * - Both: the property holds the enum instance; the DB column stores the
 *   label text directly (the enum case's own `->value`).
 */
class EnumHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isEnumType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		if (!$col->hasEnumClass()) {
			return null;
		}
		return '?' . $builder->getEnumShortName($col);
	}

	public function isConstructorDeferredDefault(Column $col): bool
	{
		return true;
	}

	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		if ($col->hasEnumClass() && $col->isNativeEnum()) {
			// A native-storage enum column's DB value is already the label
			// text (see the Platform's usesNativeEnumStorage()), so it maps
			// straight to the enum case -- no valueSet index lookup needed.
			return $builder->getEnumShortName($col) . "::from(\$v)";
		}
		if ($col->hasEnumClass()) {
			// $v is the raw stored index (see buildValueExpr()); resolve it
			// back to its label via the peer's valueSet, then to the enum
			// case that label belongs to.
			$enumShortName = $builder->getEnumShortName($col);
			$peerClass = $builder->getPeerClassname();
			$columnConstant = $builder->getColumnConstant($col);
			return "{$enumShortName}::from({$peerClass}::getValueSet($columnConstant)[(int) \$v])";
		}
		if ($col->isNativeEnum()) {
			// The property still holds the emulated index internally, but a
			// native-storage column's DB value is the label text -- convert
			// it back to the index it would have had under emulation.
			$peerClass = $builder->getPeerClassname();
			$columnConstant = $builder->getColumnConstant($col);
			return "array_search(\$v, {$peerClass}::getValueSet($columnConstant))";
		}
		// Plain emulated enum, no backing class -- no conversion needed, see
		// the class docblock.
		return null;
	}

	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		if ($col->hasEnumClass() && $col->isNativeEnum()) {
			// The DB column stores the label text directly -- the enum's own
			// ->value already *is* that label (its valueSet is derived from
			// the enum's own case values at parse time), so no index lookup
			// is needed.
			return "{$phpAccessExpr} === null ? null : {$phpAccessExpr}->value";
		}
		if ($col->hasEnumClass()) {
			// Stores the enum case's emulated index, not the enum instance
			// the property actually holds.
			$peerClass = $builder->getPeerClassname();
			$columnConstant = $builder->getColumnConstant($col);
			return "{$phpAccessExpr} === null ? null : array_search({$phpAccessExpr}->value, {$peerClass}::getValueSet($columnConstant))";
		}
		if ($col->isNativeEnum()) {
			// The property holds the emulated index, but a native-storage
			// column needs the label text -- resolve it back via valueSet
			// before sending it to the DB.
			$peerClass = $builder->getPeerClassname();
			$columnConstant = $builder->getColumnConstant($col);
			return "{$phpAccessExpr} === null ? null : {$peerClass}::getValueSet($columnConstant)[(int) {$phpAccessExpr}]";
		}
		return null;
	}
}
