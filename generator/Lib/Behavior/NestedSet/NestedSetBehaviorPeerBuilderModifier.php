<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\NestedSet;

use Propulsion\Generator\Builder\OM\PeerBuilder;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Table;

/**
 * Behavior to adds nested set tree structure columns and abilities
 *
 * @author     François Zaninotto
 * @author     heltem <heltem@o2php.com>
 */
class NestedSetBehaviorPeerBuilderModifier
{
	protected NestedSetBehavior $behavior;
	protected Table $table;
	protected ?PeerBuilder $builder = null;
	protected ?string $objectClassname = null;
	protected ?string $peerClassname = null;

	public function __construct(NestedSetBehavior $behavior)
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

	protected function getColumnAttribute(string $name): string
	{
		// See NestedSetBehaviorObjectBuilderModifier::getColumnAttribute()
		// for why this must be the PhpName, not the lowercased column name.
		return $this->getColumn($name)->getPhpName();
	}

	protected function getColumnConstant(string $name): string
	{
		$columnName = $this->getColumn($name)->getName();
		if ($columnName === null) {
			throw new EngineException(sprintf("Column for parameter '%s' has no name", $name));
		}
		return strtoupper($columnName);
	}

	private function requireBuilder(): PeerBuilder
	{
		if ($this->builder === null) {
			throw new EngineException('No builder has been set yet; setBuilder() must run before this call');
		}
		return $this->builder;
	}

	protected function getColumnPhpName(string $name): string
	{
		return $this->getColumn($name)->getPhpName();
	}

	protected function setBuilder(PeerBuilder $builder): void
	{
		$this->builder = $builder;
		$this->objectClassname = $builder->getStubObjectBuilder()->getClassname();
		$this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
	}

	public function staticAttributes(PeerBuilder $builder): string
	{
		$tableName = $this->table->getName();

		$script = "
/**
 * Left column for the set
 */
const LEFT_COL = '" . $tableName . '.' . $this->getColumnConstant('left_column') . "';

/**
 * Right column for the set
 */
const RIGHT_COL = '" . $tableName . '.' . $this->getColumnConstant('right_column') . "';

/**
 * Level column for the set
 */
const LEVEL_COL = '" . $tableName . '.' . $this->getColumnConstant('level_column') . "';
";

		if ($this->behavior->useScope()) {
			$script .= 	"
/**
 * Scope column for the set
 */
const SCOPE_COL = '" . $tableName . '.' . $this->getColumnConstant('scope_column') . "';
";
		}

		return $script;
	}

	public function staticMethods(PeerBuilder $builder): string
	{
		$this->setBuilder($builder);
		$script = '';

		if ($this->getParameter('use_scope') == 'true')
		{
			$this->addRetrieveRoots($script);
		}
		$this->addRetrieveRoot($script);
		$this->addRetrieveTree($script);
		$this->addIsValid($script);
		$this->addDeleteTree($script);
		$this->addShiftRLValues($script);
		$this->addShiftLevel($script);
		$this->addUpdateLoadedNodes($script);
		$this->addMakeRoomForLeaf($script);
		$this->addFixLevels($script);

		return $script;
	}

