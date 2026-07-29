<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Type;
use PHPStan\TrinaryLogic;

/**
 * @see ModelCriteriaMagicMethodsExtension
 */
class ModelCriteriaMagicMethodReflection implements MethodReflection
{
	private ClassReflection $declaringClass;
	private string $name;
	private Type $returnType;

	public function __construct(ClassReflection $declaringClass, string $name, Type $returnType)
	{
		$this->declaringClass = $declaringClass;
		$this->name = $name;
		$this->returnType = $returnType;
	}

	public function getDeclaringClass(): ClassReflection
	{
		return $this->declaringClass;
	}

	public function isStatic(): bool
	{
		return false;
	}

	public function isPrivate(): bool
	{
		return false;
	}

	public function isPublic(): bool
	{
		return true;
	}

	public function getDocComment(): ?string
	{
		return null;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getPrototype(): ClassMemberReflection
	{
		return $this;
	}

	/**
	 * @return list<ParametersAcceptor>
	 */
	public function getVariants(): array
	{
		return array(
			new FunctionVariant(
				TemplateTypeMap::createEmpty(),
				null,
				array(new ModelCriteriaMagicMethodParameterReflection()),
				true,
				$this->returnType
			),
		);
	}

	public function isDeprecated(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getDeprecatedDescription(): ?string
	{
		return null;
	}

	public function isFinal(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isInternal(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getThrowType(): ?Type
	{
		return null;
	}

	public function hasSideEffects(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}
}
