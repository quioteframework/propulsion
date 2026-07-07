<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Runtime coverage for the nested_set behavior's generated tree-manipulation
 * methods against a real Postgres-backed Table9, seeded with a non-trivial
 * tree. The existing NestedSetBehaviorTest only checks that the right
 * columns/method names get added to the schema/table map; none of the actual
 * lft/rgt/level bookkeeping (insertion, move, deletion renumbering -- the
 * classic sources of nested-set bugs) was exercised anywhere.
 *
 * Tree built by setUp():
 *
 *        Root (1,10,0)
 *       /            \
 *  Child1 (2,7,1)   Child2 (8,9,1)
 *   /       \
 *  GC1(3,4,2) GC2(5,6,2)
 */
class NestedSetBehaviorRuntimeTest extends BookstoreTestBase
{
    private $root;
    private $child1;
    private $child2;
    private $gc1;
    private $gc2;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->con) {
            return;
        }

        // Table9 belongs to the separate "behavior.nested_set" package/database,
        // not the main bookstore fixture depopulate()/populate() cycle -- its
        // rows persist across the whole process unless cleared explicitly, so
        // each test must reset it itself to stay order-independent.
        Table9Query::create()->deleteAll();

        $this->root = new Table9();
        $this->root->setTitle('Root');
        $this->root->makeRoot();
        $this->root->save();

        $this->child1 = new Table9();
        $this->child1->setTitle('Child1');
        $this->child1->insertAsFirstChildOf($this->root);
        $this->child1->save();

        $this->child2 = new Table9();
        $this->child2->setTitle('Child2');
        $this->child2->insertAsLastChildOf($this->root);
        $this->child2->save();

        $this->gc1 = new Table9();
        $this->gc1->setTitle('GC1');
        $this->gc1->insertAsFirstChildOf($this->child1);
        $this->gc1->save();

        $this->gc2 = new Table9();
        $this->gc2->setTitle('GC2');
        $this->gc2->insertAsNextSiblingOf($this->gc1);
        $this->gc2->save();
    }

    protected function tearDown(): void
    {
        if ($this->con) {
            Table9Query::create()->deleteAll();
        }
        parent::tearDown();
    }

    private function reload(Table9 $node): Table9
    {
        return Table9Query::create()->findPk($node->getId());
    }

    public function testTreeHasExpectedLeftRightLevelValues()
    {
        $this->assertSame(1, $this->reload($this->root)->getLeftValue());
        $this->assertSame(10, $this->reload($this->root)->getRightValue());
        $this->assertSame(0, $this->reload($this->root)->getLevel());

        $this->assertSame(2, $this->reload($this->child1)->getLeftValue());
        $this->assertSame(7, $this->reload($this->child1)->getRightValue());
        $this->assertSame(1, $this->reload($this->child1)->getLevel());

        $this->assertSame(8, $this->reload($this->child2)->getLeftValue());
        $this->assertSame(9, $this->reload($this->child2)->getRightValue());
        $this->assertSame(1, $this->reload($this->child2)->getLevel());

        $this->assertSame(3, $this->reload($this->gc1)->getLeftValue());
        $this->assertSame(4, $this->reload($this->gc1)->getRightValue());
        $this->assertSame(2, $this->reload($this->gc1)->getLevel());

        $this->assertSame(5, $this->reload($this->gc2)->getLeftValue());
        $this->assertSame(6, $this->reload($this->gc2)->getRightValue());
        $this->assertSame(2, $this->reload($this->gc2)->getLevel());
    }

    public function testIsRootIsLeafIsInTree()
    {
        $this->assertTrue($this->reload($this->root)->isRoot());
        $this->assertFalse($this->reload($this->child1)->isRoot());

        $this->assertFalse($this->reload($this->root)->isLeaf());
        $this->assertFalse($this->reload($this->child1)->isLeaf());
        $this->assertTrue($this->reload($this->gc1)->isLeaf());
        $this->assertTrue($this->reload($this->child2)->isLeaf());
    }

    public function testIsDescendantOfAndIsAncestorOf()
    {
        $gc1 = $this->reload($this->gc1);
        $child1 = $this->reload($this->child1);
        $root = $this->reload($this->root);
        $child2 = $this->reload($this->child2);

        $this->assertTrue($gc1->isDescendantOf($root));
        $this->assertTrue($gc1->isDescendantOf($child1));
        $this->assertFalse($gc1->isDescendantOf($child2));

        $this->assertTrue($root->isAncestorOf($gc1));
        $this->assertTrue($child1->isAncestorOf($gc1));
        $this->assertFalse($child2->isAncestorOf($gc1));
    }

    public function testGetParentAndHasParent()
    {
        $gc1 = $this->reload($this->gc1);
        $parent = $gc1->getParent();
        $this->assertSame($this->child1->getId(), $parent->getId());

        $this->assertFalse($this->reload($this->root)->hasParent());
        $this->assertTrue($this->reload($this->child1)->hasParent());
    }

    public function testGetChildrenReturnsOnlyDirectChildren()
    {
        $children = $this->reload($this->root)->getChildren(null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($children));
        sort($titles);
        $this->assertSame(array('Child1', 'Child2'), $titles);
    }

    public function testCountChildrenAndCountDescendants()
    {
        $root = $this->reload($this->root);
        $this->assertSame(2, $root->countChildren(null));
        $this->assertSame(4, $root->countDescendants(null));

        $child1 = $this->reload($this->child1);
        $this->assertSame(2, $child1->countChildren(null));
    }

    public function testGetDescendantsReturnsAllLevelsInBranchOrder()
    {
        $descendants = $this->reload($this->root)->getDescendants(null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($descendants));
        $this->assertSame(array('Child1', 'GC1', 'GC2', 'Child2'), $titles);
    }

    public function testGetAncestorsReturnsPathToRoot()
    {
        $ancestors = $this->reload($this->gc1)->getAncestors(null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($ancestors));
        $this->assertSame(array('Root', 'Child1'), $titles);
    }

    public function testGetSiblingsExcludesSelfByDefault()
    {
        $siblings = $this->reload($this->gc1)->getSiblings(false, null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($siblings));
        $this->assertSame(array('GC2'), $titles);
    }

    public function testGetSiblingsIncludesSelfWhenRequested()
    {
        $siblings = $this->reload($this->gc1)->getSiblings(true, null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($siblings));
        $this->assertSame(array('GC1', 'GC2'), $titles);
    }

    public function testGetPrevAndNextSibling()
    {
        $gc2 = $this->reload($this->gc2);
        $this->assertTrue($gc2->hasPrevSibling());
        $prev = $gc2->getPrevSibling();
        $this->assertSame('GC1', $prev->getTitle());

        $gc1 = $this->reload($this->gc1);
        $this->assertTrue($gc1->hasNextSibling());
        $next = $gc1->getNextSibling();
        $this->assertSame('GC2', $next->getTitle());

        $this->assertFalse($gc1->hasPrevSibling());
        $this->assertFalse($gc2->hasNextSibling());
    }

    public function testInsertAsPrevSiblingOf()
    {
        $newNode = new Table9();
        $newNode->setTitle('GC0');
        $newNode->insertAsPrevSiblingOf($this->gc1);
        $newNode->save();

        $children = $this->reload($this->child1)->getChildren(null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($children));
        $this->assertSame(array('GC0', 'GC1', 'GC2'), $titles);
    }

    public function testMoveToLastChildOfRenumbersCorrectly()
    {
        // Move Child1 (with its two children) to become the last child of Child2.
        $this->reload($this->child1)->moveToLastChildOf($this->reload($this->child2));

        $root = $this->reload($this->root);
        $this->assertSame(1, $root->getLeftValue());
        $this->assertSame(10, $root->getRightValue(), 'Total tree size is unchanged by an internal move');

        $child2 = $this->reload($this->child2);
        $child1 = $this->reload($this->child1);
        $this->assertTrue($child1->isDescendantOf($child2), 'Child1 (and its subtree) now lives under Child2');
        $this->assertSame(2, $child1->getLevel());

        $gc1 = $this->reload($this->gc1);
        $this->assertSame(3, $gc1->getLevel());
        $this->assertTrue($gc1->isDescendantOf($child1));

        // No overlapping/duplicate lft-rgt ranges anywhere in the tree after the move.
        $ranges = array();
        foreach (Table9Query::create()->find() as $node) {
            $ranges[] = array($node->getLeftValue(), $node->getRightValue());
        }
        $seen = array();
        foreach ($ranges as [$l, $r]) {
            $this->assertArrayNotHasKey($l, $seen, 'No two nodes share a left value after renumbering');
            $seen[$l] = true;
            $this->assertLessThan($r, $l);
        }
    }

    public function testMoveToPrevSiblingOfReordersSiblings()
    {
        $this->reload($this->child2)->moveToPrevSiblingOf($this->reload($this->child1));

        $children = $this->reload($this->root)->getChildren(null);
        $titles = array_map(fn($c) => $c->getTitle(), iterator_to_array($children));
        $this->assertSame(array('Child2', 'Child1'), $titles);

        // Overall tree bounds are preserved.
        $root = $this->reload($this->root);
        $this->assertSame(1, $root->getLeftValue());
        $this->assertSame(10, $root->getRightValue());
    }

    public function testDeleteDescendantsRemovesSubtreeAndClosesGap()
    {
        $this->reload($this->child1)->deleteDescendants();

        // GC1/GC2 are gone...
        $this->assertNull(Table9Query::create()->findPk($this->gc1->getId()));
        $this->assertNull(Table9Query::create()->findPk($this->gc2->getId()));

        // ...Child1 itself survives as a now-childless node...
        $child1 = $this->reload($this->child1);
        $this->assertNotNull($child1);
        $this->assertTrue($child1->isLeaf());

        // ...and the gap left by the 4 removed lft/rgt slots is closed: the whole
        // tree shrinks by exactly the two deleted nodes' worth of lft/rgt space,
        // not left with a hole.
        $root = $this->reload($this->root);
        $this->assertSame(1, $root->getLeftValue());
        $this->assertSame(6, $root->getRightValue());

        $child2 = $this->reload($this->child2);
        $this->assertSame(4, $child2->getLeftValue());
        $this->assertSame(5, $child2->getRightValue());
    }

    public function testDeleteOfNonLeafNodeAlsoDeletesDescendantsAndClosesGap()
    {
        // BaseTable9::delete() -- unlike plain BaseObject::delete() -- must also
        // remove the node's descendants and shift every subsequent lft/rgt value
        // down, or deleting an internal tree node would silently orphan its
        // children (a dangling foreign-key-less subtree) and leave a lft/rgt gap.
        $this->reload($this->child1)->delete();

        $this->assertNull(Table9Query::create()->findPk($this->child1->getId()));
        $this->assertNull(Table9Query::create()->findPk($this->gc1->getId()));
        $this->assertNull(Table9Query::create()->findPk($this->gc2->getId()));

        $root = $this->reload($this->root);
        $this->assertSame(1, $root->getLeftValue());
        $this->assertSame(4, $root->getRightValue());

        $child2 = $this->reload($this->child2);
        $this->assertSame(2, $child2->getLeftValue());
        $this->assertSame(3, $child2->getRightValue());
    }

    public function testFindRootAndFindTree()
    {
        $foundRoot = Table9Query::create()->findRoot();
        $this->assertSame('Root', $foundRoot->getTitle());

        $tree = Table9Query::create()->findTree();
        $this->assertCount(5, $tree);
    }
}
