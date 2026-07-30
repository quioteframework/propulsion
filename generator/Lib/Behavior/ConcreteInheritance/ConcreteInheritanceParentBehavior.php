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
 * Symmetrical behavior of the concrete_inheritance. When model A extends model B,
 * model A gets the concrete_inheritance behavior, and model B gets the
 * concrete_inheritance_parent
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */

 use Propulsion\Generator\Builder\OM\ObjectBuilder;
 use Propulsion\Generator\Exception\EngineException;
 use Propulsion\Generator\Model\Behavior;

class ConcreteInheritanceParentBehavior extends Behavior
{
	/**
	 * default parameters value
	 * @var array<string, mixed>
	 */
	protected $parameters = array(
		'descendant_column' => 'descendant_class'
	);

	protected ?ObjectBuilder $builder = null;

	/**
	 * modifyTable() is only ever invoked once this behavior is attached
	 * to a table, but getTable() stays nullable to also cover the
	 * not-yet-attached construction phase. Guard against the
	 * (should-never-happen) unattached case with a clear error instead
	 * of a null dereference.
	 */
	private function requireTable(): \Propulsion\Generator\Model\Table
	{
		$table = $this->getTable();
		if ($table === null) {
			throw new EngineException('ConcreteInheritanceParentBehavior is not attached to a table');
		}
		return $table;
	}

	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		if (!is_string($value)) {
			throw new EngineException(sprintf("Parameter '%s' is expected to be a string", $name));
		}
		return $value;
	}

	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$descendantColumn = $this->getStringParameter('descendant_column');
		if (!$table->hasColumn($descendantColumn)) {
			$table->addColumn(array(
				'name' => $descendantColumn,
				'type' => 'VARCHAR',
				'size' => 100
			));
		}
	}

	protected function getColumnGetter(): string
	{
		$column = $this->getColumnForParameter('descendant_column');
		if ($column === null) {
			throw new EngineException("Parameter 'descendant_column' does not reference an existing column");
		}
		return 'get' . $column->getPhpName();
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		$this->builder = $builder;
		$script = '';
		$this->addHasChildObject($script);
		$this->addGetChildObject($script);

		return $script;
	}

	protected function addHasChildObject(string &$script): void
	{
		$script .= "
/**
 * Whether or not this object is the parent of a child object
 *
 * @return    bool
 */
public function hasChildObject()
{
	return \$this->" . $this->getColumnGetter() . "() !== null;
}
";
	}

	protected function addGetChildObject(string &$script): void
	{
		$script .= "
/**
 * Get the child object of this object
 *
 * @return    mixed
 */
public function getChildObject()
{
	if (!\$this->hasChildObject()) {
		return null;
	}
	\$childObjectClass = \$this->" . $this->getColumnGetter() . "();
	\$childObject = PropulsionQuery::from(\$childObjectClass)->findPk(\$this->getPrimaryKey());
	return \$childObject->hasChildObject() ? \$childObject->getChildObject() : \$childObject;
}
";
	}

}