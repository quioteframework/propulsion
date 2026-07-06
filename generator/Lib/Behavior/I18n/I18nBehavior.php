<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\I18n;

/**
 * Allows translation of text columns through transparent one-to-many relationship
 *
 * @author    Francois Zaninotto
 * @version		$Revision$
 */
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\PropulsionTypes;

class I18nBehavior extends Behavior
{
	const DEFAULT_LOCALE = 'en_EN';

	// default parameters value
	/** @var array<string, string|null> */
	protected $parameters = array(
		'i18n_table'    => '%TABLE%_i18n',
		'i18n_phpname'  => '%PHPNAME%I18n',
		'i18n_columns'  => '',
		'locale_column' => 'locale',
		'default_locale' => null,
		'locale_alias'  => '',
	);

	/** @var int */
	protected $tableModificationOrder = 70;

	protected ?I18nBehaviorObjectBuilderModifier $objectBuilderModifier = null;
	protected ?I18nBehaviorQueryBuilderModifier $queryBuilderModifier = null;
	protected ?I18nBehaviorPeerBuilderModifier $peerBuilderModifier = null;
	protected ?\Propulsion\Generator\Model\Table $i18nTable = null;

	public function modifyDatabase(): void
	{
		foreach ($this->getDatabase()->getTables() as $table) {
			if ($table->hasBehavior('i18n') && !$table->getBehavior('i18n')->getParameter('default_locale')) {
				$table->getBehavior('i18n')->addParameter(array(
					'name' => 'default_locale',
					'value' => $this->getParameter('default_locale')
				));
			}
		}
	}

	public function modifyTable(): void
	{
		$this->addI18nTable();
		$this->relateI18nTableToMainTable();
		$this->addLocaleColumnToI18n();
		$this->moveI18nColumns();
	}

	protected function addI18nTable(): void
	{
		$table = $this->getTable();
		$database = $table->getDatabase();
		$i18nTableName = $this->getI18nTableName();
		if($database->hasTable($i18nTableName)) {
			$this->i18nTable = $database->getTable($i18nTableName);
		} else {
			$this->i18nTable = $database->addTable(array(
				'name'      => $i18nTableName,
				'phpName'   => $this->getI18nTablePhpName(),
				'package'   => $table->getPackage(),
				'schema'    => $table->getSchema(),
				'namespace' => $table->getNamespace() ? '\\' . $table->getNamespace() : null,
			));
			// every behavior adding a table should re-execute database behaviors
			foreach ($database->getBehaviors() as $behavior) {
				$behavior->modifyDatabase();
			}
		}
	}

	protected function relateI18nTableToMainTable(): void
	{
		$table = $this->getTable();
		$i18nTable = $this->i18nTable;
		$pks = $this->getTable()->getPrimaryKey();
		if (count($pks) > 1) {
			throw new EngineException('The i18n behavior does not support tables with composite primary keys');
		}
		foreach ($pks as $column) {
			if (!$i18nTable->hasColumn($column->getName())) {
				$column = clone $column;
				$column->setAutoIncrement(false);
				$i18nTable->addColumn($column);
			}
		}
		if (in_array($table->getName(), $i18nTable->getForeignTableNames())) {
			return;
		}
		$fk = new ForeignKey();
		$fk->setForeignTableCommonName($table->getCommonName());
		$fk->setForeignSchemaName($table->getSchema());
		$fk->setDefaultJoin('LEFT JOIN');
		$fk->setOnDelete(ForeignKey::CASCADE);
		$fk->setOnUpdate(ForeignKey::NONE);
		foreach ($pks as $column) {
			$fk->addReference($column->getName(), $column->getName());
		}
		$i18nTable->addForeignKey($fk);
	}

	protected function addLocaleColumnToI18n(): void
	{
		$localeColumnName = $this->getLocaleColumnName();
		if (!$this->i18nTable->hasColumn($localeColumnName)) {
			$this->i18nTable->addColumn(array(
				'name'       => $localeColumnName,
				'type'       => PropulsionTypes::VARCHAR,
				'size'       => 5,
				'default'    => $this->getDefaultLocale(),
				'primaryKey' => 'true',
			));
		}
	}

