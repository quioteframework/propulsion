<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use Propulsion\Config\ConfigSectionReader;
use Propulsion\Exception\PropulsionException;

/**
 * Parsed, validated form of the optional `cache.query` section of the runtime
 * configuration -- the settings for the global (cross-process) query result
 * cache tier. See docs/CACHING.md.
 *
 * Unlike {@see \Propulsion\Propulsion::getDatasourcesConfig()} and friends,
 * which silently degrade a missing/malformed section to an empty array, this
 * class rejects unknown keys and wrong value types outright, following the
 * stricter precedent of {@see \Propulsion\Propulsion::processDriverOptions()}.
 * The section is brand new, so there is no back-compatibility surface to
 * preserve, and a silently-ignored `'tll' => 300` typo is a bug that would
 * never surface -- the cache would simply run with the default TTL forever.
 *
 * The whole section is optional: an absent `cache` key yields
 * {@see self::disabled()}, which is byte-for-byte the pre-existing
 * request-scoped-only behaviour.
 */
final readonly class QueryCacheConfig
{
    use ConfigSectionReader;

    /** Driver name meaning "the application supplies its own PSR-16 pool". */
    public const DRIVER_USER_SUPPLIED = 'psr16';

    public const DEFAULT_TTL = 300;
    public const DEFAULT_NAMESPACE = 'propulsion';
    public const DEFAULT_MIN_SIGHTINGS = 2;
    public const DEFAULT_BETA = 1.0;
    public const DEFAULT_LOCK_TTL = 5;

    /**
     * @param bool        $enabled       master on/off switch; false means the shared tier is never consulted
     * @param string      $driver        '' | 'null' | 'array' | 'apcu' | 'file' | 'psr16'
     * @param int|null    $ttl           result-entry TTL in seconds; null means no expiry (discouraged)
     * @param string      $namespace     key prefix, so several apps may share one backend
     * @param int         $minSightings  how many times a query must be seen before its result is admitted
     * @param int|null    $admissionWindow  TTL of the sighting markers, in seconds; null means use $ttl
     * @param float       $beta          XFetch aggressiveness; 0.0 disables probabilistic early recomputation
     * @param int         $lockTtl       single-flight lock TTL in seconds
     * @param array<string, mixed> $driverOptions the selected driver's own option block
     */
    public function __construct(
        public bool $enabled = false,
        public string $driver = '',
        public ?int $ttl = self::DEFAULT_TTL,
        public string $namespace = self::DEFAULT_NAMESPACE,
        public int $minSightings = self::DEFAULT_MIN_SIGHTINGS,
        public ?int $admissionWindow = null,
        public float $beta = self::DEFAULT_BETA,
        public int $lockTtl = self::DEFAULT_LOCK_TTL,
        public array $driverOptions = [],
    ) {
    }

    /**
     * The "no cache configured" config: what an absent `cache` section, or an
     * explicit `enabled => false`, resolves to.
     */
    public static function disabled(): self
    {
        return new self();
    }

    /**
     * Effective TTL for the sighting markers used by admission control.
     */
    public function getAdmissionWindow(): ?int
    {
        return $this->admissionWindow ?? $this->ttl;
    }

    /**
     * True when a shared tier should actually be built and consulted.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->driver !== '' && $this->driver !== 'null';
    }

    /**
     * Build from the whole runtime configuration array (the thing
     * `Propulsion::setConfiguration()` was handed). An absent `cache` or
     * `cache.query` section yields {@see self::disabled()}.
     *
     * @param  array<string, mixed> $config
     * @throws PropulsionException on an unknown key, a wrong value type, or a
     *         driver whose required options are missing
     */
    public static function fromConfigArray(array $config): self
    {
        $cache = $config['cache'] ?? null;
        if ($cache === null) {
            return self::disabled();
        }
        if (!is_array($cache)) {
            throw new PropulsionException('Propulsion configuration key "cache" must be an array: Check your configuration file');
        }

        self::rejectUnknownKeys($cache, ['query'], 'cache');

        $query = $cache['query'] ?? null;
        if ($query === null) {
            return self::disabled();
        }
        if (!is_array($query)) {
            throw new PropulsionException('Propulsion configuration key "cache.query" must be an array: Check your configuration file');
        }

        return self::fromQuerySection($query);
    }

    /**
     * @param  array<mixed, mixed> $query
     * @throws PropulsionException
     */
    private static function fromQuerySection(array $query): self
    {
        $scalarKeys = ['enabled', 'driver', 'ttl', 'namespace', 'admission', 'stampede'];
        $driverKeys = CacheDriverFactory::getDriverNames();

        self::rejectUnknownKeys($query, array_merge($scalarKeys, $driverKeys), 'cache.query');

        $enabled = self::readBool($query, 'enabled', false, 'cache.query.enabled');
        $driver = self::readString($query, 'driver', '', 'cache.query.driver');
        $ttl = self::readNullableInt($query, 'ttl', self::DEFAULT_TTL, 'cache.query.ttl');
        $namespace = self::readString($query, 'namespace', self::DEFAULT_NAMESPACE, 'cache.query.namespace');

        self::validateNamespace($namespace);

        if ($driver !== '' && $driver !== self::DRIVER_USER_SUPPLIED && !in_array($driver, $driverKeys, true)) {
            throw new PropulsionException(
                'Unsupported Propulsion cache driver: ' . $driver . ': Check your configuration file'
            );
        }

        [$minSightings, $admissionWindow] = self::readAdmission($query);
        [$beta, $lockTtl] = self::readStampede($query);

        $driverOptions = [];
        if ($driver !== '' && $driver !== self::DRIVER_USER_SUPPLIED) {
            $raw = $query[$driver] ?? [];
            if (!is_array($raw)) {
                throw new PropulsionException(
                    'Propulsion configuration key "cache.query.' . $driver . '" must be an array: Check your configuration file'
                );
            }
            /** @var array<string, mixed> $driverOptions */
            $driverOptions = $raw;
        }

        return new self(
            enabled: $enabled,
            driver: $driver,
            ttl: $ttl,
            namespace: $namespace,
            minSightings: $minSightings,
            admissionWindow: $admissionWindow,
            beta: $beta,
            lockTtl: $lockTtl,
            driverOptions: $driverOptions,
        );
    }

    /**
     * @param  array<mixed, mixed> $query
     * @return array{0: int, 1: int|null}
     * @throws PropulsionException
     */
    private static function readAdmission(array $query): array
    {
        $raw = $query['admission'] ?? [];
        if (!is_array($raw)) {
            throw new PropulsionException('Propulsion configuration key "cache.query.admission" must be an array: Check your configuration file');
        }
        self::rejectUnknownKeys($raw, ['min_sightings', 'window'], 'cache.query.admission');

        $minSightings = self::readInt($raw, 'min_sightings', self::DEFAULT_MIN_SIGHTINGS, 'cache.query.admission.min_sightings');
        if ($minSightings < 1) {
            throw new PropulsionException('Propulsion configuration option "cache.query.admission.min_sightings" must be at least 1, got ' . $minSightings);
        }

        $window = self::readNullableInt($raw, 'window', null, 'cache.query.admission.window');

        return [$minSightings, $window];
    }

    /**
     * @param  array<mixed, mixed> $query
     * @return array{0: float, 1: int}
     * @throws PropulsionException
     */
    private static function readStampede(array $query): array
    {
        $raw = $query['stampede'] ?? [];
        if (!is_array($raw)) {
            throw new PropulsionException('Propulsion configuration key "cache.query.stampede" must be an array: Check your configuration file');
        }
        self::rejectUnknownKeys($raw, ['beta', 'lock_ttl'], 'cache.query.stampede');

        $beta = self::readFloat($raw, 'beta', self::DEFAULT_BETA, 'cache.query.stampede.beta');
        if ($beta < 0.0) {
            throw new PropulsionException('Propulsion configuration option "cache.query.stampede.beta" must not be negative, got ' . $beta);
        }

        $lockTtl = self::readInt($raw, 'lock_ttl', self::DEFAULT_LOCK_TTL, 'cache.query.stampede.lock_ttl');
        if ($lockTtl < 1) {
            throw new PropulsionException('Propulsion configuration option "cache.query.stampede.lock_ttl" must be at least 1, got ' . $lockTtl);
        }

        return [$beta, $lockTtl];
    }

    /**
     * The namespace ends up in every cache key, which PSR-16 restricts to
     * `A-Za-z0-9_.` and 64 characters; keeping it short and to that charset is
     * what leaves room for the hash that follows it.
     *
     * @throws PropulsionException
     */
    private static function validateNamespace(string $namespace): void
    {
        if (preg_match('/^[A-Za-z0-9_.]{1,24}$/', $namespace) !== 1) {
            throw new PropulsionException(
                'Propulsion configuration option "cache.query.namespace" must be 1-24 characters of A-Za-z0-9_. , got "'
                . $namespace . '": Check your configuration file'
            );
        }
    }
}
