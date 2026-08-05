<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\SharedQueryCache;
use Propulsion\Cache\SharedQueryCacheConfig;
use Propulsion\Cache\TableVersionRegistry;
use Propulsion\Exception\PropulsionException;
use Psr\SimpleCache\CacheInterface;

/**
 * Storage-level tests for the shared tier in isolation: key derivation,
 * admission control, probabilistic early recomputation and single-flight.
 *
 * @see GlobalQueryResultCacheTest for the end-to-end version through a real DB.
 */
class SharedQueryCacheUnitTest extends TestCase
{
    private function makeCache(
        ?CacheInterface $backend = null,
        ?SharedQueryCacheConfig $config = null,
        ?callable $random = null,
    ): SharedQueryCache {
        $backend ??= new ArrayCache(10000);
        // minSightings 1 keeps admission out of the way unless a test is
        // specifically about it.
        $config ??= new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 1, beta: 0.0);

        return new SharedQueryCache($backend, $config, new TableVersionRegistry($backend, $config), $random);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rows(): array
    {
        return [[1, 'a'], [2, 'b']];
    }

    public function testStoreThenFetchRoundTripsRows()
    {
        $cache = $this->makeCache();
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v1']);

        $this->assertTrue($cache->store($key, $this->rows(), 300));

        $entry = $cache->fetch($key);
        $this->assertTrue($entry['hit']);
        $this->assertSame($this->rows(), $entry['rows']);
        $this->assertFalse($entry['stale']);
    }

    public function testFetchOfAnUnknownKeyIsAMiss()
    {
        $cache = $this->makeCache();
        $entry = $cache->fetch($cache->buildKey('bookstore', 'SELECT 1', [], ['v1']));

        $this->assertFalse($entry['hit']);
        $this->assertSame([], $entry['rows']);
    }

    public function testEmptyResultSetIsAHitNotAMiss()
    {
        // A query that legitimately matched nothing must be cached, not
        // re-executed on every request.
        $cache = $this->makeCache();
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v1']);
        $cache->store($key, [], 300);

        $entry = $cache->fetch($key);
        $this->assertTrue($entry['hit']);
        $this->assertSame([], $entry['rows']);
    }

    public function testKeysAreLegalPsr16Keys()
    {
        $cache = $this->makeCache();
        $key = $cache->buildKey(
            'bookstore',
            'SELECT b.id, b.title FROM book b JOIN author a ON (b.author_id = a.id) WHERE b.title LIKE ? AND a.id IN (1,2,3)',
            [['table' => 'book', 'column' => 'title', 'value' => '%x%']],
            ['abcdef0123456789']
        );

        // The raw form of this key would be illegal on both counts PSR-16
        // cares about: too long, and full of reserved characters.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
        $this->assertLessThanOrEqual(64, strlen($key));
    }

    public function testDifferentVersionTokensProduceDifferentKeys()
    {
        $cache = $this->makeCache();
        $before = $cache->buildKey('bookstore', 'SELECT 1', [], ['token_a']);
        $after = $cache->buildKey('bookstore', 'SELECT 1', [], ['token_b']);

        $this->assertNotSame($before, $after, 'a bumped table version must orphan the old key');
    }

    public function testKeyVariesByDatasourceSqlAndParams()
    {
        $cache = $this->makeCache();
        $base = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $this->assertNotSame($base, $cache->buildKey('other', 'SELECT 1', [], ['v']));
        $this->assertNotSame($base, $cache->buildKey('bookstore', 'SELECT 2', [], ['v']));
        $this->assertNotSame($base, $cache->buildKey('bookstore', 'SELECT 1', [['v' => 1]], ['v']));
    }

    public function testKeyIsDeterminedByTheRowsAloneSoFormattersShareThem()
    {
        // Datasource, SQL, parameters and version tokens are the whole key.
        // There is deliberately no formatter/variant argument by which two
        // callers wanting the same rows could be given different keys: this
        // tier stores raw rows, so an ARRAY-formatted and an OBJECT-formatted
        // query with identical SQL share one entry. (The L1 key *does* fold the
        // formatter in -- it stores the formatted result, where the two are
        // different values of different types. See TieredQueryCache::localKey().)
        //
        // Guarded here as a signature property because it is the kind of thing
        // a later change adds back "for safety"; that it is also *correct* --
        // that each formatter can really consume the other's stored rows -- is
        // proven end-to-end in GlobalQueryResultCacheTest.
        $parameters = (new ReflectionMethod(SharedQueryCache::class, 'buildKey'))->getParameters();

        $this->assertSame(
            ['dbName', 'sql', 'params', 'versionTokens'],
            array_map(static fn (ReflectionParameter $p): string => $p->getName(), $parameters),
            'buildKey() must not grow a discriminator that would fragment one row set across formatters'
        );
    }

