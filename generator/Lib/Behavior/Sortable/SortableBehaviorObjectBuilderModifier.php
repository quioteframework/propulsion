<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Sortable;

use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Model\Table;

/**
 * Behavior to add sortable columns and abilities
 *
 * @author     François Zaninotto
 * @author     heltem <heltem@o2php.com>
 */
class SortableBehaviorObjectBuilderModifier
{
	protected SortableBehavior $behavior;
	protected Table $table;
	protected ?ObjectBuilder $builder = null;
	protected ?string $objectClassname = null;
	protected ?string $peerClassname = null;
	protected ?string $queryClassname = null;

	public function __construct(SortableBehavior $behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->getTable();
	}

	protected function getParameter(string $key): string
	{
		return $this->behavior->getParameter($key);
	}

	protected function getColumnAttribute(string $name): string
	{
		return strtolower($this->behavior->getColumnForParameter($name)->getName());
	}

	protected function getColumnPhpName(string $name): string
	{
		return $this->behavior->getColumnForParameter($name)->getPhpName();
	}

	protected function setBuilder(ObjectBuilder $builder): void
	{
		$this->builder = $builder;
		$this->objectClassname = $builder->getStubObjectBuilder()->getClassname();
		$this->queryClassname = $builder->getStubQueryBuilder()->getClassname();
		$this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
	}

	/**
	 * Get the getter of the column of the behavior
	 *
	 * @return string The related getter, e.g. 'getRank'
	 */
	protected function getColumnGetter(string $columnName = 'rank_column'): string
	{
		return 'get' . $this->behavior->getColumnForParameter($columnName)->getPhpName();
	}

	/**
	 * Get the setter of the column of the behavior
	 *
	 * @return string The related setter, e.g. 'setRank'
	 */
	protected function getColumnSetter(string $columnName = 'rank_column'): string
	{
		return 'set' . $this->behavior->getColumnForParameter($columnName)->getPhpName();
	}

	public function preSave(ObjectBuilder $builder): string
	{
		return "\$this->processSortableQueries(\$con);";
	}