	/**
	 * Moves i18n columns from the main table to the i18n table
	 */
	protected function moveI18nColumns(): void
	{
		$table = $this->getTable();
		$i18nTable = $this->i18nTable;
		foreach ($this->getI18nColumnNamesFromConfig() as $columnName) {
			if (!$i18nTable->hasColumn($columnName)) {
				if (!$table->hasColumn($columnName)) {
					throw new EngineException(sprintf('No column named %s found in table %s', $columnName, $table->getName()));
				}
				$column = $table->getColumn($columnName);
				// add the column
				$i18nColumn = $i18nTable->addColumn(clone $column);
				// add related validators
				if ($validator = $column->getValidator()) {
					$i18nValidator = $i18nTable->addValidator(clone $validator);
				}
				// FIXME: also move FKs, and indices on this column
			}
			if ($table->hasColumn($columnName)) {
				$table->removeColumn($columnName);
				$table->removeValidatorForColumn($columnName);
			}
		}
	}

	protected function getI18nTableName(): string
	{
		return $this->replaceTokens($this->getParameter('i18n_table'));
	}

	protected function getI18nTablePhpName(): string
	{
		return $this->replaceTokens($this->getParameter('i18n_phpname'));
	}

	protected function getLocaleColumnName(): string
	{
		return $this->replaceTokens($this->getParameter('locale_column'));
	}

	/** @return array<int, string> */
	protected function getI18nColumnNamesFromConfig(): array
	{
		$columnNames = explode(',', $this->getParameter('i18n_columns'));
		foreach ($columnNames as $key => $columnName) {
			if ($columnName = trim($columnName)) {
				$columnNames[$key] = $columnName;
			} else {
				unset($columnNames[$key]);
			}
		}
		return $columnNames;
	}

	public function getDefaultLocale(): string
	{
		if (!$defaultLocale = $this->getParameter('default_locale')) {
			$defaultLocale = self::DEFAULT_LOCALE;
		}
		return $defaultLocale;
	}

	public function getI18nTable(): ?\Propulsion\Generator\Model\Table
	{
		return $this->i18nTable;
	}

	public function getI18nForeignKey(): ?ForeignKey
	{
		foreach ($this->i18nTable->getForeignKeys() as $fk) {
			if ($fk->getForeignTableName() == $this->table->getName()) {
				return $fk;
			}
		}
		return null;
	}

	public function getLocaleColumn(): \Propulsion\Generator\Model\Column
	{
		return $this->getI18nTable()->getColumn($this->getLocaleColumnName());
	}

	/** @return array<int, \Propulsion\Generator\Model\Column> */
	public function getI18nColumns(): array
	{
		$columns = array();
		$i18nTable = $this->getI18nTable();
		if ($columnNames = $this->getI18nColumnNamesFromConfig()) {
			// Strategy 1: use the i18n_columns parameter
			foreach ($columnNames as $columnName) {
				$columns []= $i18nTable->getColumn($columnName);
			}
		} else {
			// strategy 2: use the columns of the i18n table
			// warning: does not work when database behaviors add columns to all tables
			// (such as timestampable behavior)
			foreach ($i18nTable->getColumns() as $column) {
				if (!$column->isPrimaryKey()) {
					$columns []= $column;
				}
			}
		}

		return $columns;
	}

	public function replaceTokens(string $string): string
	{
		$table = $this->getTable();
		return strtr($string, array(
			'%TABLE%'   => $table->getName(),
			'%PHPNAME%' => $table->getPhpName(),
		));
	}

	public function getObjectBuilderModifier(): I18nBehaviorObjectBuilderModifier
	{
		if (is_null($this->objectBuilderModifier)) {
			$this->objectBuilderModifier = new I18nBehaviorObjectBuilderModifier($this);
		}
		return $this->objectBuilderModifier;
	}

	public function getQueryBuilderModifier(): I18nBehaviorQueryBuilderModifier
	{
		if (is_null($this->queryBuilderModifier)) {
			$this->queryBuilderModifier = new I18nBehaviorQueryBuilderModifier($this);
		}
		return $this->queryBuilderModifier;
	}

	public function getPeerBuilderModifier(): I18nBehaviorPeerBuilderModifier
	{
		if (is_null($this->peerBuilderModifier)) {
			$this->peerBuilderModifier = new I18nBehaviorPeerBuilderModifier($this);
		}
		return $this->peerBuilderModifier;
	}

}
