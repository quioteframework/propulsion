<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Query\Criteria;
use Propulsion\Query\Criterion;
use Propulsion\Query\CriterionIterator;

/**
 * CriterionIterator is what makes `foreach ($criteria as $column => $criterion)`
 * work -- Criteria implements IteratorAggregate and getIterator() returns one of
 * these. It had no coverage at all despite being reachable from any Criteria.
 */
class CriterionIteratorTest extends TestCase
{
    private function criteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->add('book.TITLE', 'Harry Potter');
        $criteria->add('book.ISBN', '1234');

        return $criteria;
    }

    public function testCriteriaIteratesItsCriteriaByColumnName()
    {
        $seen = array();
        foreach ($this->criteria() as $column => $criterion) {
            $this->assertInstanceOf(Criterion::class, $criterion);
            $seen[$column] = $criterion->getValue();
        }

        $this->assertSame(array('book.TITLE' => 'Harry Potter', 'book.ISBN' => '1234'), $seen);
    }

    public function testGetIteratorReturnsACriterionIterator()
    {
        $this->assertInstanceOf(CriterionIterator::class, $this->criteria()->getIterator());
    }

    /**
     * The SPL contract, driven directly rather than through foreach: the cursor
     * starts valid, key()/current() agree, and valid() turns false exactly once
     * past the last entry.
     */
    public function testIteratorProtocolWalksEveryEntryExactlyOnce()
    {
        $criteria = $this->criteria();
        $iterator = new CriterionIterator($criteria);

        $iterator->rewind();
        $this->assertTrue($iterator->valid());
        $this->assertSame('book.TITLE', $iterator->key());
        $this->assertSame($criteria->getCriterion('book.TITLE'), $iterator->current());

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertSame('book.ISBN', $iterator->key());

        $iterator->next();
        $this->assertFalse($iterator->valid(), 'exhausted once past the last key');
    }

    public function testRewindRestartsTheWalk()
    {
        $iterator = new CriterionIterator($this->criteria());
        $iterator->rewind();
        $iterator->next();
        $iterator->next();
        $this->assertFalse($iterator->valid());

        $iterator->rewind();
        $this->assertTrue($iterator->valid());
        $this->assertSame('book.TITLE', $iterator->key());
    }

    /**
     * An empty Criteria is never valid, so `foreach` over one runs zero times
     * rather than reading a key that isn't there.
     */
    public function testAnEmptyCriteriaYieldsNothing()
    {
        $iterator = new CriterionIterator(new Criteria());
        $iterator->rewind();
        $this->assertFalse($iterator->valid());

        $count = 0;
        foreach (new Criteria() as $ignored) {
            $count++;
        }
        $this->assertSame(0, $count);
    }

    /**
     * The key list is snapshotted at construction, so a criterion added to the
     * Criteria afterwards is not picked up by an iterator already built from it.
     * Documents current behaviour -- Criteria::getIterator() hands out a fresh
     * iterator per foreach, so ordinary use never sees this.
     */
    public function testTheKeyListIsSnapshottedAtConstruction()
    {
        $criteria = $this->criteria();
        $iterator = new CriterionIterator($criteria);
        $criteria->add('book.PRICE', 10);

        $count = 0;
        foreach ($iterator as $ignored) {
            $count++;
        }
        $this->assertSame(2, $count, 'the later addition is not seen by this iterator');
        $this->assertSame(3, count($criteria->keys()), 'but it is on the Criteria itself');
    }
}
