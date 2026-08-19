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
use Propulsion\Observability\RowCapturingQueryObserver;
use Propulsion\Propulsion;

/**
 * Requests row capture (with an optional cap) in queryStarted(), records
 * every rowsCaptured() call it sees.
 */
class RecordingRowCapturingObserver implements RowCapturingQueryObserver
{
	/** @var array<int, QueryExecution> */
	public array $rowsCapturedCalls = array();

	public function __construct(private readonly int $maxRows = 100)
	{
	}

	public function queryStarted(QueryExecution $execution): void
	{
		$execution->requestRowCapture($this->maxRows);
	}

	public function queryFinished(QueryExecution $execution): void
	{
	}

	public function rowsCaptured(QueryExecution $execution): void
	{
		$this->rowsCapturedCalls[] = $execution;
	}
}

/**
 * An ordinary observer that never asks for rows -- used to confirm it never
 * sees rowsCaptured() and is unaffected by a row-capturing observer running
 * alongside it.
 */
class PlainQueryObserver implements QueryObserver
{
	/** @var array<int, string> */
	public array $calls = array();

	public function queryStarted(QueryExecution $execution): void
	{
		$this->calls[] = 'started';
	}

	public function queryFinished(QueryExecution $execution): void
	{
		$this->calls[] = 'finished';
	}
}

/**
 * `QueryExecution`'s bound-parameter capture, correlation id, and
 * {@see RowCapturingQueryObserver} -- against a real `sqlite::memory:`
 * connection through the real {@see \Propulsion\Connection\PropulsionStatement},
 * matching {@see QueryObserverTest}'s style: the point is to prove these work
 * through the actual statement class the ORM uses, not a stand-in.
 */
class RowCapturingQueryObserverTest extends TestCase
{
	private const DATASOURCE = 'row_capturing_query_observer_test';

	private PropulsionPDO $pdo;

	protected function setUp(): void
	{
		parent::setUp();

		Propulsion::clearQueryObservers();
		Propulsion::setCorrelationId(null);

		$this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
		$this->pdo->setConfiguration(new PropulsionConfiguration(array()));
		$this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

		Propulsion::setDB(self::DATASOURCE, new DBSQLite());
		Propulsion::setConnection(self::DATASOURCE, $this->pdo, Propulsion::CONNECTION_WRITE);
	}

	protected function tearDown(): void
	{
		Propulsion::clearQueryObservers();
		Propulsion::setCorrelationId(null);
		Propulsion::discardConnection($this->pdo);
		parent::tearDown();
	}

	/** Inserts via explicit bindValue() + a no-arg execute(), matching how DBAdapter::bindValues() actually calls it. */
	private function insertWidgetViaBindValue(int $id, string $name): void
	{
		$stmt = $this->pdo->prepare('INSERT INTO widgets (id, name) VALUES (?, ?)');
		$this->assertNotFalse($stmt);
		$stmt->bindValue(1, $id, PDO::PARAM_INT);
		$stmt->bindValue(2, $name, PDO::PARAM_STR);
		$stmt->execute();
	}

	public function testBoundParamsAreCapturedForQuestionMarkPlaceholders()
	{
		$observer = new class implements QueryObserver {
			public ?QueryExecution $captured = null;

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				if (str_contains($execution->sql, 'INSERT')) {
					$this->captured = $execution;
				}
			}
		};
		Propulsion::addQueryObserver($observer);

		$this->insertWidgetViaBindValue(1, 'a');

