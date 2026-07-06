<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Map;

/**
 * ValidatorMap is used to model a column validator.
 *
 * GENERAL NOTE
 * ------------
 * The propel.map classes are abstract building-block classes for modeling
 * the database at runtime.  These classes are similar (a lite version) to the
 * propel.engine.database.model classes, which are build-time modeling classes.
 * These classes in themselves do not do any database metadata lookups.
 *
 * @author     Michael Aichler <aichler@mediacluster.de>
 * @version    $Revision$
 */
class ValidatorMap
{
	/** rule name of this validator */
	private ?string $name = null;
	/** the dot-path to class to use for validator */
	private ?string $classname = null;
	/** value to check against */
	private ?string $value = null;
	/** execption message thrown on invalid input */
	private ?string $message = null;
	/** related column */
	private ColumnMap $column;

	public function __construct(ColumnMap $containingColumn)
	{
		$this->column = $containingColumn;
	}

	public function getColumn(): ColumnMap
	{
		return $this->column;
	}

	public function getColumnName(): string
	{
		return $this->column->getColumnName();
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function setClass(string $classname): void
	{
		$this->classname = $classname;
	}

	public function setValue(string $value): void
	{
		$this->value = $value;
	}

	public function setMessage(string $message): void
	{
		$this->message = $message;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getClass(): ?string
	{
		return $this->classname;
	}

	public function getValue(): ?string
	{
		return $this->value;
	}

	public function getMessage(): ?string
	{
		return $this->message;
	}
}
