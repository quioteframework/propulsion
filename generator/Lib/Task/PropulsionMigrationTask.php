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
 * This Task executes the next migrations up
 *
 * @author     Francois Zaninotto
 * @package    propel.generator.task
 */
class PropulsionMigrationTask extends BasePropulsionMigrationTask
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

		if (!$manager->getFirstUpMigrationTimestamp()) {
			$this->log('All migrations were already executed - nothing to migrate.');
			return false;
		}

		$timestamps = $manager->getValidMigrationTimestamps();
		if (count($timestamps) > 1) {
			$this->log(sprintf('%d migrations to execute', count($timestamps)));
		}
		foreach ($timestamps as $timestamp) {
			$this->log(sprintf(
				'Executing migration %s up',
				$manager->getMigrationClassName($timestamp)
			));
			$migration = $manager->getMigrationObject($timestamp);
			if (false === $migration->preUp($manager)) {
				$this->log('preUp() returned false. Aborting migration.', Project::MSG_ERR);
				return false;
			}

			// Executes every datasource's up SQL, recording the outcome in
			// the migration ledger and throwing a BuildException (failing
			// the Phing build) on the first statement failure -- see
			// BasePropulsionMigrationTask::runMigrationDirection() for the
			// full transaction/ledger semantics.
			$this->runMigrationDirection($manager, $timestamp, 'up', $migration->getUpSQL());

			$migration->postUp($manager);
		}

		$this->log('Migration complete. No further migration to execute.');
	}
}
