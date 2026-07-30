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
 * Adds a primary key to models defined without one
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Table;

class AutoAddPkBehavior extends Behavior
{

	// default parameters value
	protected $parameters = array(
		'name'					=> 'id',
		'autoIncrement' => 'true',
		'type'					=> 'INTEGER'
	);

	/**
	 * modifyDatabase()/modifyTable() are only ever invoked once this
	 * behavior is attached to a database/table, but getDatabase()/
	 * getTable() stay nullable to also cover the not-yet-attached
	 * construction phase. Guard against the (should-never-happen)
	 * unattached case with a clear error instead of a null dereference.
	 */
	private function requireDatabase(): Database
	{
		$database = $this->getDatabase();
		if ($database === null) {
			throw new EngineException('AutoAddPkBehavior is not attached to a database');
		}
		return $database;
	}

	private function requireTable(): Table
	{
		$table = $this->getTable();
		if ($table === null) {
			throw new EngineException('AutoAddPkBehavior is not attached to a table');
		}
		return $table;
	}

	/**
	 * Copy the behavior to the database tables
	 * Only for tables that have no Pk
	 */
	public function modifyDatabase()
	{
		foreach ($this->requireDatabase()->getTables() as $table) {
			if(!$table->hasPrimaryKey()) {
				$b = clone $this;
				$table->addBehavior($b);
			}
		}
	}

	/**
	 * Add the primary key to the current table
	 */
	public function modifyTable()
	{
		$table = $this->requireTable();
		if (!$table->hasPrimaryKey() && !$table->hasBehavior('concrete_inheritance')) {
			$columnAttributes = array_merge(array('primaryKey' => 'true'), $this->getParameters());
			$table->addColumn($columnAttributes);
		}
	}
}