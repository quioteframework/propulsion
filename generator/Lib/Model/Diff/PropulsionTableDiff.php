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
 * Value object for storing Table object diffs
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 *
 */
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\ForeignKey;
class PropulsionTableDiff
{
	protected ?Table $fromTable = null;
	protected ?Table $toTable = null;

	/** @var array<string, Column> */
	protected array $addedColumns = array();
	/** @var array<string, Column> */
	protected array $removedColumns = array();
	/** @var array<string, PropulsionColumnDiff> */
	protected array $modifiedColumns = array();
	/** @var array<int, array<int, Column>> */
	protected array $renamedColumns = array();

	/** @var array<string, Column> */
	protected array $addedPkColumns = array();
	/** @var array<string, Column> */
	protected array $removedPkColumns = array();
	/** @var array<int, array<int, Column>> */
	protected array $renamedPkColumns = array();

	/** @var array<string, Index> */
	protected array $addedIndices = array();
	/** @var array<string, Index> */
	protected array $removedIndices = array();
	/** @var array<string, array<int, Index>> */
	protected array $modifiedIndices = array();

	/** @var array<string, ForeignKey> */
	protected array $addedFks = array();
	/** @var array<string, ForeignKey> */
	protected array $removedFks = array();
	/** @var array<string, array<int, ForeignKey>> */
	protected array $modifiedFks = array();

	protected ?string $columnName = null;

	/**
	 * Setter for the fromTable property
	 *
	 * @param Table $fromTable
	 */
	public function setFromTable(Table $fromTable): void
	{
		$this->fromTable = $fromTable;
	}

	/**
	 * Getter for the fromTable property
	 *
	 * @return Table|null
	 */
	public function getFromTable()
	{
		return $this->fromTable;
	}

	/**
	 * Setter for the toTable property
	 *
	 * @param Table $toTable
	 */
	public function setToTable(Table $toTable): void
	{
		$this->toTable = $toTable;
	}

	/**
	 * Getter for the toTable property
	 *
	 * @return Table|null
	 */
	public function getToTable()
	{
		return $this->toTable;
	}

	/**
	 * Setter for the addedColumns property
	 *
	 * @param array<string, Column> $addedColumns
	 */
	public function setAddedColumns($addedColumns): void
	{
		$this->addedColumns = $addedColumns;
	}

	/**
	 * Add an added column
	 *
	 * @param string $columnName
	 * @param Column $addedColumn
	 */
	public function addAddedColumn($columnName, Column $addedColumn): void
	{
		$this->addedColumns[$columnName] = $addedColumn;
	}

	/**
	 * Remove an added column
	 *
	 * @param string $columnName
	 */
	public function removeAddedColumn($columnName): void
	{
		unset($this->addedColumns[$columnName]);
	}

	/**
	 * Getter for the addedColumns property
	 *
	 * @return array<string, Column>
	 */
	public function getAddedColumns()
	{
		return $this->addedColumns;
	}

	/**
	 * Get an added column
	 *
	 * @param string $columnName
	 *
	 * @return Column
	 */
	public function getAddedColumn($columnName)
	{
		return $this->addedColumns[$columnName];
	}

	/**
	 * Setter for the removedColumns property
	 *
	 * @param array<string, Column> $removedColumns
	 */
	public function setRemovedColumns($removedColumns): void
	{
		$this->removedColumns = $removedColumns;
	}

	/**
	 * Add a removed column
	 *
	 * @param string $columnName
	 * @param Column $removedColumn
	 */
	public function addRemovedColumn($columnName, Column $removedColumn): void
	{
		$this->removedColumns[$columnName] = $removedColumn;
	}

	/**
	 * Remove a removed column
	 *
	 * @param string $columnName
	 */
	public function removeRemovedColumn($columnName): void
	{
		unset($this->removedColumns[$columnName]);
	}

