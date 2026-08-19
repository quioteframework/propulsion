<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Exception\PropulsionException;
use Propulsion\Observability\TelemetryConfig;

/**
 * The `telemetry` section is brand new, so -- like `cache.query`
 * ({@see QueryCacheConfigTest}) -- it rejects unknown keys and wrong types
 * outright rather than silently ignoring them. A quietly ignored
 * `'endpiont' => '...'` typo would leave `enabled: true` shipping nothing,
 * forever, with no error anywhere.
 */
class TelemetryConfigTest extends TestCase
{
    public function testAbsentTelemetrySectionIsDisabled()
    {
        $config = TelemetryConfig::fromConfigArray(['datasources' => []]);

        $this->assertFalse($config->enabled);
        $this->assertFalse($config->isActive());
    }

    public function testDisabledConfigNeedsNoEndpoint()
    {
        $config = TelemetryConfig::fromConfigArray(['telemetry' => ['enabled' => false]]);

        $this->assertFalse($config->isActive());
        $this->assertSame('', $config->endpoint);
    }

    public function testDefaults()
    {
        $config = TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => ['endpoint' => 'http://collector:4318/v1/traces'],
            ],
        ]);

        $this->assertTrue($config->enabled);
        $this->assertTrue($config->isActive());
        $this->assertSame(TelemetryConfig::DEFAULT_SERVICE_NAME, $config->serviceName);
        $this->assertSame('http://collector:4318/v1/traces', $config->endpoint);
        $this->assertSame(TelemetryConfig::PROTOCOL_HTTP_PROTOBUF, $config->protocol);
        $this->assertSame([], $config->headers);
        $this->assertSame(TelemetryConfig::DEFAULT_TIMEOUT_SECONDS, $config->timeoutSeconds);
        $this->assertSame(1.0, $config->samplerRatio);
        $this->assertTrue($config->recordStatementText);
    }

    public function testFullSectionIsParsed()
    {
        $config = TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'service_name' => 'bookstore',
                'exporter' => [
                    'endpoint' => 'https://collector.internal:4318/v1/traces',
                    'protocol' => 'http/json',
                    'headers' => ['authorization' => 'Bearer secret'],
                    'timeout' => 5,
                ],
                'sampler' => ['ratio' => 0.25],
                'record_statement_text' => false,
            ],
        ]);

        $this->assertSame('bookstore', $config->serviceName);
        $this->assertSame('https://collector.internal:4318/v1/traces', $config->endpoint);
        $this->assertSame('http/json', $config->protocol);
        $this->assertSame(['authorization' => 'Bearer secret'], $config->headers);
        $this->assertSame(5, $config->timeoutSeconds);
        $this->assertSame(0.25, $config->samplerRatio);
        $this->assertFalse($config->recordStatementText);
    }

    public function testUnknownTopLevelKeyIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.exportre/');

        TelemetryConfig::fromConfigArray(['telemetry' => ['exportre' => []]]);
    }

    public function testUnknownExporterKeyIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.exporter\.endpiont/');

        TelemetryConfig::fromConfigArray(['telemetry' => ['exporter' => ['endpiont' => 'http://x']]]);
    }

    public function testEnabledWithoutEndpointIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.exporter\.endpoint/');

        TelemetryConfig::fromConfigArray(['telemetry' => ['enabled' => true]]);
    }

    public function testMalformedEndpointIsRejected()
    {
        $this->expectException(PropulsionException::class);

        TelemetryConfig::fromConfigArray([
            'telemetry' => ['enabled' => true, 'exporter' => ['endpoint' => 'not a url']],
        ]);
    }

    public function testUnsupportedProtocolIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.exporter\.protocol/');

        TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => ['endpoint' => 'http://collector:4318/v1/traces', 'protocol' => 'grpc'],
            ],
        ]);
    }

    public function testNonStringHeaderIsRejected()
    {
        $this->expectException(PropulsionException::class);

        TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => [
                    'endpoint' => 'http://collector:4318/v1/traces',
                    'headers' => ['x-count' => 5],
                ],
            ],
        ]);
    }

    public function testSamplerRatioBelowZeroIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.sampler\.ratio/');

        TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => ['endpoint' => 'http://collector:4318/v1/traces'],
                'sampler' => ['ratio' => -0.1],
            ],
        ]);
    }

    public function testSamplerRatioAboveOneIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.sampler\.ratio/');

        TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => ['endpoint' => 'http://collector:4318/v1/traces'],
                'sampler' => ['ratio' => 1.1],
            ],
        ]);
    }

    public function testTimeoutBelowOneIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/telemetry\.exporter\.timeout/');

        TelemetryConfig::fromConfigArray([
            'telemetry' => [
                'enabled' => true,
                'exporter' => ['endpoint' => 'http://collector:4318/v1/traces', 'timeout' => 0],
            ],
        ]);
    }
}
