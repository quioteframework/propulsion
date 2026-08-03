<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

use PDOStatement;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;
use Propulsion\Util\StatementRows;

/**
 * The single seam every cached read goes through: coordinates the
 * request-scoped {@see QueryResultCache} (L1) with the process-shared
 * {@see SharedQueryCache} (L2).
 *
 * **Why the tiering does not live inside `QueryResultCache`.** L1 stores the
 * *formatted* result -- a PropulsionCollection, a hydrated object, an int --
 * while L2 stores the raw pre-hydration *rows*. `QueryResultCache::set()`
 * never sees rows, so it structurally cannot populate L2, and on an L2 hit it
 * would have to re-run a formatter it knows nothing about. Hiding the tiering
 * in there would mean passing a hydration callback into a class whose whole
 * value is being a dumb array. So the tiering lives here, and
 * `QueryResultCache` stays exactly what it was.
 *
 * Owned by {@see \Propulsion\Session} and therefore request-scoped, which is
 * what makes the transaction bookkeeping below safe: it never outlives the
 * request that opened the transaction.
 */
class TieredQueryCache
{
    public function __construct(
        private readonly QueryResultCache $local,
        private readonly ?SharedQueryCache $shared = null,
    ) {
    }

    public function getSharedCache(): ?SharedQueryCache
    {
        return $this->shared;
    }

    /**
     * Serve a query from cache if possible, otherwise run it and cache the
     * result.
     *
     * Three paths, in order of how much work they avoid:
     *
     *  1. `$shareable === false` -- no caching at all, at either tier. This is
     *     how the two statement-bound formatters are handled: caching them
     *     hands the next caller an exhausted cursor (see
     *     {@see \Propulsion\Formatter\PropulsionFormatter::supportsRowCaching()}).
     *  2. L1 hit -- returns the very same formatted result the earlier call
     *     got, exactly as before this class existed.
     *  3. Miss -- execute, format, populate L1.
     *
     * Note that the miss path formats straight from the live statement rather
     * than materialising rows first. Rows only need materialising when there is
     * a shared tier to store them in, so an L1-only deployment keeps the
     * streaming memory profile it has always had.
     *
     * @param  string                              $dbName        datasource name, part of cache identity
     * @param  string                              $sql           the rendered SQL
     * @param  array<int|string, mixed>            $params        bound parameter structs
     * @param  list<string>                        $touchedTables tables whose contents the result depends on
     * @param  string                              $variant       discriminator for results that differ despite identical SQL
     * @param  callable(): PDOStatement            $execute       runs the query
     * @param  callable(PDOStatement): mixed       $formatStatement formats from a live statement
     * @param  callable(iterable<int, array<int, mixed>>): mixed $formatRows formats from a row array
     * @param  bool                                $cacheable     whether this result may be cached at all, at either tier
     * @param  bool                                $shared        whether it may additionally reach the process-shared tier
     */
    public function remember(
        string $dbName,
        string $sql,
        array $params,
        array $touchedTables,
        string $variant,
        callable $execute,
        callable $formatStatement,
        callable $formatRows,
        bool $cacheable = true,
        bool $shared = true,
        ?PropulsionPDO $con = null,
        ?int $ttl = null,
    ): mixed {
        if (!$cacheable) {
            return $formatStatement($execute());
        }

        // A result with no recorded dependencies is not cacheable at any tier,
        // because nothing could ever evict it. At L1 there is no table for
        // invalidateTable() to match; at L2 the key folds in one version token
        // per dependency, so with none it folds in nothing and no publish() can
        // orphan it -- putting it out of reach of
        // Propulsion::invalidateQueryCacheForTables() too. Such an entry would
        // be served, stale, for its whole TTL across every process sharing the
        // pool.
        //
        // Callers are expected to supply dependencies (Criteria derives them,
        // RawQuery rejects the empty case outright with a message telling the
        // caller to declare them), so this is a backstop against a future caller
        // getting it wrong, not a supported mode: it degrades to an uncached
        // read rather than throwing, since silently skipping the cache is the
        // failure mode that cannot corrupt anything.
        if ($touchedTables === []) {
            return $formatStatement($execute());
        }

        $localKey = $this->localKey($dbName, $sql, $params, $variant);
        if ($this->local->has($localKey)) {
            return $this->local->get($localKey);
        }

        $shared = $shared ? $this->sharedTierFor($con) : null;
        if ($shared === null) {
            // No shared tier in play, so there is no reason to materialise
            // rows: format straight off the live statement and keep the
            // streaming memory profile.
            $value = $formatStatement($execute());
            $this->local->set($localKey, $value, $touchedTables);

            return $value;
        }

        $tokens = $shared->versions()->tokensFor($dbName, $touchedTables);
        $sharedKey = $shared->buildKey($dbName, $sql, $params, $tokens, $variant);

        $entry = $shared->fetch($sharedKey);
        if ($entry['hit'] && !($entry['stale'] && $shared->acquireRecomputeLock($sharedKey))) {
            $value = $formatRows($entry['rows']);
            $this->local->set($localKey, $value, $touchedTables);

            return $value;
        }

        // On a miss, try to be the only caller that runs the query. A pool
        // that cannot lock lets everyone through, which is what the
        // probabilistic scheme above is there to soften.
        $holdsLock = $entry['hit'] || $shared->acquireRecomputeLock($sharedKey);

        try {
            $startedAt = microtime(true);
            $rows = $this->rowsFrom($execute());
            $elapsed = microtime(true) - $startedAt;

            $value = $formatRows($rows);
            $this->local->set($localKey, $value, $touchedTables);

            // Reuse the tokens the miss was observed under rather than
            // re-reading them: a bump that landed while the query was running
            // must orphan this entry, and re-reading would defeat that.
            $shared->store($sharedKey, $rows, $ttl, $elapsed);
        } finally {
            if ($holdsLock) {
                $shared->releaseRecomputeLock($sharedKey);
            }
        }

        return $value;
    }