	public function preInsert(ObjectBuilder $builder): string
	{
		$useScope = $this->behavior->useScope();
		$this->setBuilder($builder);
		return "if (!\$this->isColumnModified({$this->peerClassname}::RANK_COL)) {
	\$this->{$this->getColumnSetter()}({$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con) + 1);
}
";
	}

	public function preDelete(ObjectBuilder $builder): string
	{
		$useScope = $this->behavior->useScope();
		$this->setBuilder($builder);
		return "
{$this->peerClassname}::shiftRank(-1, \$this->{$this->getColumnGetter()}() + 1, null, " . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);
{$this->peerClassname}::clearInstancePool();
";
	}

	public function objectAttributes(ObjectBuilder $builder): string
	{
		return "
/**
 * Queries to be executed in the save transaction
 * @var        array
 */
protected \$sortableQueries = array();
";
	}

	public function objectMethods(ObjectBuilder $builder): string
	{
		$this->setBuilder($builder);
		$script = '';
		if ($this->getParameter('rank_column') != 'rank') {
			$this->addRankAccessors($script);
		}
		if ($this->behavior->useScope() &&
				$this->getParameter('scope_column') != 'scope_value') {
			$this->addScopeAccessors($script);
		}
		$this->addIsFirst($script);
		$this->addIsLast($script);
		$this->addGetNext($script);
		$this->addGetPrevious($script);
		$this->addInsertAtRank($script);
		$this->addInsertAtBottom($script);
		$this->addInsertAtTop($script);
		$this->addMoveToRank($script);
		$this->addSwapWith($script);
		$this->addMoveUp($script);
		$this->addMoveDown($script);
		$this->addMoveToTop($script);
		$this->addMoveToBottom($script);
		$this->addRemoveFromList($script);
		$this->addProcessSortableQueries($script);

		return $script;
	}

	/**
	 * Get the wraps for getter/setter, if the rank column has not the default name
	 */
	protected function addRankAccessors(string &$script): void
	{
    $script .= "
/**
 * Wrap the getter for rank value
 *
 * @return    int
 */
public function getRank()
{
	return \$this->{$this->getColumnGetter('rank_column')}();
}

/**
 * Wrap the setter for rank value
 *
 * @param     int
 * @return    {$this->objectClassname}
 */
public function setRank(\$v)
{
	return \$this->{$this->getColumnSetter()}(\$v);
}
";
	}

	/**
	 * Get the wraps for getter/setter, if the scope column has not the default name
	 */
	protected function addScopeAccessors(string &$script): void
	{
    $script .= "
/**
 * Wrap the getter for scope value
 *
 * @return    int
 */
public function getScopeValue()
{
	return \$this->{$this->getColumnGetter('scope_column')}();
}

/**
 * Wrap the setter for scope value
 *
 * @param     int
 * @return    {$this->objectClassname}
 */
public function setScopeValue(\$v)
{
	return \$this->{$this->getColumnSetter('scope_column')}(\$v);
}
";
	}

	protected function addIsFirst(string &$script): void
	{
		$script .= "
/**
 * Check if the object is first in the list, i.e. if it has 1 for rank
 *
 * @return    boolean
 */
public function isFirst()
{
	return \$this->{$this->getColumnGetter()}() == 1;
}
";
	}

	protected function addIsLast(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Check if the object is last in the list, i.e. if its rank is the highest rank
 *
 * @param     PropulsionPDO  \$con      optional connection
 *
 * @return    boolean
 */
public function isLast(?PropulsionPDO \$con = null)
{
	return \$this->{$this->getColumnGetter()}() == {$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);
}
";
	}

	protected function addGetNext(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Get the next item in the list, i.e. the one for which rank is immediately higher
 *
 * @param     PropulsionPDO  \$con      optional connection
 *
 * @return    {$this->objectClassname}
 */
public function getNext(?PropulsionPDO \$con = null)
{";
		if ($this->behavior->getParameter('rank_column') == 'rank' && $useScope) {
			$script .= "
	return {$this->queryClassname}::create()
		->filterByRank(\$this->{$this->getColumnGetter()}() + 1)
		->inList(\$this->{$this->getColumnGetter('scope_column')}())
		->findOne(\$con);";
		} else {
			$script .= "
	return {$this->queryClassname}::create()->findOneByRank(\$this->{$this->getColumnGetter()}() + 1, " . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);";
		}

		$script .= "
}
";
	}

	protected function addGetPrevious(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Get the previous item in the list, i.e. the one for which rank is immediately lower
 *
 * @param     PropulsionPDO  \$con      optional connection
 *
 * @return    {$this->objectClassname}
 */
public function getPrevious(?PropulsionPDO \$con = null)
{";
		if ($this->behavior->getParameter('rank_column') == 'rank' && $useScope) {
			$script .= "
	return {$this->queryClassname}::create()
		->filterByRank(\$this->{$this->getColumnGetter()}() - 1)
		->inList(\$this->{$this->getColumnGetter('scope_column')}())
		->findOne(\$con);";
		} else {
			$script .= "
	return {$this->queryClassname}::create()->findOneByRank(\$this->{$this->getColumnGetter()}() - 1, " . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);";
		}
		$script .= "
}
";
	}

	protected function addInsertAtRank(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Insert at specified rank
 * The modifications are not persisted until the object is saved.
 *
 * @param     integer    \$rank rank value
 * @param     PropulsionPDO  \$con      optional connection
 *
 * @return    {$this->objectClassname} the current object
 *
 * @throws    PropulsionException
 */
public function insertAtRank(\$rank, ?PropulsionPDO \$con = null)
{";
		if ($useScope) {
			$script .= "
	if (null === \$this->{$this->getColumnGetter('scope_column')}()) {
		throw new PropulsionException('The scope must be defined before inserting an object in a suite');
	}";
		}
		$script .= "
	\$maxRank = {$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);
	if (\$rank < 1 || \$rank > \$maxRank + 1) {
		throw new PropulsionException('Invalid rank ' . \$rank);
	}
	// move the object in the list, at the given rank
	\$this->{$this->getColumnSetter()}(\$rank);
	if (\$rank != \$maxRank + 1) {
		// Keep the list modification query for the save() transaction
		\$this->sortableQueries []= array(
			'callable'  => array('$peerClassname', 'shiftRank'),
			'arguments' => array(1, \$rank, null, " . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}()" : '') . ")
		);
	}

	return \$this;
}
";
	}

	protected function addInsertAtBottom(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Insert in the last rank
 * The modifications are not persisted until the object is saved.
 *
 * @param PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 *
 * @throws    PropulsionException
 */
public function insertAtBottom(?PropulsionPDO \$con = null)
{";
		if ($useScope) {
			$script .= "
	if (null === \$this->{$this->getColumnGetter('scope_column')}()) {
		throw new PropulsionException('The scope must be defined before inserting an object in a suite');
	}";
		}
		$script .= "
	\$this->{$this->getColumnSetter()}({$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con) + 1);

	return \$this;
}
";
	}

	protected function addInsertAtTop(string &$script): void
	{
		$script .= "
/**
 * Insert in the first rank
 * The modifications are not persisted until the object is saved.
 *
 * @return    {$this->objectClassname} the current object
 */
public function insertAtTop()
{
	return \$this->insertAtRank(1);
}
";
	}

	protected function addMoveToRank(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Move the object to a new rank, and shifts the rank
 * Of the objects inbetween the old and new rank accordingly
 *
 * @param     integer   \$newRank rank value
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 *
 * @throws    PropulsionException
 */
public function moveToRank(\$newRank, ?PropulsionPDO \$con = null)
{
	if (\$this->isNew()) {
		throw new PropulsionException('New objects cannot be moved. Please use insertAtRank() instead');
	}
	if (\$con === null) {
		\$con = Propulsion::getConnection($peerClassname::DATABASE_NAME);
	}
	if (\$newRank < 1 || \$newRank > {$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con)) {
		throw new PropulsionException('Invalid rank ' . \$newRank);
	}

	\$oldRank = \$this->{$this->getColumnGetter()}();
	if (\$oldRank == \$newRank) {
		return \$this;
	}

	\$con->beginTransaction();
	try {
		// shift the objects between the old and the new rank
		\$delta = (\$oldRank < \$newRank) ? -1 : 1;
		$peerClassname::shiftRank(\$delta, min(\$oldRank, \$newRank), max(\$oldRank, \$newRank), " . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);

		// move the object to its new rank
		\$this->{$this->getColumnSetter()}(\$newRank);
		\$this->save(\$con);

		\$con->commit();
		return \$this;
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addSwapWith(string &$script): void
	{
		$script .= "
/**
 * Exchange the rank of the object with the one passed as argument, and saves both objects
 *
 * @param     {$this->objectClassname} \$object
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 *
 * @throws Exception if the database cannot execute the two updates
 */
public function swapWith(\$object, ?PropulsionPDO \$con = null)
{
	if (\$con === null) {
		\$con = Propulsion::getConnection({$this->peerClassname}::DATABASE_NAME);
	}
	\$con->beginTransaction();
	try {
		\$oldRank = \$this->{$this->getColumnGetter()}();
		\$newRank = \$object->{$this->getColumnGetter()}();
		\$this->{$this->getColumnSetter()}(\$newRank);
		\$this->save(\$con);
		\$object->{$this->getColumnSetter()}(\$oldRank);
		\$object->save(\$con);
		\$con->commit();

		return \$this;
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addMoveUp(string &$script): void
	{
		$script .= "
/**
 * Move the object higher in the list, i.e. exchanges its rank with the one of the previous object
 *
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 */
public function moveUp(?PropulsionPDO \$con = null)
{
	if (\$this->isFirst()) {
		return \$this;
	}
	if (\$con === null) {
		\$con = Propulsion::getConnection({$this->peerClassname}::DATABASE_NAME);
	}
	\$con->beginTransaction();
	try {
		\$prev = \$this->getPrevious(\$con);
		\$this->swapWith(\$prev, \$con);
		\$con->commit();

		return \$this;
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addMoveDown(string &$script): void
	{
		$script .= "
/**
 * Move the object higher in the list, i.e. exchanges its rank with the one of the next object
 *
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 */
public function moveDown(?PropulsionPDO \$con = null)
{
	if (\$this->isLast(\$con)) {
		return \$this;
	}
	if (\$con === null) {
		\$con = Propulsion::getConnection({$this->peerClassname}::DATABASE_NAME);
	}
	\$con->beginTransaction();
	try {
		\$next = \$this->getNext(\$con);
		\$this->swapWith(\$next, \$con);
		\$con->commit();

		return \$this;
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addMoveToTop(string &$script): void
	{
		$script .= "
/**
 * Move the object to the top of the list
 *
 * @param     PropulsionPDO \$con optional connection
 *
 * @return    {$this->objectClassname} the current object
 */
public function moveToTop(?PropulsionPDO \$con = null)
{
	if (\$this->isFirst()) {
		return \$this;
	}
	return \$this->moveToRank(1, \$con);
}
";
	}

	protected function addMoveToBottom(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Move the object to the bottom of the list
 *
 * @param     PropulsionPDO \$con optional connection
 *
 * @return integer the old object's rank
 */
public function moveToBottom(?PropulsionPDO \$con = null)
{
	if (\$this->isLast(\$con)) {
		return false;
	}
	if (\$con === null) {
		\$con = Propulsion::getConnection({$this->peerClassname}::DATABASE_NAME);
	}
	\$con->beginTransaction();
	try {
		\$bottom = {$this->queryClassname}::create()->getMaxRank(" . ($useScope ? "\$this->{$this->getColumnGetter('scope_column')}(), " : '') . "\$con);
		\$res = \$this->moveToRank(\$bottom, \$con);
		\$con->commit();

		return \$res;
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addRemoveFromList(string &$script): void
	{
		$useScope = $this->behavior->useScope();
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Removes the current object from the list.
 * The modifications are not persisted until the object is saved.
 *
 * @return    {$this->objectClassname} the current object
 */
public function removeFromList()
{
	// Keep the list modification query for the save() transaction
	\$this->sortableQueries []= array(
		'callable'  => array('$peerClassname', 'shiftRank'),
		'arguments' => array(-1, \$this->{$this->getColumnGetter()}() + 1, null" . ($useScope ? ", \$this->{$this->getColumnGetter('scope_column')}()" : '') . ")
	);
	// remove the object from the list
	\$this->{$this->getColumnSetter('rank_column')}(null);";
		if ($useScope) {
		$script .= "
	\$this->{$this->getColumnSetter('scope_column')}(null);";
		}
		$script .= "

	return \$this;
}
";
	}

	protected function addProcessSortableQueries(string &$script): void
	{
		$script .= "
/**
 * Execute queries that were saved to be run inside the save transaction
 */
protected function processSortableQueries(\$con)
{
	foreach (\$this->sortableQueries as \$query) {
		\$query['arguments'][]= \$con;
		call_user_func_array(\$query['callable'], \$query['arguments']);
	}
	\$this->sortableQueries = array();
}
";
	}
}