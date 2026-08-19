<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * The process-shared (L2) tier: raw pre-hydration rows in a PSR-16 pool, keyed
 * by query identity plus the version tokens of every table the query reads.
 *
 * Storing *rows* rather than formatted results is what makes the tier safe to
 * share. A serialized PropulsionCollection would carry a whole live object
 * graph across a request boundary; a row array is inert data, and re-hydrating
 * it on the way out runs the normal generated `populateObject()` path, instance
 * pool and all -- so an L2 hit is indistinguishable from a fresh database read.
 *
 * Because rows are formatter-agnostic, an ARRAY-formatted and an
 * OBJECT-formatted query with identical SQL share one entry here.
 * {@see buildKey()} deliberately takes no formatter/variant discriminator, which
 * is the one place this tier's key differs in *shape* from L1's
 * ({@see TieredQueryCache::localKey()}): L1 stores the formatted result, so
 * there the discriminator prevents a genuine wrong-type collision, while here it
 * would only fragment identical row sets across as many entries as there are
 * ways to format them.
 *
 * Nothing in this class may throw on a backend problem. A dead Redis or an
 * unwritable cache directory has to degrade to a miss, never to a failed query.
 */
class SharedQueryCache
{
    /**
     * Distinguishes "stored null" from "not present" without a second round
     * trip. PSR-16's has() is both an extra call and documented as racy, so
     * every read passes this object as the default and compares identity.
     */
    private readonly object $miss;

    public function __construct(
        private readonly CacheInterface $backend,
        private readonly SharedQueryCacheConfig $config,
        private readonly TableVersionRegistry $versions,
        /**
         * Source of the uniform random draw used by probabilistic early
         * recomputation. Injectable so the behaviour is testable without
         * flakiness; production never passes one.
         *
         * @var (callable(): float)|null
         */
        private $randomSource = null,
    ) {
        $this->miss = new \stdClass();
    }

    public function getConfig(): SharedQueryCacheConfig
    {
        return $this->config;
    }

    public function versions(): TableVersionRegistry
    {
        return $this->versions;
    }

    public function getBackend(): CacheInterface
    {
        return $this->backend;
    }

    /**
     * The cache key for a query, given the current version tokens of the tables
     * it reads.
     *
     * Hashed because the raw key would be illegal PSR-16 on two counts: it
     * exceeds 64 characters and SQL is full of reserved characters
     * (`{}()/\@:`). xxh128 is used rather than a cryptographic digest because
     * it is far faster over the multi-kilobyte payloads a joined query's SQL
     * produces, and 128 bits puts a collision out of reach.
     *
     * **Datasource, SQL and parameters identify the rows completely**, which is
     * why no formatter or variant discriminator appears here. What this tier
     * stores is exactly what executing that statement returns -- fetched
     * `PDO::FETCH_NUM` by {@see \Propulsion\Util\StatementRows}, the same way
     * for every formatter -- so two callers agreeing on those three things are
     * asking for the same rows however differently they intend to shape them
     * afterwards. Adding a discriminator would not prevent a collision; it would
     * manufacture one entry per formatter for one row set.
     *
     * @param array<int|string, mixed> $params
     * @param list<string>      $versionTokens
     */
    public function buildKey(string $dbName, string $sql, array $params, array $versionTokens): string
    {
        $payload = implode("\0", array_merge(
            [
                (string) SharedQueryCacheConfig::PAYLOAD_FORMAT,
                $dbName,
                $sql,
                serialize($params),
            ],
            $versionTokens
        ));

        return $this->config->namespace . '.q.' . hash($this->config->hashAlgo, $payload);
    }

