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
 * Service class for comparing Database objects
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 *
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Database;
class PropulsionDatabaseComparator
{
	protected PropulsionDatabaseDiff $databaseDiff;
	protected ?Database $fromDatabase = null;
	protected ?Database $toDatabase = null;

	public function __construct(?PropulsionDatabaseDiff $databaseDiff = null)
	{
		$this->databaseDiff = (null === $databaseDiff) ? new PropulsionDatabaseDiff() : $databaseDiff;
	}

	public function getDatabaseDiff(): PropulsionDatabaseDiff
	{
		return $this->databaseDiff;
	}

	/**
	 * Setter for the fromDatabase property
	 *
	 * @param Database $fromDatabase
	 */
	public function setFromDatabase(Database $fromDatabase): void
	{
		$this->fromDatabase = $fromDatabase;
	}

	/**
	 * Getter for the fromDatabase property
	 *
	 * @return Database|null
	 */
	public function getFromDatabase()
	{
		return $this->fromDatabase;
	}

	/**
	 * Getter for the fromDatabase property, or throw if unset. computeDiff()
	 * always calls setFromDatabase()/setToDatabase() together before ever
	 * calling compareTables(), so every real call site can assume this.
	 */
	public function requireFromDatabase(): Database
	{
		if ($this->fromDatabase === null) {
			throw new EngineException('PropulsionDatabaseComparator has no fromDatabase set.');
		}
		return $this->fromDatabase;
	}

	/**
	 * Setter for the toDatabase property
	 *
	 * @param Database $toDatabase
	 */
	public function setToDatabase(Database $toDatabase): void
	{
		$this->toDatabase = $toDatabase;
	}

	/**
	 * Getter for the toDatabase property
	 *
	 * @return Database|null
	 */
	public function getToDatabase()
	{
		return $this->toDatabase;
	}

	/**
	 * Getter for the toDatabase property, or throw if unset -- see
	 * requireFromDatabase()'s own docblock for why this is always safe in practice.
	 */
	public function requireToDatabase(): Database
	{
		if ($this->toDatabase === null) {
			throw new EngineException('PropulsionDatabaseComparator has no toDatabase set.');
		}
		return $this->toDatabase;
	}

	/**
	 * Compute and return the difference between two database objects
	 *
	 * @param Database $fromDatabase
	 * @param Database $toDatabase
	 * @param  boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                  False by default.
	 *
	 * @return PropulsionDatabaseDiff|false return false if the two databases are similar
	 */
	public static function computeDiff(Database $fromDatabase, Database $toDatabase, $caseInsensitive = false)
	{
		$dc = new self();
		$dc->setFromDatabase($fromDatabase);
		$dc->setToDatabase($toDatabase);
		$differences = 0;
		$differences += $dc->compareTables($caseInsensitive);

		return ($differences > 0) ? $dc->getDatabaseDiff() : false;
	}

	/**
	 * Compare the tables of the fromDatabase and the toDatabase,
	 * and modifies the inner databaseDiff if necessary.
	 * Returns the number of differences.
	 *
	 * @param  boolean $caseInsensitive Whether the comparison is case insensitive.
	 *                                  False by default.
	 *
	 * @return integer The number of table differences
	 */
	public function compareTables($caseInsensitive = false)
	{
		$fromDatabase = $this->requireFromDatabase();
		$toDatabase = $this->requireToDatabase();
		$fromDatabaseTables = $fromDatabase->getTables();
		$toDatabaseTables = $toDatabase->getTables();
		$databaseDifferences = 0;

		// check for new tables in $toDatabase
		foreach ($toDatabaseTables as $table) {
			$tableName = $table->getName() ?? '';
			if (!$fromDatabase->hasTable($tableName, $caseInsensitive) && !$table->isSkipSql()) {
				$this->databaseDiff->addAddedTable($tableName, $table);
				$databaseDifferences++;
			}
		}

		// check for removed tables in $toDatabase
		foreach ($fromDatabaseTables as $table) {
			$tableName = $table->getName() ?? '';
			if (!$toDatabase->hasTable($tableName, $caseInsensitive) && !$table->isSkipSql()) {
				$this->databaseDiff->addRemovedTable($tableName, $table);
				$databaseDifferences++;
			}
		}

		// check for table differences
		foreach ($fromDatabaseTables as $fromTable) {
			$fromTableName = $fromTable->getName() ?? '';
			if ($toDatabase->hasTable($fromTableName, $caseInsensitive)) {
				$toTable = $toDatabase->getTable($fromTableName, $caseInsensitive);
				if ($toTable === null) {
					continue;
				}
				$databaseDiff = PropulsionTableComparator::computeDiff($fromTable, $toTable, $caseInsensitive);
				if ($databaseDiff) {
					$this->databaseDiff->addModifiedTable($fromTableName, $databaseDiff);
					$databaseDifferences++;
				}
			}
		}

		// check for table renamings
		foreach ($this->databaseDiff->getAddedTables() as $addedTableName => $addedTable) {
			foreach ($this->databaseDiff->getRemovedTables() as $removedTableName => $removedTable) {
				if (!PropulsionTableComparator::computeDiff($addedTable, $removedTable, $caseInsensitive)) {
					// no difference except the name, that's probably a renaming
					$this->databaseDiff->addRenamedTable($removedTableName, $addedTableName);
					$this->databaseDiff->removeAddedTable($addedTableName);
					$this->databaseDiff->removeRemovedTable($removedTableName);
					$databaseDifferences--;
				}
			}
		}

		return $databaseDifferences;
	}

}