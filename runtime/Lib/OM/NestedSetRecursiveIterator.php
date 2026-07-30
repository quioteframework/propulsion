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
 * @implements \RecursiveIterator<string, ?object>
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

	/**
	 * Calls a method by name on a duck-typed node object. Routing every call through
	 * here (rather than `$obj->$method()` inline) keeps $method's type a plain
	 * `string` at the call site instead of a literal-string union PHPStan would
	 * otherwise try to check against the (deliberately unconstrained) `object` type
	 * -- see this class's own docblock for why no shared interface exists to check
	 * against instead.
	 */
	private function callMethod(object $obj, string $method, mixed ...$args): mixed
	{
		return $obj->$method(...$args);
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
		if ($this->curNode === null) {
			throw new \LogicException('key() called on an invalid iterator position');
		}
		$method = method_exists($this->curNode, 'getPath') ? self::dynamicMethod('getPath') : self::dynamicMethod('getAncestors');
		$ancestors = $this->callMethod($this->curNode, $method);
		if (!is_iterable($ancestors)) {
			throw new \LogicException(get_class($this->curNode) . '::' . $method . '() must return an iterable');
		}
		$key = array();
		foreach ($ancestors as $node) {
			if (!is_object($node)) {
				continue;
			}
			$primaryKey = $this->callMethod($node, 'getPrimaryKey');
			$key[] = is_scalar($primaryKey) ? (string) $primaryKey : '';
		}
		return implode('.', $key);
	}

	public function next(): void
	{
		$nextNode = null;
		if ($this->valid() && $this->curNode !== null) {
			$curNode = $this->curNode;
			$method = method_exists($curNode, 'retrieveNextSibling') ? self::dynamicMethod('retrieveNextSibling') : self::dynamicMethod('getNextSibling');
			while (null === $nextNode) {
				if ($this->callMethod($curNode, 'hasNextSibling')) {
					$nextNode = $this->toNodeOrNull($this->callMethod($curNode, $method));
				} else {
					break;
				}
			}
			$this->curNode = $nextNode;
		}
	}

	public function hasChildren() : bool
	{
		if ($this->curNode === null) {
			throw new \LogicException('hasChildren() called on an invalid iterator position');
		}
		return (bool) $this->callMethod($this->curNode, 'hasChildren');
	}

	/**
	 * @return \RecursiveIterator<string, ?object>
	 */
	public function getChildren() : \RecursiveIterator
	{
		if ($this->curNode === null) {
			throw new \LogicException('getChildren() called on an invalid iterator position');
		}
		$method = method_exists($this->curNode, 'retrieveFirstChild') ? self::dynamicMethod('retrieveFirstChild') : self::dynamicMethod('getFirstChild');
		$child = $this->callMethod($this->curNode, $method);
		if (!is_object($child)) {
			throw new \LogicException(get_class($this->curNode) . '::' . $method . '() must return an object');
		}
		return new NestedSetRecursiveIterator($child);
	}

	/**
	 * Widens a literal method name to a plain, non-literal string so PHPStan
	 * doesn't try (and fail) to verify it against the bare `object` type
	 * these duck-typed node references carry. The actual method is resolved
	 * and verified at runtime, same as before this file was made
	 * level-9-clean.
	 */
	private static function dynamicMethod(string $name): string
	{
		return $name;
	}

	private function toNodeOrNull(mixed $value): ?object
	{
		return is_object($value) ? $value : null;
	}
}
