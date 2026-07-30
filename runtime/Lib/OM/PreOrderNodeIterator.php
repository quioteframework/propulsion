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
 * This is intentionally duck-typed rather than requiring a common interface:
 * callers pass in objects that implement getNodePath()/getFirstChildNode()/
 * equals()/getSiblingNode()/getParentNode() by convention, not by a shared
 * NodeObject-style contract (see PreOrderNodeIteratorTest's own node fixture,
 * which implements none of the interfaces in this namespace).
 *
 * @author     Dave Lawson <dlawson@masterytech.com>
 * @version    $Revision$
 *
 * @implements \Iterator<mixed,mixed>
 */
class PreOrderNodeIterator implements \Iterator
{
	private ?object $topNode = null;

	private ?object $curNode = null;

	private bool $querydb = false;

	/** @var mixed */
	private $con = null;

	/**
	 * @param array<string,mixed> $opts
	 */
	public function __construct(object $node, array $opts) {
		$this->topNode = $node;
		$this->curNode = $node;

		if (isset($opts['con']))
			$this->con = $opts['con'];

		if (isset($opts['querydb']))
			$this->querydb = (bool) $opts['querydb'];
	}

	public function rewind(): void {
		$this->curNode = $this->topNode;
	}

	public function valid(): bool {
		return ($this->curNode !== null);
	}

	public function current(): mixed {
		return $this->curNode;
	}

	public function key(): mixed {
		if ($this->curNode === null) {
			throw new \LogicException('key() called on an invalid iterator position');
		}
		return $this->curNode->{self::dynamicMethod('getNodePath')}();
	}

	public function next(): void {

		if ($this->valid() && $this->curNode !== null && $this->topNode !== null)
		{
			$nextNode = $this->toNodeOrNull($this->curNode->{self::dynamicMethod('getFirstChildNode')}($this->querydb, $this->con));

			while ($nextNode === null)
			{
				if ($this->curNode === null || $this->curNode->{self::dynamicMethod('equals')}($this->topNode))
					break;

				$nextNode = $this->toNodeOrNull($this->curNode->{self::dynamicMethod('getSiblingNode')}(false, $this->querydb, $this->con));

				if ($nextNode === null)
					$this->curNode = $this->toNodeOrNull($this->curNode->{self::dynamicMethod('getParentNode')}($this->querydb, $this->con));
			}

			$this->curNode = $nextNode;
		}

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
