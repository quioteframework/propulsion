<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use Propulsion\Query\ModelCriteria;

/**
 * Teaches PHPStan about the magic methods ModelCriteria::__call() dispatches --
 * findByXxx()/findOneByXxx()/filterByXxx()/orderByXxx()/groupByXxx() (where Xxx is
 * a column phpName), leftJoin()/rightJoin()/innerJoin(), and joinWithXxx()/
 * leftJoinWithXxx()/rightJoinWithXxx()/innerJoinWithXxx() (where Xxx is a relation
 * name). Without this, PHPStan reports every one of these -- the single most common
 * way consumers build queries against generated models -- as "Call to an undefined
 * method", since PHPStan does not trust an arbitrary __call() implementation by
 * default. (ModelCriteria's own @method docblock annotations for leftJoin()/
 * rightJoin()/innerJoin() don't appear to be honored by PHPStan in practice --
 * verified those three still error out with this extension absent -- so this
 * extension covers them directly rather than relying on the docblock.)
 *
 * This only recognizes the *shape* of these method names (the same prefixes/suffixes
 * __call() itself switches on), not whether the named column or relation actually
 * exists on the concrete model being queried -- verifying that would need per-model
 * TableMap/RelationMap introspection at analysis time, a natural follow-up but a
 * materially bigger undertaking than closing the current false-positive gap.
 *
 * Registered via phpstan.neon's `phpstan.broker.methodsClassReflectionExtension` tag.
 */
class ModelCriteriaMagicMethodsExtension implements MethodsClassReflectionExtension
{
	/**
	 * @var array<int, string>
	 */
	private const FIND_OR_FILTER_PREFIXES = array('findOneBy', 'findBy', 'filterBy', 'orderBy', 'groupBy');

	/**
	 * @var array<int, string>
	 */
	private const DIRECTION_JOINS = array('leftJoin', 'rightJoin', 'innerJoin');

	public function hasMethod(ClassReflection $classReflection, string $methodName): bool
	{
		if (!$classReflection->is(ModelCriteria::class)) {
			return false;
		}

		return self::matchesFindOrFilterPattern($methodName)
			|| self::matchesJoinWithPattern($methodName)
			|| in_array($methodName, self::DIRECTION_JOINS, true);
	}

	public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
	{
		return new ModelCriteriaMagicMethodReflection($classReflection, $methodName, self::returnType($classReflection, $methodName));
	}

	private static function matchesFindOrFilterPattern(string $methodName): bool
	{
		foreach (self::FIND_OR_FILTER_PREFIXES as $prefix) {
			if ($methodName !== $prefix && strncmp($methodName, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}

		return false;
	}

	private static function matchesJoinWithPattern(string $methodName): bool
	{
		// __call()'s only other magic dispatch is leftJoin()/rightJoin()/innerJoin(),
		// already covered by ModelCriteria's own @method docblock annotations.
		return preg_match('/^(left|right|inner)?[Jj]oinWith.+$/', $methodName) === 1;
	}

	private static function returnType(ClassReflection $classReflection, string $methodName): Type
	{
		// findByXxx()/findOneByXxx() mirror ModelCriteria::findBy()/findOneBy(), both
		// themselves declared `mixed` -- see ModelCriteria.php.
		if (strncmp($methodName, 'findBy', 6) === 0 || strncmp($methodName, 'findOneBy', 9) === 0) {
			return new MixedType();
		}

		// filterByXxx()/orderByXxx()/groupByXxx()/joinWithXxx() all return $this for
		// chaining, mirroring filterBy()/orderBy()/groupBy()/joinWith().
		return new StaticType($classReflection);
	}
}