	/**
	 * Getter for the removedColumns property
	 *
	 * @return array<string, Column>
	 */
	public function getRemovedColumns()
	{
		return $this->removedColumns;
	}

	/**
	 * Get a removed column
	 *
	 * @param string $columnName
	 *
	 * @return Column
	 */
	public function getRemovedColumn($columnName)
	{
		return $this->removedColumns[$columnName];
	}

	/**
	 * Setter for the modifiedColumns property
	 *
	 * @param array<string, PropulsionColumnDiff> $modifiedColumns
	 */
	public function setModifiedColumns($modifiedColumns): void
	{
		$this->modifiedColumns = $modifiedColumns;
	}

	/**
	 * Add a column difference
	 *
	 * @param string $columnName
	 * @param PropulsionColumnDiff $modifiedColumn
	 */
	public function addModifiedColumn($columnName, PropulsionColumnDiff $modifiedColumn): void
	{
		$this->modifiedColumns[$columnName] = $modifiedColumn;
	}

	/**
	 * Getter for the modifiedColumns property
	 *
	 * @return array<string, PropulsionColumnDiff>
	 */
	public function getModifiedColumns()
	{
		return $this->modifiedColumns;
	}

	/**
	 * Setter for the renamedColumns property
	 *
	 * @param array<int, array<int, Column>> $renamedColumns
	 */
	public function setRenamedColumns($renamedColumns): void
	{
		$this->renamedColumns = $renamedColumns;
	}

	/**
	 * Add a renamed column
	 *
	 * @param Column $fromColumn
	 * @param Column $toColumn
	 */
	public function addRenamedColumn($fromColumn, $toColumn): void
	{
		$this->renamedColumns[] = array($fromColumn, $toColumn);
	}

	/**
	 * Getter for the renamedColumns property
	 *
	 * @return array<int, array<int, Column>>
	 */
	public function getRenamedColumns()
	{
		return $this->renamedColumns;
	}

	/**
	 * Setter for the addedPkColumns property
	 *
	 * @param array<string, Column> $addedPkColumns
	 */
	public function setAddedPkColumns(array $addedPkColumns): void
	{
		$this->addedPkColumns = $addedPkColumns;
	}

	/**
	 * Add an added Pk column
	 *
	 * @param string $columnName
	 * @param Column $addedPkColumn
	 */
	public function addAddedPkColumn($columnName, Column $addedPkColumn): void
	{
		$this->addedPkColumns[$columnName] = $addedPkColumn;
	}

	/**
	 * Remove an added Pk column
	 *
	 * @param string $columnName
	 */
	public function removeAddedPkColumn($columnName): void
	{
		unset($this->addedPkColumns[$columnName]);
	}

	/**
	 * Getter for the addedPkColumns property
	 *
	 * @return array<string, Column>
	 */
	public function getAddedPkColumns()
	{
		return $this->addedPkColumns;
	}

	/**
	 * Setter for the removedPkColumns property
	 *
	 * @param array<string, Column> $removedPkColumns
	 */
	public function setRemovedPkColumns(array $removedPkColumns): void
	{
		$this->removedPkColumns = $removedPkColumns;
	}

	/**
	 * Add a removed Pk column
	 *
	 * @param string $columnName
	 * @param Column $removedPkColumn
	 */
	public function addRemovedPkColumn($columnName, Column $removedPkColumn): void
	{
		$this->removedPkColumns[$columnName] = $removedPkColumn;
	}

	/**
	 * Remove a removed Pk column
	 *
	 * @param string $columnName
	 */
	public function removeRemovedPkColumn($columnName): void
	{
		unset($this->removedPkColumns[$columnName]);
	}

	/**
	 * Getter for the removedPkColumns property
	 *
	 * @return array<string, Column>
	 */
	public function getRemovedPkColumns()
	{
		return $this->removedPkColumns;
	}

