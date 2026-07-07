<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\OM\NestedSetRecursiveIterator;

/**
 * Minimal duck-typed node stub matching what NestedSetRecursiveIterator expects,
 * mirroring the "modern nested_set behavior" method names described in the
 * class's own docblock (getPath()/retrieveNextSibling()/retrieveFirstChild()).
 */
class NestedSetRecursiveIteratorTestNode
{
    private $pk;
    private $path;
    private $nextSibling;
    private $firstChild;

    public function __construct($pk, array $path = array())
    {
        $this->pk = $pk;
        $this->path = $path;
    }

    public function getPrimaryKey()
    {
        return $this->pk;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function setNextSibling(?self $node): void
    {
        $this->nextSibling = $node;
    }

    public function hasNextSibling(): bool
    {
        return $this->nextSibling !== null;
    }

    public function retrieveNextSibling()
    {
        return $this->nextSibling;
    }

    public function setFirstChild(?self $node): void
    {
        $this->firstChild = $node;
    }

    public function hasChildren(): bool
    {
        return $this->firstChild !== null;
    }

    public function retrieveFirstChild()
    {
        return $this->firstChild;
    }
}

/**
 * Test class for NestedSetRecursiveIterator.
 */
class NestedSetRecursiveIteratorTest extends TestCase
{
    public function testCurrentReturnsTopNodeInitially()
    {
        $node = new NestedSetRecursiveIteratorTestNode(1);
        $iterator = new NestedSetRecursiveIterator($node);
        $this->assertSame($node, $iterator->current());
        $this->assertTrue($iterator->valid());
    }

    public function testRewindResetsToTopNode()
    {
        $top = new NestedSetRecursiveIteratorTestNode(1);
        $sibling = new NestedSetRecursiveIteratorTestNode(2);
        $top->setNextSibling($sibling);

        $iterator = new NestedSetRecursiveIterator($top);
        $iterator->next();
        $this->assertSame($sibling, $iterator->current());

        $iterator->rewind();
        $this->assertSame($top, $iterator->current());
    }

    public function testKeyBuildsDotSeparatedPathFromPrimaryKeys()
    {
        $ancestor1 = new NestedSetRecursiveIteratorTestNode(1);
        $ancestor2 = new NestedSetRecursiveIteratorTestNode(2);
        $node = new NestedSetRecursiveIteratorTestNode(3, array($ancestor1, $ancestor2));

        $iterator = new NestedSetRecursiveIterator($node);
        $this->assertSame('1.2', $iterator->key());
    }

    public function testNextAdvancesToNextSibling()
    {
        $first = new NestedSetRecursiveIteratorTestNode(1);
        $second = new NestedSetRecursiveIteratorTestNode(2);
        $first->setNextSibling($second);

        $iterator = new NestedSetRecursiveIterator($first);
        $iterator->next();
        $this->assertSame($second, $iterator->current());
    }

    public function testNextBecomesInvalidWhenNoMoreSiblings()
    {
        $node = new NestedSetRecursiveIteratorTestNode(1);
        $iterator = new NestedSetRecursiveIterator($node);
        $iterator->next();
        $this->assertFalse($iterator->valid());
        $this->assertNull($iterator->current());
    }

    public function testHasChildrenDelegatesToNode()
    {
        $withChildren = new NestedSetRecursiveIteratorTestNode(1);
        $withChildren->setFirstChild(new NestedSetRecursiveIteratorTestNode(2));
        $iterator = new NestedSetRecursiveIterator($withChildren);
        $this->assertTrue($iterator->hasChildren());

        $withoutChildren = new NestedSetRecursiveIteratorTestNode(3);
        $iterator2 = new NestedSetRecursiveIterator($withoutChildren);
        $this->assertFalse($iterator2->hasChildren());
    }

    public function testGetChildrenReturnsIteratorOverFirstChild()
    {
        $child = new NestedSetRecursiveIteratorTestNode(2);
        $parent = new NestedSetRecursiveIteratorTestNode(1);
        $parent->setFirstChild($child);

        $iterator = new NestedSetRecursiveIterator($parent);
        $childIterator = $iterator->getChildren();

        $this->assertInstanceOf(NestedSetRecursiveIterator::class, $childIterator);
        $this->assertSame($child, $childIterator->current());
    }
}
