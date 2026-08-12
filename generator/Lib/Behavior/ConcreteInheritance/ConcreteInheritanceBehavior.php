<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

 namespace Propulsion\Generator\Behavior\ConcreteInheritance;

/**
 * Makes a model inherit another one. The model with this behavior gets a copy
 * of the structure of the parent model. In addition, both the ActiveRecord and
 * ActiveQuery classes will extend the related classes of the parent model.
 * Lastly (an optionally), the data from a model with this behavior is copied
 * to the parent model.
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */

use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\OM\AbstractObjectBuilder;
use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Builder\OM\QueryBuilder;
use Propulsion\Generator\Builder\OM\AbstractPeerBuilder;

class ConcreteInheritanceBehavior extends Behavior
{
	/**
	 * default parameters value
	 * @var array<string, mixed>
	 */
	protected $parameters = array(
		'extends'             => '',
		'descendant_column'   => 'descendant_class',
		'copy_data_to_parent' => 'true',
		'schema'              => ''
	);

	protected ?ObjectBuilder $builder = null;

	protected ?bool $isParentChild = null;

	/**
	 * getParentTable() legitimately returns null when the 'extends'
	 * parameter doesn't resolve to a real table (e.g. a typo in the
	 * schema.xml), but every call site here requires a real parent
	 * table to do anything useful, so surface that misconfiguration as
	 * a clear error instead of a null dereference.
	 */
	private function requireParentTable(): Table
	{
		$parentTable = $this->getParentTable();
		if ($parentTable === null) {
			throw new EngineException(sprintf(
				"ConcreteInheritanceBehavior on table '%s' could not resolve its parent table (parameter 'extends' = '%s')",
				$this->requireTable()->getName(),
				$this->getStringParameter('extends'),
			));
		}
		return $parentTable;
	}

	private function requireBuilder(): ObjectBuilder
	{
		if ($this->builder === null) {
			throw new EngineException('No builder has been set yet; objectMethods() must run before this call');
		}
		return $this->builder;
	}

	private function requireColumn(Table $table, string $name): \Propulsion\Generator\Model\Column
	{
		$column = $table->getColumn($name);
		if ($column === null) {
			throw new EngineException(sprintf("Column '%s' was not found on table '%s'", $name, $table->getName()));
		}
		return $column;
	}

	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$parentTable = $this->requireParentTable();

		if ($this->isCopyData()) {
			// tell the parent table that it has a descendant
			if (!$parentTable->hasBehavior('concrete_inheritance_parent')) {
				$parentBehavior = new ConcreteInheritanceParentBehavior();
				$parentBehavior->setName('concrete_inheritance_parent');
				$parentBehavior->addParameter(array('name' => 'descendant_column', 'value' => $this->getParameter('descendant_column')));
				$parentTable->addBehavior($parentBehavior);
				// The parent table's behavior modifyTable() must be executed before this one
				$parentBehavior->getTableModifier()->modifyTable();
				$parentBehavior->setTableModified(true);
			}
		}

		// Add the columns of the parent table
		foreach ($parentTable->getColumns() as $column) {
			$columnName = $column->getName();
			if ($columnName === null) {
				throw new EngineException(sprintf("A column of table '%s' has no name", $parentTable->getName()));
			}
			if ($columnName == $this->getStringParameter('descendant_column')) {
				continue;
			}
			if ($table->hasColumn($columnName)) {
				continue;
			}
			$copiedColumn = clone $column;
			if ($column->isAutoIncrement() && $this->isCopyData()) {
				$copiedColumn->setAutoIncrement(false);
			}
			$table->addColumn($copiedColumn);
			if ($column->isPrimaryKey() && $this->isCopyData()) {
				$fk = new ForeignKey();
				$fk->setForeignTableCommonName($parentTable->getCommonName());
				$fk->setForeignSchemaName($parentTable->getSchema());
				$fk->setOnDelete('CASCADE');
				$fk->setOnUpdate(null);
				$fk->addReference($copiedColumn, $column);
				$fk->isParentChild = true;
				$table->addForeignKey($fk);
			}
		}

		// add the foreign keys of the parent table
		foreach ($parentTable->getForeignKeys() as $fk) {
			$copiedFk = clone $fk;
			$copiedFk->setName('');
			$copiedFk->setRefPhpName('');
			$table->addForeignKey($copiedFk);
		}

		// add the validators of the parent table
		foreach ($parentTable->getValidators() as $validator) {
			$copiedValidator = clone $validator;
			$table->addValidator($copiedValidator);
		}

		// add the indices of the parent table
		foreach ($parentTable->getIndices() as $index) {
			$copiedIndex = clone $index;
			$copiedIndex->setName('');
			$table->addIndex($copiedIndex);
		}

		// add the unique indices of the parent table
		foreach ($parentTable->getUnices() as $unique) {
			$copiedUnique = clone $unique;
			$copiedUnique->setName('');
			$table->addUnique($copiedUnique);
		}

