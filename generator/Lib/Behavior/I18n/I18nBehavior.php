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
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;

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
	protected ?Table $i18nTable = null;

	/**
	 * The i18n table is only known once addI18nTable() has run as part
	 * of modifyTable(); calling this before that point is a programming
	 * error.
	 */
	public function requireI18nTable(): Table
	{
		if ($this->i18nTable === null) {
			throw new EngineException('I18n table has not been created yet; addI18nTable() must run before this call');
		}
		return $this->i18nTable;
	}

	public function requireColumn(Table $table, string $name): Column
	{
		$column = $table->getColumn($name);
		if ($column === null) {
			throw new EngineException(sprintf("Column '%s' was not found on table '%s'", $name, $table->getName()));
		}
		return $column;
	}

	public function modifyDatabase(): void
	{
		foreach ($this->requireDatabase()->getTables() as $table) {
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
		$table = $this->requireTable();
		$database = $table->requireDatabase();
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
		$table = $this->requireTable();
		$i18nTable = $this->requireI18nTable();
		$pks = $table->getPrimaryKey();
		if (count($pks) > 1) {
			throw new EngineException('The i18n behavior does not support tables with composite primary keys');
		}
		foreach ($pks as $column) {
			$columnName = $column->getName();
			if ($columnName === null) {
				throw new EngineException(sprintf("A primary key column of table '%s' has no name", $table->getName()));
			}
			if (!$i18nTable->hasColumn($columnName)) {
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
		$i18nTable = $this->requireI18nTable();
		if (!$i18nTable->hasColumn($localeColumnName)) {
			$i18nTable->addColumn(array(
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
		$table = $this->requireTable();
		$i18nTable = $this->requireI18nTable();
		foreach ($this->getI18nColumnNamesFromConfig() as $columnName) {
			if (!$i18nTable->hasColumn($columnName)) {
				if (!$table->hasColumn($columnName)) {
					throw new EngineException(sprintf('No column named %s found in table %s', $columnName, $table->getName()));
				}
				$column = $this->requireColumn($table, $columnName);
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

	/**
	 * getParameter() is typed to return mixed at the Behavior base class,
	 * but the parameters used here ('i18n_table', 'i18n_phpname',
	 * 'locale_column', 'i18n_columns') always default to (and are only
	 * ever configured as) strings, so centralize the cast-with-check
	 * here rather than sprinkling it at each call site.
	 */
	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		if (!is_string($value)) {
			throw new EngineException(sprintf("Parameter '%s' is expected to be a string", $name));
		}
		return $value;
	}

	protected function getI18nTableName(): string
	{
		return $this->replaceTokens($this->getStringParameter('i18n_table'));
	}

	protected function getI18nTablePhpName(): string
	{
		return $this->replaceTokens($this->getStringParameter('i18n_phpname'));
	}

	protected function getLocaleColumnName(): string
	{
		return $this->replaceTokens($this->getStringParameter('locale_column'));
	}

	/** @return array<int, string> */
	protected function getI18nColumnNamesFromConfig(): array
	{
		$columnNames = explode(',', $this->getStringParameter('i18n_columns'));
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
		// 'default_locale' is genuinely optional (defaults to null until
		// configured via the behavior's XML parameters), so fall back to
		// the class default whenever it isn't set to a non-empty string.
		$defaultLocale = $this->getParameter('default_locale');
		if (!is_string($defaultLocale) || $defaultLocale === '') {
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
		$table = $this->requireTable();
		foreach ($this->requireI18nTable()->getForeignKeys() as $fk) {
			if ($fk->getForeignTableName() == $table->getName()) {
				return $fk;
			}
		}
		return null;
	}

	public function getLocaleColumn(): Column
	{
		return $this->requireColumn($this->requireI18nTable(), $this->getLocaleColumnName());
	}

	/** @return array<int, Column> */
	public function getI18nColumns(): array
	{
		$columns = array();
		$i18nTable = $this->requireI18nTable();
		if ($columnNames = $this->getI18nColumnNamesFromConfig()) {
			// Strategy 1: use the i18n_columns parameter
			foreach ($columnNames as $columnName) {
				$columns []= $this->requireColumn($i18nTable, $columnName);
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
		$table = $this->requireTable();
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