	/**
	 * Setter for the renamedPkColumns property
	 *
	 * @param array<int, array<int, Column>> $renamedPkColumns
	 */
	public function setRenamedPkColumns(array $renamedPkColumns): void
	{
		$this->renamedPkColumns = $renamedPkColumns;
	}

	/**
	 * Add a renamed Pk column
	 *
	 * @param Column $fromColumn
	 * @param Column $toColumn
	 */
	public function addRenamedPkColumn($fromColumn, $toColumn): void
	{
		$this->renamedPkColumns[] = array($fromColumn, $toColumn);
	}

	/**
	 * Getter for the renamedPkColumns property
	 *
	 * @return array<int, array<int, Column>>
	 */
	public function getRenamedPkColumns()
	{
		return $this->renamedPkColumns;
	}

	/**
	 * Whether the primary key was modified
	 *
	 * @return boolean
	 */
	public function hasModifiedPk()
	{
		return $this->renamedPkColumns || $this->removedPkColumns || $this->addedPkColumns;
	}

	/**
	 * Setter for the addedIndices property
	 *
	 * @param array<string, Index> $addedIndices
	 */
	public function setAddedIndices(array $addedIndices): void
	{
		$this->addedIndices = $addedIndices;
	}

	/**
	 * Add an added Index
	 *
	 * @param string $indexName
	 * @param Index $addedIndex
	 */
	public function addAddedIndex($indexName, Index $addedIndex): void
	{
		$this->addedIndices[$indexName] = $addedIndex;
	}

	/**
	 * Getter for the addedIndices property
	 *
	 * @return array<string, Index>
	 */
	public function getAddedIndices()
	{
		return $this->addedIndices;
	}

	/**
	 * Setter for the removedIndices property
	 *
	 * @param array<string, Index> $removedIndices
	 */
	public function setRemovedIndices(array $removedIndices): void
	{
		$this->removedIndices = $removedIndices;
	}

	/**
	 * Add a removed Index
	 *
	 * @param string $indexName
	 * @param Index $removedIndex
	 */
	public function addRemovedIndex($indexName, Index $removedIndex): void
	{
		$this->removedIndices[$indexName] = $removedIndex;
	}

	/**
	 * Getter for the removedIndices property
	 *
	 * @return array<string, Index>
	 */
	public function getRemovedIndices()
	{
		return $this->removedIndices;
	}

	/**
	 * Setter for the modifiedIndices property
	 *
	 * @param array<string, array<int, Index>> $modifiedIndices
	 */
	public function setModifiedIndices(array $modifiedIndices): void
	{
		$this->modifiedIndices = $modifiedIndices;
	}

	/**
	 * Add a modified Index
	 *
	 * @param string $indexName
	 * @param Index $fromIndex
	 * @param Index $toIndex
	 */
	public function addModifiedIndex($indexName, Index $fromIndex, Index $toIndex): void
	{
		$this->modifiedIndices[$indexName] = array($fromIndex, $toIndex);
	}

	/**
	 * Getter for the modifiedIndices property
	 *
	 * @return array<string, array<int, Index>>
	 */
	public function getModifiedIndices()
	{
		return $this->modifiedIndices;
	}

	/**
	 * Setter for the addedFks property
	 *
	 * @param array<string, ForeignKey> $addedFks
	 */
	public function setAddedFks(array $addedFks): void
	{
		$this->addedFks = $addedFks;
	}

	/**
	 * Add an added Fk column
	 *
	 * @param string $fkName
	 * @param ForeignKey $addedFk
	 */
	public function addAddedFk($fkName, ForeignKey $addedFk): void
	{
		$this->addedFks[$fkName] = $addedFk;
	}

	/**
	 * Remove an added Fk column
	 *
	 * @param string $fkName
	 */
	public function removeAddedFk($fkName): void
	{
		unset($this->addedFks[$fkName]);
	}

	/**
	 * Getter for the addedFks property
	 *
	 * @return array<string, ForeignKey>
	 */
	public function getAddedFks()
	{
		return $this->addedFks;
	}

