<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionCollection.
 *
 * @author     Francois Zaninotto
 * @version    $Id: PropulsionCollectionTest.php 1348 2009-12-03 21:49:00Z francois $
 * @package    runtime.collection
 */
class PropulsionCollectionTest extends BookstoreTestBase
{
	public function testArrayAccess()
	{
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar1', $col[0], 'PropulsionCollection allows access via $foo[$index]');
		$this->assertEquals('bar2', $col[1], 'PropulsionCollection allows access via $foo[$index]');
		$this->assertEquals('bar3', $col[2], 'PropulsionCollection allows access via $foo[$index]');
	}

	public function testGetData()
	{
		$col = new PropulsionCollection();
		$this->assertEquals(array(), $col->getData(), 'getData() returns an empty array for empty collections');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals($data, $col->getData(), 'getData() returns the collection data');
		$col[0] = 'bar4';
		$this->assertEquals('bar1', $data[0], 'getData() returns a copy of the collection data');
	}

	public function testSetData()
	{
		$col = new PropulsionCollection();
		$col->setData(array());
		$this->assertEquals(array(), $col->getArrayCopy(), 'setData() can set data to an empty array');

		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection();
		$col->setData($data);
		$this->assertEquals($data, $col->getArrayCopy(), 'setData() sets the collection data');
	}