    public function testResourceValuedRowsAreNotStored()
    {
        $cache = $this->makeCache();
        $key = $cache->buildKey('bookstore', 'SELECT blob', [], ['v']);
        $handle = fopen('php://memory', 'rb');
        $this->assertIsResource($handle);

        try {
            // Serializing a resource is fatal, so such a query must silently
            // skip the shared tier rather than take the request down.
            $this->assertFalse($cache->store($key, [[1, $handle]], 300));
            $this->assertFalse($cache->fetch($key)['hit']);
        } finally {
            fclose($handle);
        }
    }

    public function testResourceInALaterRowIsAlsoNotStored()
    {
        $cache = $this->makeCache();
        $key = $cache->buildKey('bookstore', 'SELECT blob', [], ['v']);
        $handle = fopen('php://memory', 'rb');
        $this->assertIsResource($handle);

        try {
            // The regression: only row 0 used to be inspected, so a result set
            // whose first row carries a NULL blob and whose later rows carry
            // real streams passed the check. serialize() does not fail on a
            // resource -- it writes i:0 with no warning at all -- so the entry
            // was stored with its blob columns silently turned into the integer
            // 0, and served that way to every subsequent reader.
            $rows = [[1, null], [2, $handle]];

            $this->assertFalse($cache->store($key, $rows, 300));
            $this->assertFalse($cache->fetch($key)['hit']);
        } finally {
            fclose($handle);
        }
    }

    public function testPayloadFromAnIncompatibleFormatIsAMiss()
    {
        $backend = new ArrayCache(10);
        $cache = $this->makeCache($backend);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $backend->set($key, ['f' => 9999, 'd' => [[1]]]);

        $this->assertFalse($cache->fetch($key)['hit'], 'a payload written by a different Propulsion version must not be read');
    }

    public function testGarbagePayloadIsAMiss()
    {
        $backend = new ArrayCache(10);
        $cache = $this->makeCache($backend);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $backend->set($key, 'not a payload at all');

        $this->assertFalse($cache->fetch($key)['hit']);
    }

    public function testBackendFailuresDegradeToAMissRatherThanThrowing()
    {
        $cache = $this->makeCache(new ExplodingCache());
        $key = 'test.q.abc';

        // A dead Redis must never turn a SELECT into an exception.
        $this->assertFalse($cache->fetch($key)['hit']);
        $this->assertFalse($cache->store($key, $this->rows(), 300));
    }

    // ---------------------------------------------------------------
    // Admission control -- the defence against cache pollution
    // ---------------------------------------------------------------

