<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Task;
use Propulsion\Generator\Util\PropulsionMigrationManager;
use Phing\Project;

/**
 * This Task executes the next migration down
 *
 * @author     Francois Zaninotto
 * @package    propel.generator.task
 */
class PropulsionMigrationDownTask extends BasePropulsionMigrationTask
{

	/**
	 * Main method builds all the targets for a typical propel project.
	 */
	public function main()
	{
		$manager = new PropulsionMigrationManager();
		$manager->setConnections($this->getGeneratorConfig()->getBuildConnections());
		$manager->setMigrationTable($this->getMigrationTable());
		$manager->setMigrationDir($this->getOutputDirectory());

		$previousTimestamps = $manager->getAlreadyExecutedMigrationTimestamps();
		if (!$nextMigrationTimestamp = array_pop($previousTimestamps)) {
			$this->log('No migration were ever executed on this database - nothing to reverse.');
			return false;
		}
		$this->log(sprintf(
			'Executing migration %s down',
			$manager->getMigrationClassName($nextMigrationTimestamp)
		));

		$migration = $manager->getMigrationObject($nextMigrationTimestamp);
		if (false === $migration->preDown($manager)) {
			$this->log('preDown() returned false. Aborting migration.', Project::MSG_ERR);
			return false;
		}

		// Executes every datasource's down SQL, recording the outcome in the
		// migration ledger and throwing a BuildException (failing the Phing
		// build) on the first statement failure -- see
		// BasePropulsionMigrationTask::runMigrationDirection() for the full
		// transaction/ledger semantics. Unlike the old
		// updateLatestMigrationTimestamp()-based approach, there's no need to
		// separately compute "the previous timestamp to fall back to" here:
		// once this migration's ledger row records direction=down/success, it
		// naturally drops out of PropulsionMigrationManager::getCurrentVersion()'s
		// "currently applied" set, revealing whatever timestamp (if any) is
		// still applied below it.
		$this->runMigrationDirection($manager, $nextMigrationTimestamp, 'down', $migration->getDownSQL());

		$migration->postDown($manager);

		$remainingTimestamps = $manager->getAlreadyExecutedMigrationTimestamps();
		if ($nbRemainingTimestamps = count($remainingTimestamps)) {
			$this->log(sprintf('Reverse migration complete. %d more migrations available for reverse.', $nbRemainingTimestamps));
		} else {
			$this->log('Reverse migration complete. No more migration available for reverse');
		}
	}
}
