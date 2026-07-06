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
 * Pre-order node iterator for Node objects.
 *
 * This is intentionally duck-typed rather than requiring the NodeObject
 * interface: the modern nested_set behavior generates plain ActiveRecord
 * objects with the same method names (getPath()/getAncestors(),
 * retrieveNextSibling()/getNextSibling(), retrieveFirstChild()/getFirstChild())
 * without necessarily implementing NodeObject, alongside the legacy
 * NodeObject-interface-based treeMode objects, which do.
 *
 * @author     Heltem <heltem@o2php.com>
 * @version    $Revision$
 *
 * @implements \RecursiveIterator<string, object>
 */
class NestedSetRecursiveIterator implements \RecursiveIterator
{
	protected ?object $topNode = null;

	protected ?object $curNode = null;

	public function __construct(object $node)
	{
		$this->topNode = $node;
		$this->curNode = $node;
	}

	public function rewind(): void
	{
		$this->curNode = $this->topNode;
	}

	public function valid(): bool
	{
		return ($this->curNode !== null);
	}

	public function current(): mixed
	{
		return $this->curNode;
	}

	public function key(): mixed
	{
		$method = method_exists($this->curNode, 'getPath') ? 'getPath' : 'getAncestors';
		$key = array();
		foreach ($this->curNode->$method() as $node) {
			$key[] = $node->getPrimaryKey();
		}
		return implode('.', $key);
	}

	public function next(): void
	{
		$nextNode = null;
		$method = method_exists($this->curNode, 'retrieveNextSibling') ? 'retrieveNextSibling' : 'getNextSibling';
		if ($this->valid()) {
			while (null === $nextNode) {
				if (null === $this->curNode) {
					break;
				}

				if ($this->curNode->hasNextSibling()) {
					$nextNode = $this->curNode->$method();
				} else {
					break;
				}
			}
			$this->curNode = $nextNode;
		}
	}

	public function hasChildren() : bool
	{
		return $this->curNode->hasChildren();
	}

	/**
	 * @return \RecursiveIterator<string, object>
	 */
	public function getChildren() : \RecursiveIterator
	{
		$method = method_exists($this->curNode, 'retrieveFirstChild') ? 'retrieveFirstChild' : 'getFirstChild';
		return new NestedSetRecursiveIterator($this->curNode->$method());
	}
}
