<?php
namespace Propulsion\Generator\Behavior\OptimisticLock;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;

/**
 * Adds an optimistic-lock version column: every UPDATE's WHERE clause is
 * guarded by the version value the object was loaded with, and the SET
 * clause bumps it -- a stale writer's UPDATE affects zero rows instead of
 * silently overwriting a change it never saw, and doSave() turns that into
 * a thrown ConcurrencyException (mirrors EF Core's `[ConcurrencyCheck]`/
 * `RowVersion` + `DbUpdateConcurrencyException`).
 *
 * Unrelated to VersionableBehavior, despite the similar-sounding name:
 * Versionable keeps a full history/audit-log table of every past row state;
 * this behavior keeps no history at all, it only detects a lost-update race
 * on the *current* row.
 *
 * @author     Propulsion contributors
 */
class OptimisticLockBehavior extends Behavior
{
	/** @var array<string, string> */
	protected $parameters = array(
		'version_column' => 'version',
	);

	protected ?OptimisticLockBehaviorObjectBuilderModifier $objectBuilderModifier = null;
	protected ?OptimisticLockBehaviorPeerBuilderModifier $peerBuilderModifier = null;

	public function modifyTable(): void
	{
		$table = $this->getTable();
		if ($table === null) {
			throw new EngineException('OptimisticLockBehavior can only be applied to a table.');
		}
		$versionColumnName = $this->getParameter('version_column');
		if (!is_string($versionColumnName)) {
			throw new EngineException('OptimisticLockBehavior: version_column parameter must be a string.');
		}
		if (!$table->hasColumn($versionColumnName)) {
			$table->addColumn(array(
				'name' => $versionColumnName,
				'type' => 'INTEGER',
				'required' => true,
				'default' => 0,
			));
		}
	}

	public function getObjectBuilderModifier(): OptimisticLockBehaviorObjectBuilderModifier
	{
		if ($this->objectBuilderModifier === null) {
			$this->objectBuilderModifier = new OptimisticLockBehaviorObjectBuilderModifier($this);
		}
		return $this->objectBuilderModifier;
	}

	public function getPeerBuilderModifier(): OptimisticLockBehaviorPeerBuilderModifier
	{
		if ($this->peerBuilderModifier === null) {
			$this->peerBuilderModifier = new OptimisticLockBehaviorPeerBuilderModifier($this);
		}
		return $this->peerBuilderModifier;
	}
}
