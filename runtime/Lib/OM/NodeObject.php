<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\OM;

/**
 * This interface defines methods that must be implemented by all
 * business objects within the system to handle Node object.
 *
 * @author     Heltem <heltem@o2php.com> (Propel)
 * @version    $Revision$
 */
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;

interface NodeObject extends \IteratorAggregate
{
	/**
	 * If object is saved without left/right values, set them as undefined (0)
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 * @throws     PropulsionException
	 */
	public function save(?PropulsionPDO $con = null);

	/**
	 * Delete node and descendants
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 * @throws     PropulsionException
	 */
	public function delete(?PropulsionPDO $con = null);

	/**
	 * Sets node properties to make it a root node.
	 *
	 * @return     object The current object (for fluent API support)
	 * @throws     PropulsionException
	 */
	public function makeRoot();

	/**
	 * Gets the level if set, otherwise calculates this and returns it
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int
	 */
	public function getLevel(?PropulsionPDO $con = null);

	/**
	 * Get the path to the node in the tree
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     array
	 */
	public function getPath(?PropulsionPDO $con = null);

	/**
	 * Gets the number of children for the node (direct descendants)
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int
	 */
	public function getNumberOfChildren(?PropulsionPDO $con = null);

	/**
	 * Gets the total number of desceandants for the node
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int
	 */
	public function getNumberOfDescendants(?PropulsionPDO $con = null);

	/**
	 * Gets the children for the node
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     array
	 */
	public function getChildren(?PropulsionPDO $con = null);

	/**
	 * Gets the descendants for the node
	 *
	 * @param      PropulsionPDO $con	Connection to use.
 	 * @return     array
	 */
	public function getDescendants(?PropulsionPDO $con = null);

	/**
	 * Sets the level of the node in the tree
	 *
	 * @param      int $level new value
	 * @return     object The current object (for fluent API support)
	 */
	public function setLevel($level);

	/**
	 * Sets the children array of the node in the tree
	 *
	 * @param      NodeObject[] $children Array of Propulsion node objects
	 * @return     object The current object (for fluent API support)
	 */
	public function setChildren(array $children);

	/**
	 * Sets the parentNode of the node in the tree
	 *
	 * @param      NodeObject $parent Propulsion node object
	 * @return     object The current object (for fluent API support)
	 */
	public function setParentNode(?NodeObject $parent = null);

	/**
	 * Sets the previous sibling of the node in the tree
	 *
	 * @param      NodeObject $node Propulsion node object
	 * @return     object The current object (for fluent API support)
	 */
	public function setPrevSibling(?NodeObject $node = null);

	/**
	 * Sets the next sibling of the node in the tree
	 *
	 * @param      NodeObject $node Propulsion node object
	 * @return     object The current object (for fluent API support)
	 */
	public function setNextSibling(?NodeObject $node = null);

	/**
	 * Determines if the node is the root node
	 *
	 * @return     bool
	 */
	public function isRoot();

	/**
	 * Determines if the node is a leaf node
	 *
	 * @return     bool
	 */
	public function isLeaf();

	/**
	 * Tests if object is equal to $node
	 *
	 * @param      NodeObject $node	Propulsion object for node to compare to
	 * @return     bool
	 */
	public function isEqualTo(NodeObject $node);

	/**
	 * Tests if object has an ancestor
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     bool
	 */
	public function hasParent(?PropulsionPDO $con = null);

	/**
	 * Determines if the node has children / descendants
	 *
	 * @return     bool
	 */
	public function hasChildren();

	/**
	 * Determines if the node has previous sibling
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     bool
	 */
	public function hasPrevSibling(?PropulsionPDO $con = null);

	/**
	 * Determines if the node has next sibling
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     bool
	 */
	public function hasNextSibling(?PropulsionPDO $con = null);

