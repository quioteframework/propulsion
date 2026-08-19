<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Observability\OpenTelemetryQueryObserver;
use Propulsion\Propulsion;

/**
 * `Propulsion::setConfiguration()`'s telemetry wiring -- registering and
 * tearing down an {@see OpenTelemetryQueryObserver} as `telemetry.enabled`
 * changes, and {@see Propulsion::setTelemetryTracerProvider()}'s override.
 *
 * Deliberately never exercises the `telemetry.enabled` *config-driven* path
 * far enough to build a real tracer provider: that would construct a real
 * OTLP/HTTP exporter via {@see \Propulsion\Observability\TelemetryTracerProviderFactory},
 * and pointing that at a fake endpoint from a unit test risks a real (failing)
 * network attempt when the registered `register_shutdown_function()` fires
 * at the end of the whole PHPUnit process, not just this test. These tests
 * only ever run a query with telemetry *registered but never resolved*
 * (config-driven, unresolved) or resolved against an in-memory exporter (via
 * {@see Propulsion::setTelemetryTracerProvider()}, which never touches the
 * factory at all). The factory's own happy path, with a fake PSR-18 client
 * standing in for the network, is covered by `TelemetryTracerProviderFactoryTest`.
 */
class TelemetryWiringTest extends TestCase
{
    private PropulsionPDO $pdo;
    private PropulsionStateSnapshot $state;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here calls Propulsion::setConfiguration(), which drops
        // the whole adapter map -- capture/restore so a datasource another
        // test registered with setDB() (unrebuildable from configuration)
        // does not vanish for good; see PropulsionStateSnapshot's docblock.
        $this->state = PropulsionStateSnapshot::capture();

        Propulsion::setConfiguration(array());   // clears any telemetry wiring left by a previous test
        Propulsion::clearQueryObservers();

        $this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
        $this->pdo->setConfiguration(new PropulsionConfiguration(array()));
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
    }

    protected function tearDown(): void
    {
        Propulsion::clearQueryObservers();
        Propulsion::discardConnection($this->pdo);
        $this->state->restore();
        parent::tearDown();
    }

    /** @return array<int, OpenTelemetryQueryObserver> */
    private function registeredTelemetryObservers(): array
    {
        return array_values(array_filter(
            Propulsion::getQueryObservers()->all(),
            static fn ($observer): bool => $observer instanceof OpenTelemetryQueryObserver
        ));
    }

    public function testEnablingTelemetryViaConfigRegistersAnObserver()
    {
        Propulsion::setConfiguration(['telemetry' => [
            'enabled' => true,
            'exporter' => ['endpoint' => 'http://collector.invalid:4318/v1/traces'],
        ]]);

        $this->assertCount(1, $this->registeredTelemetryObservers());
    }

    public function testAbsentTelemetrySectionRegistersNothing()
    {
        Propulsion::setConfiguration(['datasources' => []]);

        $this->assertCount(0, $this->registeredTelemetryObservers());
    }

    public function testDisablingTelemetryRemovesTheObserver()
    {
        Propulsion::setConfiguration(['telemetry' => [
            'enabled' => true,
            'exporter' => ['endpoint' => 'http://collector.invalid:4318/v1/traces'],
        ]]);
        $this->assertCount(1, $this->registeredTelemetryObservers());

        Propulsion::setConfiguration(['telemetry' => ['enabled' => false]]);

        $this->assertCount(0, $this->registeredTelemetryObservers());
    }

    public function testReconfiguringWhileStillEnabledRegistersExactlyOne()
    {
        Propulsion::setConfiguration(['telemetry' => [
            'enabled' => true,
            'exporter' => ['endpoint' => 'http://collector.invalid:4318/v1/traces'],
        ]]);
        Propulsion::setConfiguration(['telemetry' => [
            'enabled' => true,
            'exporter' => ['endpoint' => 'http://another-collector.invalid:4318/v1/traces'],
        ]]);

        $this->assertCount(1, $this->registeredTelemetryObservers());
    }

    public function testBringYourOwnTracerProviderRegistersImmediatelyAndExportsRealSpans()
    {
        // telemetry.enabled is never set at all -- the manual path from
        // docs/OBSERVABILITY.md must keep working independent of it.
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        Propulsion::setTelemetryTracerProvider($provider);
        $this->assertCount(1, $this->registeredTelemetryObservers());

        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
        $this->assertNotFalse($stmt);
        $stmt->execute(array('a'));

        $this->assertCount(1, $exporter->getSpans());
        $provider->shutdown();
    }

    public function testReconfiguringDropsAPreviouslyRegisteredOverride()
    {
        // Same contract as Propulsion::setQueryCachePool(): an override does
        // not survive setConfiguration() either. Call
        // setTelemetryTracerProvider() again afterward if you still want it.
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider([new SimpleSpanProcessor($exporter)]);
        Propulsion::setTelemetryTracerProvider($provider);

        Propulsion::setConfiguration(['datasources' => []]);
        $this->assertCount(0, $this->registeredTelemetryObservers());

        $stmt = $this->pdo->prepare('INSERT INTO widgets (name) VALUES (?)');
        $this->assertNotFalse($stmt);
        $stmt->execute(array('a'));

        $this->assertSame(array(), $exporter->getSpans(), 'the dropped override must not still be receiving spans');
        $provider->shutdown();
    }

    public function testFlushTelemetryIsANoOpWhenNothingWasBuilt()
    {
        Propulsion::flushTelemetry();
        $this->addToAssertionCount(1);   // did not throw
    }
}