	/**
	 * Setter for the removedFks property
	 *
	 * @param array<string, ForeignKey> $removedFks
	 */
	public function setRemovedFks(array $removedFks): void
	{
		$this->removedFks = $removedFks;
	}

	/**
	 * Add a removed Fk column
	 *
	 * @param string|null $fkName
	 * @param ForeignKey $removedFk
	 */
	public function addRemovedFk($fkName, ForeignKey $removedFk): void
	{
		$this->removedFks[$fkName ?? ''] = $removedFk;
	}

	/**
	 * Remove a removed Fk column
	 *
	 * @param string $fkName
	 */
	public function removeRemovedFk($fkName): void
	{
		unset($this->removedFks[$fkName]);
	}

	/**
	 * Getter for the removedFks property
	 *
	 * @return array<string, ForeignKey>
	 */
	public function getRemovedFks()
	{
		return $this->removedFks;
	}

	/**
	 * Setter for the modifiedFks property
	 *
	 * @param array<string, array<int, ForeignKey>> $modifiedFks
	 */
	public function setModifiedFks($modifiedFks): void
	{
		$this->modifiedFks = $modifiedFks;
	}

	/**
	 * Add a modified Fk
	 *
	 * @param string $fkName
	 * @param ForeignKey $fromFk
	 * @param ForeignKey $toFk
	 */
	public function addModifiedFk($fkName, ForeignKey $fromFk, ForeignKey $toFk): void
	{
		$this->modifiedFks[$fkName] = array($fromFk, $toFk);
	}

	/**
	 * Getter for the modifiedFks property
	 *
	 * @return array<string, array<int, ForeignKey>>
	 */
	public function getModifiedFks()
	{
		return $this->modifiedFks;
	}

	/**
	 * Get the reverse diff for this diff
	 *
	 * @return PropulsionTableDiff
	 */
	public function getReverseDiff()
	{
		$diff = new self();

		// tables
		$diff->setFromTable($this->getToTable());
		$diff->setToTable($this->getFromTable());

		// columns
		$diff->setAddedColumns($this->getRemovedColumns());
		$diff->setRemovedColumns($this->getAddedColumns());
		$renamedColumns = array();
		foreach ($this->getRenamedColumns() as $columnRenaming) {
			$renamedColumns[]= array_reverse($columnRenaming);
		}
		$diff->setRenamedColumns($renamedColumns);
		$columnDiffs = array();
		foreach ($this->getModifiedColumns() as $name => $columnDiff) {
			$columnDiffs[$name] = $columnDiff->getReverseDiff();
		}
		$diff->setModifiedColumns($columnDiffs);

		// pks
		$diff->setAddedPkColumns($this->getRemovedPkColumns());
		$diff->setRemovedPkColumns($this->getAddedPkColumns());
		$renamedPkColumns = array();
		foreach ($this->getRenamedPkColumns() as $columnRenaming) {
			$renamedPkColumns[]= array_reverse($columnRenaming);
		}
		$diff->setRenamedPkColumns($renamedPkColumns);

		// indices
		$diff->setAddedIndices($this->getRemovedIndices());
		$diff->setRemovedIndices($this->getAddedIndices());
		$indexDiffs = array();
		foreach ($this->getModifiedIndices() as $name => $indexDiff) {
			$indexDiffs[$name] = array_reverse($indexDiff);
		}
		$diff->setModifiedIndices($indexDiffs);

		// fks
		$diff->setAddedFks($this->getRemovedFks());
		$diff->setRemovedFks($this->getAddedFks());
		$fkDiffs = array();
		foreach ($this->getModifiedFks() as $name => $fkDiff) {
			$fkDiffs[$name] = array_reverse($fkDiff);
		}
		$diff->setModifiedFks($fkDiffs);

		return $diff;
	}

