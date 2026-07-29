<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM\ColumnType;

use Propulsion\Generator\Model\Column;

/**
 * Resolves a Column to the ColumnTypeHandler that owns it. Every handler's
 * `applies()` predicate is mutually exclusive with every other's (each
 * Column has exactly one PropulsionTypes type; a handler internally branches
 * on any finer-grained sub-flag it needs, e.g. EnumHandler on `enumClass`/
 * `nativeEnum`, ArrayHandler on `nativeArray`), so registration order here
 * doesn't affect correctness -- listed in roughly the order these types were
 * added to the codebase.
 */
final class ColumnTypeHandlerRegistry
{
	/** @var ColumnTypeHandler[]|null */
	private static ?array $handlers = null;

	/**
	 * @return ColumnTypeHandler[]
	 */
	private static function handlers(): array
	{
		if (self::$handlers === null) {
			self::$handlers = [
				new EnumHandler(),
				new TemporalHandler(),
				new ObjectHandler(),
				new ArrayHandler(),
				new BooleanHandler(),
				new JsonHandler(),
				new LobHandler(),
				new BcMathNumberHandler(),
				new IntervalHandler(),
				new RangeHandler(),
				new VectorHandler(),
				new SetHandler(),
			];
		}
		return self::$handlers;
	}

	/**
	 * Returns the handler that owns $col, or null if it's a plain scalar
	 * with no type-specific codegen (the common case -- CHAR/VARCHAR/
	 * INTEGER/UUID/INET/TSVECTOR/... all fall through to ObjectBuilder's own
	 * generic handling for every hook).
	 */
	public static function resolve(Column $col): ?ColumnTypeHandler
	{
		foreach (self::handlers() as $handler) {
			if ($handler->applies($col)) {
				return $handler;
			}
		}
		return null;
	}
}
