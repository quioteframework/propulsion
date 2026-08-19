<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Propulsion\Observability\TelemetryConfig;
use Propulsion\Observability\TelemetryTracerProviderFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A fake PSR-18 client standing in for the network -- every export request
 * is answered locally and recorded, so this suite can assert real spans made
 * it all the way to an HTTP transport without a live collector and without
 * any risk of a real (failing) connection attempt.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = array();

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        // An empty body is a valid protobuf-encoded empty response, but not
        // valid JSON -- reply with "{}" for that protocol so the exporter's
        // own response parsing does not itself error and log, independent of
        // what this test is actually asserting.
        $body = $request->getHeaderLine('Content-Type') === 'application/json' ? '{}' : '';

        return new Response(200, [], $body);
    }
}

/**
 * {@see TelemetryTracerProviderFactory::build()}'s real construction path --
 * OTLP/HTTP exporter, batch processor, resource, sampler -- verified against
 * a fake PSR-18 client so no network is ever touched.
 *
 * Does not attempt the "optional package missing" or "no PSR-18 client
 * installed" exception branches: both are `class_exists()`/discovery guards
 * against the environment's installed packages, and this repo's own test
 * suite requires `open-telemetry/sdk`, `open-telemetry/exporter-otlp` and a
 * PSR-18 client (`symfony/http-client`) in `require-dev` to run at all --
 * there is no way to make either branch true without uninstalling a
 * dependency the rest of this suite needs.
 */
class TelemetryTracerProviderFactoryTest extends TestCase
{
    public function testBuildProducesAWorkingProviderThatExportsOverTheSuppliedClient()
    {
        $client = new RecordingHttpClient();
        $config = TelemetryConfig::fromConfigArray(['telemetry' => [
            'enabled' => true,
            'service_name' => 'factory-test',
            'exporter' => ['endpoint' => 'http://fake-collector.test/v1/traces'],
        ]]);

        $provider = TelemetryTracerProviderFactory::build($config, $client);

        $provider->getTracer('propulsion-test')->spanBuilder('SELECT')->startSpan()->end();
        $provider->forceFlush();

        $this->assertCount(1, $client->requests, 'the batch processor must have exported over the supplied client');
        $this->assertSame('POST', $client->requests[0]->getMethod());
        $this->assertSame('fake-collector.test', $client->requests[0]->getUri()->getHost());
        $this->assertSame('/v1/traces', $client->requests[0]->getUri()->getPath());

        $provider->shutdown();
    }

    public function testHttpJsonProtocolSetsTheMatchingContentType()
    {
        $client = new RecordingHttpClient();
        $config = TelemetryConfig::fromConfigArray(['telemetry' => [
            'enabled' => true,
            'exporter' => [
                'endpoint' => 'http://fake-collector.test/v1/traces',
                'protocol' => TelemetryConfig::PROTOCOL_HTTP_JSON,
            ],
        ]]);

        $provider = TelemetryTracerProviderFactory::build($config, $client);
        $provider->getTracer('propulsion-test')->spanBuilder('SELECT')->startSpan()->end();
        $provider->forceFlush();

        $this->assertSame(['application/json'], $client->requests[0]->getHeader('Content-Type'));

        $provider->shutdown();
    }

    public function testConfiguredHeadersReachTheExportRequest()
    {
        $client = new RecordingHttpClient();
        $config = TelemetryConfig::fromConfigArray(['telemetry' => [
            'enabled' => true,
            'exporter' => [
                'endpoint' => 'http://fake-collector.test/v1/traces',
                'headers' => ['authorization' => 'Bearer secret-token'],
            ],
        ]]);

        $provider = TelemetryTracerProviderFactory::build($config, $client);
        $provider->getTracer('propulsion-test')->spanBuilder('SELECT')->startSpan()->end();
        $provider->forceFlush();

        $this->assertSame(['Bearer secret-token'], $client->requests[0]->getHeader('authorization'));

        $provider->shutdown();
    }

    public function testBuildRejectsAnInactiveConfig()
    {
        $this->expectException(\Propulsion\Exception\PropulsionException::class);

        TelemetryTracerProviderFactory::build(TelemetryConfig::disabled());
    }
}
