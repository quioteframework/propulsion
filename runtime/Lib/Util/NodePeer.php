<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Util;

/**
 * This is a utility interface for all generated NodePeer classes in the system.
 *
 * @author     Heltem <heltem@o2php.com> (Propel)
 * @version    $Revision$
 * @package    propel.runtime.util
 */
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Om\NodeObject;

interface NodePeer
{
	/**
	 * Creates the supplied node as the root node.
	 *
	 * @param      object $node	Propulsion object for model
	 * @return     object		Inserted propel object for model
	 */
	public static function createRoot(NodeObject $node);

	/**
	 * Returns the root node for a given scope id
	 *
	 * @param      int $scopeId		Scope id to determine which root node to return
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     object			Propulsion object for root node
	 */
	public static function retrieveRoot($scopeId = 1, ?PropulsionPDO $con = null);

	/**
	 * Inserts $child as first child of destination node $parent
	 *
	 * @param      object $child	Propulsion object for child node
	 * @param      object $parent	Propulsion object for parent node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function insertAsFirstChildOf(NodeObject $child, NodeObject $parent, ?PropulsionPDO $con = null);

	/**
	 * Inserts $child as last child of destination node $parent
	 *
	 * @param      object $child	Propulsion object for child node
	 * @param      object $parent	Propulsion object for parent node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function insertAsLastChildOf(NodeObject $child, NodeObject $parent, ?PropulsionPDO $con = null);

	/**
	 * Inserts $sibling as previous sibling to destination node $node
	 *
	 * @param      object $node		Propulsion object for destination node
	 * @param      object $sibling	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function insertAsPrevSiblingOf(NodeObject $node, NodeObject $sibling, ?PropulsionPDO $con = null);

	/**
	 * Inserts $sibling as next sibling to destination node $node
	 *
	 * @param      object $node		Propulsion object for destination node
	 * @param      object $sibling	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function insertAsNextSiblingOf(NodeObject $node, NodeObject $sibling, ?PropulsionPDO $con = null);

	/**
	 * Inserts $parent as parent of given $node.
	 *
	 * @param      object $parent  	Propulsion object for given parent node
	 * @param      object $node  	Propulsion object for given destination node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 * @throws     Exception      When trying to insert node as parent of a root node
	 */
	public static function insertAsParentOf(NodeObject $parent, NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Inserts $node as root node
	 *
	 * @param      object $node	Propulsion object as root node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function insertRoot(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Delete root node
	 *
	 * @param      int $scopeId		Scope id to determine which root node to delete
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     boolean		Deletion status
	 */
	public static function deleteRoot($scopeId = 1, ?PropulsionPDO $con = null);

	/**
	 * Delete $dest node
	 *
	 * @param      object $dest	Propulsion object node to delete
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     boolean		Deletion status
	 */
	public static function deleteNode(NodeObject $dest, ?PropulsionPDO $con = null);

	/**
	 * Moves $child to be first child of $parent
	 *
	 * @param      object $parent	Propulsion object for parent node
	 * @param      object $child	Propulsion object for child node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function moveToFirstChildOf(NodeObject $parent, NodeObject $child, ?PropulsionPDO $con = null);

	/**
	 * Moves $node to be last child of $dest
	 *
	 * @param      object $dest	Propulsion object for destination node
	 * @param      object $node	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function moveToLastChildOf(NodeObject $dest, NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Moves $node to be prev sibling to $dest
	 *
	 * @param      object $dest	Propulsion object for destination node
	 * @param      object $node	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function moveToPrevSiblingOf(NodeObject $dest, NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Moves $node to be next sibling to $dest
	 *
	 * @param      object $dest	Propulsion object for destination node
	 * @param      object $node	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     void
	 */
	public static function moveToNextSiblingOf(NodeObject $dest, NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets first child for the given node if it exists
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public static function retrieveFirstChild(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets last child for the given node if it exists
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public static function retrieveLastChild(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets prev sibling for the given node if it exists
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public static function retrievePrevSibling(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets next sibling for the given node if it exists
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public static function retrieveNextSibling(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Retrieves the entire tree from root
	 *
	 * @param      int $scopeId		Scope id to determine which scope tree to return
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function retrieveTree($scopeId = 1, ?PropulsionPDO $con = null);

	/**
	 * Retrieves the entire tree from parent $node
	 *
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function retrieveBranch(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets direct children for the node
	 *
	 * @param      object $node	Propulsion object for parent node
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function retrieveChildren(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets all descendants for the node
	 *
	 * @param      object $node	Propulsion object for parent node
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function retrieveDescendants(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets all siblings for the node
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function retrieveSiblings(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets ancestor for the given node if it exists
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     mixed 		Propulsion object if exists else false
	 */
	public static function retrieveParent(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets level for the given node
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int			Level for the given node
	 */
	public static function getLevel(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets number of direct children for given node
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int			Level for the given node
	 */
	public static function getNumberOfChildren(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Gets number of descendants for given node
	 *
	 * @param      object $node	Propulsion object for src node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     int			Level for the given node
	 */
	public static function getNumberOfDescendants(NodeObject $node, ?PropulsionPDO $con = null);

 	/**
	 * Returns path to a specific node as an array, useful to create breadcrumbs
	 *
	 * @param      object $node	Propulsion object of node to create path to
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     array		Array in order of heirarchy
	 */
	public static function getPath(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Tests if node is valid
	 *
	 * @param      object $node	Propulsion object for src node
	 * @return     bool
	 */
	public static function isValid(?NodeObject $node = null);

	/**
	 * Tests if node is a root
	 *
	 * @param      object $node	Propulsion object for src node
	 * @return     bool
	 */
	public static function isRoot(NodeObject $node);

	/**
	 * Tests if node is a leaf
	 *
	 * @param      object $node	Propulsion object for src node
	 * @return     bool
	 */
	public static function isLeaf(NodeObject $node);

	/**
	 * Tests if $child is a child of $parent
	 *
	 * @param      object $child	Propulsion object for node
	 * @param      object $parent	Propulsion object for node
	 * @return     bool
	 */
	public static function isChildOf(NodeObject $child, NodeObject $parent);

	/**
	 * Tests if $node1 is equal to $node2
	 *
	 * @param      object $node1	Propulsion object for node
	 * @param      object $node2	Propulsion object for node
	 * @return     bool
	 */
	public static function isEqualTo(NodeObject $node1, NodeObject $node2);

	/**
	 * Tests if $node has an ancestor
	 *
	 * @param      object $node	Propulsion object for node
	 * @param      PropulsionPDO $con		Connection to use.
	 * @return     bool
	 */
	public static function hasParent(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Tests if $node has prev sibling
	 *
	 * @param      object $node	Propulsion object for node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     bool
	 */
	public static function hasPrevSibling(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Tests if $node has next sibling
	 *
	 * @param      object $node	Propulsion object for node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     bool
	 */
	public static function hasNextSibling(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Tests if $node has children
	 *
	 * @param      object $node	Propulsion object for node
	 * @return     bool
	 */
	public static function hasChildren(NodeObject $node);

	/**
	 * Deletes $node and all of its descendants
	 *
	 * @param      object $node	Propulsion object for source node
	 * @param      PropulsionPDO $con	Connection to use.
	 */
	public static function deleteDescendants(NodeObject $node, ?PropulsionPDO $con = null);

	/**
	 * Returns a node given its primary key or the node itself
	 *
	 * @param      int/object $node	Primary key/instance of required node
	 * @param      PropulsionPDO $con	Connection to use.
	 * @return     object		Propulsion object for model
	 */
	public static function getNode($node, ?PropulsionPDO $con = null);

} // NodePeer
