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
	 * Add the left, right and scope to the current table
	 */
	public function modifyTable(): void
	{
		if(!$this->getTable()->hasColumn($this->getParameter('left_column'))) {
			$this->getTable()->addColumn(array(
				'name' => $this->getParameter('left_column'),
				'type' => 'INTEGER'
			));
		}
		if(!$this->getTable()->hasColumn($this->getParameter('right_column'))) {
			$this->getTable()->addColumn(array(
				'name' => $this->getParameter('right_column'),
				'type' => 'INTEGER'
			));
		}
		if(!$this->getTable()->hasColumn($this->getParameter('level_column'))) {
			$this->getTable()->addColumn(array(
				'name' => $this->getParameter('level_column'),
				'type' => 'INTEGER'
			));
		}
		if ($this->getParameter('use_scope') == 'true' &&
			 !$this->getTable()->hasColumn($this->getParameter('scope_column'))) {
			$this->getTable()->addColumn(array(
				'name' => $this->getParameter('scope_column'),
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