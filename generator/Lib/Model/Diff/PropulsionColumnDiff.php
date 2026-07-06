<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license     MIT License
 */
namespace Propulsion\Generator\Model\Diff;

/**
 * Value object for storing Column object diffs.
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 *
 */
use Propulsion\Generator\Model\Column;
class PropulsionColumnDiff
{
	/** @var array<string, array<int, mixed>> */
	protected array $changedProperties = array();
	protected ?Column $fromColumn = null;
	protected ?Column $toColumn = null;

	/**
	 * Setter for the changedProperties property
	 *
	 * @param array<string, array<int, mixed>> $changedProperties
	 */
	public function setChangedProperties($changedProperties): void
	{
		$this->changedProperties = $changedProperties;
	}

	/**
	 * Getter for the changedProperties property
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function getChangedProperties()
	{
		return $this->changedProperties;
	}

	/**
	 * Setter for the fromColumn property
	 *
	 * @param Column $fromColumn
	 */
	public function setFromColumn(Column $fromColumn): void
	{
		$this->fromColumn = $fromColumn;
	}

	/**
	 * Getter for the fromColumn property
	 *
	 * @return Column|null
	 */
	public function getFromColumn()
	{
		return $this->fromColumn;
	}

	/**
	 * Setter for the toColumn property
	 *
	 * @param Column $toColumn
	 */
	public function setToColumn(Column $toColumn): void
	{
		$this->toColumn = $toColumn;
	}

	/**
	 * Getter for the toColumn property
	 *
	 * @return Column|null
	 */
	public function getToColumn()
	{
		return $this->toColumn;
	}

	/**
	 * Get the reverse diff for this diff
	 *
	 * @return PropulsionColumnDiff
	 */
	public function getReverseDiff()
	{
		$diff = new self();

		// columns
		$diff->setFromColumn($this->getToColumn());
		$diff->setToColumn($this->getFromColumn());

		// properties
		$changedProperties = array();
		foreach ($this->getChangedProperties() as $name => $propertyChange) {
			$changedProperties[$name] = array_reverse($propertyChange);
		}
		$diff->setChangedProperties($changedProperties);

		return $diff;
	}

	public function __toString()
	{
		$ret = '';
		$ret .= sprintf("      %s:\n", $this->getFromColumn()->getFullyQualifiedName());
		$ret .= "        modifiedProperties:\n";
		foreach ($this->getChangedProperties() as $key => $value) {
			$ret .= sprintf("          %s: %s\n", $key, json_encode($value));
		}

		return $ret;
	}

}
