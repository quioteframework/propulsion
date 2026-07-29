<?php
namespace Propulsion\Generator\Behavior\OptimisticLock;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Builder\OM\PeerBuilder;
use Propulsion\Generator\Exception\EngineException;

/**
 * @see OptimisticLockBehavior
 */
class OptimisticLockBehaviorPeerBuilderModifier
{
	protected OptimisticLockBehavior $behavior;

	public function __construct(OptimisticLockBehavior $behavior)
	{
		$this->behavior = $behavior;
	}

	/**
	 * Adds the version column to doUpdateThis()'s WHERE clause (see
	 * PeerBuilder::addDoUpdate()) -- spliced right after
	 * `$selectCriteria = $values->buildPkeyCriteria()`, which otherwise only
	 * ever guards the UPDATE by primary key. `$values` is the same object
	 * instance save()/doSave() already ran preUpdate() against (see
	 * OptimisticLockBehaviorObjectBuilderModifier::preUpdate()), so its
	 * getOptimisticLockPreviousVersion() already holds the pre-bump value to
	 * check the row still has.
	 */
	public function doUpdateSelectCriteria(PeerBuilder $builder): string
	{
		$column = $this->behavior->getColumnForParameter('version_column');
		if ($column === null) {
			throw new EngineException('OptimisticLockBehavior: version column not found on table ' . ($this->behavior->getTable()?->getName() ?? '(unknown)'));
		}

		return "
			\$selectCriteria->add(" . $builder->getColumnConstant($column) . ", \$values->getOptimisticLockPreviousVersion());";
	}
}