	public function testGetPosition()
	{
		$col = new PropulsionCollection();
		$this->assertEquals(0, $col->getPosition(), 'getPosition() returns 0 on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$expectedPositions = array(0, 1, 2);
		foreach ($col as $element) {
			$this->assertEquals(array_shift($expectedPositions), $col->getPosition(), 'getPosition() returns the current position');
			$this->assertEquals($element, $col->getCurrent(), 'getPosition() does not change the current position');
		}
	}

	public function testGetFirst()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->getFirst(), 'getFirst() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar1', $col->getFirst(), 'getFirst() returns value of the first element in the collection');
	}

	public function testIsFirst()
	{
		$col = new PropulsionCollection();
		$this->assertTrue($col->isFirst(), 'isFirst() returns true on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$expectedRes = array(true, false, false);
		foreach ($col as $element) {
			$this->assertEquals(array_shift($expectedRes), $col->isFirst(), 'isFirst() returns true only for the first element');
			$this->assertEquals($element, $col->getCurrent(), 'isFirst() does not change the current position');
		}
	}

	public function testGetPrevious()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->getPrevious(), 'getPrevious() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertNull($col->getPrevious(), 'getPrevious() returns null when the internal pointer is at the beginning of the list');
		$col->getNext();
		$this->assertEquals('bar1', $col->getPrevious(), 'getPrevious() returns the previous element');
		$this->assertEquals('bar1', $col->getCurrent(), 'getPrevious() decrements the internal pointer');
	}

	public function testGetCurrent()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->getCurrent(), 'getCurrent() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar1', $col->getCurrent(), 'getCurrent() returns the value of the first element when the internal pointer is at the beginning of the list');
		foreach ($col as $key => $value) {
			$this->assertEquals($value, $col->getCurrent(), 'getCurrent() returns the value of the current element in the collection');
		}
	}

	public function testGetNext()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->getNext(), 'getNext() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar2', $col->getNext(), 'getNext() returns the second element when the internal pointer is at the beginning of the list');
		$this->assertEquals('bar2', $col->getCurrent(), 'getNext() increments the internal pointer');
		$col->getNext();
		$this->assertNull($col->getNext(), 'getNext() returns null when the internal pointer is at the end of the list');
	}

	public function testGetLast()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->getLast(), 'getLast() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar3', $col->getLast(), 'getLast() returns the last element');
		$this->assertEquals('bar3', $col->getCurrent(), 'getLast() moves the internal pointer to the last element');
	}

	public function testIsLAst()
	{
		$col = new PropulsionCollection();
		$this->assertTrue($col->isLast(), 'isLast() returns true on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$expectedRes = array(false, false, true);
		foreach ($col as $element) {
			$this->assertEquals(array_shift($expectedRes), $col->isLast(), 'isLast() returns true only for the last element');
			$this->assertEquals($element, $col->getCurrent(), 'isLast() does not change the current position');
		}
	}

	public function testIsEmpty()
	{
		$col = new PropulsionCollection();
		$this->assertTrue($col->isEmpty(), 'isEmpty() returns true on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertFalse($col->isEmpty(), 'isEmpty() returns false on a non empty collection');
	}

	public function testIsOdd()
	{
		$col = new PropulsionCollection();
		$this->assertFalse($col->isOdd(), 'isOdd() returns false on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection();
		$col->setData($data);
		foreach ($col as $key => $value) {
			$this->assertEquals((bool) ($key % 2), $col->isOdd(), 'isOdd() returns true only when the key is odd');
		}
	}

	public function testIsEven()
	{
		$col = new PropulsionCollection();
		$this->assertTrue($col->isEven(), 'isEven() returns true on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection();
		$col->setData($data);
		foreach ($col as $key => $value) {
			$this->assertEquals(!(bool) ($key % 2), $col->isEven(), 'isEven() returns true only when the key is even');
		}
	}

	public function testGet()
	{
		$col = new PropulsionCollection(array('foo', 'bar'));
		$this->assertEquals('foo', $col->get(0), 'get() returns an element from its key');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetUnknownOffset()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionCollection();
		$bar = $col->get('foo');
	}

	public function testPop()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->pop(), 'pop() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar3', $col->pop(), 'pop() returns the last element of the collection');
		$this->assertEquals(array('bar1', 'bar2'), $col->getData(), 'pop() removes the last element of the collection');
	}

	public function testShift()
	{
		$col = new PropulsionCollection();
		$this->assertNull($col->shift(), 'shift() returns null on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals('bar1', $col->shift(), 'shift() returns the first element of the collection');
		$this->assertEquals(array('bar2', 'bar3'), $col->getData(), 'shift() removes the first element of the collection');
	}

	public function testPrepend()
	{
		$col = new PropulsionCollection();
		$this->assertEquals(1, $col->prepend('a'), 'prepend() returns 1 on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals(4, $col->prepend('bar4'), 'prepend() returns the new number of elements in the collection when adding a variable');
		$this->assertEquals(array('bar4', 'bar1', 'bar2', 'bar3'), $col->getData(), 'prepend() adds new element to the beginning of the collection');
	}

	public function testSet()
	{
		$col = new PropulsionCollection();
		$col->set(4, 'bar');
		$this->assertEquals(array(4 => 'bar'), $col->getData(), 'set() adds an element to the collection with a key');

		$col = new PropulsionCollection();
		$col->set(null, 'foo');
		$col->set(null, 'bar');
		$this->assertEquals(array('foo', 'bar'), $col->getData(), 'set() adds an element to the collection without a key');
	}

	public function testRemove()
	{
		$col = new PropulsionCollection();
		$col[0] = 'bar';
		$col[1] = 'baz';
		$col->remove(1);
		$this->assertEquals(array('bar'), $col->getData(), 'remove() removes an element from its key');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testRemoveUnknownOffset()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionCollection();
		$col->remove(2);
	}

	public function testClear()
	{
		$col = new PropulsionCollection();
		$col->clear();
		$this->assertEquals(array(), $col->getData(), 'clear() empties the collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$col->clear();
		$this->assertEquals(array(), $col->getData(), 'clear() empties the collection');
	}

	public function testContains()
	{
		$col = new PropulsionCollection();
		$this->assertFalse($col->contains('foo_1'), 'contains() returns false on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertTrue($col->contains('bar1'), 'contains() returns true when the key exists');
		$this->assertFalse($col->contains('bar4'), 'contains() returns false when the key does not exist');
	}

	public function testSearch()
	{
		$col = new PropulsionCollection();
		$this->assertFalse($col->search('bar1'), 'search() returns false on an empty collection');
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$this->assertEquals(1, $col->search('bar2'), 'search() returns the key when the element exists');
		$this->assertFalse($col->search('bar4'), 'search() returns false when the element does not exist');
	}

	public function testSerializable()
	{
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$col->setModel('Foo');
		$serializedCol = serialize($col);

		$col2 = unserialize($serializedCol);
		$this->assertEquals($col, $col2, 'PropulsionCollection is serializable');
	}

	public function testGetIterator()
	{
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$it1 = $col->getIterator();
		$it2 = $col->getIterator();
		$this->assertNotSame($it1, $it2, 'getIterator() returns always a new iterator');
	}

	public function testGetInternalIterator()
	{
		$data = array('bar1', 'bar2', 'bar3');
		$col = new PropulsionCollection($data);
		$it1 = $col->getInternalIterator();
		$it2 = $col->getINternalIterator();
		$this->assertSame($it1, $it2, 'getInternalIterator() returns always the same iterator');
		$col->getInternalIterator()->next();
		$this->assertEquals('bar2', $col->getInternalIterator()->current(), 'getInternalIterator() returns always the same iterator');
	}

	public function testGetPeerClass()
	{
		$col = new PropulsionCollection();
		$col->setModel('Book');
		$this->assertEquals('BookPeer', $col->getPeerClass(), 'getPeerClass() returns the Peer class for the collection model');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetPeerClassNoModel()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionCollection();
		$col->getPeerClass();
	}

	public function testGetConnection()
	{
		$col = new PropulsionCollection();
		$col->setModel('Book');
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
		$this->assertEquals($con, $col->getConnection(), 'getConnection() returns a connection for the collection model');
		$con = Propulsion::getConnection(BookPeer::DATABASE_NAME, Propulsion::CONNECTION_WRITE);
		$this->assertEquals($con, $col->getConnection(Propulsion::CONNECTION_WRITE), 'getConnection() accepts a connection type parameter');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetConnectionNoModel()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionCollection();
		$col->getConnection();
	}

}