	protected function addRetrieveRoots(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Returns the root nodes for the tree
 *
 * @param      PropulsionPDO \$con	Connection to use.
 * @return     array<int, {$this->objectClassname}> Propulsion objects for the root nodes
 */
public static function retrieveRoots(?Criteria \$criteria = null, ?PropulsionPDO \$con = null)
{
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	\$criteria->add($peerClassname::LEFT_COL, 1, Criteria::EQUAL);
	// Every scope's own root independently has LeftValue 1, so without an
	// explicit order the relative order between different scopes' roots is
	// unspecified SQL -- Postgres/MySQL/SQLite/MSSQL happen to return this
	// small a result set in insertion order (a full-table-scan artifact, not
	// a documented guarantee), but Oracle's query planner does not.
	\$criteria->addAscendingOrderByColumn($peerClassname::SCOPE_COL);

	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}

	protected function addRetrieveRoot(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Returns the root node for a given scope
 *";
 		if($useScope) {
 			$script .= "
 * @param      int \$scope		Scope to determine which root node to return";
 		}
 		$script .= "
 * @param      PropulsionPDO \$con	Connection to use.
 * @return     {$this->objectClassname}|null Propulsion object for the root node, or null when there is none
 */
public static function retrieveRoot(" . ($useScope ? "\$scope = null, " : "") . "?PropulsionPDO \$con = null)
{
	\$c = new Criteria($peerClassname::DATABASE_NAME);
	\$c->add($peerClassname::LEFT_COL, 1, Criteria::EQUAL);";
		if($useScope) {
			$script .= "
	\$c->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "

	return $peerClassname::doSelectOne(\$c, \$con);
}
";
	}

	protected function addRetrieveTree(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Returns the whole tree node for a given scope
 *";
 		if($useScope) {
 			$script .= "
 * @param      int \$scope		Scope to determine which root node to return";
 		}
 		$script .= "
 * @param      Criteria \$criteria	Optional Criteria to filter the query
 * @param      PropulsionPDO \$con	Connection to use.
 * @return     array<int, {$this->objectClassname}> Propulsion objects for the whole tree
 */
public static function retrieveTree(" . ($useScope ? "\$scope = null, " : "") . "?Criteria \$criteria = null, ?PropulsionPDO \$con = null)
{
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	\$criteria->addAscendingOrderByColumn($peerClassname::LEFT_COL);";
		if($useScope) {
			$script .= "
	\$criteria->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "

	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}

	protected function addIsValid(string &$script): void
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Tests if node is valid
 *
 * @param      $objectClassname \$node	Propulsion object for src node
 * @return     bool
 */
public static function isValid(?$objectClassname \$node = null)
{
	if (is_object(\$node) && \$node->getRightValue() > \$node->getLeftValue()) {
		return true;
	} else {
		return false;
	}
}
";
	}

	protected function addDeleteTree(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Delete an entire tree
 * ";
 		if($useScope) {
 			$script .= "
 * @param      int \$scope		Scope to determine which tree to delete";
 		}
 		$script .= "
 * @param      PropulsionPDO \$con	Connection to use.
 *
 * @return     int  The number of deleted nodes
 */
public static function deleteTree(" . ($useScope ? "\$scope = null, " : "") . "?PropulsionPDO \$con = null)
{";
		if($useScope) {
			$script .= "
	\$c = new Criteria($peerClassname::DATABASE_NAME);
	\$c->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);
	return $peerClassname::doDelete(\$c, \$con);";
		} else {
			$script .= "
	return $peerClassname::doDeleteAll(\$con);";
		}
		$script .= "
}
";
	}

	protected function addShiftRLValues(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Adds \$delta to all L and R values that are >= \$first and <= \$last.
 * '\$delta' can also be negative.
 *
 * @param      int \$delta		Value to be shifted by, can be negative
 * @param      int \$first		First node to be shifted
 * @param      int \$last			Last node to be shifted (optional)";
		if($useScope) {
			$script .= "
 * @param      int \$scope		Scope to use for the shift";
		}
		$script .= "
 * @param      PropulsionPDO \$con		Connection to use.
 * @return     void
 */
public static function shiftRLValues(\$delta, \$first, \$last = null" . ($useScope ? ", \$scope = null" : ""). ", ?PropulsionPDO \$con = null)
{
	if (\$con === null) {
		\$con = Propulsion::getConnection($peerClassname::DATABASE_NAME, Propulsion::CONNECTION_WRITE);
	}
	// Shift left column values
	\$whereCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$criterion = \$whereCriteria->getNewCriterion($peerClassname::LEFT_COL, \$first, Criteria::GREATER_EQUAL);
	if (null !== \$last) {
		\$criterion->addAnd(\$whereCriteria->getNewCriterion($peerClassname::LEFT_COL, \$last, Criteria::LESS_EQUAL));
	}
	\$whereCriteria->add(\$criterion);";
		if ($useScope) {
			$script .= "
	\$whereCriteria->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "

	\$valuesCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$valuesCriteria->add($peerClassname::LEFT_COL, array('raw' => $peerClassname::LEFT_COL . ' + ?', 'value' => \$delta), Criteria::CUSTOM_EQUAL);

	{$this->requireBuilder()->getBasePeerClassname()}::doUpdate(\$whereCriteria, \$valuesCriteria, \$con);

	// Shift right column values
	\$whereCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$criterion = \$whereCriteria->getNewCriterion($peerClassname::RIGHT_COL, \$first, Criteria::GREATER_EQUAL);
	if (null !== \$last) {
		\$criterion->addAnd(\$whereCriteria->getNewCriterion($peerClassname::RIGHT_COL, \$last, Criteria::LESS_EQUAL));
	}
	\$whereCriteria->add(\$criterion);";
		if ($useScope) {
			$script .= "
	\$whereCriteria->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "

	\$valuesCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$valuesCriteria->add($peerClassname::RIGHT_COL, array('raw' => $peerClassname::RIGHT_COL . ' + ?', 'value' => \$delta), Criteria::CUSTOM_EQUAL);

	{$this->requireBuilder()->getBasePeerClassname()}::doUpdate(\$whereCriteria, \$valuesCriteria, \$con);
}
";
	}

	protected function addShiftLevel(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Adds \$delta to level for nodes having left value >= \$first and right value <= \$last.
 * '\$delta' can also be negative.
 *
 * @param      int \$delta		Value to be shifted by, can be negative
 * @param      int \$first		First node to be shifted
 * @param      int \$last			Last node to be shifted";
		if($useScope) {
			$script .= "
 * @param      int \$scope		Scope to use for the shift";
		}
		$script .= "
 * @param      PropulsionPDO \$con		Connection to use.
 * @return     void
 */
public static function shiftLevel(\$delta, \$first, \$last" . ($useScope ? ", \$scope = null" : ""). ", ?PropulsionPDO \$con = null)
{
	if (\$con === null) {
		\$con = Propulsion::getConnection($peerClassname::DATABASE_NAME, Propulsion::CONNECTION_WRITE);
	}
	\$whereCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$whereCriteria->add($peerClassname::LEFT_COL, \$first, Criteria::GREATER_EQUAL);
	\$whereCriteria->add($peerClassname::RIGHT_COL, \$last, Criteria::LESS_EQUAL);";
		if ($useScope) {
			$script .= "
	\$whereCriteria->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "

	\$valuesCriteria = new Criteria($peerClassname::DATABASE_NAME);
	\$valuesCriteria->add($peerClassname::LEVEL_COL, array('raw' => $peerClassname::LEVEL_COL . ' + ?', 'value' => \$delta), Criteria::CUSTOM_EQUAL);

	{$this->requireBuilder()->getBasePeerClassname()}::doUpdate(\$whereCriteria, \$valuesCriteria, \$con);
}
";
	}

	protected function addUpdateLoadedNodes(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Reload all already loaded nodes to sync them with updated db
 *
 * @param      $objectClassname \$prune		Object to prune from the update
 * @param      PropulsionPDO \$con		Connection to use.
 * @return     void
 */
public static function updateLoadedNodes(\$prune = null, ?PropulsionPDO \$con = null)
{
	if (Propulsion::isInstancePoolingEnabled()) {
		\$keys = array();
		foreach ($peerClassname::getInstancePool() as \$obj) {
			if (!\$prune || !\$prune->equals(\$obj)) {
				\$keys[] = \$obj->getPrimaryKey();
			}
		}

		if (!empty(\$keys)) {
			// We don't need to alter the object instance pool; we're just modifying these ones
			// already in the pool.
			\$criteria = new Criteria($peerClassname::DATABASE_NAME);";
		if (count($this->table->getPrimaryKey()) === 1) {
			$pkey = $this->table->getPrimaryKey();
			$col = array_shift($pkey);
			$script .= "
			\$criteria->add(".$this->requireBuilder()->getColumnConstant($col).", \$keys, Criteria::IN);";
		} else {
			$fields = array();
			foreach ($this->table->getPrimaryKey() as $k => $col) {
				$fields[] = $this->requireBuilder()->getColumnConstant($col);
			};
			$script .= "

			// Loop on each instances in pool
			foreach (\$keys as \$values) {
			  // Create initial Criterion
				\$cton = \$criteria->getNewCriterion(" . $fields[0] . ", \$values[0]);";
			unset($fields[0]);
			foreach ($fields as $k => $col) {
				$script .= "

				// Create next criterion
				\$nextcton = \$criteria->getNewCriterion(" . $col . ", \$values[$k]);
				// And merge it with the first
				\$cton->addAnd(\$nextcton);";
			}
			$script .= "

				// Add final Criterion to Criteria
				\$criteria->addOr(\$cton);
			}";
		}

		$script .= "
			\$stmt = $peerClassname::doSelectStmt(\$criteria, \$con);
			// is_array() rather than a bare truthiness test: PDOStatement::fetch()
			// is declared to return mixed, so every \$row[N] read below was an
			// access on mixed. It returns false when the result set is exhausted,
			// so this terminates identically while giving the rows a type.
			while (is_array(\$row = \$stmt->fetch(PDO::FETCH_NUM))) {
				\$key = $peerClassname::getPrimaryKeyHashFromRow(\$row, 0);
				if (null !== (\$object = $peerClassname::getInstanceFromPool(\$key))) {";
		$n = 0;
		foreach ($this->table->getColumns() as $col) {
			if ($col->isLazyLoad()) continue;
			if ($col->getPhpName() == $this->getColumnPhpName('left_column')) {
				$script .= "
					\$object->setLeftValue(\$row[$n]);";
			} else if ($col->getPhpName() == $this->getColumnPhpName('right_column')) {
				$script .= "
					\$object->setRightValue(\$row[$n]);";
			} else if ($col->getPhpName() == $this->getColumnPhpName('level_column')) {
				$script .= "
					\$object->setLevel(\$row[$n]);
					\$object->clearNestedSetChildren();";
			}
			$n++;
		}
		$script .= "
				}
			}
			\$stmt->closeCursor();
		}
	}
}
";
	}

	protected function addMakeRoomForLeaf(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Update the tree to allow insertion of a leaf at the specified position
 *
 * @param      int \$left	left column value";
 		if ($useScope) {
 			 		$script .= "
 * @param      integer \$scope	scope column value";
 		}
 		$script .= "
 * @param      mixed \$prune	Object to prune from the shift
 * @param      PropulsionPDO \$con	Connection to use.
 * @return     void
 */
public static function makeRoomForLeaf(\$left" . ($useScope ? ", \$scope" : ""). ", \$prune = null, ?PropulsionPDO \$con = null)
{
	// Update database nodes
	$peerClassname::shiftRLValues(2, \$left, null" . ($useScope ? ", \$scope" : "") . ", \$con);

	// Update all loaded nodes
	$peerClassname::updateLoadedNodes(\$prune, \$con);
}
";
	}

	protected function addFixLevels(string &$script): void
	{
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Update the tree to allow insertion of a leaf at the specified position
 *";
 		if ($useScope) {
 			 		$script .= "
 * @param      integer \$scope	scope column value";
 		}
 		$script .= "
 * @param      PropulsionPDO \$con	Connection to use.
 * @return     void
 */
public static function fixLevels(" . ($useScope ? "\$scope, " : ""). "?PropulsionPDO \$con = null)
{
	\$c = new Criteria();";
		if ($useScope) {
			$script .= "
	\$c->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "
	\$c->addAscendingOrderByColumn($peerClassname::LEFT_COL);
	\$stmt = $peerClassname::doSelectStmt(\$c, \$con);
	";
		if (!$this->table->getChildrenColumn()) {
			$script .= "
	// set the class once to avoid overhead in the loop
	\$cls = $peerClassname::getOMClass(false);";
		}

		$script .= "
	// Buffer every row (and close the cursor) before the loop below, rather
	// than iterating \$stmt lazily -- the loop body calls \$obj->save(\$con) on
	// the very same connection, which FreeTDS/pdo_dblib (MSSQL) can't do while
	// a SELECT's result set is still open (no MARS support): \"Attempt to
	// initiate a new Adaptive Server operation with results pending\".
	\$rows = \$stmt->fetchAll(PDO::FETCH_NUM);
	\$stmt->closeCursor();

	\$level = null;
	foreach (\$rows as \$row) {

		// hydrate object
		\$key = $peerClassname::getPrimaryKeyHashFromRow(\$row, 0);
		if (null === (\$obj = $peerClassname::getInstanceFromPool(\$key))) {";
		if ($this->table->getChildrenColumn()) {
			$script .= "
			// class must be set each time from the record row
			\$cls = $peerClassname::getOMClass(\$row, 0);
			\$cls = substr('.'.\$cls, strrpos('.'.\$cls, '.') + 1);
			" . $this->requireBuilder()->buildObjectInstanceCreationCode('$obj', '$cls') . "
			\$obj->hydrate(\$row);
			$peerClassname::addInstanceToPool(\$obj, \$key);";
		} else {
			$script .= "
			" . $this->requireBuilder()->buildObjectInstanceCreationCode('$obj', '$cls') . "
			\$obj->hydrate(\$row);
			$peerClassname::addInstanceToPool(\$obj, \$key);";
		}
		$script .= "
		}

		// compute level
		// Algorithm shamelessly stolen from sfPropulsionActAsNestedSetBehaviorPlugin
		// Probably authored by Tristan Rivoallan
		if (\$level === null) {
			\$level = 0;
			\$i = 0;
			\$prev = array(\$obj->getRightValue());
		} else {
			while (\$obj->getRightValue() > \$prev[\$i]) {
				\$i--;
			}
			\$level = ++\$i;
			\$prev[\$i] = \$obj->getRightValue();
		}

		// update level in node if necessary
		if (\$obj->getLevel() !== \$level) {
			\$obj->setLevel(\$level);
			\$obj->save(\$con);
		}
	}
}
";
	}
}