    /**
     * The shared tier, if it should be consulted for this query at all.
     *
     * Returns null while $con is inside a transaction. That is a correctness
     * rule, not an optimisation: a SELECT issued inside our own transaction
     * sees our uncommitted rows, and publishing those to a cache other
     * processes read would leak uncommitted data. In-transaction reads still
     * get the request-scoped tier.
     */
    private function sharedTierFor(?PropulsionPDO $con): ?SharedQueryCache
    {
        if ($this->shared === null) {
            return null;
        }
        if ($con !== null && $con->isInTransaction()) {
            return null;
        }

        return $this->shared;
    }

    /**
     * Evict everything that depends on $tableName.
     *
     * @param string $tableName the written table
     */
    public function invalidateTable(string $tableName, ?PropulsionPDO $con = null, ?string $dbName = null): void
    {
        // Always immediate for this process: a write must be visible to its own
        // subsequent reads regardless of transaction state.
        $this->local->invalidateTable($tableName);

        if ($this->shared === null) {
            return;
        }

        $dbName ??= Propulsion::getDefaultDB();

        if ($con !== null && $con->isInTransaction()) {
            // Hold the bump back until commit; see
            // TableVersionRegistry::overrideLocally() for why publishing now
            // would be a correctness bug rather than merely early.
            $this->shared->versions()->overrideLocally($dbName, $tableName, spl_object_id($con));

            return;
        }

        $this->shared->versions()->publish($dbName, [$tableName]);
    }

    /**
     * Publish the token bumps buffered for a connection. Called from the
     * outermost {@see \Propulsion\Connection\PropulsionPDOTrait::commit()}.
     */
    public function onCommit(PropulsionPDO $con): void
    {
        $this->shared?->versions()->publishPending(spl_object_id($con));
    }

    /**
     * Drop the token bumps buffered for a connection. Called on rollback --
     * nothing reached the backend, so there is nothing to undo.
     */
    public function onRollBack(PropulsionPDO $con): void
    {
        $this->shared?->versions()->discardPending(spl_object_id($con));
    }

    /**
     * Clear the request-scoped state, at a worker request boundary.
     *
     * Deliberately never touches a shared backend: calling `clear()` on a
     * PSR-16 pool here would flush the cache for every process on the host at
     * the end of every request, which is the single worst thing this class
     * could do.
     */
    public function reset(): void
    {
        $this->local->clear();
        $this->shared?->versions()->reset();
    }

    /**
     * The underlying request-scoped cache, for tests and for callers that
     * legitimately want the L1 tier alone.
     */
    public function getLocalCache(): QueryResultCache
    {
        return $this->local;
    }

    /**
     * The L1 key.
     *
     * Unlike {@see \Propulsion\Query\Criteria::getQueryCacheKey()}, this folds
     * in a `$variant` discriminator. That fixes a real collision: the formatter
     * is not part of the SQL, so a `->setFormatter(FORMAT_ARRAY)->find()` and a
     * plain `->find()` producing identical SQL used to share one cache entry,
     * and whichever ran second silently received the other's result.
     *
     * @param array<int|string, mixed> $params
     */
    private function localKey(string $dbName, string $sql, array $params, string $variant): string
    {
        return $dbName . '|' . $variant . '|' . $sql . '|' . serialize($params);
    }

    /**
     * Materialise a statement's rows. Exposed so callers that already know they
     * want rows (the shared tier, the raw-SQL API) share one implementation.
     *
     * @return list<array<int, mixed>>
     */
    protected function rowsFrom(PDOStatement $stmt): array
    {
        return StatementRows::all($stmt);
    }
}
