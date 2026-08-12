<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Sortable;

use Propulsion\Generator\Builder\OM\QueryBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Table;

/**
 * Behavior to add sortable query methods
 *
 * @author     François Zaninotto
 */
class SortableBehaviorQueryBuilderModifier
{
	protected SortableBehavior $behavior;
	protected Table $table;
	protected ?QueryBuilder $builder = null;
	protected ?string $objectClassname = null;
	protected ?string $peerClassname = null;
	protected ?string $queryClassname = null;

	public function __construct(SortableBehavior $behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->requireTable();
	}

	protected function getParameter(string $key): mixed
	{
		return $this->behavior->getParameter($key);
	}

	protected function getColumn(string $name): Column
	{
		$column = $this->behavior->getColumnForParameter($name);
		if ($column === null) {
			throw new EngineException(sprintf("Parameter '%s' does not reference an existing column", $name));
		}
		return $column;
	}

	private function requireBuilder(): QueryBuilder
	{
		if ($this->builder === null) {
			throw new EngineException('No builder has been set yet; setBuilder() must run before this call');
		}
		return $this->builder;
	}

	protected function setBuilder(QueryBuilder $builder): void
	{
		$this->builder = $builder;
		$this->objectClassname = $builder->getStubObjectBuilder()->getClassname();
		$this->queryClassname = $builder->getStubQueryBuilder()->getClassname();
		$this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
	}

	public function queryMethods(QueryBuilder $builder): string
	{
		$this->setBuilder($builder);
		$script = '';

		// select filters
		if ($this->behavior->useScope()) {
			$this->addInList($script);
		}
		if ($this->getParameter('rank_column') != 'rank') {
			$this->addFilterByRank($script);
			$this->addOrderByRank($script);
		}

		// select termination methods
		if ($this->getParameter('rank_column') != 'rank' || $this->behavior->useScope()) {
			$this->addFindOneByRank($script);
		}
		$this->addFindList($script);

		// utilities
		$this->addGetMaxRank($script);
		$this->addReorder($script);

		return $script;
	}

	protected function addInList(string &$script): void
	{
		$script .= "
/**
 * Returns the objects in a certain list, from the list scope
 *
 * @param     int \$scope		Scope to determine which objects node to return
 *
 * @return    static The current query, for fluid interface
 */
public function inList(\$scope = null)
{
	return \$this->addUsingAlias({$this->peerClassname}::SCOPE_COL, \$scope, Criteria::EQUAL);
}
";
	}

	protected function addFilterByRank(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Filter the query based on a rank in the list
 *
 * @param     integer   \$rank rank";
		if($useScope) {
			$script .= "
 * @param     int \$scope		Scope to determine which suite to consider";
		}
		$script .= "
 *
 * @return    static The current query, for fluid interface
 */
public function filterByRank(\$rank" . ($useScope ? ", \$scope = null" : "") . ")
{
	return \$this";
		if ($useScope) {
			$script .= "
		->inList(\$scope)";
		}
		$script .= "
		->addUsingAlias($peerClassname::RANK_COL, \$rank, Criteria::EQUAL);
}
";
	}

	protected function addOrderByRank(string &$script): void
	{
		$script .= "
/**
 * Order the query based on the rank in the list.
 * Using the default \$order, returns the item with the lowest rank first
 *
 * @param     string \$order either Criteria::ASC (default) or Criteria::DESC
 *
 * @return    static The current query, for fluid interface
 */
public function orderByRank(\$order = Criteria::ASC)
{
	\$order = strtoupper(\$order);
	switch (\$order) {
		case Criteria::ASC:
			return \$this->addAscendingOrderByColumn(\$this->getAliasedColName(" . $this->peerClassname . "::RANK_COL));
			break;
		case Criteria::DESC:
			return \$this->addDescendingOrderByColumn(\$this->getAliasedColName(" . $this->peerClassname . "::RANK_COL));
			break;
		default:
			throw new PropulsionException('" . $this->queryClassname . "::orderBy() only accepts \"asc\" or \"desc\" as argument');
	}
}
";
	}

