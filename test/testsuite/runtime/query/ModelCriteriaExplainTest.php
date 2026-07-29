<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Propulsion;
use Propulsion\Exception\PropulsionException;

/**
 * Integration tests for {@see \Propulsion\Query\ModelCriteria::explain()}
 * against whichever platform this test run targets (see BookstoreTestBase /
 * IntegrationDatabase -- Postgres by default, or PROPULSION_TEST_DB=mysql/
 * mariadb/mssql/oracle). Builds the exact SQL find() would, then executes it
 * wrapped in the platform's EXPLAIN syntax.
 */
class ModelCriteriaExplainTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::depopulate();
		BookstoreDataPopulator::populate();
	}

	public function testExplainReturnsAPlanOnASupportedPlatform(): void
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped('MSSQL/Oracle do not support ->explain() -- see testExplainThrowsOnAnUnsupportedPlatform().');
		}

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Harry Potter and the Order of the Phoenix');

		$plan = $c->explain(false, $this->con);

		$this->assertIsList($plan);
		$this->assertNotEmpty($plan, 'a plan for a real query must have at least one row');
		foreach ($plan as $row) {
			$this->assertIsArray($row);
		}
	}

	public function testExplainAnalyzeAlsoReturnsAPlan(): void
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped('MSSQL/Oracle do not support ->explain().');
		}

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Harry Potter and the Order of the Phoenix');

		$plan = $c->explain(true, $this->con);

		$this->assertNotEmpty($plan, 'EXPLAIN ANALYZE must still return at least one plan row');
	}

	public function testExplainThrowsOnAnUnsupportedPlatform(): void
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if (!($db instanceof DBMSSQL || $db instanceof DBOracle)) {
			$this->markTestSkipped('This platform supports ->explain() -- see testExplainReturnsAPlanOnASupportedPlatform().');
		}

		$c = new ModelCriteria('bookstore', 'Book', 'b');

		$this->expectException(PropulsionException::class);
		$c->explain(false, $this->con);
	}

	public function testExplainDoesNotConsultTheQueryResultCache(): void
	{
		$db = Propulsion::getDB(BookPeer::DATABASE_NAME);
		if ($db instanceof DBMSSQL || $db instanceof DBOracle) {
			$this->markTestSkipped('MSSQL/Oracle do not support ->explain().');
		}

		Propulsion::getSession()->getQueryResultCache()->clear();

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->setQueryCache(true);
		$c->where('b.Title = ?', 'Harry Potter and the Order of the Phoenix');

		$c->explain(false, $this->con);

		$this->assertSame(0, Propulsion::getSession()->getQueryResultCache()->count(), 'explain() must not populate the query result cache, even for a query with setQueryCache(true)');
	}
}
