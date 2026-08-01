<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

use PDO;
use PDOStatement;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Formatter\PropulsionFormatter;
use Propulsion\Propulsion;

/**
 * Runs hand-written SQL through the same caching machinery the fluent API uses.
 *
 * Some queries are genuinely easier to write by hand than to express as a
 * Criteria -- and those tend to be the expensive ones, which makes them the
 * best candidates for caching. Because the shared tier stores raw
 * pre-hydration rows rather than formatted results, nothing about it is
 * specific to `ModelCriteria`: a caller who can supply the SQL, its parameters
 * and the tables it reads gets the whole stack, including invalidation when an
 * ORM write touches one of those tables, admission control and single-flight.
 *
 *     $books = Propulsion::rawQuery(
 *             'SELECT b.* FROM book b JOIN author a ON ... complicated ...',
 *             [$authorId]
 *         )
 *         ->dependsOn('book', 'author')
 *         ->cache(ttl: 300)
 *         ->hydrate(BookPeer::class);
 *
 * **Declaring the tables is mandatory when caching.** Propulsion will not
 * inspect the SQL to work them out: a parser would be wrong about CTEs, views,
 * aliases and subqueries, and a cache that is *silently* wrong about
 * invalidation is worse than one that insists you say. {@see dependsOn()}
 * validates every name against the runtime DatabaseMap, so a typo fails
 * immediately rather than quietly never invalidating.
 *
 * Writes issued as raw SQL still invalidate nothing on their own -- use
 * {@see Propulsion::invalidateQueryCacheForTables()} after them.
 */
class RawQuery
{
    /** @var list<string> */
    private array $tables = [];

    private bool $cacheEnabled = false;

    private ?int $ttl = null;

    private bool $shared = true;

    private ?PropulsionPDO $con = null;

    private string $dbName;

    /**
     * @param string                    $sql    the SQL to run
     * @param array<int|string, mixed>  $params bound values: a list for `?`
     *        placeholders, or a name-keyed map for `:name` placeholders
     */
    public function __construct(
        private readonly string $sql,
        private readonly array $params = [],
        ?string $dbName = null,
    ) {
        $this->dbName = $dbName ?? Propulsion::getDefaultDB();
    }

    /**
     * Declare which tables this query reads, so a write to any of them evicts
     * the cached result.
     *
     * @param  string ...$tables table names as they appear in the database
     * @throws PropulsionException if a name is not in the DatabaseMap
     */
    public function dependsOn(string ...$tables): static
    {
        foreach ($tables as $table) {
            $this->assertKnownTable($table);
            if (!in_array($table, $this->tables, true)) {
                $this->tables[] = $table;
            }
        }

        return $this;
    }

    /**
     * Opt this query into the result cache.
     *
     * @param  int|null $ttl    per-query TTL override in seconds
     * @param  bool     $shared false keeps the result in the request-scoped
     *                          tier only -- use it when the SQL text is stable
     *                          but the correct result is not (NOW(), RANDOM()).
     */
    public function cache(?int $ttl = null, bool $shared = true): static
    {
        $this->cacheEnabled = true;
        $this->ttl = $ttl;
        $this->shared = $shared;

        return $this;
    }

    /**
     * Run this query on a specific connection instead of the datasource's
     * default write connection.
     */
    public function on(PropulsionPDO $con): static
    {
        $this->con = $con;

        return $this;
    }

    /**
     * The raw rows, exactly as `PDO::FETCH_NUM` produces them.
     *
     * @return list<array<int, mixed>>
     */
    public function rows(): array
    {
        $result = $this->run(
            'rows',
            static fn (iterable $rows): array => self::materialise($rows),
        );

        return self::materialise(is_iterable($result) ? $result : []);
    }

