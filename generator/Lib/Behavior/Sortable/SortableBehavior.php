<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Sortable;

/**
 * Gives a model class the ability to be ordered
 * Uses one additional column storing the rank
 *
 * @author      Massimiliano Arione
 * @version     $Revision$
 */
use Propulsion\Generator\Model\Behavior;
class SortableBehavior extends Behavior
{
	// default parameters value
	/** @var array<string, string> */
	protected $parameters = array(
		'rank_column'  => 'sortable_rank',
		'use_scope'    => 'false',
		'scope_column' => 'sortable_scope',
	);

	protected ?SortableBehaviorObjectBuilderModifier $objectBuilderModifier = null;
	protected ?SortableBehaviorQueryBuilderModifier $queryBuilderModifier = null;
	protected ?SortableBehaviorPeerBuilderModifier $peerBuilderModifier = null;

	private function getStringParameter(string $name): string
	{
		$value = $this->getParameter($name);
		return is_string($value) ? $value : '';
	}

	/**
	 * Add the rank_column to the current table
	 */
	public function modifyTable(): void
	{
		$table = $this->requireTable();
		$rankColumn = $this->getStringParameter('rank_column');
		if (!$table->hasColumn($rankColumn)) {
			$table->addColumn(array(
				'name' => $rankColumn,
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

	public function getObjectBuilderModifier(): SortableBehaviorObjectBuilderModifier
	{
		if (is_null($this->objectBuilderModifier)) {
			$this->objectBuilderModifier = new SortableBehaviorObjectBuilderModifier($this);
		}
		return $this->objectBuilderModifier;
	}

	public function getQueryBuilderModifier(): SortableBehaviorQueryBuilderModifier
	{
		if (is_null($this->queryBuilderModifier)) {
			$this->queryBuilderModifier = new SortableBehaviorQueryBuilderModifier($this);
		}
		return $this->queryBuilderModifier;
	}

	public function getPeerBuilderModifier(): SortableBehaviorPeerBuilderModifier
	{
		if (is_null($this->peerBuilderModifier)) {
			$this->peerBuilderModifier = new SortableBehaviorPeerBuilderModifier($this);
		}
		return $this->peerBuilderModifier;
	}

	public function useScope(): bool
	{
		return $this->getParameter('use_scope') == 'true';
	}

}
