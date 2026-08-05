<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for BaseObject.
 *
 * @author     François Zaninotto
 */
class BaseObjectTest extends TestCase
{
	public function testGetVirtualColumns()
	{
		$b = new TestableBaseObject();
		$this->assertEquals(array(), $b->getVirtualColumns(), 'getVirtualColumns() returns an empty array for new objects');
		$b->virtualColumns = array('foo' => 'bar');
		$this->assertEquals(array('foo' => 'bar'), $b->getVirtualColumns(), 'getVirtualColumns() returns an associative array of virtual columns');
	}

	public function testHasVirtualColumn()
	{
		$b = new TestableBaseObject();
		$this->assertFalse($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns false if the virtual column is not set');
		$b->virtualColumns = array('foo' => 'bar');
		$this->assertTrue($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns true if the virtual column is set');
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetVirtualColumnWrongKey()
	{
		$this->expectException(PropulsionException::class);
		$b = new TestableBaseObject();
		$b->getVirtualColumn('foo');
	}

	public function testGetVirtualColumn()
	{
		$b = new TestableBaseObject();
		$b->virtualColumns = array('foo' => 'bar');
		$this->assertEquals('bar', $b->getVirtualColumn('foo'), 'getVirtualColumn() returns a virtual column value based on its key');
	}

	public function testSetVirtualColumn()
	{
		$b = new TestableBaseObject();
		$b->setVirtualColumn('foo', 'bar');
		$this->assertEquals('bar', $b->getVirtualColumn('foo'), 'setVirtualColumn() sets a virtual column value based on its key');
		$b->setVirtualColumn('foo', 'baz');
		$this->assertEquals('baz', $b->getVirtualColumn('foo'), 'setVirtualColumn() can modify the value of an existing virtual column');
		$this->assertEquals($b, $b->setVirtualColumn('foo', 'bar'), 'setVirtualColumn() returns the current object');
	}

	public function testModifiedColumnsAsASet()
	{
		$b = new TestableBaseObject();
		$b->markModifiedAsSet('book.TITLE');
		$this->assertTrue($b->isModified(), 'isModified() is true once a column is marked modified');
		$this->assertTrue($b->isColumnModified('book.TITLE'), 'isColumnModified() sees a set-style entry');
		$this->assertFalse($b->isColumnModified('book.ISBN'), 'isColumnModified() is false for an untouched column');
		$this->assertEquals(array('book.TITLE'), $b->getModifiedColumns(), 'getModifiedColumns() returns the column names');
		$b->resetModified('book.TITLE');
		$this->assertFalse($b->isColumnModified('book.TITLE'), 'resetModified($col) clears a set-style entry');
	}

	/**
	 * Object classes generated before modified columns became a set append to
	 * $modifiedColumns instead of keying by column name. They live in the
	 * application's own repository, so they can (and do) outlive a runtime
	 * upgrade -- and if the runtime ignored their entries, isColumnModified()
	 * would answer false for every column and every insert/update would write
	 * nothing at all.
	 */
	public function testModifiedColumnsAsALegacyList()
	{
		$b = new TestableBaseObject();
		$b->markModifiedAsList('book.TITLE');
		$b->markModifiedAsList('book.ISBN');
		$this->assertTrue($b->isModified(), 'isModified() is true once a column is appended');
		$this->assertTrue($b->isColumnModified('book.TITLE'), 'isColumnModified() sees a list-style entry');
		$this->assertTrue($b->isColumnModified('book.ISBN'), 'isColumnModified() sees every list-style entry, not just the first');
		$this->assertFalse($b->isColumnModified('book.AUTHOR_ID'), 'isColumnModified() is false for an untouched column');
		$this->assertEquals(array('book.TITLE', 'book.ISBN'), $b->getModifiedColumns(), 'getModifiedColumns() returns the appended column names');
		$b->resetModified('book.TITLE');
		$this->assertFalse($b->isColumnModified('book.TITLE'), 'resetModified($col) clears a list-style entry');
		$this->assertTrue($b->isColumnModified('book.ISBN'), 'resetModified($col) leaves the other columns alone');
		$b->resetModified();
		$this->assertFalse($b->isModified(), 'resetModified() clears list-style entries too');
	}

	public function testModifiedColumnsMixingBothStyles()
	{
		$b = new TestableBaseObject();
		$b->markModifiedAsSet('book.TITLE');
		$b->markModifiedAsList('book.ISBN');
		$this->assertTrue($b->isColumnModified('book.TITLE'), 'a set-style entry survives a later append');
		$this->assertTrue($b->isColumnModified('book.ISBN'), 'an appended entry is seen alongside set-style ones');
		$this->assertEquals(array('book.TITLE', 'book.ISBN'), $b->getModifiedColumns(), 'getModifiedColumns() reports both styles once');
	}

	public function testRepeatedModificationsAreNotDuplicated()
	{
		$b = new TestableBaseObject();
		$b->markModifiedAsList('book.TITLE');
		$b->markModifiedAsList('book.TITLE');
		$this->assertEquals(array('book.TITLE'), $b->getModifiedColumns(), 'the same column appended twice is reported once');
	}
}

class TestableBaseObject extends BaseObject
{
	public $virtualColumns = array();

	// Stands in for what a generated setter does: today's generators key by
	// column name, generators older than that appended.
	public function markModifiedAsSet(string $col): void
	{
		$this->modifiedColumns[$col] = true;
	}

	public function markModifiedAsList(string $col): void
	{
		$this->modifiedColumns[] = $col;
	}

	// BaseObject::getPrimaryKey()/clearAllReferences()/getPeer() are abstract -- every
	// generated Object class implements them (unconditionally), but this hand-written
	// test double isn't generated, so it needs its own (trivial, unused-by-these-tests)
	// stubs.
	public function getPrimaryKey()
	{
		return null;
	}

	public function clearAllReferences(bool $deep = false): void
	{
	}

	public function getPeer(): string
	{
		return 'TestableBaseObjectPeer';
	}
}
