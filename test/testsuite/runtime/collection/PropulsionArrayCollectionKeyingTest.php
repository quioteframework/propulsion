<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Coverage for PropulsionArrayCollection's re-keying API -- toArray()'s
 * $keyColumn/$usePrefix branches, getArrayCopy(), and toKeyValue().
 *
 * Deliberately a plain TestCase rather than BookstoreEmptyTestBase: none of
 * this touches a database, and the existing PropulsionArrayCollectionTest needs
 * one, so all of it skips on the no-Docker tier. These run everywhere.
 *
 * The non-obvious part is the key coercion. Array keys can only be int or
 * string, so a $keyColumn holding a float, bool or null gets cast -- and
 * anything non-scalar collapses to the empty string, which silently merges rows.
 * That is worth pinning: it is the kind of behaviour that changes by accident.
 */
class PropulsionArrayCollectionKeyingTest extends TestCase
{
    /** @return PropulsionArrayCollection<array-key, mixed> */
    private function collection(array $rows, string $model = 'Book'): PropulsionArrayCollection
    {
        $coll = new PropulsionArrayCollection();
        $coll->setModel($model);
        $coll->exchangeArray($rows);

        return $coll;
    }

    public function testToArrayWithoutAKeyColumnKeepsPositionalKeys()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            ['Id' => 20, 'Title' => 'Don Juan'],
        ]);

        $this->assertSame([0, 1], array_keys($coll->toArray()));
    }

    public function testToArrayRekeysOnTheGivenColumn()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            ['Id' => 20, 'Title' => 'Don Juan'],
        ]);

        $this->assertSame([10, 20], array_keys($coll->toArray('Id')));
        $this->assertSame('Don Juan', $coll->toArray('Id')[20]['Title']);
    }

    public function testToArrayPrefixesKeysWithTheModelName()
    {
        $coll = $this->collection([['Id' => 10, 'Title' => 'War And Peace']]);

        $this->assertSame(['Book_10'], array_keys($coll->toArray('Id', true)));
    }

    /**
     * A row missing the key column is skipped rather than keyed on null, which
     * would collide every such row onto one entry.
     */
    public function testToArraySkipsRowsMissingTheKeyColumn()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            ['Title' => 'No Id Here'],
        ]);

        $result = $coll->toArray('Id');
        $this->assertCount(1, $result);
        $this->assertArrayHasKey(10, $result);
    }

    public function testToArrayCoercesNonIntegerStringKeys()
    {
        $coll = $this->collection([
            ['Id' => 1.5, 'Title' => 'Float'],
            ['Id' => true, 'Title' => 'Bool'],
        ]);

        // '1.5' stays a string key, but true casts to '1' and PHP then
        // normalizes that numeric string back to the integer 1.
        $this->assertSame(['1.5', 1], array_keys($coll->toArray('Id')));
    }

    /**
     * Non-scalar key values collapse to '', so two such rows silently merge and
     * the last one wins. Pinned because it is a data-loss shape, not because it
     * is desirable.
     */
    public function testNonScalarKeyValuesCollapseToOneEntry()
    {
        $coll = $this->collection([
            ['Id' => ['nested'], 'Title' => 'First'],
            ['Id' => null, 'Title' => 'Second'],
        ]);

        $result = $coll->toArray('Id');
        $this->assertSame([''], array_keys($result));
        $this->assertSame('Second', $result['']['Title']);
    }

    public function testGetArrayCopyWithoutArgumentsReturnsTheRawArray()
    {
        $rows = [['Id' => 10, 'Title' => 'War And Peace']];
        $this->assertSame($rows, $this->collection($rows)->getArrayCopy());
    }

    public function testGetArrayCopyDelegatesToToArrayWhenRekeying()
    {
        $coll = $this->collection([['Id' => 10, 'Title' => 'War And Peace']]);

        $this->assertSame($coll->toArray('Id'), $coll->getArrayCopy('Id'));
        $this->assertSame(['Book_10'], array_keys($coll->getArrayCopy('Id', true)));
    }

    public function testToKeyValueBuildsAPairMap()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            ['Id' => 20, 'Title' => 'Don Juan'],
        ]);

        $this->assertSame([10 => 'War And Peace', 20 => 'Don Juan'], $coll->toKeyValue('Id', 'Title'));
    }

    /**
     * Unlike toArray(), toKeyValue() does not skip a row missing the key column
     * -- it keys it on '' and still takes the value column. A missing value
     * column gives null. Two rows lacking the key would silently merge.
     */
    public function testToKeyValueKeepsRowsMissingEitherColumn()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            ['Title' => 'No Id Here'],
            ['Id' => 30],
        ]);

        $this->assertSame(
            [10 => 'War And Peace', '' => 'No Id Here', 30 => null],
            $coll->toKeyValue('Id', 'Title')
        );
    }

    public function testToKeyValueIgnoresNonArrayElements()
    {
        $coll = $this->collection([
            ['Id' => 10, 'Title' => 'War And Peace'],
            'not an array',
        ]);

        $this->assertSame([10 => 'War And Peace'], $coll->toKeyValue('Id', 'Title'));
    }

    public function testToKeyValueOnAnEmptyCollection()
    {
        $this->assertSame([], $this->collection([])->toKeyValue('Id', 'Title'));
    }
}
