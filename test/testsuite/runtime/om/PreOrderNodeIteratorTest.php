<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\OM\PreOrderNodeIterator;

/**
 * Minimal duck-typed node stub matching what PreOrderNodeIterator expects
 * (the MaterializedPath tree API: getNodePath()/getFirstChildNode()/
 * getSiblingNode()/getParentNode()/equals()). No test at all previously
 * exercised this class -- treeMode="MaterializedPath" (see
 * NodeBuilderCodegenTest) has no fixture project or runtime test anywhere in
 * the suite.
 */
class PreOrderNodeIteratorTestNode
{
    private $path;
    private $firstChild;
    private $nextSibling;
    private $parent;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function getNodePath()
    {
        return $this->path;
    }

    public function setFirstChild(?self $node): void
    {
        $this->firstChild = $node;
    }

    public function getFirstChildNode($querydb, $con)
    {
        return $this->firstChild;
    }

    public function setNextSibling(?self $node): void
    {
        $this->nextSibling = $node;
    }

    public function getSiblingNode($querydb, $con)
    {
        return $this->nextSibling;
    }

    public function setParent(?self $node): void
    {
        $this->parent = $node;
    }

    public function getParentNode($querydb, $con)
    {
        return $this->parent;
    }

    public function equals($other)
    {
        return $other instanceof self && $other->getNodePath() === $this->path;
    }
}

class PreOrderNodeIteratorTest extends TestCase
{
    public function testCurrentAndKeyReturnTopNodeInitially()
    {
        $node = new PreOrderNodeIteratorTestNode('1');
        $iterator = new PreOrderNodeIterator($node, array());
        $this->assertSame($node, $iterator->current());
        $this->assertSame('1', $iterator->key());
        $this->assertTrue($iterator->valid());
    }

    public function testRewindResetsToTopNode()
    {
        $top = new PreOrderNodeIteratorTestNode('1');
        $child = new PreOrderNodeIteratorTestNode('1.1');
        $top->setFirstChild($child);

        $iterator = new PreOrderNodeIterator($top, array());
        $iterator->next();
        $this->assertSame($child, $iterator->current());

        $iterator->rewind();
        $this->assertSame($top, $iterator->current());
    }

    public function testNextDescendsIntoFirstChild()
    {
        $top = new PreOrderNodeIteratorTestNode('1');
        $child = new PreOrderNodeIteratorTestNode('1.1');
        $top->setFirstChild($child);

        $iterator = new PreOrderNodeIterator($top, array());
        $iterator->next();
        $this->assertSame($child, $iterator->current());
    }

    public function testNextMovesToSiblingWhenNoChildren()
    {
        $top = new PreOrderNodeIteratorTestNode('1');
        $child = new PreOrderNodeIteratorTestNode('1.1');
        $sibling = new PreOrderNodeIteratorTestNode('1.2');
        $top->setFirstChild($child);
        $child->setNextSibling($sibling);

        $iterator = new PreOrderNodeIterator($top, array());
        $iterator->next(); // -> child
        $iterator->next(); // -> sibling (child has no children of its own)
        $this->assertSame($sibling, $iterator->current());
    }

    public function testNextWalksUpToParentWhenNoChildrenOrSiblings()
    {
        $top = new PreOrderNodeIteratorTestNode('1');
        $child = new PreOrderNodeIteratorTestNode('1.1');
        $grandchild = new PreOrderNodeIteratorTestNode('1.1.1');
        $uncle = new PreOrderNodeIteratorTestNode('1.2');

        $top->setFirstChild($child);
        $child->setParent($top);
        $child->setFirstChild($grandchild);
        $grandchild->setParent($child);
        $top->setFirstChild($child);
        // top's only other child, reached by walking back up from child to top
        // and taking top's next sibling slot (simulated here via child's own
        // sibling pointer once we're back at that level).
        $child->setNextSibling($uncle);

        $iterator = new PreOrderNodeIterator($top, array());
        $iterator->next(); // -> child
        $iterator->next(); // -> grandchild (child's only child)
        $this->assertSame($grandchild, $iterator->current());
        $iterator->next(); // grandchild has no children/siblings -> walk up to child -> child's sibling (uncle)
        $this->assertSame($uncle, $iterator->current());
    }

    public function testNextBecomesInvalidAtEndOfTree()
    {
        $top = new PreOrderNodeIteratorTestNode('1');
        $iterator = new PreOrderNodeIterator($top, array());
        $iterator->next();
        $this->assertFalse($iterator->valid());
        $this->assertNull($iterator->current());
    }

    public function testConstructorAcceptsConAndQuerydbOptions()
    {
        $node = new PreOrderNodeIteratorTestNode('1');
        // Just verifying the constructor accepts these options without error;
        // the stub node ignores them, real Node objects would use them to
        // decide whether to query the DB for children/siblings.
        $iterator = new PreOrderNodeIterator($node, array('con' => 'fake-connection', 'querydb' => true));
        $this->assertSame($node, $iterator->current());
    }
}
