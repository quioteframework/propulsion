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
 */
class PropulsionOnDemandCollectionTest extends BookstoreEmptyTestBase
{
	protected $books;

	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::populate($this->con);
		Propulsion::disableInstancePooling();
		$this->books = PropulsionQuery::from('Book')->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)->find();
	}

	protected function tearDown(): void
	{
		// Several tests here never fully iterate $this->books (only check
		// count()/offsetExists()/etc), leaving its underlying PDOStatement's
		// result set open. Relying on PHP's own GC to eventually destruct it
		// isn't deterministic enough for FreeTDS/pdo_dblib (MSSQL, no MARS
		// support) -- a later test's own setUp() can trip "Attempt to
		// initiate a new Adaptive Server operation with results pending"
		// before that happens. Close it explicitly instead.
		if ($this->books instanceof PropulsionOnDemandCollection) {
			$this->books->getIterator()->closeCursor();
		}
		parent::tearDown();
		Propulsion::enableInstancePooling();
	}

	/**
	 * FreeTDS/pdo_dblib (MSSQL) doesn't support PDOStatement::rowCount() for a
	 * SELECT -- always -1 -- unlike Postgres/MySQL/SQLite, which is exactly
	 * the portability caveat PropulsionOnDemandIterator::count()'s own
	 * docblock warns about ("inaccurate for most databases"). pdo_oci
	 * (Oracle) has the same portability gap, always reporting 0 instead.
	 * Neither is fixable without buffering every row up front, which defeats
	 * the point of an on-demand collection.
	 */
	private function expectedRowCount(int $actual): int
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL) {
			return -1;
		}
		return $db instanceof DBOracle ? 0 : $actual;
	}

	public function testSetFormatter()
	{
		$this->assertTrue($this->books instanceof PropulsionOnDemandCollection);
		$this->assertEquals($this->expectedRowCount(4), count($this->books));
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