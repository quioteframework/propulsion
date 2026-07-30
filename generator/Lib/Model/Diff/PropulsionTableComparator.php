<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license     MIT License
 */
namespace Propulsion\Generator\Model\Diff;

use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Exception\EngineException;

/**
 * Service class for comparing Table objects
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 *
 */
class PropulsionTableComparator
{
	protected PropulsionTableDiff $tableDiff;

	public function __construct(?PropulsionTableDiff $tableDiff = null)
	{
		$this->tableDiff = (null === $tableDiff) ? new PropulsionTableDiff() : $tableDiff;
	}

	public function getTableDiff(): PropulsionTableDiff
	{
		return $this->tableDiff;
	}

	/**
	 * Set the table the comparator starts from
	 *
	 * @param Table $fromTable
	 */
	public function setFromTable(Table $fromTable): void
	{
		$this->tableDiff->setFromTable($fromTable);
	}

	/**
	 * Get the table the comparator starts from
	 *
	 * @return Table
	 */
	public function getFromTable(): Table
	{
		$fromTable = $this->tableDiff->getFromTable();
		if ($fromTable === null) {
			throw new EngineException('PropulsionTableComparator: fromTable has not been set.');
		}

		return $fromTable;
	}

	/**
	 * Set the table the comparator goes to
	 *
	 * @param Table $toTable
	 */
	public function setToTable(Table $toTable): void
	{
		$this->tableDiff->setToTable($toTable);
	}

	/**
	 * Get the table the comparator goes to
	 *
	 * @return Table
	 */
	public function getToTable(): Table
	{
		$toTable = $this->tableDiff->getToTable();
		if ($toTable === null) {
			throw new EngineException('PropulsionTableComparator: toTable has not been set.');
		}

		return $toTable;
	}

	/**
	 * Resolve the name of a Column, Index, or ForeignKey model object to a plain string.
	 * These names are nullable at the model layer (e.g. a ForeignKey may not have been
	 * named yet at comparison time), so fall back to an empty string rather than require
	 * a name here, matching the existing `$fkName ?? ''` convention already used by
	 * PropulsionTableDiff::addRemovedFk() for the same situation.
	 */
	private static function resolveName(Column|Index|ForeignKey $item): string
	{
		return $item->getName() ?? '';
	}

	/**
	 * Compute and return the difference between two table objects
	 *
	 * @param Table $fromTable
	 * @param Table $toTable
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return PropulsionTableDiff|false return false if the two tables are similar
	 */
	public static function computeDiff(Table $fromTable, Table $toTable, $caseInsensitive = false)
	{
		$tc = new self();
		$tc->setFromTable($fromTable);
		$tc->setToTable($toTable);
		$differences = 0;
		$differences += $tc->compareColumns($caseInsensitive);
		$differences += $tc->comparePrimaryKeys($caseInsensitive);
		$differences += $tc->compareIndices($caseInsensitive);
		$differences += $tc->compareForeignKeys($caseInsensitive);

		return ($differences > 0) ? $tc->getTableDiff() : false;
	}

