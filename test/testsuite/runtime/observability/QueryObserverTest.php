<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\QueryObserver;
use Propulsion\Observability\QueryStatsObserver;
use Propulsion\Observability\SlowQueryObserver;
use Propulsion\Propulsion;

/**
 * Records everything it is told, so a test can assert on the sequence rather
 * than on one call at a time.
 */
class RecordingQueryObserver implements QueryObserver
{
	/** @var array<int, array{0: string, 1: QueryExecution}> */
	public array $calls = array();

	public function queryStarted(QueryExecution $execution): void
	{
		$this->calls[] = array('started', $execution);
	}

	public function queryFinished(QueryExecution $execution): void
	{
		$this->calls[] = array('finished', $execution);
	}

	/** @return array<int, string> */
	public function sequence(): array
	{
		return array_map(static fn (array $call): string => $call[0], $this->calls);
	}
}

class ThrowingQueryObserver implements QueryObserver
{
	public int $startedCalls = 0;
	public int $finishedCalls = 0;

	public function queryStarted(QueryExecution $execution): void
	{
		$this->startedCalls++;
		throw new \RuntimeException('observer is broken');
	}

	public function queryFinished(QueryExecution $execution): void
	{
		$this->finishedCalls++;
		throw new \RuntimeException('observer is broken');
	}
}

/**
 * Query observers, against a real `sqlite::memory:` connection through the
 * real statement class -- which is the only way to check the thing that
 * matters most here, that the hook sits on the path ORM traffic actually
 * takes rather than on `exec()`/`query()`, where almost nothing goes.
 */
class QueryObserverTest extends TestCase
{
	private const DATASOURCE = 'query_observer_test';

	private PropulsionPDO $pdo;

	protected function setUp(): void
	{
		parent::setUp();

		Propulsion::clearQueryObservers();

		$this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
		$this->pdo->setConfiguration(new PropulsionConfiguration(array()));
		$this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

		Propulsion::setDB(self::DATASOURCE, new DBSQLite());
		Propulsion::setConnection(self::DATASOURCE, $this->pdo, Propulsion::CONNECTION_WRITE);
	}

	protected function tearDown(): void
	{
		Propulsion::clearQueryObservers();
		Propulsion::discardConnection($this->pdo);
		parent::tearDown();
	}

	private function insertWidget(string $name): void
	{
		$stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
		$this->assertNotFalse($stmt);
		$stmt->execute(array($name));
	}

	public function testNoObserversRegisteredIsTheDefault()
	{
		$this->assertTrue(Propulsion::getQueryObservers()->isEmpty());
		$this->insertWidget('a');
		$this->assertSame('a', $this->pdo->query('SELECT name FROM widgets')->fetchColumn());
	}

	public function testAPreparedStatementExecutionIsObservedAtBothEnds()
	{
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);

		$this->insertWidget('a');

