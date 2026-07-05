<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropulsionObjectCollection.
 *
 * @author     Francois Zaninotto
 * @version    $Id: PropulsionObjectCollectionTest.php 1348 2009-12-03 21:49:00Z francois $
 */
class PropulsionObjectCollectionTest extends BookstoreTestBase
{

	public function testContains()
	{
		$col = new PropulsionObjectCollection();
		$book1 = new Book();
		$book1->setTitle('Foo');
		$book2 = new Book();
		$book2->setTitle('Bar');
		$col = new PropulsionObjectCollection();
		$this->assertFalse($col->contains($book1));
		$this->assertFalse($col->contains($book2));
		$col []= $book1;
		$this->assertTrue($col->contains($book1));
		$this->assertFalse($col->contains($book2));
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testSaveOnReadOnlyEntityThrowsException()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionObjectCollection();
		$col->setModel('ContestView');
		$cv = new ContestView();
		$col []= $cv;
		$col->save();
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testDeleteOnReadOnlyEntityThrowsException()
	{
		$this->expectException(PropulsionException::class);
		$col = new PropulsionObjectCollection();
		$col->setModel('ContestView');
		$cv = new ContestView();
		$cv->setNew(false);
		$col []= $cv;
		$col->delete();
	}

	public function testGetPrimaryKeys()
	{
		$books = new PropulsionObjectCollection();
		$books->setModel('Book');
		for ($i=0; $i < 4; $i++) {
			$book = new Book();
			$book->setTitle('Title' . $i);
			$book->setISBN('0140422161');
			$book->save($this->con);
			$books []= $book;
		}

		$pks = $books->getPrimaryKeys();
		$this->assertEquals(4, count($pks));

		$keys = array('Book_0', 'Book_1', 'Book_2', 'Book_3');
		$this->assertEquals($keys, array_keys($pks));

		$pks = $books->getPrimaryKeys(false);
		$keys = array(0, 1, 2, 3);
		$this->assertEquals($keys, array_keys($pks));

		foreach ($pks as $key => $value) {
			$this->assertEquals($books[$key]->getPrimaryKey(), $value);
		}
	}

	public function testToArrayDeep()
	{
		$author = new Author();
		$author->setId(5678);
		$author->setFirstName('George');
		$author->setLastName('Byron');
		$book = new Book();
		$book->setId(9012);
		$book->setTitle('Don Juan');
		$book->setISBN('0140422161');
		$book->setPrice(12.99);
		$book->setAuthor($author);

		$coll = new PropulsionObjectCollection();
		$coll->setModel('Book');
		$coll[]= $book;
		$expected = array(array(
			'Id' => 9012,
			'Title' => 'Don Juan',
			'ISBN' => '0140422161',
			'Price' => 12.99,
			'PublisherId' => null,
			'AuthorId' => 5678,
			'Author' => array(
				'Id' => 5678,
				'FirstName' => 'George',
				'LastName' => 'Byron',
				'Email' => null,
				'Age' => null,
				'Books' => array(
					'Book_0' => '*RECURSION*',
				)
			),
		));
		// toArray()'s $includeForeignObjects param (both here and on BaseObject::toArray()
		// itself) defaults to false -- a deliberate, deep-recursion-avoiding default that
		// matches the object-level API exactly. This test's own name and its expected
		// array (which includes a nested 'Author' key, itself containing a '*RECURSION*'
		// marker for the cycle back to this book) are specifically exercising the *deep*
		// behavior, so it needs to opt in explicitly rather than relying on a since-changed
		// implicit default.
		$this->assertEquals($expected, $coll->toArray(null, false, BasePeer::TYPE_PHPNAME, true, array(), true));
	}

	public function testPopulateRelationOneToManyWithEmptyCollection()
	{
		$author = new Author();
		$author->setFirstName('Anonymous');
		$author->setLastName('I who never wrote');
		$author->save($this->con);
		AuthorPeer::clearInstancePool();
		BookPeer::clearInstancePool();
		$coll = new PropulsionObjectCollection();
		$coll->setFormatter(new PropulsionObjectFormatter(new ModelCriteria(null, 'Author')));
		$coll []= $author;
		$books = $coll->populateRelation('Book', null, $this->con);
		$this->assertEquals(0, $books->count());
		$count = $this->con->getQueryCount();
		$this->assertEquals(0, $author->countBooks());
		$this->assertEquals($count, $this->con->getQueryCount());
	}
}