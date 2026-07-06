<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license     MIT License
 */
namespace Propulsion\Generator\Model\Diff;

use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Table;

/**
 * Value object for storing Database object diffs
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 *
 */
class PropulsionDatabaseDiff
{
	/** @var array<string, Table> */
	protected array $addedTables = array();
	/** @var array<string, Table> */
	protected array $removedTables = array();
	/** @var array<string, PropulsionTableDiff> */
	protected array $modifiedTables = array();
	/** @var array<string, string> */
	protected array $renamedTables = array();

	/**
	 * Setter for the addedTables property
	 *
	 * @param array<string, Table> $addedTables
	 */
	public function setAddedTables($addedTables): void
	{
		$this->addedTables = $addedTables;
	}

	/**
	 * Add an added table
	 *
	 * @param string $tableName
	 * @param Table $addedTable
	 */
	public function addAddedTable($tableName, Table $addedTable): void
	{
		$this->addedTables[$tableName] = $addedTable;
	}

	/**
	 * Remove an added table
	 *
	 * @param string $tableName
	 */
	public function removeAddedTable($tableName): void
	{
		unset($this->addedTables[$tableName]);
	}

	/**
	 * Getter for the addedTables property
	 *
	 * @return array<string, Table>
	 */
	public function getAddedTables()
	{
		return $this->addedTables;
	}

	/**
	 * Get the number of added tables
	 *
	 * @return integer
	 */
	public function countAddedTables()
	{
		return count($this->addedTables);
	}

	/**
	 * Get an added table
	 *
	 * @param string $tableName
	 *
	 * @return Table
	 */
	public function getAddedTable($tableName)
	{
		return $this->addedTables[$tableName];
	}

	/**
	 * Setter for the removedTables property
	 *
	 * @param array<string, Table> $removedTables
	 */
	public function setRemovedTables($removedTables): void
	{
		$this->removedTables = $removedTables;
	}

	/**
	 * Add a removed table
	 *
	 * @param string $tableName
	 * @param Table $removedTable
	 */
	public function addRemovedTable($tableName, Table $removedTable): void
	{
		$this->removedTables[$tableName] = $removedTable;
	}

	/**
	 * Remove a removed table
	 *
	 * @param string $tableName
	 */
	public function removeRemovedTable($tableName): void
	{
		unset($this->removedTables[$tableName]);
	}

	/**
	 * Getter for the removedTables property
	 *
	 * @return array<string, Table>
	 */
	public function getRemovedTables()
	{
		return $this->removedTables;
	}

	/**
	 * Get the number of removed tables
	 *
	 * @return integer
	 */
	public function countRemovedTables()
	{
		return count($this->removedTables);
	}

	/**
	 * Get a removed table
	 *
	 * @param string $tableName
	 *
	 * @return Table
	 */
	public function getRemovedTable($tableName)
	{
		return $this->removedTables[$tableName];
	}

	/**
	 * Setter for the modifiedTables property
	 *
	 * @param array<string, PropulsionTableDiff> $modifiedTables
	 */
	public function setModifiedTables($modifiedTables): void
	{
		$this->modifiedTables = $modifiedTables;
	}

	/**
	 * Add a table difference
	 *
	 * @param string $tableName
	 * @param PropulsionTableDiff $modifiedTable
	 */
	public function addModifiedTable($tableName, PropulsionTableDiff $modifiedTable): void
	{
		$this->modifiedTables[$tableName] = $modifiedTable;
	}

	/**
	 * Get the number of modified tables
	 *
	 * @return integer
	 */
	public function countModifiedTables()
	{
		return count($this->modifiedTables);
	}

	/**
	 * Getter for the modifiedTables property
	 *
	 * @return array<string, PropulsionTableDiff>
	 */
	public function getModifiedTables()
	{
		return $this->modifiedTables;
	}

	/**
	 * Setter for the renamedTables property
	 *
	 * @param array<string, string> $renamedTables
	 */
	public function setRenamedTables($renamedTables): void
	{
		$this->renamedTables = $renamedTables;
	}

	/**
	 * Add a renamed table
	 *
	 * @param string $fromName
	 * @param string $toName
	 */
	public function addRenamedTable($fromName, $toName): void
	{
		$this->renamedTables[$fromName] = $toName;
	}

	/**
	 * Getter for the renamedTables property
	 *
	 * @return array<string, string>
	 */
	public function getRenamedTables()
	{
		return $this->renamedTables;
	}

	/**
	 * Get the number of renamed tables
	 *
	 * @return integer
	 */
	public function countRenamedTables()
	{
		return count($this->renamedTables);
	}

	/**
	 * Get the reverse diff for this diff
	 *
	 * @return PropulsionDatabaseDiff
	 */
	public function getReverseDiff()
	{
		$diff = new self();
		$diff->setAddedTables($this->getRemovedTables());
		// idMethod is not set for tables build from reverse engineering
		// FIXME: this should be handled by reverse classes
		foreach ($diff->getAddedTables() as $name => $table) {
			if ($table->getIdMethod() == IDMethod::NO_ID_METHOD) {
				$table->setIdMethod(IDMethod::NATIVE);
			}
		}
		$diff->setRemovedTables($this->getAddedTables());
		$diff->setRenamedTables(array_flip($this->getRenamedTables()));
		$tableDiffs = array();
		foreach ($this->getModifiedTables() as $name => $tableDiff) {
			$tableDiffs[$name] = $tableDiff->getReverseDiff();
		}
		$diff->setModifiedTables($tableDiffs);

		return $diff;
	}

	/**
	 * Get a description of the database modifications
	 *
	 * @return string
	 */
	public function getDescription()
	{
		$changes = array();
		if ($count = $this->countAddedTables()) {
			$changes []= sprintf('%d added tables', $count);
		}
		if ($count = $this->countRemovedTables()) {
			$changes []= sprintf('%d removed tables', $count);
		}
		if ($count = $this->countModifiedTables()) {
			$changes []= sprintf('%d modified tables', $count);
		}
		if ($count = $this->countRenamedTables()) {
			$changes []= sprintf('%d renamed tables', $count);
		}

		return implode(', ', $changes);
	}

	public function __toString()
	{
		$ret = '';
		if ($addedTables = $this->getAddedTables()) {
			$ret .= "addedTables:\n";
			foreach ($addedTables as $tableName => $table) {
				$ret .= sprintf("  - %s\n", $tableName);
			}
		}
		if ($removedTables = $this->getRemovedTables()) {
			$ret .= "removedTables:\n";
			foreach ($removedTables as $tableName => $table) {
				$ret .= sprintf("  - %s\n", $tableName);
			}
		}
		if ($modifiedTables = $this->getModifiedTables()) {
			$ret .= "modifiedTables:\n";
			foreach ($modifiedTables as $tableName => $tableDiff) {
				$ret .= $tableDiff->__toString();
			}
		}
		if ($renamedTables = $this->getRenamedTables()) {
			$ret .= "renamedTables:\n";
			foreach ($renamedTables as $fromName => $toName) {
				$ret .= sprintf("  %s: %s\n", $fromName, $toName);
			}
		}

		return $ret;
	}

}
