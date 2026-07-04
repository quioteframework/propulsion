<?php

/*
 *	$Id: SoftDeleteBehaviorTest.php 1612 2010-03-16 22:56:21Z francois $
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests for AggregateColumnBehavior class
 *
 * @author		 François Zaninotto
 * @version		$Revision$
 * @package		generator.behavior.aggregate_column
 */
class AggregateColumnBehaviorWithSchemaTest extends SchemasTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->con = Propulsion::getConnection(BookstoreSchemasBookstorePeer::DATABASE_NAME);
		$this->con->beginTransaction();
	}

	protected function tearDown(): void
	{
		// A failing test (e.g. a constraint violation mid-transaction) leaves
		// Postgres in an aborted-transaction state, in which a plain commit()
		// throws -- see BookstoreTestBase::tearDown() for the same guard. Left
		// unguarded, that exception would skip parent::tearDown() below, which
		// is what restores the shared Propulsion configuration back to the
		// bookstore datasource (SchemasTestBase::setUp() swaps it to the
		// schemas datasource); every subsequent bookstore-fixture test running
		// afterward in this process would then fail with "No connection
		// information ... for datasource [bookstore]".
		if ($this->con && $this->con->isCommitable()) {
			$this->con->commit();
		} elseif ($this->con && $this->con->isInTransaction()) {
			$this->con->forceRollBack();
		}
		parent::tearDown();
	}

	public function testParametersWithSchema()
	{
		$storeTable = BookstoreSchemasBookstorePeer::getTableMap();
		$this->assertEquals(count($storeTable->getColumns()), 8, 'AggregateColumn adds one column by default');
		$this->assertTrue(method_exists('BookstoreSchemasBookstore', 'getTotalContestEntries'));
	}

	public function testComputeWithSchema()
	{
		ContestBookstoreContestEntryQuery::create()->deleteAll($this->con);
		BookstoreSchemasBookstoreQuery::create()->deleteAll($this->con);
		BookstoreSchemasCustomerQuery::create()->deleteAll($this->con);
		ContestBookstoreContestQuery::create()->deleteAll($this->con);

		$store = new BookstoreSchemasBookstore();
		$store->setStoreName('Foo!');
		$store->save($this->con);
		$this->assertEquals(0, $store->computeTotalContestEntries($this->con), 'The compute method returns 0 for objects with no related objects');

		$contest = new ContestBookstoreContest();
		$contest->setBookstoreSchemasBookstore($store);
		$contest->save($this->con);
		$customer1 = new BookstoreSchemasCustomer();
		$customer1->save($this->con);

		$entry1 = new ContestBookstoreContestEntry();
		$entry1->setBookstoreSchemasBookstore($store);
		$entry1->setContestBookstoreContest($contest);
		$entry1->setBookstoreSchemasCustomer($customer1);
		$entry1->save($this->con, true); // skip reload to avoid #1151 for now

		$this->assertEquals(1, $store->computeTotalContestEntries($this->con), 'The compute method computes the aggregate function on related objects');

		$customer2 = new BookstoreSchemasCustomer();
		$customer2->save($this->con);

		$entry2 = new ContestBookstoreContestEntry();
		$entry2->setBookstoreSchemasBookstore($store);
		$entry2->setContestBookstoreContest($contest);
		$entry2->setBookstoreSchemasCustomer($customer2);
		$entry2->save($this->con, true); // skip reload to avoid #1151 for now

		$this->assertEquals(2, $store->computeTotalContestEntries($this->con), 'The compute method computes the aggregate function on related objects');
		$entry1->delete($this->con);
		$this->assertEquals(1, $store->computeTotalContestEntries($this->con), 'The compute method computes the aggregate function on related objects');
	}
}