	/**
	 * Compare the columns of the fromTable and the toTable,
	 * and modifies the inner tableDiff if necessary.
	 * Returns the number of differences.
	 *
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return integer The number of column differences
	 */
	public function compareColumns($caseInsensitive = false)
	{
		$fromTableColumns = $this->getFromTable()->getColumns();
		$toTableColumns = $this->getToTable()->getColumns();
		$columnDifferences = 0;

		// check for new columns in $toTable
		foreach ($toTableColumns as $column) {
			$columnName = self::resolveName($column);
			if (!$this->getFromTable()->hasColumn($columnName, $caseInsensitive)) {
				$this->tableDiff->addAddedColumn($columnName, $column);
				$columnDifferences++;
			}
		}

		// check for removed columns in $toTable
		foreach ($fromTableColumns as $column) {
			$columnName = self::resolveName($column);
			if (!$this->getToTable()->hasColumn($columnName, $caseInsensitive)) {
				$this->tableDiff->addRemovedColumn($columnName, $column);
				$columnDifferences++;
			}
		}

		// check for column differences
		foreach ($fromTableColumns as $fromColumn) {
			$fromColumnName = self::resolveName($fromColumn);
			if ($this->getToTable()->hasColumn($fromColumnName, $caseInsensitive)) {
				$toColumn = $this->getToTable()->getColumn($fromColumnName, $caseInsensitive);
				if ($toColumn === null) {
					// hasColumn() just confirmed this column exists; a null result here
					// would mean the table changed underneath us, so there's nothing to diff.
					continue;
				}
				$columnDiff = PropulsionColumnComparator::computeDiff($fromColumn, $toColumn);
				if ($columnDiff instanceof PropulsionColumnDiff) {
					$this->tableDiff->addModifiedColumn($fromColumnName, $columnDiff);
					$columnDifferences++;
				}
			}
		}

		// check for column renamings
		foreach ($this->tableDiff->getAddedColumns() as $addedColumnName => $addedColumn) {
			foreach ($this->tableDiff->getRemovedColumns() as $removedColumnName => $removedColumn) {
				if (!PropulsionColumnComparator::computeDiff($addedColumn, $removedColumn)) {
					// no difference except the name, that's probably a renaming
					$this->tableDiff->addRenamedColumn($removedColumn, $addedColumn);
					$this->tableDiff->removeAddedColumn($addedColumnName);
					$this->tableDiff->removeRemovedColumn($removedColumnName);
					$columnDifferences--;
				}
			}
		}

		return $columnDifferences;
	}

	/**
	 * Compare the primary keys of the fromTable and the toTable,
	 * and modifies the inner tableDiff if necessary.
	 * Returns the number of differences.
	 *
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return integer The number of primary key differences
	 */
	public function comparePrimaryKeys($caseInsensitive = false)
	{
		$pkDifferences = 0;
		$fromTablePk = $this->getFromTable()->getPrimaryKey();
		$toTablePk = $this->getToTable()->getPrimaryKey();

		// check for new pk columns in $toTable
		foreach ($toTablePk as $column) {
			$columnName = self::resolveName($column);
			$fromColumn = $this->getFromTable()->getColumn($columnName, $caseInsensitive);
			if ($fromColumn === null || !$fromColumn->isPrimaryKey()) {
				$this->tableDiff->addAddedPkColumn($columnName, $column);
				$pkDifferences++;
			}
		}

		// check for removed pk columns in $toTable
		foreach ($fromTablePk as $column) {
			$columnName = self::resolveName($column);
			$toColumn = $this->getToTable()->getColumn($columnName, $caseInsensitive);
			if ($toColumn === null || !$toColumn->isPrimaryKey()) {
				$this->tableDiff->addRemovedPkColumn($columnName, $column);
				$pkDifferences++;
			}
		}

		// check for column renamings
		foreach ($this->tableDiff->getAddedPkColumns() as $addedColumnName => $addedColumn) {
			foreach ($this->tableDiff->getRemovedPkColumns() as $removedColumnName => $removedColumn) {
				if (!PropulsionColumnComparator::computeDiff($addedColumn, $removedColumn)) {
					// no difference except the name, that's probably a renaming
					$this->tableDiff->addRenamedPkColumn($removedColumn, $addedColumn);
					$this->tableDiff->removeAddedPkColumn($addedColumnName);
					$this->tableDiff->removeRemovedPkColumn($removedColumnName);
					$pkDifferences--;
				}
			}
		}

		return $pkDifferences;
	}

