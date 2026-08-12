<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM;

/**
 * Base class for Peer-building classes.
 *
 * This class is designed so that it can be extended by a PHP4PeerBuilder in addition
 * to the "standard" PHP5PeerBuilder and PHP5ComplexOMPeerBuilder.  Hence, this class
 * should not have any actual template code in it -- simply basic logic & utility
 * methods.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Model\Table;

abstract class AbstractObjectBuilder extends OMBuilder
{

	/**
	 * Constructs a new AbstractPeerBuilder subclass.
	 */
	public function __construct(Table $table) {
		parent::__construct($table);
	}

	/**
	 * This method adds the contents of the generated class to the script.
	 *
	 * This method is abstract and should be overridden by the subclasses.
	 *
	 * Hint: Override this method in your subclass if you want to reorganize or
	 * drastically change the contents of the generated peer class.
	 *
	 * @param      string &$script The script will be modified in this method.
	 */
	abstract protected function addClassBody(&$script): void;

	/**
	 * Gets the baseClass classname if specified for table/db.
	 * If not, will return 'BaseObject' (i.e. \Propulsion\OM\BaseObject,
	 * brought into scope by the builder's own declareClass() call).
	 * @return     string
	 */
	protected function getBaseClass() {
		$class = $this->getTable()->getBaseClass();
		if ($class === null) {
			$class = "BaseObject";
		}
		return $class;
	}

	/**
	 * Gets the interface classname if specified for current table.
	 * If not, will return 'Persistent' (i.e. \Propulsion\OM\Persistent,
	 * brought into scope by the builder's own declareClass() call), unless
	 * the table is read-only, in which case there is no interface at all.
	 * @return     string|null
	 */
	protected function getInterface(): ?string {
		return ClassTools::getInterface($this->getTable());
	}

	/**
	 * Whether to add the generic mutator methods (setByName(), setByPosition(), fromArray()).
	 * This is based on the build property propulsion.addGenericMutators, and also whether the
	 * table is read-only or an alias.
	 */
	protected function isAddGenericMutators(): bool
	{
		$table = $this->getTable();
		return (!$table->isAlias() && $this->getBuildProperty('addGenericMutators') && !$table->isReadOnly());
	}

	/**
	 * Whether to add the generic accessor methods (getByName(), getByPosition(), toArray()).
	 * This is based on the build property propulsion.addGenericAccessors, and also whether the
	 * table is an alias.
	 */
	protected function isAddGenericAccessors(): bool
	{
		$table = $this->getTable();
		return (!$table->isAlias() && $this->getBuildProperty('addGenericAccessors'));
	}

	/**
	 * Whether to add the validate() method.
	 * This is based on the build property propulsion.addValidateMethod
	 */
	protected function isAddValidateMethod(): bool
	{
		return (bool) $this->getBuildProperty('addValidateMethod');
	}

	protected function hasDefaultValues(): bool
	{
		foreach ($this->getTable()->getColumns() as $col) {
			if($col->getDefaultValue() !== null) return true;
		}
		return false;
	}

	/**
	 * Checks whether any registered behavior on that table has a modifier for a hook
	 * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
	 * @return boolean
	 */
	public function hasBehaviorModifier($hookName, $modifier = null)
	{
	 	return parent::hasBehaviorModifier($hookName, 'ObjectBuilderModifier');
	}

	/**
	 * Checks whether any registered behavior on that table has a modifier for a hook
	 * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
	 * @param string &$script The script will be modified in this method.
	 */
	public function applyBehaviorModifier($hookName, &$script, string $tab = "		"): void
	{
		$this->applyBehaviorModifierBase($hookName, 'ObjectBuilderModifier', $script, $tab);
	}

	/**
	 * Checks whether any registered behavior content creator on that table exists a contentName
	 * @param string $contentName The name of the content as called from one of this class methods, e.g. "parentClassname"
	 */
	public function getBehaviorContent($contentName): mixed
	{
		return $this->getBehaviorContentBase($contentName, 'ObjectBuilderModifier');
	}

	/**
	 * Returns the class the model object extends.
	 *
	 * This used to be answered by ObjectBuilder, because the generated base class
	 * was the thing doing the extending. Now that the generated code is a trait,
	 * the *stub* carries the real parent, so the answer has to be reachable from
	 * either builder.
	 *
	 * The parentClass behavior hook has no bundled provider since
	 * concrete_inheritance was removed in 3.0, but it stays as an extension point:
	 * a project behavior can still answer it to redirect a model's parent.
	 * @return     string
	 */
	protected function getObjectParentClass(): string
	{
		$parentClass = $this->getBehaviorContent('parentClass');
		return is_string($parentClass) ? $parentClass : ClassTools::classname($this->getBaseClass());
	}

	/**
	 * Returns the interfaces the model object implements, in emission order.
	 *
	 * Never empty: Poolable is unconditional. A read-only table's object is
	 * emitted without save()/delete() and so cannot be Persistent, but it is
	 * still hydrated and still pooled, and <Model>Peer::addInstanceToPool() has
	 * to have one type that accepts both.
	 * @return     list<string>
	 */
	protected function getObjectInterfaces(): array
	{
		$implementsList = array();
		if ($this->getInterface() == "Persistent") {
			$implementsList[] = "Persistent";
		}
		$implementsList[] = "Poolable";
		// setByName()/setByPosition()/fromArray() are only emitted under this same
		// isAddGenericMutators() condition -- WritableModelInterface only ever needs
		// to be implemented in lockstep with whether those methods actually exist.
		if ($this->isAddGenericMutators()) {
			$implementsList[] = "WritableModelInterface";
		}
		return $implementsList;
	}

}
