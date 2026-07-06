<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

/**
 * A ModelJoin is a Join object tied to a RelationMap object
 *
 * @author     Francois Zaninotto (Propel)
 */

 use Propulsion\Map\RelationMap;
 use Propulsion\Map\TableMap;

class ModelJoin extends Join
{
	/** @var RelationMap|null */
	protected $relationMap;
	/** @var TableMap|null */
	protected $tableMap;
	/** @var ModelJoin|null */
	protected $previousJoin;

	/**
	 * @return static
	 */
	public function setRelationMap(RelationMap $relationMap, ?string $leftTableAlias = null, ?string $relationAlias = null)
	{
		$leftCols = $relationMap->getLeftColumns();
		$rightCols = $relationMap->getRightColumns();
		$nbColumns = $relationMap->countColumnMappings();
		for ($i=0; $i < $nbColumns; $i++) {
			$this->addExplicitCondition(
				$leftCols[$i]->getTableName(), $leftCols[$i]->getName(), $leftTableAlias,
				$rightCols[$i]->getTableName(), $rightCols[$i]->getName(), $relationAlias,
				Criteria::EQUAL);
		}
		$this->relationMap = $relationMap;

		return $this;
	}

	public function getRelationMap(): ?RelationMap
	{
		return $this->relationMap;
	}

	/**
	 * Sets the right tableMap for this join
	 *
	 * @param TableMap $tableMap The table map to use
	 *
	 * @return ModelJoin The current join object, for fluid interface
	 */
	public function setTableMap(TableMap $tableMap)
	{
		$this->tableMap = $tableMap;

		return $this;
	}

	/**
	 * Gets the right tableMap for this join
	 *
	 * @return TableMap The table map
	 */
	public function getTableMap()
	{
		if (null === $this->tableMap && null !== $this->relationMap)
		{
			$this->tableMap = $this->relationMap->getRightTable();
		}
		return $this->tableMap;
	}

	/**
	 * @return static
	 */
	public function setPreviousJoin(ModelJoin $join)
	{
		$this->previousJoin = $join;

		return $this;
	}

	public function getPreviousJoin(): ?ModelJoin
	{
		return $this->previousJoin;
	}

	public function isPrimary(): bool
	{
		return null === $this->previousJoin;
	}

	/**
	 * @return static
	 */
	public function setRelationAlias(?string $relationAlias)
	{
		return $this->setRightTableAlias($relationAlias);
	}

	public function getRelationAlias(): ?string
	{
		return $this->getRightTableAlias();
	}

	public function hasRelationAlias(): bool
	{
		return $this->hasRightTableAlias();
	}
	/**
	 * This method returns the last related, but already hydrated object up until this join
	 * Starting from $startObject and continuously calling the getters to get
	 * to the base object for the current join.
	 *
	 * This method only works if PreviousJoin has been defined,
	 * which only happens when you provide dotted relations when calling join
	 *
	 * @param object $startObject the start object all joins originate from and which has already hydrated
	 * @return object the base Object of this join
	 */
	public function getObjectToRelate($startObject)
	{
		if($this->isPrimary()) {
			return $startObject;
		} else {
			$previousJoin = $this->getPreviousJoin();
			$previousObject = $previousJoin->getObjectToRelate($startObject);
			$method = 'get' . $previousJoin->getRelationMap()->getName();
			return $previousObject->$method();
		}
	}

	/**
	 * @param ModelJoin $join
	 * @return bool
	 */
	public function equals($join)
	{
		return parent::equals($join)
			&& $this->relationMap == $join->getRelationMap()
			&& $this->previousJoin == $join->getPreviousJoin()
			&& $this->rightTableAlias == $join->getRightTableAlias();
	}

	public function __toString()
	{
		return parent::toString()
			. ' tableMap: ' . ($this->tableMap ? get_class($this->tableMap) : 'null')
			. ' relationMap: ' . $this->relationMap->getName()
			. ' previousJoin: ' . ($this->previousJoin ? '(' . $this->previousJoin . ')' : 'null')
			. ' relationAlias: ' . $this->rightTableAlias;
	}
}
