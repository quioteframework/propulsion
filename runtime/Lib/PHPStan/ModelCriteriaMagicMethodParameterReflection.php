<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\PHPStan;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * A single `mixed ...$args` variadic parameter, describing the arguments any of
 * ModelCriteria::__call()'s magic methods accept.
 *
 * @see ModelCriteriaMagicMethodReflection
 */
class ModelCriteriaMagicMethodParameterReflection implements ParameterReflection
{
	public function getName(): string
	{
		return 'args';
	}

	public function isOptional(): bool
	{
		return true;
	}

	public function getType(): Type
	{
		return new MixedType();
	}

	public function passedByReference(): PassedByReference
	{
		return PassedByReference::createNo();
	}

	public function isVariadic(): bool
	{
		return true;
	}

	public function getDefaultValue(): ?Type
	{
		return null;
	}
}
