<?php
namespace Propulsion\Generator\Behavior\OptimisticLock;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Builder\OM\ObjectBuilder;

/**
 * @see OptimisticLockBehavior
 */
class OptimisticLockBehaviorObjectBuilderModifier
{
	protected OptimisticLockBehavior $behavior;

	public function __construct(OptimisticLockBehavior $behavior)
	{
		$this->behavior = $behavior;
	}

	protected function getColumnPhpName(): string
	{
		$column = $this->behavior->getColumnForParameter('version_column');
		if ($column === null) {
			// Shouldn't happen: OptimisticLockBehavior::modifyTable() always
			// creates this column first if it isn't already there.
			throw new \Propulsion\Generator\Exception\EngineException('OptimisticLockBehavior: version column not found on table ' . ($this->behavior->getTable()?->getName() ?? '(unknown)'));
		}
		return $column->getPhpName();
	}

	protected function getColumnGetter(): string
	{
		return 'get' . $this->getColumnPhpName();
	}

	protected function getColumnSetter(): string
	{
		return 'set' . $this->getColumnPhpName();
	}

	/**
	 * Stashes the pre-update version value and bumps the in-memory one --
	 * spliced into save()'s update branch (ObjectBuilder::addSave()), which
	 * runs before doSave()/doUpdateThis() build the UPDATE statement from
	 * modifiedColumns. Gated on isModified() so a save() call with nothing to
	 * actually persist doesn't bump the version anyway merely by virtue of
	 * this behavior touching the column itself -- setVersion() below would
	 * otherwise mark it modified and turn every no-op save() into a real
	 * UPDATE.
	 */
	public function preUpdate(ObjectBuilder $builder): string
	{
		return "if (\$this->isModified()) {
	\$this->optimisticLockPreviousVersion = \$this->{$this->getColumnGetter()}();
	\$this->{$this->getColumnSetter()}(\$this->optimisticLockPreviousVersion + 1);
}";
	}

	/**
	 * Declares the property preUpdate() stashes the pre-bump version value
	 * into, so doUpdateThis() (on the Peer class, a different object
	 * entirely) can read it back via the getter below to build the UPDATE's
	 * WHERE clause -- see OptimisticLockBehaviorPeerBuilderModifier.
	 */
	public function objectAttributes(ObjectBuilder $builder): string
	{
		return "
/**
 * The version value this object had before its current in-progress
 * update's preUpdate() bumped it -- see OptimisticLockBehavior.
 *
 * @var mixed
 */
protected \$optimisticLockPreviousVersion = null;
";
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		return "
/**
 * @see \$optimisticLockPreviousVersion
 * @return mixed
 */
public function getOptimisticLockPreviousVersion()
{
	return \$this->optimisticLockPreviousVersion;
}
";
	}

	/**
	 * Spliced right after doSave() calls {Peer}::doUpdateThis() (see
	 * ObjectBuilder::addDoSave()) -- doUpdateThis() affecting zero rows means
	 * the WHERE clause's version condition (see
	 * OptimisticLockBehaviorPeerBuilderModifier::doUpdateSelectCriteria())
	 * didn't match, i.e. some other writer already changed (or deleted) this
	 * row since it was loaded.
	 */
	public function postUpdateAffectedRows(ObjectBuilder $builder): string
	{
		$builder->declareClass('Propulsion\Exception\ConcurrencyException');

		return "if (\$updateAffectedRows === 0) {
	throw new ConcurrencyException(
		'Optimistic lock failed: another process already modified or deleted this ' . static::class
			. ' row (expected version ' . \$this->optimisticLockPreviousVersion . ').',
		\$this
	);
}";
	}
}
