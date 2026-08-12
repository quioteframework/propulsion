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
		$this->addGetChildObject($script, $builder);

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

	/**
	 * $builder is taken as a parameter rather than read back off $this->builder,
	 * which is nullable and would need a runtime null check here to say something
	 * objectMethods() already knows: it has the builder in hand when it calls this.
	 */
	protected function addGetChildObject(string &$script, ObjectBuilder $builder): void
	{
		// The body below calls PropulsionQuery::from() by short name; declare it so
		// the host class gets a real `use` for it instead of leaning on the bare
		// alias in runtime/Lib/legacy-class-map.php.
		$builder->declareClass('Propulsion\\Query\\PropulsionQuery');

		$script .= "
/**
 * Get the child object of this object
 *
 * @return    mixed
 */
public function getChildObject()
{
	// One read of the descendant column rather than hasChildObject() and then the
	// getter: they ask the same question, but only a single read establishes for
	// PropulsionQuery::from() below that the class name is not null.
	\$childObjectClass = \$this->" . $this->getColumnGetter() . "();
	if (\$childObjectClass === null) {
		return null;
	}
	\$childObject = PropulsionQuery::from(\$childObjectClass)->findPk(\$this->getPrimaryKey());
	return \$childObject->hasChildObject() ? \$childObject->getChildObject() : \$childObject;
}
";
	}

}