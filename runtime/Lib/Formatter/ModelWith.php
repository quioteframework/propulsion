<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Formatter;
/**
 * Data object to describe a joined hydration in a Model Query
 * ModelWith objects are used by formatters to hydrate related objects
 *
 * @author     Francois Zaninotto (Propel)
 */

 use Propulsion\Query\ModelJoin;
 use Propulsion\Map\RelationMap;
class ModelWith
{
	protected string $modelName = '';
	protected string $modelPeerName = '';
	protected bool $isSingleTableInheritance = false;
	protected bool $isAdd = false;
	protected bool $isWithOneToMany = false;
	protected string $relationName = '';
	protected string $relationMethod = '';
	protected string $initMethod = '';
	protected ?string $leftPhpName = null;
	protected ?string $rightPhpName = null;

	public function __construct(?ModelJoin $join = null)
	{
		if (null !== $join) {
			$this->init($join);
		}
	}

	/**
	 * Define the joined hydration schema based on a join object.
	 * Fills the ModelWith properties using a ModelJoin as source
	 *
	 * @param ModelJoin $join
	 */
	public function init(ModelJoin $join): void
	{
		$tableMap = $join->getTableMap();
		$this->modelName = $tableMap->getClassname();
		$this->modelPeerName = $tableMap->getPeerClassname();
		$this->isSingleTableInheritance = $tableMap->isSingleTableInheritance();
		$relation = $join->getRelationMap();
		$relationName = $relation->getName();
		if ($relation->getType() == RelationMap::ONE_TO_MANY) {
			$this->isAdd = $this->isWithOneToMany = true;
			$this->relationName = $relation->getPluralName();
			$this->relationMethod = 'add' . $relationName;
			$this->initMethod = 'init' . $this->relationName;
		} else {
			$this->relationName = $relationName;
			$this->relationMethod = 'set' . $relationName;
		}
		$this->rightPhpName = $join->hasRelationAlias() ? $join->getRelationAlias() : $relationName;
		if (!$join->isPrimary()) {
			$this->leftPhpName = $join->hasLeftTableAlias() ? $join->getLeftTableAlias() : $join->getPreviousJoin()->getRelationMap()->getName();
		}
	}

	// DataObject getters & setters

	public function setModelName(string $modelName): void
	{
		$this->modelName = $modelName;
	}

	public function getModelName(): string
	{
		return $this->modelName;
	}

	public function setModelPeerName(string $modelPeerName): void
	{
		$this->modelPeerName = $modelPeerName;
	}

	public function getModelPeerName(): string
	{
		return $this->modelPeerName;
	}

	public function setIsSingleTableInheritance(bool $isSingleTableInheritance): void
	{
		$this->isSingleTableInheritance = $isSingleTableInheritance;
	}

	public function isSingleTableInheritance(): bool
	{
		return $this->isSingleTableInheritance;
	}

	public function setIsAdd(bool $isAdd): void
	{
		$this->isAdd = $isAdd;
	}

	public function isAdd(): bool
	{
		return $this->isAdd;
	}

	public function setIsWithOneToMany(bool $isWithOneToMany): void
	{
		$this->isWithOneToMany = $isWithOneToMany;
	}

	public function isWithOneToMany(): bool
	{
		return $this->isWithOneToMany;
	}

	public function setRelationName(string $relationName): void
	{
		$this->relationName = $relationName;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function setRelationMethod(string $relationMethod): void
	{
		$this->relationMethod = $relationMethod;
	}

	public function getRelationMethod(): string
	{
		return $this->relationMethod;
	}

	public function setInitMethod(string $initMethod): void
	{
		$this->initMethod = $initMethod;
	}

	public function getInitMethod(): string
	{
		return $this->initMethod;
	}

	public function setLeftPhpName(?string $leftPhpName): void
	{
		$this->leftPhpName = $leftPhpName;
	}

	public function getLeftPhpName(): ?string
	{
		return $this->leftPhpName;
	}

	public function setRightPhpName(?string $rightPhpName): void
	{
		$this->rightPhpName = $rightPhpName;
	}

	public function getRightPhpName(): ?string
	{
		return $this->rightPhpName;
	}

	// Utility methods

	public function isPrimary(): bool
	{
		return null === $this->leftPhpName;
	}

	public function __toString()
	{
		return sprintf("modelName: %s, relationName: %s, relationMethod: %s, leftPhpName: %s, rightPhpName: %s", $this->modelName, $this->relationName, $this->relationMethod, $this->leftPhpName, $this->rightPhpName);
	}
}