		// add the Behaviors of the parent table
		foreach ($parentTable->getBehaviors() as $behavior) {
			if ($behavior->getName() == 'concrete_inheritance_parent' || $behavior->getName() == 'concrete_inheritance') {
				continue;
			}
			$copiedBehavior = clone $behavior;
			$copiedBehavior->setTableModified(false);
			$table->addBehavior($copiedBehavior);
		}

	}

	protected function getParentTable(): ?Table
	{
		$database = $this->requireTable()->requireDatabase();
		$tableName = $database->getTablePrefix() . $this->getStringParameter('extends');
		$platform = $database->getPlatform();
		$schema = $this->getStringParameter('schema');
		if ($platform !== null && $platform->supportsSchemas() && $schema !== '') {
			$tableName = $schema . '.' . $tableName;
		}
		return $database->getTable($tableName);
	}

	/**
	 * getParameter() is typed to return mixed at the Behavior base class,
	 * but this behavior's own $parameters map only ever holds strings, so
	 * centralize the cast-with-check here rather than sprinkling it at
	 * each call site.
	 */
	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		if (!is_string($value)) {
			throw new EngineException(sprintf("Parameter '%s' is expected to be a string", $name));
		}
		return $value;
	}

	protected function isCopyData(): bool
	{
		return $this->getParameter('copy_data_to_parent') == 'true';
	}

	public function parentClass(OMBuilder $builder): ?string
	{
		$parentTable = $this->requireParentTable();
		// Match against the builder base classes (via instanceof) rather than
		// get_class(), since the concrete builder class name may be a bare
		// (test bootstrap-aliased) or fully-qualified name depending on the
		// active GeneratorConfig, and may be a PHP5* or PHP84* variant.
		if ($builder instanceof QueryBuilder) {
			$queryBuilder = $builder->getNewStubQueryBuilder($parentTable);
			$builder->declareClass($queryBuilder->getFullyQualifiedClassname());
			return $queryBuilder->getClassname();
		}
		if ($builder instanceof AbstractObjectBuilder) {
			$objectBuilder = $builder->getNewStubObjectBuilder($parentTable);
			$builder->declareClass($objectBuilder->getFullyQualifiedClassname());
			return $objectBuilder->getClassname();
		}
		// Deliberately no AbstractPeerBuilder arm. Peers used to chain the same way
		// -- BaseConcreteArticlePeer extends ConcreteContentPeer -- but a peer is a
		// bag of static methods with no polymorphism, and the generated child
		// redeclares every method the parent has (verified across all four chains
		// in the fixtures, including the three-deep News -> Article -> Content one:
		// nothing was inherited-but-not-redeclared). So the chain carried no
		// behaviour; it only imposed LSP, and that is what forced
		// addInstanceToPool() to widen to Poolable and doValidateThis() to drop its
		// type -- a cost paid by all 71 generated peers for the 4 that use this
		// behavior. Objects and queries keep their arm above: an object genuinely
		// is-a its parent, and that inheritance is the point of the behavior.
		return null;
	}

	public function preSave(ObjectBuilder $builder): ?string
	{
		if ($this->isCopyData()) {
			return "\$parent = \$this->getSyncParent(\$con);
\$parent->save(\$con);
\$this->setPrimaryKey(\$parent->getPrimaryKey());
";
		}

		return null;
	}

	public function postDelete(ObjectBuilder $builder): ?string
	{
		if ($this->isCopyData()) {
			return "\$this->getParentOrCreate(\$con)->delete(\$con);
";
		}

		return null;
	}

	public function objectMethods(ObjectBuilder $builder): ?string
	{
		if (!$this->isCopyData()) {
			return null;
		}
		$this->builder = $builder;
		$script = '';
		$this->addObjectGetParentOrCreate($script);
		$this->addObjectGetSyncParent($script);

		return $script;
	}

	protected function addObjectGetParentOrCreate(string &$script): void
	{
		$parentTable = $this->requireParentTable();
		$parentClass = $this->requireBuilder()->getNewStubObjectBuilder($parentTable)->getClassname();
		$script .= "
/**
 * Get or Create the parent " . $parentClass . " object of the current object
 *
 * @param     ?PropulsionPDO \$con Optional connection object
 * @return    " . $parentClass . " The parent object
 */
public function getParentOrCreate(\$con = null)
{
	if (\$this->isNew() && \$this->isPrimaryKeyNull()) {
		\$parent = new " . $parentClass . "();
		\$parent->set" . $this->requireColumn($this->requireParentTable(), $this->getStringParameter('descendant_column'))->getPhpName() . "('" . $this->requireBuilder()->getStubObjectBuilder()->getClassname() . "');
		return \$parent;
	} else {
		return " . $this->requireBuilder()->getNewStubQueryBuilder($parentTable)->getClassname() . "::create()->findPk(\$this->getPrimaryKey(), \$con);
	}
}
";
	}

	protected function addObjectGetSyncParent(string &$script): void
	{
		$parentTable = $this->requireParentTable();
		$pkeys = $parentTable->getPrimaryKey();
		$cptype = $pkeys[0]->getPhpType();
		$script .= "
/**
 * Create or Update the parent " . $parentTable->getPhpName() . " object
 * And return its primary key
 *
 * @param     ?PropulsionPDO \$con Optional connection object
 * @return    " . $cptype . " The primary key of the parent object
 */
public function getSyncParent(\$con = null)
{
	\$parent = \$this->getParentOrCreate(\$con);";
		foreach ($parentTable->getColumns() as $column) {
			if ($column->isPrimaryKey() || $column->getName() == $this->getStringParameter('descendant_column')) {
				continue;
			}
			$phpName = $column->getPhpName();
			$script .= "
	\$parent->set{$phpName}(\$this->get{$phpName}());";
		}
		foreach ($parentTable->getForeignKeys() as $fk) {
			if (isset($fk->isParentChild) && $fk->isParentChild) {
				continue;
			}
			$refPhpName = $this->requireBuilder()->getFKPhpNameAffix($fk, $plural = false);
			$script .= "
	if (\$this->get" . $refPhpName . "() && \$this->get" . $refPhpName . "()->isNew()) {
		\$parent->set" . $refPhpName . "(\$this->get" . $refPhpName . "());
	}";
		}
		$script .= "

	return \$parent;
}
";
	}

}