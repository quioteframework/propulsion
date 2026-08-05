<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\QueryStatsObserver;
use Propulsion\Propulsion;

/**
 * The claim the whole feature rests on: observers see the statements the ORM
 * actually issues, not just the ones a test drives through PDO by hand.
 *
 * Worth its own live test because this is precisely where the *previous*
 * instrumentation in this codebase was wrong -- dropped-connection detection
 * lived on exec()/query()/DebugPDOStatement and therefore saw almost no ORM
 * traffic at all, which is what PropulsionStatement was introduced to fix. An
 * observability hook placed the same way would have measured nothing while
 * looking like it worked.
 */
class QueryObserverOrmTrafficTest extends BookstoreTestBase
{
	private QueryStatsObserver $stats;

	protected function setUp(): void
	{
		parent::setUp();
		Propulsion::clearQueryObservers();
		$this->stats = new QueryStatsObserver();
	}

	protected function tearDown(): void
	{
		Propulsion::clearQueryObservers();
		parent::tearDown();
	}

	public function testAGeneratedQueryClassFindIsObserved()
	{
		Propulsion::addQueryObserver($this->stats);
		BookQuery::create()->limit(1)->find();

		$this->assertGreaterThanOrEqual(1, $this->stats->getCount());
		$this->assertSame(0, $this->stats->getFailedCount());
		$this->assertGreaterThanOrEqual(
			1,
			$this->stats->getCountBySource()[QueryExecution::SOURCE_STATEMENT] ?? 0,
			'the ORM prepares and executes; it must be seen there, not only on exec()/query()'
		);
	}

	public function testSavingAnObjectIsObserved()
	{
		$sql = array();
		Propulsion::addQueryObserver(new class($sql) implements \Propulsion\Observability\QueryObserver {
			/** @param array<int, string> $sql */
			public function __construct(private array &$sql)
			{
			}

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->sql[] = $execution->sql;
			}
		});

		$book = new Book();
		$book->setTitle('Observed');
		$book->setISBN('isbn-observed');
		$book->save();

		$inserts = array_filter($sql, static fn (string $s): bool => stripos($s, 'INSERT INTO book') !== false);
		$this->assertNotEmpty($inserts, 'the INSERT the ORM issued must have been observed');
	}

	public function testCountIsObserved()
	{
		Propulsion::addQueryObserver($this->stats);
		BookQuery::create()->count();

		$this->assertGreaterThanOrEqual(1, $this->stats->getCount());
		$this->assertGreaterThan(0.0, $this->stats->getTotalSeconds());
	}
}
