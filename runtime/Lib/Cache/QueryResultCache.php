<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

/**
 * Request-scoped cache of formatted query results, keyed by generated
 * SQL + bound params (see {@see \Propulsion\Query\ModelCriteria} for how the
 * key is built). Owned by {@see \Propulsion\Session} so it's cleared for free
 * at every worker request boundary, the same way instance pools are --
 * caching is opt-in per query ({@see \Propulsion\Query\Criteria::setQueryCache()})
 * and process-scoped (cross-request) caching is not implemented; see
 * KNOWN_ISSUES.md.
 *
 * A cache entry also records which tables its result depends on, so a write
 * to any of those tables (via {@see \Propulsion\Util\BasePeer::doInsert()} /
 * doUpdate() / doDelete() / doDeleteAll()) can evict exactly the entries that
 * might now be stale, without flushing the whole cache.
 */
class QueryResultCache
{
    /**
     * How many results one request will hold at once.
     *
     * Request-scoped is not the same as small. Each entry is a *formatted*
     * result -- typically a whole PropulsionCollection of hydrated objects --
     * and caching is opt-in per query, so a request that runs a cached query in
     * a loop with varying bound parameters produces a distinct key every
     * iteration and retains every result set it has ever built. That is an
     * out-of-memory condition rather than a cache.
     *
     * Evicting is always safe: the worst consequence of a miss is re-running the
     * query, which is what an uncached query does anyway. The bound is therefore
     * set generously -- high enough that no reasonable request reaches it, low
     * enough to stop the pathological case.
     */
    public const MAX_ENTRIES = 500;

    /**
     * @var array<string, mixed> cache key => formatted result
     */
    private array $entries = [];

    /**
     * @var array<string, array<string, true>> table name => set of cache keys whose result depends on that table
     */
    private array $tableIndex = [];

    /**
     * The reverse of $tableIndex: which tables each entry was indexed under.
     *
     * Kept so that evicting an entry can remove it from exactly the lists it
     * appears in, instead of scanning every other table's list to find it. See
     * {@see invalidateTable()}.
     *
     * @var array<string, list<string>> cache key => table names it depends on
     */
    private array $keyTables = [];

    /**
     * A cache miss returns null, same as "no entry" -- callers must not cache
     * a query whose formatted result is itself legitimately null without
     * accounting for that ambiguity (formatOne() on an empty result set does
     * return null, so callers checking for a hit should track "found" separately,
     * e.g. via array_key_exists()).
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * @param list<string> $touchedTables
     */
    public function set(string $key, mixed $result, array $touchedTables): void
    {
        $this->entries[$key] = $result;
        // Sets rather than lists, so re-caching the same key under the same
        // table cannot append a duplicate.
        foreach ($touchedTables as $tableName) {
            $this->tableIndex[$tableName][$key] = true;
        }
        $this->keyTables[$key] = $touchedTables;
        $this->evictIfOverCapacity();
    }

    /**
     * Drop oldest-first until the entry count is back within
     * {@see MAX_ENTRIES}.
     *
     * Insertion order rather than least-recently-used: promoting on every read
     * would add work to the hot lookup path, and the bound exists to stop
     * pathological growth, not to maximise hit rate under pressure. A request
     * that reaches it is caching far more distinct queries than it can be
     * re-using.
     */
    private function evictIfOverCapacity(): void
    {
        while (count($this->entries) > self::MAX_ENTRIES) {
            // The loop condition guarantees a non-empty array, so
            // array_key_first() always yields a key here.
            $this->forget((string) array_key_first($this->entries));
        }
    }

    /**
     * Remove one entry and every trace of it in both indexes.
     */
    private function forget(string $key): void
    {
        unset($this->entries[$key]);

        foreach ($this->keyTables[$key] ?? [] as $tableName) {
            unset($this->tableIndex[$tableName][$key]);
            if (($this->tableIndex[$tableName] ?? null) === []) {
                unset($this->tableIndex[$tableName]);
            }
        }
        unset($this->keyTables[$key]);
    }

    /**
     * Evict every cache entry whose result depends on the given table.
     *
     * An entry usually depends on more than one table (any join does), so
     * dropping only this table's list would leave the evicted keys listed under
     * every *other* table they touch. Those stale keys are harmless to read
     * past -- the entry is gone from $entries either way -- but they accumulate:
     * re-running and re-caching the same query appends the key again under each
     * of its tables, so a request that invalidates and re-caches in a loop grows
     * the index without bound. The keys are therefore removed from every list
     * they appear in, not just this one.
     *
     * Which lists those are is read from $keyTables rather than found by
     * scanning. This used to walk *every other table's* whole key list on every
     * invalidation, making a request that mixes cached reads with writes cost
     * O(writes x total index size); it is now proportional to what is actually
     * evicted.
     */
    public function invalidateTable(string $tableName): void
    {
        if (!isset($this->tableIndex[$tableName])) {
            return;
        }

        foreach (array_keys($this->tableIndex[$tableName]) as $key) {
            $this->forget($key);
        }

        // Whatever is left here was indexed without a $keyTables entry, which
        // cannot happen via set(); belt and braces so the table cannot linger
        // with an empty list.
        unset($this->tableIndex[$tableName]);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->tableIndex = [];
        $this->keyTables = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
