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
 * Table version tokens: how the shared cache invalidates without an index.
 *
 * The request-scoped tier keeps a table-name-to-cache-keys map and deletes the
 * matching entries on a write. A shared backend cannot: enumerating "every key
 * touching table X" is not something APCu or a filesystem can do cheaply or
 * atomically, and maintaining the index by hand across processes reintroduces
 * every race it was meant to avoid. Instead each table has a *version token*
 * that is folded into the key of every query reading it, so bumping one token
 * makes every key derived from it unreachable in a single write, with no scan
 * and no coordination.
 *
 * **Tokens are random, not counters.** This is the subtle part, and it is worth
 * spelling out because "version counter" is the obvious first design:
 *
 *  - *Increment* (read, add one, write back) is a read-modify-write, and PSR-16
 *    has no atomic increment to close it. Two writers both reading v=7 both
 *    write v=8; a reader that queried in between caches a result under v=8 that
 *    is missing the second writer's committed row, and that entry stays live
 *    for its whole TTL. That is a genuine stale read, not a cosmetic gap.
 *  - *Delete and reseed from 1* is worse: reseeding to a previously-used value
 *    resurrects orphaned keys from an earlier generation.
 *  - *A fresh random token* has no read step at all, so the race cannot occur.
 *    Two concurrent writers write two different never-before-used tokens;
 *    whichever lands last wins, and every key derived from the old token is
 *    orphaned either way.
 *
 * The property that makes this safe to run unattended: **losing a version token
 * always fails toward a miss, never toward staleness.** If the backend evicts
 * one, the next reader finds nothing, seeds a brand-new random token, and every
 * entry derived from the old one becomes unreachable -- expensive, never wrong.
 * Under a counter, the same eviction reseeds a value that has been used before
 * and can serve stale data.
 */
class TableVersionRegistry
{
    /**
     * Tokens already read (or written) during this request.
     *
     * Memoising means a request issuing twenty cached queries over five tables
     * pays one backend round trip for versions, not twenty. It also makes
     * read-your-own-writes deterministic within the request. The cost is a
     * bounded staleness window: a bump published by another process midway
     * through this request is not observed until the next one.
     *
     * @var array<string, string> version key => token
     */
    private array $memo = [];

    /**
     * Tokens generated for a table written inside an open transaction, held
     * back from the backend until commit.
     *
     * @var array<int, array<string, string>> connection object id => version key => token
     */
    private array $pending = [];

    public function __construct(
        private readonly CacheInterface $backend,
        private readonly SharedQueryCacheConfig $config,
    ) {
    }

