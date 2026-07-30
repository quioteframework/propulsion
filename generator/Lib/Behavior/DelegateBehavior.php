<?php
namespace Propulsion\Generator\Behavior;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Gives a model class the ability to delegate methods to a relationship.
 *
 * @author     François Zaninotto
 */
use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Table;
class DelegateBehavior extends Behavior
{
	const ONE_TO_ONE = 1;
	const MANY_TO_ONE = 2;

	// default parameters value
	/** @var array<string,string> */
	protected $parameters = array(
		'to' => ''
	);

	/** @var array<string,int> */
	protected $delegates = array();

	/**
	 * modifyTable() and the helpers below are only ever invoked once
	 * this behavior is attached to a table, but getTable() stays
	 * nullable to also cover the not-yet-attached construction phase.
	 * Guard against the (should-never-happen) unattached case with a
	 * clear error instead of a null dereference.
	 */
	private function requireTable(): Table
	{
		$table = $this->getTable();
		if ($table === null) {
			throw new EngineException('DelegateBehavior is not attached to a table');
		}
		return $table;
	}

	private function requireDatabase(Table $table): Database
	{
		$database = $table->getDatabase();
		if ($database === null) {
			throw new EngineException(sprintf("Table '%s' is not attached to a database", $table->getName()));
		}
		return $database;
	}

	private function requireTableName(Table $table): string
	{
		$name = $table->getName();
		if ($name === null) {
			throw new EngineException('Table has no name');
		}
		return $name;
	}

	/**
	 * getDelegateTable() legitimately returns null when the delegate
	 * name (from the 'to' parameter) doesn't resolve to a real table,
	 * but modifyTable() already validates every delegate name against
	 * Database::hasTable() before it's used anywhere else, so treat a
	 * null result at any later call site as a programming error.
	 */
	private function requireDelegateTable(string $delegateTableName): Table
	{
		$delegateTable = $this->getDelegateTable($delegateTableName);
		if ($delegateTable === null) {
			throw new EngineException(sprintf("Delegate table '%s' could not be resolved", $delegateTableName));
		}
		return $delegateTable;
	}

	/**
	 * Lists the delegates and checks that the behavior can use them,
	 * And adds a fk from the delegate to the main table if not already set
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$database = $this->requireDatabase($table);
		$delegates = explode(',', $this->parameters['to']);
		foreach ($delegates as $delegate) {
			$delegate = trim($delegate);
			if (!$database->hasTable($delegate)) {
				throw new \InvalidArgumentException(sprintf(
					'No delegate table "%s" found for table "%s"',
					$delegate,
					$table->getName()
				));
			}
			if (in_array($delegate, $table->getForeignTableNames())) {
				// existing many-to-one relationship
				$type = self::MANY_TO_ONE;
			} else {
				// one_to_one relationship
				$delegateTable = $this->requireDelegateTable($delegate);
				if (in_array($table->getName(), $delegateTable->getForeignTableNames())) {
					// existing one-to-one relationship
					$fks = $delegateTable->getForeignKeysReferencingTable($this->requireTableName($table));
					$fk = $fks[0];
					if (!$fk->isLocalPrimaryKey()) {
						throw new \InvalidArgumentException(sprintf(
							'Delegate table "%s" has a relationship with table "%s", but it\'s a one-to-many relationship. The `delegate` behavior only supports one-to-one relationships in this case.',
							$delegate,
							$table->getName()
						));
					}
				} else {
					// no relationship yet: must be created
					$this->relateDelegateToMainTable($delegateTable, $table);
				}
				$type = self::ONE_TO_ONE;
			}
			$this->delegates[$delegate] = $type;
		}
	}

	protected function relateDelegateToMainTable(Table $delegateTable, Table $mainTable): void
	{
		$pks = $mainTable->getPrimaryKey();
		foreach ($pks as $column) {
			$mainColumnName = $column->getName();
			if ($mainColumnName === null) {
				throw new EngineException(sprintf("A primary key column of table '%s' has no name", $mainTable->getName()));
			}
			if (!$delegateTable->hasColumn($mainColumnName)) {
				$column = clone $column;
				$column->setAutoIncrement(false);
				$delegateTable->addColumn($column);
			}
		}
		// Add a one-to-one fk
		$fk = new ForeignKey();
		$fk->setForeignTableCommonName($mainTable->getCommonName());
		$fk->setForeignSchemaName($mainTable->getSchema());
		$fk->setDefaultJoin('LEFT JOIN');
		$fk->setOnDelete(ForeignKey::CASCADE);
		$fk->setOnUpdate(ForeignKey::NONE);
		foreach ($pks as $column) {
			$fk->addReference($column->getName(), $column->getName());
		}
		$delegateTable->addForeignKey($fk);
	}

	protected function getDelegateTable(string $delegateTableName): ?Table
	{
		return $this->requireDatabase($this->requireTable())->getTable($delegateTableName);
	}

	public function objectCall(ObjectBuilder $builder): string
	{
		$script = '';
		foreach ($this->delegates as $delegate => $type) {
			$delegateTable = $this->requireDelegateTable($delegate);
			if ($type == self::ONE_TO_ONE) {
				$fks = $delegateTable->getForeignKeysReferencingTable($this->requireTableName($this->requireTable()));
				$fk = $fks[0];
				$fkTable = $fk->getTable();
				if ($fkTable === null) {
					throw new EngineException('ForeignKey is not attached to a parent table');
				}
				$ARClassName = $builder->getNewStubObjectBuilder($fkTable)->getClassname();
				$ARFullClassName = $builder->getNewStubObjectBuilder($fkTable)->getFullyQualifiedClassname();
				$relationName = $builder->getRefFKPhpNameAffix($fk, $plural = false);
			} else {
				$fks = $this->requireTable()->getForeignKeysReferencingTable($delegate);
				$fk = $fks[0];
				$ARClassName = $builder->getNewStubObjectBuilder($delegateTable)->getClassname();
				$ARFullClassName = $builder->getNewStubObjectBuilder($delegateTable)->getFullyQualifiedClassname();
				$relationName = $builder->getFKPhpNameAffix($fk);
			}

			// Declare the class for import
			$builder->declareClass($ARFullClassName);

			$script .= "
		if (method_exists($ARClassName::class, \$name)) {
			if (!\$delegate = \$this->get$relationName()) {
				\$delegate = new $ARClassName();
				\$this->set$relationName(\$delegate);
			}
			return call_user_func_array(array(\$delegate, \$name), \$params);
		}";
		}
		$script .= "
		";
		return $script;
	}

}