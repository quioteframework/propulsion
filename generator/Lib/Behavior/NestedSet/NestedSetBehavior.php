<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\NestedSet;

/**
 * Behavior to adds nested set tree structure columns and abilities
 *
 * @author     François Zaninotto
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\Table;
class NestedSetBehavior extends Behavior
{
	// default parameters value
	/** @var array<string, string> */
	protected $parameters = array(
		'left_column'		 => 'tree_left',
		'right_column'	 => 'tree_right',
		'level_column'	 => 'tree_level',
		'use_scope'			 => 'false',
		'scope_column'	 => 'tree_scope',
		'method_proxies' => 'false'
	);

	protected ?NestedSetBehaviorObjectBuilderModifier $objectBuilderModifier = null;
	protected ?NestedSetBehaviorQueryBuilderModifier $queryBuilderModifier = null;
	protected ?NestedSetBehaviorPeerBuilderModifier $peerBuilderModifier = null;

	/**
	 * modifyTable() is only ever invoked once this behavior is attached
	 * to a table, but getTable() stays nullable to also cover the
	 * not-yet-attached construction phase. Guard against the
	 * (should-never-happen) unattached case with a clear error instead
	 * of a null dereference.
	 */
	public function requireTable(): Table
	{
		$table = $this->getTable();
		if ($table === null) {
			throw new EngineException('NestedSetBehavior is not attached to a table');
		}
		return $table;
	}

	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		return is_string($value) ? $value : '';
	}

	/**
	 * Add the left, right and scope to the current table
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$leftColumn = $this->getStringParameter('left_column');
		if(!$table->hasColumn($leftColumn)) {
			$table->addColumn(array(
				'name' => $leftColumn,
				'type' => 'INTEGER'
			));
		}
		$rightColumn = $this->getStringParameter('right_column');
		if(!$table->hasColumn($rightColumn)) {
			$table->addColumn(array(
				'name' => $rightColumn,
				'type' => 'INTEGER'
			));
		}
		$levelColumn = $this->getStringParameter('level_column');
		if(!$table->hasColumn($levelColumn)) {
			$table->addColumn(array(
				'name' => $levelColumn,
				'type' => 'INTEGER'
			));
		}
		$scopeColumn = $this->getStringParameter('scope_column');
		if ($this->getStringParameter('use_scope') == 'true' &&
			 !$table->hasColumn($scopeColumn)) {
			$table->addColumn(array(
				'name' => $scopeColumn,
				'type' => 'INTEGER'
			));
		}
	}

	public function getObjectBuilderModifier(): NestedSetBehaviorObjectBuilderModifier
	{
		if (is_null($this->objectBuilderModifier))
		{
			$this->objectBuilderModifier = new NestedSetBehaviorObjectBuilderModifier($this);
		}
		return $this->objectBuilderModifier;
	}

	public function getQueryBuilderModifier(): NestedSetBehaviorQueryBuilderModifier
	{
		if (is_null($this->queryBuilderModifier))
		{
			$this->queryBuilderModifier = new NestedSetBehaviorQueryBuilderModifier($this);
		}
		return $this->queryBuilderModifier;
	}

	public function getPeerBuilderModifier(): NestedSetBehaviorPeerBuilderModifier
	{
		if (is_null($this->peerBuilderModifier))
		{
			$this->peerBuilderModifier = new NestedSetBehaviorPeerBuilderModifier($this);
		}
		return $this->peerBuilderModifier;
	}

	public function useScope(): bool
	{
		return $this->getParameter('use_scope') == 'true';
	}

}