    /**
     * Current tokens for the given tables, seeding any that are missing.
     *
     * Sorted so the same set of tables always produces the same token order,
     * and fetched in a single {@see CacheInterface::getMultiple()} call -- on a
     * network-backed pool that is the difference between one round trip and one
     * per table.
     *
     * @param  list<string> $tableNames
     * @return list<string> tokens, in canonical (sorted) table order
     */
    public function tokensFor(string $dbName, array $tableNames): array
    {
        $tableNames = array_values(array_unique($tableNames));
        sort($tableNames);

        $keysByTable = [];
        $missing = [];
        foreach ($tableNames as $table) {
            $key = $this->versionKey($dbName, $table);
            $keysByTable[$table] = $key;
            if (!isset($this->memo[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            $this->readInto($missing);
        }

        $tokens = [];
        foreach ($keysByTable as $key) {
            $tokens[] = $this->memo[$key] ?? $this->newToken();
        }

        return $tokens;
    }

    /**
     * Publish a fresh token for each table immediately -- the autocommit write
     * path.
     *
     * @param list<string> $tableNames
     */
    public function publish(string $dbName, array $tableNames): void
    {
        $values = [];
        foreach ($tableNames as $table) {
            $key = $this->versionKey($dbName, $table);
            $token = $this->newToken();
            $values[$key] = $token;
            $this->memo[$key] = $token;
        }

        if ($values === []) {
            return;
        }

        try {
            $this->backend->setMultiple($values, $this->config->versionTtl);
        } catch (\Throwable) {
            // A backend that cannot record the bump must not fail the write
            // that triggered it. The local memo still holds the new token, so
            // this process reads its own writes correctly; other processes may
            // serve the old entry until its TTL lapses.
        }
    }

    /**
     * Generate a new token for a table written inside an open transaction, but
     * keep it out of the backend until the transaction commits.
     *
     * Publishing at statement time would be actively wrong: another process
     * could read our *uncommitted* rows, cache them under the freshly published
     * token, and -- since nothing bumps again at commit -- keep serving that
     * pre-commit snapshot for the whole TTL. Holding the token locally instead
     * means our own subsequent reads compute keys nobody has published, so they
     * miss the shared tier and go to the database, which is exactly the
     * read-your-own-writes behaviour a transaction needs.
     */
    public function overrideLocally(string $dbName, string $tableName, int $connectionId): void
    {
        $key = $this->versionKey($dbName, $tableName);
        $token = $this->newToken();
        $this->memo[$key] = $token;
        $this->pending[$connectionId][$key] = $token;
    }

    /**
     * Publish everything buffered for a connection. Called on the outermost
     * commit.
     */
    public function publishPending(int $connectionId): void
    {
        $values = $this->pending[$connectionId] ?? [];
        unset($this->pending[$connectionId]);

        if ($values === []) {
            return;
        }

        try {
            $this->backend->setMultiple($values, $this->config->versionTtl);
        } catch (\Throwable) {
            // See publish(): best effort, never fatal to the commit.
        }
    }

    /**
     * Discard everything buffered for a connection. Called on rollback --
     * nothing was ever published, so there is nothing to undo; the local
     * overrides are dropped so this request stops needlessly bypassing the
     * shared tier for those tables.
     */
    public function discardPending(int $connectionId): void
    {
        foreach (array_keys($this->pending[$connectionId] ?? []) as $key) {
            unset($this->memo[$key]);
        }
        unset($this->pending[$connectionId]);
    }

    public function hasPending(int $connectionId): bool
    {
        return ($this->pending[$connectionId] ?? []) !== [];
    }

    /**
     * Drop the per-request memo and any unpublished tokens, at a request
     * boundary. Never touches the backend.
     */
    public function reset(): void
    {
        $this->memo = [];
        $this->pending = [];
    }

    /**
     * @param list<string> $keys
     */
    private function readInto(array $keys): void
    {
        $fetched = [];
        try {
            /** @var iterable<string, mixed> $raw */
            $raw = $this->backend->getMultiple($keys, null);
            foreach ($raw as $key => $value) {
                // Anything that is not a non-empty string is treated as absent,
                // so a corrupt or foreign value seeds a fresh token rather than
                // being folded into a cache key.
                if (is_string($value) && $value !== '') {
                    $fetched[$key] = $value;
                }
            }
        } catch (\Throwable) {
            // Treated as "all missing": the seeding below gives every affected
            // table a brand-new token, so the worst case is a miss.
            $fetched = [];
        }

        $seed = [];
        foreach ($keys as $key) {
            if (isset($fetched[$key])) {
                $this->memo[$key] = $fetched[$key];
                continue;
            }
            $token = $this->newToken();
            $seed[$key] = $token;
            $this->memo[$key] = $token;
        }

        if ($seed === []) {
            return;
        }

        try {
            // Two processes seeding concurrently write different tokens and one
            // loses; the loser's just-stored result entry is orphaned. That is
            // a miss, not a stale read.
            $this->backend->setMultiple($seed, $this->config->versionTtl);
        } catch (\Throwable) {
            // Leaving the seed unpublished only costs a miss next request.
        }
    }

    /**
     * 64 bits of randomness: the chance of ever reusing a token is negligible,
     * which is the whole basis of the orphaning guarantee above.
     */
    private function newToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function versionKey(string $dbName, string $tableName): string
    {
        return $this->config->namespace . '.t.' . hash($this->config->hashAlgo, $dbName . "\0" . $tableName);
    }
}