	protected function addFindOneByRank(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Get an item from the list based on its rank
 *
 * @param     integer   \$rank rank";
		if($useScope) {
			$script .= "
 * @param     int \$scope		Scope to determine which suite to consider";
		}
		$script .= "
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    static
 */
public function findOneByRank(\$rank, " . ($useScope ? "\$scope = null, " : "") . "?PropulsionPDO \$con = null)
{
	return \$this
		->filterByRank(\$rank" . ($useScope ? ", \$scope" : "") . ")
		->findOne(\$con);
}
";
	}

	protected function addFindList(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Returns " . ($useScope ? 'a' : 'the') ." list of objects
 *";
 		if($useScope) {
 			$script .= "
 * @param      int \$scope		Scope to determine which list to return";
 		}
		$script .= "
 * @param      PropulsionPDO \$con	Connection to use.
 *
 * @return     mixed the list of results, formatted by the current formatter
 */
public function findList(" . ($useScope ? "\$scope = null, " : "") . "\$con = null)
{
	return \$this";
		if ($useScope) {
			$script .= "
		->inList(\$scope)";
		}
		$script .= "
		->orderByRank()
		->find(\$con);
}
";
	}

	protected function addGetMaxRank(string &$script): void
	{
		$this->requireBuilder()->declareClasses('Propulsion');
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Get the highest rank
 * ";
		if($useScope) {
			$script .= "
 * @param      int \$scope		Scope to determine which suite to consider";
		}
		$script .= "
 * @param     PropulsionPDO optional connection
 *
 * @return    integer highest position
 */
public function getMaxRank(" . ($useScope ? "\$scope = null, " : "") . "?PropulsionPDO \$con = null)
{
	if (\$con === null) {
		\$con = Propulsion::getConnection({$this->peerClassname}::DATABASE_NAME);
	}
	// shift the objects with a position lower than the one of object
	\$this->addSelectColumn('MAX(' . {$this->peerClassname}::RANK_COL . ')');";
		if ($useScope) {
		$script .= "
	\$this->add({$this->peerClassname}::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "
	\$stmt = \$this->getSelectStatement(\$con);
	\$rank = \$stmt->fetchColumn();
	// See SortableBehaviorPeerBuilderModifier::addGetMaxRank(): FreeTDS/pdo_dblib
	// (MSSQL) requires the cursor closed after a single scalar fetch.
	\$stmt->closeCursor();

	// See SortableBehaviorPeerBuilderModifier::addGetMaxRank(): fetchColumn()'s
	// raw return type isn't consistent across platforms for this MAX(...)
	// aggregate (e.g. pdo_oci/Oracle returns a numeric string, not an int) --
	// but null (an empty table's MAX() row) must stay null, not become
	// (int) null's 0.
	return \$rank !== null ? (int) \$rank : null;
}
";
	}

	protected function addReorder(string &$script): void
	{
		$this->requireBuilder()->declareClasses('Propulsion');
		$peerClassname = $this->peerClassname;
		$columnGetter = 'get' . $this->getColumn('rank_column')->getPhpName();
		$columnSetter = 'set' . $this->getColumn('rank_column')->getPhpName();
		$script .= "
/**
 * Reorder a set of sortable objects based on a list of id/position
 * Beware that there is no check made on the positions passed
 * So incoherent positions will result in an incoherent list
 *
 * @param     array<int|string, int> \$order id => rank pairs
 * @param     PropulsionPDO \$con   optional connection
 *
 * @return    boolean true if the reordering took place, false if a database problem prevented it
 */
public function reorder(array \$order, ?PropulsionPDO \$con = null)
{
	if (\$con === null) {
		\$con = Propulsion::getConnection($peerClassname::DATABASE_NAME);
	}

	\$con->beginTransaction();
	try {
		\$ids = array_keys(\$order);
		\$objects = \$this->findPks(\$ids, \$con);
		foreach (\$objects as \$object) {
			\$pk = \$object->getPrimaryKey();
			if (\$object->$columnGetter() != \$order[\$pk]) {
				\$object->$columnSetter(\$order[\$pk]);
				\$object->save(\$con);
			}
		}
		\$con->commit();

		return true;
	} catch (\Throwable \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

}