    public function testFirstSightingOfAKeyIsNotAdmitted()
    {
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 2, beta: 0.0);
        $cache = $this->makeCache(null, $config);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $this->assertFalse($cache->store($key, $this->rows(), 300));
        $this->assertFalse($cache->fetch($key)['hit']);
    }

    public function testSecondSightingIsAdmitted()
    {
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 2, beta: 0.0);
        $cache = $this->makeCache(null, $config);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $cache->store($key, $this->rows(), 300);
        $this->assertTrue($cache->store($key, $this->rows(), 300), 'a genuinely repeated query must be admitted');
        $this->assertTrue($cache->fetch($key)['hit']);
    }

    /**
     * The regression test for the random-key denial of service: a flood of
     * queries whose bound parameters never repeat produces a distinct key every
     * time, so nothing ever reaches a second sighting and nothing is stored.
     * Note that stampede protection is irrelevant here -- there is no
     * contention on any single key.
     */
    public function testFloodOfNeverRepeatedKeysStoresNothing()
    {
        $backend = new ArrayCache(100000);
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 2, beta: 0.0);
        $cache = $this->makeCache($backend, $config);

        for ($i = 0; $i < 2000; $i++) {
            $key = $cache->buildKey('bookstore', 'SELECT * FROM book WHERE id = ?', [['value' => $i]], ['v']);
            $this->assertFalse($cache->store($key, $this->rows(), 300));
        }

        // Only the lightweight sighting markers exist; no row payload was ever
        // admitted, so the flood cannot exhaust storage.
        for ($i = 0; $i < 2000; $i++) {
            $key = $cache->buildKey('bookstore', 'SELECT * FROM book WHERE id = ?', [['value' => $i]], ['v']);
            $this->assertFalse($cache->fetch($key)['hit']);
        }
    }

    public function testMinSightingsOfOneAdmitsImmediately()
    {
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 1, beta: 0.0);
        $cache = $this->makeCache(null, $config);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);

        $this->assertTrue($cache->store($key, $this->rows(), 300));
    }

    // ---------------------------------------------------------------
    // Stampede protection
    // ---------------------------------------------------------------

    public function testBetaZeroDisablesEarlyRecomputation()
    {
        $cache = $this->makeCache(null, new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 1, beta: 0.0));
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);
        $cache->store($key, $this->rows(), 300, 5.0);

        $this->assertFalse($cache->fetch($key)['stale']);
    }

    public function testAnUnluckyDrawElectsThisCallerToRefreshEarly()
    {
        // A tiny random fraction makes -log(x) large, which is what pushes the
        // effective expiry earlier; combined with a slow query and a large beta
        // it deterministically elects this caller.
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 1, beta: 1000.0);
        $cache = $this->makeCache(null, $config, static fn (): float => 1.0e-9);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);
        $cache->store($key, $this->rows(), 300, 5.0);

        $entry = $cache->fetch($key);
        $this->assertTrue($entry['hit'], 'the entry is still valid...');
        $this->assertTrue($entry['stale'], '...but this caller should refresh it early');
    }

    public function testALuckyDrawLeavesTheEntryAlone()
    {
        $config = new SharedQueryCacheConfig(namespace: 'test', ttl: 300, minSightings: 1, beta: 1.0);
        $cache = $this->makeCache(null, $config, static fn (): float => 0.999999);
        $key = $cache->buildKey('bookstore', 'SELECT 1', [], ['v']);
        $cache->store($key, $this->rows(), 300, 0.001);

        $this->assertFalse($cache->fetch($key)['stale']);
    }

    public function testSingleFlightLockIsExclusiveOnAnAtomicBackend()
    {
        $cache = $this->makeCache(new ArrayCache(100));
        $key = 'test.q.locked';

        $this->assertTrue($cache->acquireRecomputeLock($key));
        $this->assertFalse($cache->acquireRecomputeLock($key), 'a second caller must not also hold the lock');

        $cache->releaseRecomputeLock($key);
        $this->assertTrue($cache->acquireRecomputeLock($key), 'the lock must be reacquirable once released');
    }

    public function testNonAtomicBackendsAlwaysWinTheLock()
    {
        // PSR-16 has no atomic add, so a third-party pool cannot do
        // single-flight; it must fall back to letting everyone through rather
        // than pretending to hold a lock it does not have.
        $cache = $this->makeCache(new NonAtomicCache());

        $this->assertTrue($cache->acquireRecomputeLock('test.q.k'));
        $this->assertTrue($cache->acquireRecomputeLock('test.q.k'));
    }

    // ---------------------------------------------------------------
    // Configuration guards
    // ---------------------------------------------------------------

    public function testVersionTtlBelowResultTtlIsRejected()
    {
        // Version tokens expiring before the entries derived from them causes a
        // miss storm rather than staleness, but it is still a misconfiguration
        // worth catching at construction.
        $this->expectException(PropulsionException::class);
        new SharedQueryCacheConfig(ttl: 300, versionTtl: 60);
    }

    public function testUnsupportedHashAlgorithmIsRejected()
    {
        $this->expectException(PropulsionException::class);
        new SharedQueryCacheConfig(hashAlgo: 'not-a-real-algo');
    }
}

/**
 * A backend that fails every operation, standing in for a dead Redis.
 */
class ExplodingCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('backend down');
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('backend down');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('backend down');
    }

    public function clear(): bool
    {
        throw new RuntimeException('backend down');
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('backend down');
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('backend down');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('backend down');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('backend down');
    }
}

/**
 * A working PSR-16 pool that does not implement Propulsion's AtomicCache -- i.e.
 * what any third-party implementation looks like.
 */
class NonAtomicCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->entries) ? $this->entries[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->entries[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }
}