    /**
     * The first row, or null if the query matched nothing.
     *
     * @return array<int, mixed>|null
     */
    public function one(): ?array
    {
        $rows = $this->rows();
        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Hydrate model objects using a generated Peer's `populateObjects()`.
     *
     * The SELECT must return that Peer's columns, in its column order --
     * `SELECT b.*` rather than a hand-picked list -- exactly as the generated
     * `doSelect()` requires.
     *
     * @param  class-string $peerClass e.g. `BookPeer::class`
     * @return list<mixed> the hydrated objects
     */
    public function hydrate(string $peerClass): array
    {
        if (!method_exists($peerClass, 'populateObjectsFromRows')) {
            throw new PropulsionException(sprintf(
                '%s cannot hydrate raw query results: it has no populateObjectsFromRows(). Regenerate your model '
                . 'classes with this version of Propulsion.',
                $peerClass
            ));
        }

        $result = $this->run(
            'hydrate:' . $peerClass,
            static function (iterable $rows) use ($peerClass): array {
                /** @var callable(iterable<int, array<int, mixed>>): array<int, mixed> $callable */
                $callable = [$peerClass, 'populateObjectsFromRows'];

                return array_values($callable($rows));
            },
        );

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * Format the rows with any row-cacheable {@see PropulsionFormatter}.
     */
    public function formatWith(PropulsionFormatter $formatter): mixed
    {
        if (!$formatter->supportsRowCaching()) {
            throw new PropulsionException(sprintf(
                '%s cannot format a raw query: it can only work from a live statement.',
                $formatter::class
            ));
        }

        return $this->run(
            'format:' . $formatter::class,
            static fn (iterable $rows): mixed => $formatter->formatFromRows($rows),
        );
    }

    /**
     * @param callable(iterable<int, array<int, mixed>>): mixed $formatRows
     */
    private function run(string $variant, callable $formatRows): mixed
    {
        if ($this->cacheEnabled && $this->tables === []) {
            throw new PropulsionException(
                'A cached raw query must declare the tables it reads, so a write to one of them can evict it: '
                . 'call ->dependsOn(...) before ->cache(). Propulsion deliberately does not parse the SQL to '
                . 'guess them, because guessing wrong would silently serve stale data.'
            );
        }

        $con = $this->resolveConnection();
        $sql = $this->sql;
        $params = $this->params;

        $execute = static function () use ($con, $sql, $params): PDOStatement {
            $stmt = $con->prepare($sql);
            if (!$stmt instanceof PDOStatement) {
                throw new PropulsionException('Unable to prepare raw query [' . $sql . ']');
            }
            foreach ($params as $index => $value) {
                // A list binds positionally (PDO counts from 1); a name-keyed
                // map binds by name.
                $stmt->bindValue(is_int($index) ? $index + 1 : $index, $value);
            }
            $stmt->execute();

            return $stmt;
        };

        if (!$this->cacheEnabled) {
            return $formatRows(\Propulsion\Util\StatementRows::iterate($execute()));
        }

        return Propulsion::getSession()->getQueryCache()->remember(
            dbName: $this->dbName,
            sql: $sql,
            params: $params,
            touchedTables: $this->tables,
            variant: 'raw:' . $variant,
            execute: $execute,
            formatStatement: static fn (PDOStatement $stmt): mixed => $formatRows(\Propulsion\Util\StatementRows::iterate($stmt)),
            formatRows: $formatRows,
            cacheable: true,
            shared: $this->shared,
            con: $con,
            ttl: $this->ttl,
        );
    }

    /**
     * @param  iterable<mixed> $rows
     * @return list<array<int, mixed>>
     */
    private static function materialise(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalised = [];
            foreach ($row as $index => $value) {
                $normalised[(int) $index] = $value;
            }
            $out[] = $normalised;
        }

        return $out;
    }

    /**
     * @throws PropulsionException if the datasource is not backed by a Propulsion connection
     */
    private function resolveConnection(): PropulsionPDO
    {
        if ($this->con !== null) {
            return $this->con;
        }

        $con = Propulsion::getConnection($this->dbName, Propulsion::CONNECTION_READ);
        if (!$con instanceof PropulsionPDO) {
            throw new PropulsionException('Expected a PropulsionPDO connection for datasource "' . $this->dbName . '"');
        }

        return $con;
    }

    /**
     * @throws PropulsionException
     */
    private function assertKnownTable(string $table): void
    {
        $map = Propulsion::getDatabaseMap($this->dbName);
        if ($map->hasTable($table)) {
            return;
        }

        throw new PropulsionException(sprintf(
            'Unknown table "%s" in dependsOn() for datasource "%s". A misspelled table name would silently never '
            . 'invalidate this query, so it is rejected here instead.',
            $table,
            $this->dbName
        ));
    }
}
