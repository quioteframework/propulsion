<?php
namespace Propulsion\Generator\Behavior\AggregateColumn;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Keeps an aggregate column updated with related table
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
 use Propulsion\Generator\Builder\OM\ObjectBuilder;
 use Propulsion\Generator\Exception\EngineException;
 use Propulsion\Generator\Model\Behavior;
 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\ForeignKey;
 use Propulsion\Generator\Model\Table;

class AggregateColumnBehavior extends Behavior
{

	/**
	 * default parameters value
	 * @var array<string, mixed>
	 */
	protected $parameters = array(
		'name'           => null,
		'expression'     => null,
		'foreign_table'  => null,
		'foreign_schema' => null,
	);

	private function requireTableName(Table $table): string
	{
		$name = $table->getName();
		if ($name === null) {
			throw new EngineException('Table has no name');
		}
		return $name;
	}

	/**
	 * The 'name', 'foreign_table', 'foreign_schema' and 'expression'
	 * parameters default to null until configured via the behavior's XML
	 * parameters. 'foreign_schema' and 'expression' stay genuinely
	 * optional (empty string is a valid "not set" for them); 'name' and
	 * 'foreign_table' are required for the behavior to make sense, which
	 * modifyTable()/objectMethods() already enforce explicitly.
	 */
	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		return is_string($value) ? $value : '';
	}

	/**
	 * Add the aggregate key to the current table
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$columnName = $this->getStringParameter('name');
		if ($columnName === '') {
			throw new \InvalidArgumentException(sprintf('You must define a \'name\' parameter for the \'aggregate_column\' behavior in the \'%s\' table', $table->getName()));
		}
		if ($this->getStringParameter('foreign_table') === '') {
			throw new \InvalidArgumentException(sprintf('You must define a \'foreign_table\' parameter for the \'aggregate_column\' behavior in the \'%s\' table', $table->getName()));
		}

		// add the aggregate column if not present
		if(!$table->containsColumn($columnName)) {
			$column = $table->addColumn(array(
				'name'    => $columnName,
				'type'    => 'INTEGER',
			));
		}

		// add a behavior in the foreign table to autoupdate the aggregate column
		$foreignTable = $this->requireForeignTable();
		if (!$foreignTable->hasBehavior('concrete_inheritance_parent')) {
			$relationBehavior = new AggregateColumnRelationBehavior();
			$relationBehavior->setName('aggregate_column_relation');
			$foreignKey = $this->requireForeignKey();
			$relationBehavior->addParameter(array('name' => 'foreign_table', 'value' => $table->getName()));
			$relationBehavior->addParameter(array('name' => 'update_method', 'value' => 'update' . $this->requireColumn()->getPhpName()));
			$foreignTable->addBehavior($relationBehavior);
		}
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		if ($this->getStringParameter('foreign_table') === '') {
			throw new \InvalidArgumentException(sprintf('You must define a \'foreign_table\' parameter for the \'aggregate_column\' behavior in the \'%s\' table', $this->requireTable()->getName()));
		}
		$script = '';
		$script .= $this->addObjectCompute();
		$script .= $this->addObjectUpdate();

		return $script;
	}

	protected function addObjectCompute(): string
	{
		$conditions = array();
		$bindings = array();
		$database = $this->requireTable()->requireDatabase();
		foreach ($this->requireForeignKey()->getColumnObjectsMapping() as $index => $columnReference) {
			$localColumn = $columnReference['local'];
			$foreignColumn = $columnReference['foreign'];
			if ($localColumn === null || $foreignColumn === null) {
				throw new EngineException('A foreign key column mapping resolved to a nonexistent column');
			}
			$conditions[] = $localColumn->getFullyQualifiedName() . ' = :p' . ($index + 1);
			$bindings[$index + 1]   = $foreignColumn->getPhpName();
		}
		$tableName = $database->getTablePrefix() . $this->getStringParameter('foreign_table');
		$platform = $database->getPlatform();
		$foreignSchema = $this->getStringParameter('foreign_schema');
		if ($platform !== null && $platform->supportsSchemas() && $foreignSchema !== '') {
			$tableName = $foreignSchema . '.' . $tableName;
		}
		$sql = sprintf('SELECT %s FROM %s WHERE %s',
			$this->getStringParameter('expression'),
			$platform === null ? $tableName : $platform->quoteIdentifier($tableName),
			implode(' AND ', $conditions)
		);

		return $this->renderTemplate('objectCompute', array(
			'column'   => $this->requireColumn(),
			'sql'      => $sql,
			'bindings' => $bindings,
		));
	}

	protected function addObjectUpdate(): string
	{
		return $this->renderTemplate('objectUpdate', array(
			'column'  => $this->requireColumn(),
		));
	}

	protected function getForeignTable(): ?Table
	{
		$database = $this->requireTable()->requireDatabase();
		$tableName = $database->getTablePrefix() . $this->getStringParameter('foreign_table');
		$platform = $database->getPlatform();
		$foreignSchema = $this->getStringParameter('foreign_schema');
		if ($platform !== null && $platform->supportsSchemas() && $foreignSchema !== '') {
			$tableName = $foreignSchema . '.' . $tableName;
		}
		return $database->getTable($tableName);
	}

	/**
	 * getForeignTable() is a genuine lookup (Database::getTable() by the schema-configured
	 * `foreign_table` parameter name) that can legitimately return null for a misconfigured
	 * `foreign_table` parameter -- a real schema error worth a clear message.
	 */
	protected function requireForeignTable(): Table
	{
		$foreignTable = $this->getForeignTable();
		if ($foreignTable === null) {
			throw new EngineException(sprintf(
				"aggregate_column behavior on table '%s' references unknown foreign_table '%s'.",
				$this->requireTable()->getName() ?? '(unnamed)',
				$this->getStringParameter('foreign_table')
			));
		}
		return $foreignTable;
	}

	protected function getForeignKey(): ?ForeignKey
	{
		$foreignTable = $this->requireForeignTable();
		// let's infer the relation from the foreign table
		$fks = $foreignTable->getForeignKeysReferencingTable($this->requireTableName($this->requireTable()));
		if (!$fks) {
			throw new \InvalidArgumentException(sprintf('You must define a foreign key to the \'%s\' table in the \'%s\' table to enable the \'aggregate_column\' behavior', $this->requireTable()->getName(), $foreignTable->getName()));
		}
		// FIXME doesn't work when more than one fk to the same table
		return array_shift($fks);
	}

	private function requireForeignKey(): ForeignKey
	{
		$fk = $this->getForeignKey();
		if ($fk === null) {
			throw new EngineException('getForeignKey() unexpectedly returned null after already validating the foreign key exists');
		}
		return $fk;
	}

	protected function getColumn(): ?Column
	{
		return $this->requireTable()->getColumn($this->getStringParameter('name'));
	}

	private function requireColumn(): Column
	{
		$column = $this->getColumn();
		if ($column === null) {
			throw new EngineException(sprintf("Column '%s' was not found on table '%s'", $this->getStringParameter('name'), $this->requireTable()->getName()));
		}
		return $column;
	}

}