		$this->assertNotNull($observer->captured);
		$this->assertSame(array(1 => 1, 2 => 'a'), $observer->captured->boundParams);
	}

	public function testBoundParamsAreCapturedForNamedPlaceholders()
	{
		$observer = new class implements QueryObserver {
			public ?QueryExecution $captured = null;

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->captured = $execution;
			}
		};
		Propulsion::addQueryObserver($observer);

		$stmt = $this->pdo->prepare('INSERT INTO widgets (id, name) VALUES (:id, :name)');
		$this->assertNotFalse($stmt);
		$stmt->bindValue(':id', 7, PDO::PARAM_INT);
		$stmt->bindValue(':name', 'named', PDO::PARAM_STR);
		$stmt->execute();

		$this->assertNotNull($observer->captured);
		$this->assertSame(array(':id' => 7, ':name' => 'named'), $observer->captured->boundParams);
	}

	public function testExecAndQueryReportEmptyBoundParams()
	{
		$observer = new class implements QueryObserver {
			/** @var array<int, QueryExecution> */
			public array $captured = array();

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->captured[] = $execution;
			}
		};
		Propulsion::addQueryObserver($observer);

		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('a')");

		$this->assertCount(1, $observer->captured);
		$this->assertSame(array(), $observer->captured[0]->boundParams);
	}

	public function testCorrelationIdIsStampedOntoEveryExecution()
	{
		Propulsion::setCorrelationId('req-123');
		$observer = new PlainQueryObserver();
		Propulsion::addQueryObserver($observer);
		$captured = null;
		Propulsion::addQueryObserver(new class ($captured) implements QueryObserver {
			public function __construct(private mixed &$captured)
			{
			}

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->captured = $execution->correlationId;
			}
		});

		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('a')");

		$this->assertSame('req-123', $captured);
	}

	public function testCorrelationIdIsNullWhenNeverSet()
	{
		$captured = 'not set yet';
		Propulsion::addQueryObserver(new class ($captured) implements QueryObserver {
			public function __construct(private mixed &$captured)
			{
			}

			public function queryStarted(QueryExecution $execution): void
			{
			}

			public function queryFinished(QueryExecution $execution): void
			{
				$this->captured = $execution->correlationId;
			}
		});

		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('a')");

		$this->assertNull($captured);
	}

	public function testSessionResetClearsTheCorrelationId()
	{
		Propulsion::setCorrelationId('req-456');
		Propulsion::getSession()->reset();

		$this->assertNull(Propulsion::getCorrelationId());
	}

	public function testRowsCapturedFiresAfterEagerFetchWithColumnNames()
	{
		$this->insertWidgetViaBindValue(1, 'a');
		$this->insertWidgetViaBindValue(2, 'b');

		$rowCapturer = new RecordingRowCapturingObserver();
		Propulsion::addQueryObserver($rowCapturer);

		$stmt = $this->pdo->prepare('SELECT id, name FROM widgets ORDER BY id');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
			// drain -- mirrors StatementRows::iterate()'s fetch(PDO::FETCH_NUM) loop
		}

		$this->assertCount(1, $rowCapturer->rowsCapturedCalls);
		$execution = $rowCapturer->rowsCapturedCalls[0];
		$this->assertSame(array(array(1, 'a'), array(2, 'b')), $execution->getCapturedRows());
		$this->assertFalse($execution->isRowsTruncated());
		$this->assertSame(array('id', 'name'), $execution->getColumnNames());
	}

	public function testExceedingTheCapTruncatesRatherThanGrowingUnbounded()
	{
		for ($i = 1; $i <= 5; $i++) {
			$this->insertWidgetViaBindValue($i, 'w' . $i);
		}

		$rowCapturer = new RecordingRowCapturingObserver(maxRows: 3);
		Propulsion::addQueryObserver($rowCapturer);

		$stmt = $this->pdo->prepare('SELECT id FROM widgets ORDER BY id');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
		}

		$execution = $rowCapturer->rowsCapturedCalls[0];
		$this->assertCount(3, $execution->getCapturedRows());
		$this->assertTrue($execution->isRowsTruncated());
	}

	public function testAnObserverThatNeverRequestsCaptureSeesNoRowsCapturedCall()
	{
		$this->insertWidgetViaBindValue(1, 'a');

		$plain = new PlainQueryObserver();
		Propulsion::addQueryObserver($plain);

		$stmt = $this->pdo->prepare('SELECT id FROM widgets');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
		}

		$this->assertSame(array('started', 'finished'), $plain->calls);
	}

	public function testARowCapturingObserverAlongsideAPlainObserverDoesNotAffectThePlainOne()
	{
		$this->insertWidgetViaBindValue(1, 'a');

		$plain = new PlainQueryObserver();
		$rowCapturer = new RecordingRowCapturingObserver();
		Propulsion::addQueryObserver($plain);
		Propulsion::addQueryObserver($rowCapturer);

		$stmt = $this->pdo->prepare('SELECT id FROM widgets');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
		}

		$this->assertSame(array('started', 'finished'), $plain->calls);
		$this->assertCount(1, $rowCapturer->rowsCapturedCalls);
	}

	public function testAPartiallyReadStatementStillNotifiesViaCloseCursor()
	{
		$this->insertWidgetViaBindValue(1, 'a');
		$this->insertWidgetViaBindValue(2, 'b');

		$rowCapturer = new RecordingRowCapturingObserver();
		Propulsion::addQueryObserver($rowCapturer);

		$stmt = $this->pdo->prepare('SELECT id FROM widgets ORDER BY id');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		$stmt->fetch(PDO::FETCH_NUM);   // read exactly one of the two rows, then abandon
		$stmt->closeCursor();

		$this->assertCount(1, $rowCapturer->rowsCapturedCalls);
		$this->assertSame(array(array(1)), $rowCapturer->rowsCapturedCalls[0]->getCapturedRows());
	}

	public function testReExecutingAPreparedStatementNotifiesThePreviousExecutionFirst()
	{
		$this->insertWidgetViaBindValue(1, 'a');
		$this->insertWidgetViaBindValue(2, 'b');

		$rowCapturer = new RecordingRowCapturingObserver();
		Propulsion::addQueryObserver($rowCapturer);

		$stmt = $this->pdo->prepare('SELECT id FROM widgets ORDER BY id');
		$this->assertNotFalse($stmt);
		$stmt->execute();
		$stmt->fetch(PDO::FETCH_NUM);   // read one row, then re-execute without closing
		$stmt->execute();
		while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
		}

		// The first execute()'s partial read must have been notified once,
		// when the second execute() started, and the second execute()'s full
		// read notified once more -- never zero, never merged into one.
		$this->assertCount(2, $rowCapturer->rowsCapturedCalls);
		$this->assertSame(array(array(1)), $rowCapturer->rowsCapturedCalls[0]->getCapturedRows());
		$this->assertSame(array(array(1), array(2)), $rowCapturer->rowsCapturedCalls[1]->getCapturedRows());
	}
}
