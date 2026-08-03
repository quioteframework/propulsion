<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Collection\PropulsionCollection;

/**
 * PropulsionCollection must not leave a reference cycle behind after ordinary
 * iteration.
 *
 * getIterator() memoises the iterator it hands out so that getPosition()/
 * getNext()/isLast() called from inside a foreach describe that loop. But
 * ArrayObject's iterator refers back to the collection, so memoising it
 * *strongly* made every iterated collection part of a cycle -- and a cycle is
 * freed only when PHP's cycle collector runs, not when its refcount drops. In a
 * persistent worker there is no process exit to fall back on, so iterated
 * collections accumulated until GC happened to fire.
 *
 * These tests are deliberately independent of the database (plain TestCase, not
 * BookstoreTestBase): the property under test is about object lifetime, not
 * hydration, and it should be verifiable wherever the suite runs.
 */
class PropulsionCollectionIteratorCycleTest extends TestCase
{
    public function testIteratingDoesNotCreateCyclicGarbage()
    {
        // Establish a clean baseline: no cycles pending from earlier tests.
        gc_collect_cycles();

        for ($i = 0; $i < 200; $i++) {
            $col = new PropulsionCollection(range(1, 20));
            foreach ($col as $value) {
                // drive the iterator to exhaustion, as any consumer would
            }
            unset($col);
        }

        $this->assertSame(
            0,
            gc_collect_cycles(),
            'iterating a PropulsionCollection must not leave cycles for the collector to reclaim'
        );
    }

    public function testIteratedCollectionsAreFreedWithoutRunningTheCollector()
    {
        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($i = 0; $i < 200; $i++) {
            $col = new PropulsionCollection(range(1, 20));
            foreach ($col as $value) {
            }
            unset($col);
        }

        $retained = memory_get_usage() - $baseline;

        // Refcounting alone must reclaim these. The bound is generous -- this is
        // asserting "does not grow with the number of collections", not an exact
        // figure: before the fix this retained roughly a kilobyte per
        // collection, i.e. ~200 KB at this loop count.
        $this->assertLessThan(
            50000,
            $retained,
            'iterated collections must be reclaimed by refcounting, not left to the cycle collector'
        );
    }

    public function testGetInternalIteratorStillReturnsAStableCursor()
    {
        $col = new PropulsionCollection(array('bar1', 'bar2', 'bar3'));

        $first = $col->getInternalIterator();
        $this->assertSame($first, $col->getInternalIterator(), 'getInternalIterator() returns always the same iterator');

        $col->getInternalIterator()->next();
        $this->assertEquals(
            'bar2',
            $col->getInternalIterator()->current(),
            'the internal cursor keeps its position between calls'
        );
    }

    public function testPositionHelpersStillTrackARunningForeach()
    {
        $col = new PropulsionCollection(array('a', 'b', 'c'));

        $seen = array();
        foreach ($col as $value) {
            $seen[] = $value . ':' . $col->getPosition() . ':' . ($col->isLast() ? 'last' : '-');
        }

        $this->assertSame(
            array('a:0:-', 'b:1:-', 'c:2:last'),
            $seen,
            'getPosition()/isLast() called inside a foreach still describe that loop'
        );
    }

    public function testClearIteratorReleasesTheInternalCursor()
    {
        $col = new PropulsionCollection(array('bar1', 'bar2'));
        $cursor = $col->getInternalIterator();
        $cursor->next();

        $col->clearIterator();

        $this->assertNotSame(
            $cursor,
            $col->getInternalIterator(),
            'clearIterator() drops the cursor, so the next call builds a fresh one'
        );
    }
}
