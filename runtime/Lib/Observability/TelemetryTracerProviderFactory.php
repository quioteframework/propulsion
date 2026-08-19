<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use Propulsion\Exception\PropulsionException;
use Psr\Http\Client\ClientInterface;

/**
 * Builds a real, exporting `TracerProviderInterface` from a
 * {@see TelemetryConfig} -- the only class in this namespace that touches
 * `open-telemetry/sdk` or `open-telemetry/exporter-otlp`. Every other class
 * here (`QueryObserver`, `OpenTelemetryQueryObserver`) binds only against
 * `open-telemetry/api`, so an application that never sets
 * `telemetry.enabled` never needs this class's dependencies installed --
 * nothing here is referenced until {@see build()} actually runs.
 *
 * Resolves its own PSR-18 client via `php-http/discovery` when
 * `$httpClient` is not supplied, rather than pinning a concrete client for
 * every consumer -- see docs/OBSERVABILITY.md. That is also why
 * `psr/http-client`, `psr/http-factory` and `php-http/discovery` are ordinary
 * (not `-dev`) dependencies of Propulsion despite this feature being fully
 * optional: they are interfaces and discovery plumbing, not a client
 * implementation, the same tier as `psr/simple-cache`.
 */
abstract class TelemetryTracerProviderFactory
{
    private const REQUIRED_CLASSES = [
        \OpenTelemetry\SDK\Trace\TracerProvider::class,
        \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::class,
        \OpenTelemetry\Contrib\Otlp\SpanExporter::class,
    ];

    /**
     * @throws PropulsionException if `open-telemetry/sdk`/`open-telemetry/exporter-otlp`
     *         are not installed, if `$config` is not active, or if no PSR-18
     *         client could be resolved and none was supplied
     */
    public static function build(TelemetryConfig $config, ?ClientInterface $httpClient = null): TracerProviderInterface
    {
        if (!$config->isActive()) {
            throw new PropulsionException('TelemetryTracerProviderFactory::build() called with an inactive TelemetryConfig');
        }

        foreach (self::REQUIRED_CLASSES as $class) {
            if (!class_exists($class)) {
                throw new PropulsionException(
                    '"telemetry.enabled" is true but OpenTelemetry is not installed: composer require '
                    . 'open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp open-telemetry/sem-conv'
                );
            }
        }

        $transport = self::buildTransport($config, $httpClient);
        $exporter = new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
        $processor = new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
            $exporter,
            \OpenTelemetry\API\Common\Time\Clock::getDefault()
        );
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => $config->serviceName,
            ])
        );
        $sampler = new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
            new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($config->samplerRatio)
        );

        $provider = new \OpenTelemetry\SDK\Trace\TracerProvider([$processor], $sampler, $resource);

        // Correct granularity under ordinary PHP-FPM/CLI, where a shutdown
        // function runs at the end of every request/script -- flushing the
        // batch so a short-lived request does not lose its spans waiting for
        // the queue to fill. Under a true async worker runtime (FrankenPHP
        // worker mode) the whole worker script is one long-lived process, so
        // this fires only once, at worker exit -- see
        // Propulsion::flushTelemetry() for that case, the same "reset per
        // request yourself under a worker" contract QueryStatsObserver::reset()
        // already documents.
        register_shutdown_function(static function () use ($provider): void {
            $provider->shutdown();
        });

        return $provider;
    }

    /**
     * @return \OpenTelemetry\SDK\Common\Export\TransportInterface<'application/x-protobuf'|'application/json'|'application/x-ndjson'>
     * @throws PropulsionException if no PSR-18 client is available, either
     *         supplied or discoverable
     */
    private static function buildTransport(TelemetryConfig $config, ?ClientInterface $httpClient): \OpenTelemetry\SDK\Common\Export\TransportInterface
    {
        // TelemetryConfig::PROTOCOL_* constants are deliberately the same
        // string values as Protocols::HTTP_PROTOBUF/HTTP_JSON (both ultimately
        // OTLP's own spec strings), so no translation table is needed here.
        $contentType = \OpenTelemetry\Contrib\Otlp\Protocols::contentType($config->protocol);

        $transportFactory = new \OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory($httpClient);

        try {
            return $transportFactory->create(
                $config->endpoint,
                $contentType,
                $config->headers,
                null,
                (float) $config->timeoutSeconds
            );
        } catch (\Http\Discovery\Exception\NotFoundException $e) {
            throw new PropulsionException(
                '"telemetry.enabled" is true but no PSR-18 HTTP client is installed: composer require '
                . 'guzzlehttp/guzzle (or any other package providing psr/http-client-implementation), or call '
                . 'Propulsion::setTelemetryHttpClient() with one directly',
                $e
            );
        }
    }
}