	/**
	 * Gets ancestor for the given node if it exists
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public function retrieveParent(?PropulsionPDO $con = null);

	/**
	 * Gets first child if it exists
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public function retrieveFirstChild(?PropulsionPDO $con = null);

	/**
	 * Gets last child if it exists
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public function retrieveLastChild(?PropulsionPDO $con = null);

	/**
	 * Gets prev sibling for the given node if it exists
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public function retrievePrevSibling(?PropulsionPDO $con = null);

	/**
	 * Gets next sibling for the given node if it exists
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public function retrieveNextSibling(?PropulsionPDO $con = null);

	/**
	 * Inserts as first child of destination node $parent
	 *
	 * @param      NodeObject $parent	Propulsion object for given destination node
	 * @param      PropulsionPDO $conn	Connection to use.
	 * @return     object The current object (for fluent API support)
	 */
	public function insertAsFirstChildOf(NodeObject $parent, ?PropulsionPDO $conn = null);

	/**
	 * Inserts as last child of destination node $parent
	 *
	 * @param      NodeObject $parent	Propulsion object for given destination node
	 * @param      PropulsionPDO $conn	Connection to use.
	 * @return     object The current object (for fluent API support)
	 */
	public function insertAsLastChildOf(NodeObject $parent, ?PropulsionPDO $conn = null);

	/**
	 * Inserts node as previous sibling to destination node $dest
	 *
	 * @param      NodeObject $dest	Propulsion object for given destination node
	 * @param      PropulsionPDO $conn	Connection to use.
	 * @return     object The current object (for fluent API support)
	 */
	public function insertAsPrevSiblingOf(NodeObject $dest, ?PropulsionPDO $conn = null);

	/**
	 * Inserts node as next sibling to destination node $dest
	 *
	 * @param      NodeObject $dest	Propulsion object for given destination node
	 * @param      PropulsionPDO $conn	Connection to use.
	 * @return     object The current object (for fluent API support)
	 */
	public function insertAsNextSiblingOf(NodeObject $dest, ?PropulsionPDO $conn = null);

	/**
	 * Moves node to be first child of $parent
	 *
	 * @param      NodeObject $parent	Propulsion object for destination node
	 * @param      PropulsionPDO $conn Connection to use.
	 * @return     void
	 */
	public function moveToFirstChildOf(NodeObject $parent, ?PropulsionPDO $conn = null);

	/**
	 * Moves node to be last child of $parent
	 *
	 * @param      NodeObject $parent	Propulsion object for destination node
	 * @param      PropulsionPDO $conn Connection to use.
	 * @return     void
	 */
	public function moveToLastChildOf(NodeObject $parent, ?PropulsionPDO $conn = null);

	/**
	 * Moves node to be prev sibling to $dest
	 *
	 * @param      NodeObject $dest	Propulsion object for destination node
	 * @param      PropulsionPDO $conn Connection to use.
	 * @return     void
	 */
	public function moveToPrevSiblingOf(NodeObject $dest, ?PropulsionPDO $conn = null);

	/**
	 * Moves node to be next sibling to $dest
	 *
	 * @param      NodeObject $dest	Propulsion object for destination node
	 * @param      PropulsionPDO $conn Connection to use.
	 * @return     void
	 */
	public function moveToNextSiblingOf(NodeObject $dest, ?PropulsionPDO $conn = null);

	/**
	 * Inserts node as parent of given node.
	 *
	 * @param      NodeObject $node  Propulsion object for given destination node
	 * @param      PropulsionPDO $conn	Connection to use.
	 * @return     void
	 * @throws     PropulsionException      When trying to insert node as parent of a root node
	 */
	public function insertAsParentOf(NodeObject $node, ?PropulsionPDO $conn = null);

	/**
	 * Wraps the getter for the scope value
	 *
	 * @return     int
	 */
	public function getScopeIdValue();

	/**
	 * Set the value of scope column
	 *
	 * @param      int $v new value
	 * @return     object The current object (for fluent API support)
	 */
	public function setScopeIdValue($v);
} // NodeObject
