<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

use Propulsion\Config\ConfigSectionReader;
use Propulsion\Exception\PropulsionException;

/**
 * Parsed, validated form of the optional `telemetry` section of the runtime
 * configuration -- turns OpenTelemetry span export for every statement on or
 * off. See docs/OBSERVABILITY.md.
 *
 * Mirrors {@see \Propulsion\Cache\QueryCacheConfig}'s strictness: unknown
 * keys and wrong value types are rejected outright rather than silently
 * ignored, via the same {@see ConfigSectionReader} both classes share. A
 * silently-ignored `'enpoint' => '...'` typo is a bug that would never
 * surface -- the exporter would simply never receive an endpoint and the
 * feature would look "on" while shipping nothing.
 *
 * The whole section is optional: an absent `telemetry` key yields
 * {@see self::disabled()}, under which {@see \Propulsion\Propulsion} never
 * touches `open-telemetry/*` at all -- no class is referenced, so an
 * application that never sets `telemetry.enabled` does not need those
 * packages installed.
 */
final readonly class TelemetryConfig
{
    use ConfigSectionReader;

    public const PROTOCOL_HTTP_PROTOBUF = 'http/protobuf';
    public const PROTOCOL_HTTP_JSON = 'http/json';

    public const DEFAULT_SERVICE_NAME = 'propulsion';
    public const DEFAULT_PROTOCOL = self::PROTOCOL_HTTP_PROTOBUF;
    public const DEFAULT_TIMEOUT_SECONDS = 10;
    public const DEFAULT_SAMPLER_RATIO = 1.0;

    /**
     * @param bool                 $enabled              master on/off switch
     * @param string               $serviceName          resource attribute identifying the app in the backend
     * @param string                $endpoint            OTLP/HTTP traces endpoint, e.g. "http://collector:4318/v1/traces"
     * @param string               $protocol             {@see PROTOCOL_HTTP_PROTOBUF} or {@see PROTOCOL_HTTP_JSON}
     * @param array<string, string> $headers             extra HTTP headers sent with every export request (e.g. auth)
     * @param int                  $timeoutSeconds       export request timeout
     * @param float                $samplerRatio         0.0 (nothing) .. 1.0 (everything), TraceIdRatioBased
     * @param bool                 $recordStatementText  whether `db.query.text` is attached to spans -- see the PII
     *                                                    note in docs/OBSERVABILITY.md
     */
    public function __construct(
        public bool $enabled = false,
        public string $serviceName = self::DEFAULT_SERVICE_NAME,
        public string $endpoint = '',
        public string $protocol = self::DEFAULT_PROTOCOL,
        public array $headers = [],
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public float $samplerRatio = self::DEFAULT_SAMPLER_RATIO,
        public bool $recordStatementText = true,
    ) {
    }

    /**
     * The "no telemetry configured" config: what an absent `telemetry`
     * section, or an explicit `enabled => false`, resolves to.
     */
    public static function disabled(): self
    {
        return new self();
    }

    /**
     * True when an observer should actually be built and registered.
     */
    public function isActive(): bool
    {
        return $this->enabled;
    }

    /**
     * Build from the whole runtime configuration array (the thing
     * `Propulsion::setConfiguration()` was handed). An absent `telemetry`
     * section yields {@see self::disabled()}.
     *
     * @param  array<string, mixed> $config
     * @throws PropulsionException on an unknown key, a wrong value type, or
     *         an `enabled => true` section missing its endpoint
     */
    public static function fromConfigArray(array $config): self
    {
        $telemetry = $config['telemetry'] ?? null;
        if ($telemetry === null) {
            return self::disabled();
        }
        if (!is_array($telemetry)) {
            throw new PropulsionException('Propulsion configuration key "telemetry" must be an array: Check your configuration file');
        }

        self::rejectUnknownKeys(
            $telemetry,
            ['enabled', 'service_name', 'exporter', 'sampler', 'record_statement_text'],
            'telemetry'
        );

        $enabled = self::readBool($telemetry, 'enabled', false, 'telemetry.enabled');
        $serviceName = self::readString($telemetry, 'service_name', self::DEFAULT_SERVICE_NAME, 'telemetry.service_name');
        $recordStatementText = self::readBool($telemetry, 'record_statement_text', true, 'telemetry.record_statement_text');

        [$endpoint, $protocol, $headers, $timeoutSeconds] = self::readExporter($telemetry, $enabled);
        $samplerRatio = self::readSampler($telemetry);

        return new self(
            enabled: $enabled,
            serviceName: $serviceName,
            endpoint: $endpoint,
            protocol: $protocol,
            headers: $headers,
            timeoutSeconds: $timeoutSeconds,
            samplerRatio: $samplerRatio,
            recordStatementText: $recordStatementText,
        );
    }

    /**
     * @param  array<mixed, mixed> $telemetry
     * @return array{0: string, 1: string, 2: array<string, string>, 3: int}
     * @throws PropulsionException
     */
    private static function readExporter(array $telemetry, bool $enabled): array
    {
        $raw = $telemetry['exporter'] ?? [];
        if (!is_array($raw)) {
            throw new PropulsionException('Propulsion configuration key "telemetry.exporter" must be an array: Check your configuration file');
        }
        self::rejectUnknownKeys($raw, ['endpoint', 'protocol', 'headers', 'timeout'], 'telemetry.exporter');

        $endpoint = self::readString($raw, 'endpoint', '', 'telemetry.exporter.endpoint');
        if ($enabled && $endpoint === '') {
            throw new PropulsionException(
                'Propulsion configuration option "telemetry.exporter.endpoint" is required when "telemetry.enabled" is true: '
                . 'Check your configuration file'
            );
        }
        if ($endpoint !== '' && filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw new PropulsionException(
                'Propulsion configuration option "telemetry.exporter.endpoint" must be a valid URL, got "' . $endpoint . '"'
            );
        }

        $protocol = self::readString($raw, 'protocol', self::DEFAULT_PROTOCOL, 'telemetry.exporter.protocol');
        if ($protocol !== self::PROTOCOL_HTTP_PROTOBUF && $protocol !== self::PROTOCOL_HTTP_JSON) {
            throw new PropulsionException(
                'Propulsion configuration option "telemetry.exporter.protocol" must be "'
                . self::PROTOCOL_HTTP_PROTOBUF . '" or "' . self::PROTOCOL_HTTP_JSON . '", got "' . $protocol . '"'
            );
        }

        $headers = self::readHeaders($raw);

        $timeoutSeconds = self::readInt($raw, 'timeout', self::DEFAULT_TIMEOUT_SECONDS, 'telemetry.exporter.timeout');
        if ($timeoutSeconds < 1) {
            throw new PropulsionException('Propulsion configuration option "telemetry.exporter.timeout" must be at least 1, got ' . $timeoutSeconds);
        }

        return [$endpoint, $protocol, $headers, $timeoutSeconds];
    }

    /**
     * @param  array<mixed, mixed> $raw
     * @return array<string, string>
     * @throws PropulsionException
     */
    private static function readHeaders(array $raw): array
    {
        $headers = $raw['headers'] ?? [];
        if (!is_array($headers)) {
            throw new PropulsionException('Propulsion configuration key "telemetry.exporter.headers" must be an array: Check your configuration file');
        }

        $result = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new PropulsionException(
                    'Propulsion configuration key "telemetry.exporter.headers" must be a map of string header names to string values'
                );
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed> $telemetry
     * @throws PropulsionException
     */
    private static function readSampler(array $telemetry): float
    {
        $raw = $telemetry['sampler'] ?? [];
        if (!is_array($raw)) {
            throw new PropulsionException('Propulsion configuration key "telemetry.sampler" must be an array: Check your configuration file');
        }
        self::rejectUnknownKeys($raw, ['ratio'], 'telemetry.sampler');

        $ratio = self::readFloat($raw, 'ratio', self::DEFAULT_SAMPLER_RATIO, 'telemetry.sampler.ratio');
        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new PropulsionException('Propulsion configuration option "telemetry.sampler.ratio" must be between 0.0 and 1.0, got ' . $ratio);
        }

        return $ratio;
    }
}
