<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionOnDemandCollection.
 *
 * @author     Francois Zaninotto
 * @version    $Id: PropulsionObjectCollectionTest.php 1348 2009-12-03 21:49:00Z francois $
 * @package    runtime.collection
 */
class PropulsionOnDemandCollectionTest extends BookstoreEmptyTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::populate($this->con);
		Propulsion::disableInstancePooling();
		$this->books = PropulsionQuery::from('Book')->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)->find();
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		Propulsion::enableInstancePooling();
	}

	public function testSetFormatter()
	{
		$this->assertTrue($this->books instanceof PropulsionOnDemandCollection);
		$this->assertEquals(4, count($this->books));
	}

	public function testKeys()
	{
		$i = 0;
		foreach ($this->books as $key => $book) {
			$this->assertEquals($i, $key);
			$i++;
		}
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testoffsetExists()
	{
		$this->expectException(PropulsionException::class);
		$this->books->offsetExists(2);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testoffsetGet()
	{
		$this->expectException(PropulsionException::class);
		$this->books->offsetGet(2);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testoffsetSet()
	{
		$this->expectException(PropulsionException::class);
		$this->books->offsetSet(2, 'foo');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testoffsetUnset()
	{
		$this->expectException(PropulsionException::class);
		$this->books->offsetUnset(2);
	}

	public function testToArray()
	{
		$this->assertNotEquals(array(), $this->books->toArray());
		// since the code from toArray comes frmo PropulsionObjectCollection, we'll assume it's good
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testFromArray()
	{
		$this->expectException(PropulsionException::class);
		$this->books->fromArray(array());
	}

}