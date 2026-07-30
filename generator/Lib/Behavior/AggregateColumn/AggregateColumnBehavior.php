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
 use Propulsion\Generator\Model\Behavior;
 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\ForeignKey;
 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Exception\EngineException;

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

	/**
	 * Add the aggregate key to the current table
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		if (!$columnName = $this->getParameter('name')) {
			throw new \InvalidArgumentException(sprintf('You must define a \'name\' parameter for the \'aggregate_column\' behavior in the \'%s\' table', $table->getName()));
		}
		if (!$this->getParameter('foreign_table')) {
			throw new \InvalidArgumentException(sprintf('You must define a \'foreign_table\' parameter for the \'aggregate_column\' behavior in the \'%s\' table', $table->getName()));
		}

		// add the aggregate column if not present
		if(!$this->requireTable()->containsColumn($columnName)) {
			$column = $this->requireTable()->addColumn(array(
				'name'    => $columnName,
				'type'    => 'INTEGER',
			));
		}

		// add a behavior in the foreign table to autoupdate the aggregate column
		$foreignTable = $this->requireForeignTable();
		if (!$foreignTable->hasBehavior('concrete_inheritance_parent')) {
			$relationBehavior = new AggregateColumnRelationBehavior();
			$relationBehavior->setName('aggregate_column_relation');
			$foreignKey = $this->getForeignKey();
			$relationBehavior->addParameter(array('name' => 'foreign_table', 'value' => $table->getName()));
			$relationBehavior->addParameter(array('name' => 'update_method', 'value' => 'update' . $this->getColumn()->getPhpName()));
			$foreignTable->addBehavior($relationBehavior);
		}
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		if (!$foreignTableName = $this->getParameter('foreign_table')) {
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
		$database = $this->requireTable()->getDatabase();
		foreach ($this->getForeignKey()->getColumnObjectsMapping() as $index => $columnReference) {
			$conditions[] = $columnReference['local']->getFullyQualifiedName() . ' = :p' . ($index + 1);
			$bindings[$index + 1]   = $columnReference['foreign']->getPhpName();
		}
		$tableName = $database->getTablePrefix() . $this->getParameter('foreign_table');
		if ($database->getPlatform()->supportsSchemas() && $this->getParameter('foreign_schema')) {
			$tableName = $this->getParameter('foreign_schema').'.'.$tableName;
		}
		$sql = sprintf('SELECT %s FROM %s WHERE %s',
			$this->getParameter('expression'),
			$database->getPlatform()->quoteIdentifier($tableName),
			implode(' AND ', $conditions)
		);

		return $this->renderTemplate('objectCompute', array(
			'column'   => $this->getColumn(),
			'sql'      => $sql,
			'bindings' => $bindings,
		));
	}

	protected function addObjectUpdate(): string
	{
		return $this->renderTemplate('objectUpdate', array(
			'column'  => $this->getColumn(),
		));
	}

	protected function getForeignTable(): ?Table
	{
		$database = $this->requireTable()->requireDatabase();
		$tableName = $database->getTablePrefix() . $this->getParameter('foreign_table');
		if ($database->getPlatform()?->supportsSchemas() && $this->getParameter('foreign_schema')) {
			$tableName = $this->getParameter('foreign_schema'). '.' . $tableName;
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
				$this->getParameter('foreign_table')
			));
		}
		return $foreignTable;
	}

	protected function getForeignKey(): ?ForeignKey
	{
		$foreignTable = $this->requireForeignTable();
		// let's infer the relation from the foreign table
		$fks = $foreignTable->getForeignKeysReferencingTable($this->requireTable()->getName());
		if (!$fks) {
			throw new \InvalidArgumentException(sprintf('You must define a foreign key to the \'%s\' table in the \'%s\' table to enable the \'aggregate_column\' behavior', $this->requireTable()->getName(), $foreignTable->getName()));
		}
		// FIXME doesn't work when more than one fk to the same table
		return array_shift($fks);
	}

	protected function getColumn(): ?Column
	{
		return $this->requireTable()->getColumn($this->getParameter('name'));
	}

}