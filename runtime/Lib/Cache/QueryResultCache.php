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
     * @var array<string, mixed> cache key => formatted result
     */
    private array $entries = [];

    /**
     * @var array<string, list<string>> table name => cache keys whose result depends on that table
     */
    private array $tableIndex = [];

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
        foreach ($touchedTables as $tableName) {
            $this->tableIndex[$tableName][] = $key;
        }
    }

    /**
     * Evict every cache entry whose result depends on the given table.
     */
    public function invalidateTable(string $tableName): void
    {
        if (!isset($this->tableIndex[$tableName])) {
            return;
        }

        foreach ($this->tableIndex[$tableName] as $key) {
            unset($this->entries[$key]);
        }

        unset($this->tableIndex[$tableName]);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->tableIndex = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
