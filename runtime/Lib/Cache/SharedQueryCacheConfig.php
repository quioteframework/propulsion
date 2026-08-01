<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use Propulsion\Exception\PropulsionException;

/**
 * Runtime settings for {@see SharedQueryCache}: the parts of
 * {@see QueryCacheConfig} the shared tier actually consumes, plus the two knobs
 * that are implementation details rather than user configuration (the hash
 * algorithm and the payload format epoch).
 */
final readonly class SharedQueryCacheConfig
{
    /**
     * Bumped whenever the stored payload's shape changes. It is folded into
     * every cache key, so an upgraded deployment cannot read entries written by
     * an older Propulsion in a format it no longer understands -- they simply
     * become unreachable and age out.
     */
    public const PAYLOAD_FORMAT = 1;

    /**
     * @param string   $namespace   key prefix; validated by QueryCacheConfig
     * @param int|null $ttl         result-entry TTL in seconds, null for no expiry
     * @param int|null $versionTtl  table-version-token TTL; null (the default) means never expire
     * @param int      $minSightings admission threshold; 1 admits on first miss
     * @param int|null $admissionWindow sighting-marker TTL in seconds
     * @param float    $beta        XFetch aggressiveness; 0.0 disables early recomputation
     * @param int      $lockTtl     single-flight lock TTL in seconds
     * @param string   $hashAlgo    key digest algorithm
     */
    public function __construct(
        public string $namespace = QueryCacheConfig::DEFAULT_NAMESPACE,
        public ?int $ttl = QueryCacheConfig::DEFAULT_TTL,
        public ?int $versionTtl = null,
        public int $minSightings = QueryCacheConfig::DEFAULT_MIN_SIGHTINGS,
        public ?int $admissionWindow = null,
        public float $beta = QueryCacheConfig::DEFAULT_BETA,
        public int $lockTtl = QueryCacheConfig::DEFAULT_LOCK_TTL,
        public string $hashAlgo = 'xxh128',
    ) {
        if (!in_array($this->hashAlgo, hash_algos(), true)) {
            throw new PropulsionException('Unsupported Propulsion cache hash algorithm: ' . $this->hashAlgo);
        }

        // A version token that expires before the entries derived from it does
        // not cause staleness -- the next reader seeds a fresh token and
        // everything derived from the old one becomes unreachable -- but it
        // does cause a cache-wide miss storm for that table. Catching an
        // obviously inverted configuration here is cheaper than diagnosing the
        // resulting load spike in production.
        if ($this->versionTtl !== null && $this->ttl !== null && $this->versionTtl <= $this->ttl) {
            throw new PropulsionException(sprintf(
                'The query cache version-token TTL (%d s) must be much greater than the result TTL (%d s), '
                . 'or every table version will expire before the entries derived from it and cause a miss storm. '
                . 'Prefer leaving it unset (no expiry).',
                $this->versionTtl,
                $this->ttl
            ));
        }
    }

    public static function fromQueryCacheConfig(QueryCacheConfig $config): self
    {
        return new self(
            namespace: $config->namespace,
            ttl: $config->ttl,
            minSightings: $config->minSightings,
            admissionWindow: $config->getAdmissionWindow(),
            beta: $config->beta,
            lockTtl: $config->lockTtl,
        );
    }
}