	/**
	 * Compare the indices and unique indices of the fromTable and the toTable,
	 * and modifies the inner tableDiff if necessary.
	 * Returns the number of differences.
	 *
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return integer The number of index differences
	 */
	public function compareIndices($caseInsensitive = false)
	{
		$indexDifferences = 0;
		$fromTableIndices = array_merge($this->getFromTable()->getIndices(), $this->getFromTable()->getUnices());
		$toTableIndices = array_merge($this->getToTable()->getIndices(), $this->getToTable()->getUnices());

		foreach ($toTableIndices as $toTableIndexPos => $toTableIndex) {
			foreach ($fromTableIndices as $fromTableIndexPos => $fromTableIndex) {
				if (PropulsionIndexComparator::computeDiff($fromTableIndex, $toTableIndex, $caseInsensitive) === false) {
					unset($fromTableIndices[$fromTableIndexPos]);
					unset($toTableIndices[$toTableIndexPos]);
				} else {
					$fromTableIndexName = self::resolveName($fromTableIndex);
					$toTableIndexName = self::resolveName($toTableIndex);
					$test = $caseInsensitive ?
						strtolower($fromTableIndexName) == strtolower($toTableIndexName) :
						$fromTableIndexName == $toTableIndexName;
					if ($test) {
						// same name, but different columns
						$this->tableDiff->addModifiedIndex($fromTableIndexName, $fromTableIndex, $toTableIndex);
						unset($fromTableIndices[$fromTableIndexPos]);
						unset($toTableIndices[$toTableIndexPos]);
						$indexDifferences++;
					}
				}
			}
		}

		foreach ($fromTableIndices as $fromTableIndexPos => $fromTableIndex) {
			$this->tableDiff->addRemovedIndex(self::resolveName($fromTableIndex), $fromTableIndex);
			$indexDifferences++;
		}

		foreach ($toTableIndices as $toTableIndexPos => $toTableIndex) {
			$this->tableDiff->addAddedIndex(self::resolveName($toTableIndex), $toTableIndex);
			$indexDifferences++;
		}

		return $indexDifferences;
	}

	/**
	 * Compare the foreign keys of the fromTable and the toTable,
	 * and modifies the inner tableDiff if necessary.
	 * Returns the number of differences.
	 *
	 * @param boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                 False by default.
	 *
	 * @return integer The number of foreign key differences
	 */
	public function compareForeignKeys($caseInsensitive = false)
	{
		$fkDifferences = 0;
		$fromTableFks = $this->getFromTable()->getForeignKeys();
		$toTableFks = $this->getToTable()->getForeignKeys();

		foreach ($fromTableFks as $fromTableFkPos => $fromTableFk) {
			foreach ($toTableFks as $toTableFkPos => $toTableFk) {
				if (PropulsionForeignKeyComparator::computeDiff($fromTableFk, $toTableFk, $caseInsensitive) === false) {
					unset($fromTableFks[$fromTableFkPos]);
					unset($toTableFks[$toTableFkPos]);
				} else {
					$fromTableFkName = self::resolveName($fromTableFk);
					$toTableFkName = self::resolveName($toTableFk);
					$test = $caseInsensitive ?
						strtolower($fromTableFkName) == strtolower($toTableFkName) :
						$fromTableFkName == $toTableFkName;
					if ($test) {
						// same name, but different columns
						$this->tableDiff->addModifiedFk($fromTableFkName, $fromTableFk, $toTableFk);
						unset($fromTableFks[$fromTableFkPos]);
						unset($toTableFks[$toTableFkPos]);
						$fkDifferences++;
					}
				}
			}
		}

		foreach ($fromTableFks as $fromTableFkPos => $fromTableFk) {
			if (!$fromTableFk->isSkipSql() && !in_array($fromTableFk, $toTableFks)) {
				$this->tableDiff->addRemovedFk(self::resolveName($fromTableFk), $fromTableFk);
				$fkDifferences++;
			}
		}

		foreach ($toTableFks as $toTableFkPos => $toTableFk) {
			if (!$toTableFk->isSkipSql() && !in_array($toTableFk, $fromTableFks)) {
				$this->tableDiff->addAddedFk(self::resolveName($toTableFk), $toTableFk);
				$fkDifferences++;
			}
		}

		return $fkDifferences;
	}

}