    /**
     * Look up a stored row set.
     *
     * @param  string $key
     * @return array{hit: bool, rows: list<array<int, mixed>>, stale: bool}
     *         `stale` is true when the entry is still valid but probabilistic
     *         early recomputation has elected this caller to refresh it.
     */
    public function fetch(string $key): array
    {
        try {
            /** @var mixed $stored */
            $stored = $this->backend->get($key, $this->miss);
        } catch (\Throwable) {
            return ['hit' => false, 'rows' => [], 'stale' => false];
        }

        if ($stored === $this->miss || !is_array($stored)) {
            return ['hit' => false, 'rows' => [], 'stale' => false];
        }

        $rows = $this->normaliseRows($stored['d'] ?? null);
        if (($stored['f'] ?? null) !== SharedQueryCacheConfig::PAYLOAD_FORMAT || $rows === null) {
            return ['hit' => false, 'rows' => [], 'stale' => false];
        }

        return [
            'hit' => true,
            'rows' => $rows,
            'stale' => $this->shouldRecomputeEarly($stored),
        ];
    }

    /**
     * Store a row set, subject to admission control.
     *
     * @param  list<array<int, mixed>> $rows
     * @param  float $elapsedSeconds how long the query took, recorded so
     *         probabilistic early recomputation can weight expensive queries
     *         toward refreshing sooner
     * @param  bool|null $admitted the outcome of an {@see admit()} call the
     *         caller already made for this key, so the sighting is not counted
     *         twice. Null (the default) means "decide now", which is what a
     *         caller that just wants to store something wants.
     *         {@see TieredQueryCache::remember()} passes it because it has to
     *         know the answer *before* running the query -- see admit()'s note
     *         on why asking is not free of consequence.
     * @return bool whether the entry was actually admitted
     */
    public function store(string $key, array $rows, ?int $ttl, float $elapsedSeconds = 0.0, ?bool $admitted = null): bool
    {
        if (!$this->isStorable($rows)) {
            return false;
        }
        if (!($admitted ?? $this->admit($key))) {
            return false;
        }

        $payload = [
            'f' => SharedQueryCacheConfig::PAYLOAD_FORMAT,
            'd' => $rows,
            'c' => time(),
            'e' => $elapsedSeconds,
            't' => $ttl ?? $this->config->ttl,
        ];

        try {
            return $this->backend->set($key, $payload, $ttl ?? $this->config->ttl);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Admission control: the defence against cache pollution.
     *
     * A query whose bound parameters vary per request -- `WHERE id = <random>`,
     * whether from a legitimately diverse workload or a deliberate flood --
     * produces a distinct key every time. Every request misses, and if every
     * miss also *wrote*, the cache would grow without bound while never serving
     * a hit. Stampede protection does nothing about this: there is no
     * contention on any single key.
     *
     * So an entry is only admitted once its key has been seen
     * `min_sightings` times within a short window, tracked by a tiny marker
     * key. A never-repeating key never reaches a second sighting and never
     * stores anything, so the flood costs the attacker database load and costs
     * the cache nothing. A genuinely repeated query is admitted from its second
     * execution.
     *
     * **Asking records a sighting**, so this is not a free predicate and must be
     * called exactly once per miss for a given key. Public because
     * {@see TieredQueryCache::remember()} needs the answer *before* it runs the
     * query: with the default `min_sightings` of 2, the first execution of every
     * cached query is rejected, and materialising the whole row set only to
     * discard it doubled peak memory for that query. It hands the answer back to
     * {@see store()} via that method's `$admitted` argument so the sighting is
     * not counted a second time.
     */
    public function admit(string $key): bool
    {
        if ($this->config->minSightings <= 1) {
            return true;
        }

        $markerKey = $this->sightingKey($key);
        try {
            /** @var mixed $seen */
            $seen = $this->backend->get($markerKey, $this->miss);
            if ($seen !== $this->miss && is_int($seen)) {
                if ($seen + 1 >= $this->config->minSightings) {
                    return true;
                }
                $this->backend->set($markerKey, $seen + 1, $this->config->admissionWindow);

                return false;
            }

            $this->backend->set($markerKey, 1, $this->config->admissionWindow);
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Try to become the single caller that recomputes $key.
     *
     * Only possible when the backend can create-if-absent atomically, which
     * PSR-16 cannot express; see {@see AtomicCache}. On a pool that cannot,
     * every caller "wins" and the probabilistic defence is what keeps
     * simultaneous recomputation rare.
     */
    public function acquireRecomputeLock(string $key): bool
    {
        if (!$this->backend instanceof AtomicCache) {
            return true;
        }

        try {
            return $this->backend->add($this->lockKey($key), 1, $this->config->lockTtl);
        } catch (\Throwable) {
            return true;
        }
    }

    public function releaseRecomputeLock(string $key): void
    {
        if (!$this->backend instanceof AtomicCache) {
            return;
        }

        try {
            $this->backend->delete($this->lockKey($key));
        } catch (\Throwable) {
            // A lock left behind expires on its own within lock_ttl.
        }
    }

    /**
     * Probabilistic early recomputation (the XFetch scheme).
     *
     * As an entry nears expiry, each reader independently draws a random number
     * and may elect to refresh it early; the probability rises as expiry
     * approaches and scales with how expensive the query was to run. The effect
     * is that one reader refreshes slightly early while everyone else still
     * gets a hit, instead of every reader piling onto the database the instant
     * the entry lapses. It needs no locking, no extra round trip, and leaves
     * nothing to clean up if a process dies mid-refresh.
     *
     * @param array<string, mixed> $stored
     */
    private function shouldRecomputeEarly(array $stored): bool
    {
        if ($this->config->beta <= 0.0) {
            return false;
        }

        $createdAt = $stored['c'] ?? null;
        $ttl = $stored['t'] ?? null;
        $elapsed = $stored['e'] ?? null;
        if (!is_int($createdAt) || !is_int($ttl) || $ttl <= 0 || !is_float($elapsed) || $elapsed <= 0.0) {
            return false;
        }

        $expiry = $createdAt + $ttl;
        $random = $this->randomFraction();
        if ($random <= 0.0) {
            return false;
        }

        return (time() - ($elapsed * $this->config->beta * log($random))) >= $expiry;
    }

    private function randomFraction(): float
    {
        if ($this->randomSource !== null) {
            return ($this->randomSource)();
        }

        return mt_rand(1, mt_getrandmax()) / mt_getrandmax();
    }

    /**
     * Validate a payload read back from the backend into the row shape the
     * formatters expect.
     *
     * Whatever comes out of a shared cache is untrusted input: another process
     * (or another application sharing the namespace) may have written it, and
     * an older Propulsion may have used a different shape. Checking rather than
     * asserting means a malformed entry is a miss, not a type error thrown at
     * a caller who merely ran a SELECT.
     *
     * @return list<array<int, mixed>>|null null if the payload is not a row set
     */
    private function normaliseRows(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $rows = [];
        foreach ($value as $row) {
            // is_array() alone is not enough: the formatters expect
            // PDO::FETCH_NUM-shaped rows (sequential int keys from 0), the
            // same convention StatementRows::iterate() and
            // PropulsionOnDemandIterator::next() already rely on. A row with
            // string keys is exactly as malformed for this cache as a
            // non-array value -- a miss, not a type error at the formatter.
            if (!is_array($row) || !array_is_list($row)) {
                return null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * PDO can hand back a stream resource for a BLOB column. Such a query
     * silently skips the shared tier; the request-scoped tier still serves it.
     *
     * **Every row is checked, not just the first.** serialize() does not fail
     * on a resource -- it quietly writes `i:0`, with no warning and nothing for
     * store()'s catch to catch -- so a result set whose first row happens to
     * carry a NULL blob while a later row carries a real stream used to sail
     * through this check and get published with its blob columns silently
     * replaced by the integer 0. A wrong value served from cache is far worse
     * than a skipped one, and the scan is cheap next to the serialize() it is
     * guarding (is_resource() is a type-tag test, and it stops at the first
     * hit).
     *
     * @param list<array<int, mixed>> $rows
     */
    private function isStorable(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_resource($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function sightingKey(string $key): string
    {
        return $this->config->namespace . '.s.' . hash($this->config->hashAlgo, $key);
    }

    private function lockKey(string $key): string
    {
        return $this->config->namespace . '.l.' . hash($this->config->hashAlgo, $key);
    }
}
