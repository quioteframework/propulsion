<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Observability\OpenTelemetryQueryObserver;
use Propulsion\Observability\QueryExecution;
use Propulsion\Propulsion;

/**
 * `OpenTelemetryQueryObserver` against an in-memory span exporter, so no
 * network or a real collector is involved -- exactly how the wiring-level
 * happy path is also exercised in `TelemetryWiringTest`, via
 * `Propulsion::setTelemetryTracerProvider()` rather than the
 * `telemetry.enabled` config path, for the same reason: the config path
 * builds a real OTLP/HTTP exporter, and pointing that at anything reachable
 * from a unit test would mean either a live collector or a fake PSR-18
 * client (covered separately in `TelemetryTracerProviderFactoryTest`).
 *
 * Real `sqlite::memory:` traffic through `PropulsionStatement`, like
 * `QueryObserverTest`, for the same reason that test gives: the only way to
 * check the hook actually sits on the path ORM traffic takes.
 */
class OpenTelemetryQueryObserverTest extends TestCase
{
    private const DATASOURCE = 'otel_query_observer_test';

    private PropulsionPDO $pdo;
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        parent::setUp();

        Propulsion::clearQueryObservers();

        $this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
        $this->pdo->setConfiguration(new PropulsionConfiguration(array()));
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        Propulsion::setDB(self::DATASOURCE, new DBSQLite());
        Propulsion::setConnection(self::DATASOURCE, $this->pdo, Propulsion::CONNECTION_WRITE);

        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider([new SimpleSpanProcessor($this->exporter)]);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Propulsion::clearQueryObservers();
        Propulsion::discardConnection($this->pdo);
        parent::tearDown();
    }

    private function newObserver(bool $recordStatementText = true): OpenTelemetryQueryObserver
    {
        return new OpenTelemetryQueryObserver(
            fn () => $this->tracerProvider->getTracer('propulsion-test'),
            $recordStatementText
        );
    }

    private function insertWidget(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
        $this->assertNotFalse($stmt);
        $stmt->execute(array($name));
    }

    public function testSuccessfulStatementProducesAClientSpanWithDbAttributes()
    {
        Propulsion::addQueryObserver($this->newObserver());

        $this->insertWidget('a');

        $spans = $this->exporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];

        $this->assertSame('INSERT', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(
            DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_SQLITE,
            $span->getAttributes()->get(DbIncubatingAttributes::DB_SYSTEM_NAME)
        );
        $this->assertStringContainsString(
            'INSERT INTO widgets',
            (string) $span->getAttributes()->get(DbIncubatingAttributes::DB_QUERY_TEXT)
        );
        $this->assertSame(1, $span->getAttributes()->get(DbIncubatingAttributes::DB_RESPONSE_RETURNED_ROWS));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertTrue($span->hasEnded());
    }

    public function testASelectDoesNotReportARowCount()
    {
        // Mirrors QueryObserverTest::testASelectDoesNotReportARowCount(): the
        // span must not carry an attribute QueryExecution itself deliberately
        // never populates for a SELECT.
        $this->insertWidget('a');
        Propulsion::addQueryObserver($this->newObserver());

        $stmt = $this->pdo->prepare('SELECT name FROM widgets');
        $this->assertNotFalse($stmt);
        $stmt->execute();

        $span = $this->exporter->getSpans()[0];
        $this->assertSame('SELECT', $span->getName());
        $this->assertFalse($span->getAttributes()->has(DbIncubatingAttributes::DB_RESPONSE_RETURNED_ROWS));
    }

    public function testRecordStatementTextCanBeDisabled()
    {
        // Most Propulsion traffic is a prepared statement with placeholders,
        // but exec()/query() can carry literals -- see docs/OBSERVABILITY.md.
        Propulsion::addQueryObserver($this->newObserver(recordStatementText: false));

        $this->insertWidget('a');

        $span = $this->exporter->getSpans()[0];
        $this->assertFalse($span->getAttributes()->has(DbIncubatingAttributes::DB_QUERY_TEXT));
    }

    public function testAFailedStatementRecordsTheExceptionAndAnErrorStatus()
    {
        Propulsion::addQueryObserver($this->newObserver());

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

        $spans = $this->exporter->getSpans();
        $failedSpan = $spans[1];

        $this->assertSame(StatusCode::STATUS_ERROR, $failedSpan->getStatus()->getCode());
        $this->assertSame(\PDOException::class, $failedSpan->getAttributes()->get(ErrorAttributes::ERROR_TYPE));

        $events = $failedSpan->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('exception', $events[0]->getName());
        $this->assertTrue($failedSpan->hasEnded(), 'a failed statement must still close its span');
    }

    public function testASpanThatWasNeverStartedIsIgnoredRatherThanThrowing()
    {
        // The defensive branch for when queryStarted() itself threw before
        // reaching setAttribute() -- QueryObservers already caught and logged
        // that, so queryFinished() must tolerate finding nothing there.
        $observer = $this->newObserver();
        $execution = new QueryExecution('SELECT 1', QueryExecution::SOURCE_QUERY, $this->pdo);
        $execution->finish(null, null);

        $observer->queryFinished($execution);

        $this->assertSame(array(), $this->exporter->getSpans());
    }

    public function testTracerIsResolvedAtMostOnce()
    {
        $calls = 0;
        $observer = new OpenTelemetryQueryObserver(function () use (&$calls) {
            $calls++;

            return $this->tracerProvider->getTracer('propulsion-test');
        });
        Propulsion::addQueryObserver($observer);

        $this->insertWidget('a');
        $this->insertWidget('b');

        $this->assertSame(1, $calls, 'the tracer factory is memoised after the first statement');
        $this->assertCount(2, $this->exporter->getSpans());
    }
}