	public function __toString()
	{
		$ret = '';
		$ret .= sprintf("  %s:\n", $this->getFromTable()->getName());
		if ($addedColumns = $this->getAddedColumns()) {
			$ret .= "    addedColumns:\n";
			foreach ($addedColumns as $colname => $column) {
				$ret .= sprintf("      - %s\n", $colname);
			}
		}
		if ($removedColumns = $this->getRemovedColumns()) {
			$ret .= "    removedColumns:\n";
			foreach ($removedColumns as $colname => $column) {
				$ret .= sprintf("      - %s\n", $colname);
			}
		}
		if ($modifiedColumns = $this->getModifiedColumns()) {
			$ret .= "    modifiedColumns:\n";
			foreach ($modifiedColumns as $colname => $colDiff) {
				$ret .= $colDiff->__toString();
			}
		}
		if ($renamedColumns = $this->getRenamedColumns()) {
			$ret .= "    renamedColumns:\n";
			foreach ($renamedColumns as $columnRenaming) {
				list($fromColumn, $toColumn) = $columnRenaming;
				$ret .= sprintf("      %s: %s\n", $fromColumn->getName(), $toColumn->getName());
			}
		}
		if ($addedIndices = $this->getAddedIndices()) {
			$ret .= "    addedIndices:\n";
			foreach ($addedIndices as $indexName => $index) {
				$ret .= sprintf("      - %s\n", $indexName);
			}
		}
		if ($removedIndices = $this->getRemovedIndices()) {
			$ret .= "    removedIndices:\n";
			foreach ($removedIndices as $indexName => $index) {
				$ret .= sprintf("      - %s\n", $indexName);
			}
		}
		if ($modifiedIndices = $this->getModifiedIndices()) {
			$ret .= "    modifiedIndices:\n";
			foreach ($modifiedIndices as $indexName => $indexDiff) {
				$ret .= sprintf("      - %s\n", $indexName);
			}
		}
		if ($addedFks = $this->getAddedFks()) {
			$ret .= "    addedFks:\n";
			foreach ($addedFks as $fkName => $fk) {
				$ret .= sprintf("      - %s\n", $fkName);
			}
		}
		if ($removedFks = $this->getRemovedFks()) {
			$ret .= "    removedFks:\n";
			foreach ($removedFks as $fkName => $fk) {
				$ret .= sprintf("      - %s\n", $fkName);
			}
		}
		if ($modifiedFks = $this->getModifiedFks()) {
			$ret .= "    modifiedFks:\n";
			foreach ($modifiedFks as $fkName => $fkFromTo) {
				$ret .= sprintf("      %s:\n", $fkName);
				list($fromFk, $toFk) = $fkFromTo;
				$fromLocalColumns = json_encode($fromFk->getLocalColumns());
				$toLocalColumns = json_encode($toFk->getLocalColumns());
				if ($fromLocalColumns != $toLocalColumns) {
					$ret .= sprintf("          localColumns: from %s to %s\n", $fromLocalColumns, $toLocalColumns);
				}
				$fromForeignColumns = json_encode($fromFk->getForeignColumns());
				$toForeignColumns = json_encode($toFk->getForeignColumns());
				if ($fromForeignColumns != $toForeignColumns) {
					$ret .= sprintf("          foreignColumns: from %s to %s\n", $fromForeignColumns, $toForeignColumns);
				}
				if ($fromFk->normalizeFKey($fromFk->getOnUpdate()) != $toFk->normalizeFKey($toFk->getOnUpdate())) {
					$ret .= sprintf("          onUpdate: from %s to %s\n", $fromFk->getOnUpdate(), $toFk->getOnUpdate());
				}
				if ($fromFk->normalizeFKey($fromFk->getOnDelete()) != $toFk->normalizeFKey($toFk->getOnDelete())) {
					$ret .= sprintf("          onDelete: from %s to %s\n", $fromFk->getOnDelete(), $toFk->getOnDelete());
				}
			}
		}

		return $ret;
	}

}