		$this->assertSame(array('started', 'finished'), $observer->sequence());
		[, $execution] = $observer->calls[1];
		$this->assertSame(QueryExecution::SOURCE_STATEMENT, $execution->source);
		$this->assertStringContainsString('INSERT INTO widgets', $execution->sql);
		$this->assertSame($this->pdo, $execution->connection);
		$this->assertFalse($execution->isFailed());
		$this->assertSame(1, $execution->getRowCount(), 'a write reports its affected rows');
		$this->assertNotNull($execution->getDurationSeconds());
		$this->assertGreaterThanOrEqual(0.0, $execution->getDurationSeconds());
	}

	public function testTheSameExecutionObjectIsPassedToBothCallbacks()
	{
		// This is what lets a tracer stash an open span on the execution in
		// queryStarted() and close it in queryFinished() without keeping its
		// own correlation map.
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);

		$this->insertWidget('a');

		$this->assertSame($observer->calls[0][1], $observer->calls[1][1]);
	}

	public function testAttributesCarryStateBetweenTheTwoCallbacks()
	{
		$span = new \stdClass();
		$observer = new class($span) implements QueryObserver {
			public mixed $recovered = null;

			public function __construct(private readonly \stdClass $span)
			{
			}

			public function queryStarted(QueryExecution $execution): void
			{
				$execution->setAttribute('test.span', $this->span);
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->recovered = $execution->getAttribute('test.span');
			}
		};
		Propulsion::addQueryObserver($observer);

		$this->insertWidget('a');

		$this->assertSame($span, $observer->recovered);
	}

	public function testASelectDoesNotReportARowCount()
	{
		// rowCount() is documented as unreliable for a SELECT, and asking for
		// it can force a driver to buffer the whole result set -- the
		// measurement would change what it measures.
		$this->insertWidget('a');
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);

		$stmt = $this->pdo->prepare('SELECT name FROM widgets');
		$this->assertNotFalse($stmt);
		$stmt->execute();

		[, $execution] = $observer->calls[1];
		$this->assertNull($execution->getRowCount());
	}

	public function testAFailedStatementIsObservedAndStillThrows()
	{
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);

		$stmt = $this->pdo->prepare('INSERT INTO widgets (id, name) VALUES (1, ?)');
		$this->assertNotFalse($stmt);
		$stmt->execute(array('a'));

		$threw = false;
		try {
			$stmt->execute(array('b'));   // duplicate primary key
		} catch (\PDOException) {
			$threw = true;
		}

		$this->assertTrue($threw, 'the statement error must still reach the caller');
		[, $execution] = $observer->calls[3];
		$this->assertTrue($execution->isFailed());
		$this->assertInstanceOf(\PDOException::class, $execution->getError());
	}

	public function testExecAndQueryAreObservedToo()
	{
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);

		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('a')");
		$this->pdo->query('SELECT name FROM widgets');

		$sources = array();
		foreach ($observer->calls as [$phase, $execution]) {
			if ($phase === 'finished') {
				$sources[] = $execution->source;
			}
		}
		$this->assertSame(array(QueryExecution::SOURCE_EXEC, QueryExecution::SOURCE_QUERY), $sources);
	}

	public function testABrokenObserverCannotBreakTheQuery()
	{
		// Telemetry breaking the query it measures is strictly worse than
		// losing the telemetry -- and a throw from queryFinished() would
		// additionally replace whatever the statement itself was reporting.
		$broken = new ThrowingQueryObserver();
		$working = new RecordingQueryObserver();
		Propulsion::addQueryObserver($broken);
		Propulsion::addQueryObserver($working);

		$this->insertWidget('a');

		$this->assertSame(1, $broken->startedCalls);
		$this->assertSame(1, $broken->finishedCalls);
		$this->assertSame(array('started', 'finished'), $working->sequence(), 'a later observer still runs');
		$this->assertSame('a', $this->pdo->query('SELECT name FROM widgets')->fetchColumn());
	}

	public function testRegisteringTheSameObserverTwiceRegistersItOnce()
	{
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);
		Propulsion::addQueryObserver($observer);

		$this->insertWidget('a');

		$this->assertSame(array('started', 'finished'), $observer->sequence());
		$this->assertCount(1, Propulsion::getQueryObservers()->all());
	}

	public function testRemoveAndClear()
	{
		$observer = new RecordingQueryObserver();
		Propulsion::addQueryObserver($observer);
		Propulsion::removeQueryObserver($observer);
		$this->assertTrue(Propulsion::getQueryObservers()->isEmpty());

		Propulsion::removeQueryObserver($observer);   // not an error

		Propulsion::addQueryObserver($observer);
		Propulsion::clearQueryObservers();
		$this->assertTrue(Propulsion::getQueryObservers()->isEmpty());

		$this->insertWidget('a');
		$this->assertSame(array(), $observer->calls);
	}

	public function testQueryStatsObserverAggregates()
	{
		$stats = new QueryStatsObserver();
		Propulsion::addQueryObserver($stats);

		$this->insertWidget('a');
		$this->insertWidget('b');
		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('c')");

		$this->assertSame(3, $stats->getCount());
		$this->assertSame(0, $stats->getFailedCount());
		$this->assertSame(
			array(QueryExecution::SOURCE_STATEMENT => 2, QueryExecution::SOURCE_EXEC => 1),
			$stats->getCountBySource()
		);
		$this->assertGreaterThan(0.0, $stats->getTotalSeconds());
		$this->assertSame($stats->getTotalSeconds() * 1000.0, $stats->getTotalMilliseconds());
		$this->assertGreaterThan(0.0, $stats->getAverageSeconds());
		$this->assertNotNull($stats->getSlowestSql());

		$stats->reset();
		$this->assertSame(0, $stats->getCount());
		$this->assertSame(0.0, $stats->getTotalSeconds());
		$this->assertSame(0.0, $stats->getAverageSeconds(), 'no division by zero when empty');
		$this->assertNull($stats->getSlowestSql());
	}

	public function testQueryStatsObserverCountsFailures()
	{
		$stats = new QueryStatsObserver();
		Propulsion::addQueryObserver($stats);

		$stmt = $this->pdo->prepare('INSERT INTO widgets (id, name) VALUES (1, ?)');
		$this->assertNotFalse($stmt);
		$stmt->execute(array('a'));
		try {
			$stmt->execute(array('b'));
		} catch (\PDOException) {
		}

		$this->assertSame(2, $stats->getCount());
		$this->assertSame(1, $stats->getFailedCount());
	}

	public function testSlowQueryObserverIgnoresFastQueries()
	{
		$seen = array();
		Propulsion::addQueryObserver(new SlowQueryObserver(30.0, function (QueryExecution $e) use (&$seen) {
			$seen[] = $e->sql;
		}));

		$this->insertWidget('a');

		$this->assertSame(array(), $seen, 'an in-memory insert is not 30 seconds slow');
	}

	public function testSlowQueryObserverReportsAnythingOverTheThreshold()
	{
		// Threshold zero: every statement is "slow", which is how the branch
		// gets exercised without making the test wait for one.
		$seen = array();
		Propulsion::addQueryObserver(new SlowQueryObserver(0.0, function (QueryExecution $e) use (&$seen) {
			$seen[] = $e->sql;
		}));

		$this->insertWidget('a');

		$this->assertCount(1, $seen);
		$this->assertStringContainsString('INSERT INTO widgets', $seen[0]);
	}

	public function testSlowQueryObserverLogsThroughPsr3ByDefault()
	{
		$logger = new class extends \Psr\Log\AbstractLogger {
			/** @var array<int, string> */
			public array $records = array();

			public function log($level, string|\Stringable $message, array $context = array()): void
			{
				$this->records[] = $level . ': ' . $message;
			}
		};
		Propulsion::addQueryObserver(new SlowQueryObserver(0.0, null, \Psr\Log\LogLevel::WARNING, $logger));

		$this->insertWidget('a');

		$this->assertCount(1, $logger->records);
		$this->assertStringStartsWith('warning: Slow query (', $logger->records[0]);
		$this->assertStringContainsString('INSERT INTO widgets', $logger->records[0]);
	}
}
