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
 * One column "kind"'s (ENUM, JSON, a Postgres array, ...) codegen behavior,
 * previously scattered as parallel `elseif` chains across six independent
 * `ObjectBuilder` methods (`getPhp84TypeHint()`, `addProperties()`/
 * `addApplyDefaultValues()`'s default-value handling, `addHydrate()`,
 * `addBuildCriteria()`/`addBuildPkeyCriteria()`, `getColumnValueCastExpr()`).
 * Adding a new column type used to mean touching all six; a handler collects
 * one type's behavior in one file instead.
 *
 * Every method here returns null to mean "this handler has nothing to say
 * about this hook" -- `ColumnTypeHandlerRegistry`'s callers fall through to
 * their own generic default logic in that case. This faithfully mirrors the
 * pre-refactor code: the six original call sites did not all special-case
 * the same set of types (e.g. LOB columns only had a special case in the
 * type-hint and cast-expression sites, never in hydrate/buildCriteria), so a
 * handler is not required to implement every hook just because it implements
 * one.
 *
 * Concrete handlers only override the hooks their type actually needs;
 * `applies()` is the only one every handler must implement.
 */
abstract class ColumnTypeHandler
{
	/**
	 * Whether this handler owns $col. `ColumnTypeHandlerRegistry` checks
	 * handlers in a fixed priority order and stops at the first match --
	 * that order matters for the handlers whose `applies()` genuinely
	 * overlaps another's (e.g. a native-enum column with a backing
	 * `enumClass` matches both the enum-class handler and the plain
	 * native-enum handler; the registry lists the more specific one first).
	 */
	abstract public function applies(Column $col): bool;

	/**
	 * The PHP 8.4 type hint (property type / getter return type / setter
	 * parameter type) for $col, e.g. `?DateTimeInterface`. Null falls
	 * through to `ObjectBuilder::getPhp84TypeHint()`'s own generic mapping
	 * (from `Column::getPhpType()`).
	 */
	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		return null;
	}

	/**
	 * Whether $col's real default value can't be used as a PHP property
	 * declaration's default (only a constant expression is legal there) and
	 * must instead be deferred to `applyDefaultValues()`, called from the
	 * constructor, where a plain assignment is allowed. True for anything
	 * whose default is a `new X(...)`/similar non-constant expression
	 * (temporal, `BcMath\Number`, `DateInterval`, `Range`, a backed enum
	 * instance).
	 */
	public function isConstructorDeferredDefault(Column $col): bool
	{
		return false;
	}

	/**
	 * The property-declaration default literal to use for $col when it has
	 * no explicit schema default (e.g. `array()` for an array-like column,
	 * instead of `null`). Null means no override -- the property defaults
	 * to `null` (if nullable) same as any plain scalar column.
	 */
	public function getEmptyValueLiteral(Column $col): ?string
	{
		return null;
	}

	/**
	 * Wraps $defaultValueExpr (the literal produced by
	 * `ObjectBuilder::getDefaultValueString()`) into the real assignment
	 * expression `applyDefaultValues()` needs for a column WITH an explicit
	 * schema default, e.g. `new DateTime($defaultValueExpr)`. Null means a
	 * plain `$this->prop = $defaultValueExpr;` assignment is correct as-is.
	 */
	public function wrapDefaultValueAssignment(Column $col, string $defaultValueExpr, ObjectBuilder $builder): ?string
	{
		return null;
	}

	/**
	 * The hydrate-time expression converting the raw (non-null) DB value
	 * `$v` into this column's PHP representation, e.g. `new DateTime($v)`.
	 * Null falls through to `addHydrate()`'s own generic numeric/string
	 * cast.
	 */
	public function buildHydrateExpr(Column $col, ObjectBuilder $builder): ?string
	{
		return null;
	}

	/**
	 * The DB-write expression converting $phpAccessExpr (a PHP expression
	 * reading this column's current value, e.g. `$this->Tags`) into the
	 * value to hand to `Criteria::add()`. Shared by `addBuildCriteria()`
	 * (which wraps the result in an `isColumnModified()` check) and
	 * `addBuildPkeyCriteria()` (which doesn't), and reused by
	 * `QueryBuilder::addFilterByCol()` for the handful of types whose
	 * filter-value encoding is identical to their storage encoding. Null
	 * falls through to a plain passthrough of $phpAccessExpr.
	 */
	public function buildValueExpr(Column $col, string $phpAccessExpr, ObjectBuilder $builder): ?string
	{
		return null;
	}

	/**
	 * Narrows a mixed value (e.g. an element of the array passed to
	 * `setPrimaryKey()`) to this column's setter's accepted type, preserving
	 * null -- mirrors the cast `buildHydrateExpr()` applies for the same
	 * column coming from a raw DB row. Null falls through to
	 * `getColumnValueCastExpr()`'s own generic numeric/string cast.
	 */
	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return null;
	}

	/**
	 * Whether a plural-named column of this type (e.g. "tags") should get
	 * the has<Singular>()/add<Singular>()/remove<Singular>() convenience
	 * methods `ObjectBuilder::addColumnAccessorMethods()` adds for an
	 * array-like column.
	 */
	public function hasArrayElementConvenienceMethods(Column $col): bool
	{
		return false;
	}
}
