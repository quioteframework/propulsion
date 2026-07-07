<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Runtime coverage for the sortable behavior's generated rank-manipulation
 * methods against a real Postgres-backed Table11, seeded with an ordered list.
 * The existing SortableBehaviorTest only checks that the rank column gets
 * added to the schema/table map; none of the actual rank bookkeeping
 * (insertion, swap, move, removal renumbering) was exercised anywhere.
 *
 * List built by setUp(): A(1), B(2), C(3), D(4).
 *
 * Note: Table11 belongs to the separate "bookstore-behavior" database, not the
 * main bookstore fixture's depopulate()/populate() cycle -- its rows persist
 * across the whole process unless cleared explicitly, so each test resets it
 * itself. None of Table11's own generated methods are ever passed
 * $this->con -- that connection is bound to the *bookstore* database
 * (BookstoreTestBase::setUp() uses BookPeer::DATABASE_NAME), a different
 * logical database on the same physical connection; passing it into
 * Table11/Table11Query methods (which default to
 * Propulsion::getConnection(Table11Peer::DATABASE_NAME) when no $con is
 * given) caused real Postgres lock contention in the nested_set runtime test
 * before this same fix was applied there.
 */
class SortableBehaviorRuntimeTest extends BookstoreTestBase
{
    private $a;
    private $b;
    private $c;
    private $d;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->con) {
            return;
        }

        Table11Query::create()->deleteAll();

        $this->a = $this->makeNode('A');
        $this->b = $this->makeNode('B');
        $this->c = $this->makeNode('C');
        $this->d = $this->makeNode('D');
    }

    protected function tearDown(): void
    {
        if ($this->con) {
            Table11Query::create()->deleteAll();
        }
        parent::tearDown();
    }

    private function makeNode($title)
    {
        $node = new Table11();
        $node->setTitle($title);
        $node->insertAtBottom();
        $node->save();
        return $node;
    }

    private function reload(Table11 $node): Table11
    {
        return Table11Query::create()->findPk($node->getId());
    }

    private function titlesInRankOrder()
    {
        $titles = array();
        foreach (Table11Query::create()->findList() as $node) {
            $titles[] = $node->getTitle();
        }
        return $titles;
    }

    public function testInsertAtBottomAssignsSequentialRanks()
    {
        $this->assertSame(1, $this->reload($this->a)->getRank());
        $this->assertSame(2, $this->reload($this->b)->getRank());
        $this->assertSame(3, $this->reload($this->c)->getRank());
        $this->assertSame(4, $this->reload($this->d)->getRank());
    }

    public function testIsFirstAndIsLast()
    {
        $this->assertTrue($this->reload($this->a)->isFirst());
        $this->assertFalse($this->reload($this->b)->isFirst());

        $this->assertTrue($this->reload($this->d)->isLast());
        $this->assertFalse($this->reload($this->c)->isLast());
    }

    public function testGetMaxRank()
    {
        $this->assertSame(4, Table11Query::create()->getMaxRank());
    }

    public function testGetNextAndGetPrevious()
    {
        $b = $this->reload($this->b);
        $this->assertSame('C', $b->getNext()->getTitle());
        $this->assertSame('A', $b->getPrevious()->getTitle());

        $this->assertNull($this->reload($this->a)->getPrevious());
        $this->assertNull($this->reload($this->d)->getNext());
    }

    public function testFindOneByRank()
    {
        $node = Table11Query::create()->findOneByRank(3);
        $this->assertSame('C', $node->getTitle());
    }

    public function testFindListReturnsAllInRankOrder()
    {
        $this->assertSame(array('A', 'B', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testInsertAtTopShiftsEveryoneDown()
    {
        $e = new Table11();
        $e->setTitle('E');
        $e->insertAtTop();
        $e->save();

        $this->assertSame(1, $this->reload($e)->getRank());
        $this->assertSame(2, $this->reload($this->a)->getRank());
        $this->assertSame(3, $this->reload($this->b)->getRank());
        $this->assertSame(4, $this->reload($this->c)->getRank());
        $this->assertSame(5, $this->reload($this->d)->getRank());
        $this->assertSame(array('E', 'A', 'B', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testInsertAtRankInTheMiddleShiftsOnlyLaterItems()
    {
        $e = new Table11();
        $e->setTitle('E');
        $e->insertAtRank(3);
        $e->save();

        $this->assertSame(1, $this->reload($this->a)->getRank());
        $this->assertSame(2, $this->reload($this->b)->getRank());
        $this->assertSame(3, $this->reload($e)->getRank());
        $this->assertSame(4, $this->reload($this->c)->getRank());
        $this->assertSame(5, $this->reload($this->d)->getRank());
        $this->assertSame(array('A', 'B', 'E', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testInsertAtRankRejectsOutOfBoundsRank()
    {
        $e = new Table11();
        $e->setTitle('E');
        $this->expectException(PropulsionException::class);
        $e->insertAtRank(99);
    }

    public function testSwapWithExchangesRanks()
    {
        $a = $this->reload($this->a);
        $c = $this->reload($this->c);
        $a->swapWith($c);

        $this->assertSame(3, $this->reload($this->a)->getRank());
        $this->assertSame(1, $this->reload($this->c)->getRank());
        $this->assertSame(array('C', 'B', 'A', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveUpSwapsWithPrevious()
    {
        $this->reload($this->c)->moveUp();

        $this->assertSame(array('A', 'C', 'B', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveUpOnFirstItemIsANoOp()
    {
        $this->reload($this->a)->moveUp();
        $this->assertSame(array('A', 'B', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveDownSwapsWithNext()
    {
        $this->reload($this->b)->moveDown();

        $this->assertSame(array('A', 'C', 'B', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveDownOnLastItemIsANoOp()
    {
        $this->reload($this->d)->moveDown();
        $this->assertSame(array('A', 'B', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveToRankShiftsIntermediateItems()
    {
        // Move D (rank 4) to rank 2: B and C should each shift down one rank.
        $this->reload($this->d)->moveToRank(2);

        $this->assertSame(1, $this->reload($this->a)->getRank());
        $this->assertSame(2, $this->reload($this->d)->getRank());
        $this->assertSame(3, $this->reload($this->b)->getRank());
        $this->assertSame(4, $this->reload($this->c)->getRank());
        $this->assertSame(array('A', 'D', 'B', 'C'), $this->titlesInRankOrder());
    }

    public function testMoveToRankTheOtherDirection()
    {
        // Move A (rank 1) to rank 3: B and C shift up one rank.
        $this->reload($this->a)->moveToRank(3);

        $this->assertSame(array('B', 'C', 'A', 'D'), $this->titlesInRankOrder());
    }

    public function testMoveToTopAndMoveToBottom()
    {
        $this->reload($this->d)->moveToTop();
        $this->assertSame(array('D', 'A', 'B', 'C'), $this->titlesInRankOrder());

        $this->reload($this->d)->moveToBottom();
        $this->assertSame(array('A', 'B', 'C', 'D'), $this->titlesInRankOrder());
    }

    public function testRemoveFromListClosesRankGapOnSave()
    {
        $b = $this->reload($this->b);
        $b->removeFromList();
        $b->save();

        $this->assertNull($this->reload($this->b)->getRank());
        // A, C, D shift to fill the gap left by removing B (rank 2).
        $this->assertSame(1, $this->reload($this->a)->getRank());
        $this->assertSame(2, $this->reload($this->c)->getRank());
        $this->assertSame(3, $this->reload($this->d)->getRank());
    }

    public function testReorderAppliesArbitraryRankMapping()
    {
        Table11Query::create()->reorder(array(
            $this->d->getId() => 1,
            $this->c->getId() => 2,
            $this->b->getId() => 3,
            $this->a->getId() => 4,
        ));

        $this->assertSame(array('D', 'C', 'B', 'A'), $this->titlesInRankOrder());
    }
}
