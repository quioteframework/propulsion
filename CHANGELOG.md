# Changelog

All notable changes to Propulsion are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Query result cache: aliased and joined queries were indexed under names no
  write ever invalidates.** `Criteria::getQueryCacheTouchedTables()` derived its
  table list from the criterion-map keys, which carry the *alias* for an aliased
  query (`b.TITLE`, not `book.TITLE`) — so a cached
  `setModelAlias('b', true)` query depended on `"b"` while every write path
  bumps `"book"`, and the entry survived a write that should have evicted it
  (for the rest of the request in the L1 tier, or until the TTL lapsed in the
  shared tier). Separately, only the *right* side of each join contributed, so
  `BookQuery::create()->join('Book.Author')` depended on `author` but not on
  `book` at all, since a query with no WHERE clause contributes no criterion
  keys and the find() path never calls `setPrimaryTableName()`. Both sides of
  every join are now included, and every name is resolved through the alias map
  before being recorded. `BasePeer::doDelete()` was the one write path
  invalidating under the alias rather than the real table name (it resolved the
  alias for its SQL but not for the invalidation call), so it now agrees with
  `doInsert()`/`doUpdate()`/`doDeleteAll()`/`doUpsert()`.
- **`count()` left its statement's cursor open.** `StatementRows::iterate()`
  closed the cursor after its loop, which only runs when the generator is driven
  to exhaustion. `ModelCriteria::countFromRows()` returns from inside its
  `foreach` as soon as it has the scalar, leaving the generator suspended and
  the cursor never closed — the exact FreeTDS/pdo_dblib "results pending"
  hazard the class exists to centralise. The close moved into a `finally`, which
  also covers a consumer that `break`s out or throws.
- **A transaction could be left open when a non-`PropulsionException` escaped.**
  `UnitOfWork::flush()` and `ModelCriteria::delete()`/`deleteAll()`/`update()`
  each opened a transaction but caught only `PropulsionException` for the
  rollback. A PSR-14 listener's own exception, a `TypeError`, or a raw
  `PDOException` from `commit()` propagated out with the transaction still open;
  on PostgreSQL that poisons the connection for whatever reuses it next
  ("current transaction is aborted" until an explicit `ROLLBACK`), with nothing
  cleaning it up before `Session::reset()`'s dangling-transaction sweep at the
  next request boundary. All four now catch `\Throwable`, roll back, and rethrow
  the original throwable unchanged.

## [2.0.0] — 2026-07-30

180 commits since v1.0.0 (2026-07-07); 411 files changed. This release adds a
large batch of query-layer capabilities, new column types, per-platform DDL
parity, full test-suite parity on MySQL/MSSQL/Oracle, and takes the whole
project from a PHPStan level 6 baseline to a clean level 9. It also removes
several legacy code paths, hence the major version bump.

### Added

#### Query layer

- **Common table expressions and window functions.** `Criteria::withCte()`
  emits `WITH [RECURSIVE] name AS (...)`; the CTE name then resolves anywhere
  a table name does. `ModelCriteria::useCteQuery()` adds a closure-scoped
  convenience for the non-recursive case. `WindowExpression` is a fluent
  `<fn>(...) OVER (PARTITION BY ... ORDER BY ... <frame>)` builder
  (`rowNumber`/`rank`/`lag`/`lead`/`sum`/`avg`/…, `partitionBy()`,
  `orderBy()`, `rowsBetween()`, `rangeBetween()`), accepted by
  `ModelCriteria::withColumn()`.
- **Set operations.** `Criteria::union()`/`unionAll()`/`intersect()`/`except()`,
  chainable and recursively composable. The outer query's
  `orderBy()`/`limit()`/`offset()` apply to the combined result.
- **EXISTS / IN correlated subqueries.** `Criteria::addExistsQuery()` and
  `addInQuery()` as the low-level primitives, plus closure-scoped
  `ModelCriteria::useExistsQuery()`/`useNotExistsQuery()`/`useInQuery()`.
  Unlike `useQuery()`/`withQuery()`, these nest an independent sub-SELECT and
  need no join relation.
- **Pessimistic locking.** `Criteria::setLockForUpdate()`/`setLockForShare()`
  (with `SKIP LOCKED`/`NOWAIT`) and the `ModelCriteria::forUpdate()`/`forShare()`
  wrappers. MSSQL emits `WITH (UPDLOCK|HOLDLOCK, ROWLOCK[, READPAST])` table
  hints instead of a trailing clause. Capability flags make unsupported
  combinations throw rather than emit invalid SQL (SQLite has no row locking,
  Oracle no `FOR SHARE`, MSSQL no `NOWAIT` equivalent).
- **Upsert.** `BasePeer::doUpsert()` backed by `DBAdapter::supportsUpsert()`/
  `getUpsertSql()` — `ON CONFLICT ... DO UPDATE`/`DO NOTHING` on
  Postgres/SQLite, `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB. MSSQL/Oracle
  need `MERGE` and are deferred.
- **Column expressions in UPDATE.** `Propulsion\Query\ColumnExpression` makes a
  column's new value a raw SQL expression (`view_count = view_count + 1`)
  instead of a PHP-computed bound literal, removing the read-modify-write
  lost-update race.
- **Bulk load.** `BasePeer::doBulkInsert()` with `DBAdapter::supportsBulkLoad()`/
  `bulkLoad()` — real `COPY FROM STDIN` on Postgres, `LOAD DATA LOCAL INFILE`
  on MySQL/MariaDB.
- **`explain()`** on query objects for Postgres, MySQL/MariaDB, and SQLite;
  throws on MSSQL/Oracle, which need a multi-statement mechanism.
- **Global (cross-process) query result cache.** A second, process-/host-shared
  tier behind the request-scoped one, so a cached query survives the worker
  request boundary instead of dying with it. Backed by any PSR-16 pool (new
  `psr/simple-cache ^3.0` dependency): register one with
  `Propulsion::setQueryCachePool()`, or name a shipped driver (`array`, `apcu`,
  `file`, `null`) in the new optional `cache.query` config section.
  **Propulsion ships no Redis or Memcached client** — use any third-party PSR-16
  implementation. Cached payloads are raw pre-hydration rows, so a hit
  re-hydrates through the normal instance-pool path and never leaks an object
  graph across requests. Invalidation is by **table version token**: an ORM
  write replaces the token of each written table, making every cache key derived
  from it unreachable across every process sharing the pool, with no index to
  maintain. Bumps are buffered until commit, and queries inside a transaction
  are never published (they can see uncommitted rows). Includes **admission
  control** (an entry is stored only on its second sighting, so a flood of
  never-repeating keys cannot grow the cache) and **stampede protection**
  (probabilistic early recomputation everywhere, plus strict single-flight on
  backends that can create-if-absent atomically). **Off by default**: both the
  config flag and the existing per-query `Criteria::setQueryCache()` opt-in must
  be present, so an upgrade changes nothing until you ask it to. See
  `docs/CACHING.md`.
- **`Propulsion::rawQuery()`**: hand-written SQL through the same cache, for the
  complex queries that are easier to write by hand than as a `Criteria` — and
  which tend to be the expensive ones.
  `Propulsion::rawQuery($sql, $params)->dependsOn('book', 'author')->cache(ttl: 300)->hydrate(BookPeer::class)`,
  with `rows()`/`one()`/`formatWith()` as alternative terminals. Tables must be
  declared rather than inferred: Propulsion will not parse SQL to guess them,
  because a parser would be wrong about CTEs, views and aliases, and
  `dependsOn()` validates each name against the DatabaseMap so a typo throws
  instead of silently never invalidating.
- **`Propulsion::invalidateQueryCacheForTables()`** for writes Propulsion cannot
  see (raw SQL, other applications, migrations).
- **Query result cache** (opt-in, `Criteria::setQueryCache()`): a request-scoped
  `QueryResultCache` owned by `Session`, keyed by SQL+params+dbName, wired into
  `ModelCriteria::find()`/`findOne()`/`count()` and the generated Peer's
  `doSelect()`/`doCount()`. Writes through `BasePeer` invalidate by table.
- **Compiled-query cache** (`Criteria::setCompiledQueryCache()`): caches the
  compiled SELECT SQL string itself, keyed by a caller-supplied shape key, for
  long-lived worker processes. Scoped to plain SELECTs.

#### Unit of Work, events, transactions

- **Unit of Work.** `UnitOfWork`/`EntityState` for coordinated multi-object
  flush ordering with pre/post-flush events, plus a `suppressAutoCascade`
  escape hatch so `BaseObject::save()`'s FK-cascade logic defers to a
  UnitOfWork-driven save order instead of double-saving.
- **`OptimisticLockBehavior`**, raising `ConcurrencyException` on a
  version-column mismatch instead of silently overwriting concurrent changes.
- **PSR-14 event dispatching** for model lifecycle events, mirroring the
  existing PSR-3 logger facade: `Propulsion::setEventDispatcher()`/`dispatch()`,
  a no-op when nothing is registered. Covers per-object hooks
  (`preSave`/`postSave`/`preInsert`/…) and the bulk
  `ModelCriteria::update()`/`delete()`/`deleteAll()` path. Pre-events implement
  `StoppableEventInterface`, so a listener can veto. Adds a `psr/event-dispatcher`
  dependency.
- **Real SAVEPOINT-based nested transactions** on pgsql, mysql, and sqlite,
  replacing the depth-counter emulation where an inner `rollBack()` merely
  poisoned the outer transaction. Platforms without savepoints keep the old
  fallback.

#### Column types

- **JSON/JSONB** — stored as real JSON text (`json_encode`/`json_decode`)
  rather than the legacy `PHP_ARRAY`/`OBJECT` `serialize()` storage. Native on
  Postgres (author's choice of JSON or JSONB) and MySQL, text fallback
  elsewhere; hydration uses `JSON_THROW_ON_ERROR` and names the offending
  column on malformed data.
- **UUID** — native on Postgres, `CHAR(36)` elsewhere; the generated setter
  validates the canonical 8-4-4-4-12 form and normalizes to lowercase.
- **Native PHP enum mapping** (`enumClass`), native enum DDL (`nativeEnum`),
  `BcMath\Number` for `DECIMAL`/`NUMERIC`, `INTERVAL` → `DateInterval`,
  Postgres network types (`inet`/`cidr`/`macaddr`) and `citext`, Postgres range
  types (via a new `Propulsion\Type\Range`), pgvector/MariaDB `VECTOR`, a
  `GEOMETRY` type (deliberately WKT-text-emulated everywhere), and a native
  MySQL `SET` type.

#### Platform DDL parity

- **PostgreSQL:** `GENERATED ... AS IDENTITY` (`identity="true"`), opt-in native
  array columns (`nativeArray="true"` + a `Propulsion\Type\PgArray` codec; the
  emulated `" | "` format remains the default), full-text search (`TSVECTOR`,
  `tsvectorFrom`-generated STORED columns, GIN/GiST via `Index::indexType`),
  partial and expression indexes, index storage parameters, `INCLUDE` covering
  columns, `CONCURRENTLY`, `EXCLUDE USING gist` exclusion constraints, and
  classic table inheritance (`inheritsFrom`). PostgreSQL 16 documented as the
  minimum.
- **MySQL/MariaDB:** native `SET`, `unsigned`/`zerofill` attributes,
  FULLTEXT/SPATIAL indexes, generated/virtual columns, opt-in MariaDB-only
  native `CREATE SEQUENCE` (`nativeSequence`) and native UUID (`nativeUuid`).
- **SQLite:** generated columns (`GENERATED ALWAYS AS (expr) VIRTUAL|STORED`)
  and partial/expression indexes.
- **MSSQL:** `CREATE SEQUENCE`, computed columns (`AS (expr) [PERSISTED]`),
  `rowVersion="true"` → `ROWVERSION`, clustered/nonclustered control on
  indexes/uniques/PKs, temporal tables (`SYSTEM_VERSIONING`), `IDENTITY_INSERT`
  support, and real savepoints. Supported floor raised to SQL Server 2012+
  (2016+/Azure SQL for temporal tables).
- **Oracle:** `GENERATED ... AS IDENTITY`, native JSON (21c+), native
  `BINARY_FLOAT`/`BINARY_DOUBLE` for `REAL`/`DOUBLE`. Floor raised to 12c+.
- **Generated-id retrieval folded into the INSERT** on every platform via
  `RETURNING`/`OUTPUT` (`RETURNING ... INTO` for Oracle's OUT-bind shape), plus
  `UPDATE`/`DELETE ... RETURNING` for affected-row hydration everywhere except
  Oracle. MariaDB is detected at runtime (`DBMySQL::isMariaDb()`) to gate its
  `RETURNING` support.

#### Testing & tooling

- **Full test-suite parity on MySQL, MSSQL, and Oracle**, all wired into CI as
  dedicated `integration-mysql`/`integration-mssql`/`integration-oracle` jobs
  alongside the Postgres job, plus a `PROPULSION_TEST_DB=mariadb`
  testcontainer path.
- **Reverse-engineering test coverage** for MSSQL, Oracle, and SQLite.
- **`bench/`** — a generated-code performance harness (file-based generated
  classes on in-memory SQLite) plus a correctness check, with results in
  `bench/RESULTS.md`. Includes `global_query_cache_bench.php`, which measures
  genuine cross-request cache hits (it calls `Session::reset()` before each
  iteration) per driver, with a `reset()`-only control column.
- **Two CI gaps closed.** New `analyse` job, so the project's PHPStan level 9
  requirement is enforced mechanically rather than by discipline; and a new
  `worker` job running `composer test:worker`, which is the only place the
  worker-mode request-boundary guarantees — including the global query cache
  surviving a boundary while the request-scoped tier does not — are actually
  provable. APCu is now installed in the unit job with `apc.enable_cli=1`,
  without which `ApcuCacheTest` would silently skip and report green.
- **Worker harness extended** with four global-query-cache profiles
  (`array`, `array` cross-thread, `apcu` cross-thread, `file` cross-thread) and
  a two-OS-process filesystem-cache test driven by `proc_open`, which needs no
  Docker and runs in the fast unit tier.
- **New docs:** `PLATFORM_FEATURES.md` (platform/feature roadmap),
  `UNIT_OF_WORK.md`, `TECH_DEBT.md`, `DOCS_TODO.md`. `KNOWN_ISSUES.md` trimmed
  back to actually-open items.
- A PHPStan extension for `ModelCriteria`'s magic methods, and
  `Propulsion\OM\WritableModelInterface`, implemented by generated models
  exactly when the generic mutators (`setByName()`/`setByPosition()`/
  `fromArray()`) are emitted.

### Changed

- **`PropulsionPDO` is now an interface**, not a concrete class. Every
  connection Propulsion constructs is a driver-specific subclass of the
  matching PHP 8.4 `\Pdo\*` class — `PgsqlPropulsionPDO extends \Pdo\Pgsql`,
  `MysqlPropulsionPDO extends \Pdo\Mysql`, `SqlitePropulsionPDO extends
  \Pdo\Sqlite`, `MssqlPropulsionPDO extends \Pdo\Dblib`; `OraclePropulsionPDO`
  and `GenericPropulsionPDO` extend plain `\PDO`. Shared connection behavior
  (savepoints, query counting, logging, statement caching) moved into
  `PropulsionPDOTrait`. Existing `?PropulsionPDO $con` type hints keep working;
  code that instantiated or extended the concrete class does not.
- **`withQuery()` split into `withQuery()`/`withTypedQuery()`**, fixing the
  broken generic contract of `useQuery()`/`withQuery()`; `endUse()` is now
  guarded.
- **PHPStan baseline raised from level 6 to level 9**, project-wide and clean
  (generator, runtime, bin). This ran through thousands of real findings and
  fixed a long tail of genuine nullability, mixed-type, and dynamic-dispatch
  bugs along the way rather than suppressing them. Supporting changes include
  throwing `require*()` accessors for `Table`/`Column`/`Domain` attach
  invariants, `XMLElement` typed-attribute helpers, and replacing
  `call_user_func` Peer dispatch with dynamic static calls.
- **`ObjectBuilder`'s per-column-type codegen extracted** into
  `generator/Lib/Builder/OM/ColumnType/` handler classes with a resolving
  registry, replacing ~15 column-type checks duplicated across six parallel
  elseif chains in a 4363-line file.
- `MysqlPlatform`'s default storage engine changed from MyISAM to InnoDB —
  matching what every test fixture already overrode it to, and what
  `supportsNativeDeleteTrigger()` requires.
- `DBMSSQL::applyLimit()` rewritten to native `OFFSET`/`FETCH`, removing a
  ~150-line `ROW_NUMBER()` regex rewriter; `getDropTableDDL()`'s catalog
  queries modernized from `sysobjects`/`sysreferences` to
  `sys.foreign_keys`/`sys.tables`. Oracle's `applyLimit()` likewise replaced
  its `ROWNUM` double-nested subquery with `OFFSET ... FETCH NEXT ... ROWS ONLY`.
- `PropulsionTypes` and `PropulsionColumnTypes`, which had drifted apart,
  deduplicated into one map (fixing two real PDO-type mismatches).
- `getPhp84TypeHint()`/`getPhp84PropertyType()` renamed to `getPhp85*`.

### Removed

- **`buildtime-conf.xml` support.** Build-time connection config is
  plain-PHP-array only now (a `.php` file, or the
  `propulsion.buildtimeConfigArray` build property):
  `GeneratorConfig::parseBuildConnections()`, the XML/base64-string branches of
  `getBuildConnections()`, the extension dispatch in
  `loadBuildConnectionsFile()`, and the stale
  `propulsion.buildtime.conf.file` default are all gone. `runtime-conf.xml`
  (a separate legacy format) and schema-XML parsing are untouched.
- **`--target-platform=php5`.** The option's only remaining behavior was a
  dead branch in `QueryInheritanceBuilder::addFactory()` that emitted an
  untyped `create()` signature to match an already-removed `PHP5QueryBuilder`
  — passing it today produced a fatal signature clash.
- **The legacy `QueryCacheBehavior`**, replaced by the real result cache above.
  It only cached the rendered SQL string (queries still hit the DB), called APC
  functions that don't exist on PHP ≥ 8.5, had no invalidation, and had no test
  coverage.
- Leftover SVN `$Id$` keyword tags in test docblocks; PHP5-named test
  files/classes renamed to their real subject.

### Fixed

Selected fixes; many more came out of the level 7/8/9 cleanup and the live
multi-platform test runs.

- **Cross-namespace `extends=` resolution in single-table inheritance.**
  `MultiExtendObjectBuilder::getParentClasspath()` used the schema value
  verbatim, so a relative-qualified ancestor name silently resolved against the
  generated file's own namespace instead of the intended one.
- **Raw-expression parameter misalignment in `BasePeer::doUpdate()`.** A raw
  expression with no `?` placeholder still got an entry in the positional bound
  params array, silently shifting every later `:pN` in the same statement.
- **MSSQL:** nested-transaction leak, silent INSERT failure, stale LOB stream
  handles after `save()` on drivers that close them, several MARS "results
  pending" cursor leaks, an auto-increment PK leaking into `UPDATE SET`,
  aliased `UPDATE`, and `applyLimit()` for offset-only and structurally
  unrewritable queries (UNION etc.).
- **`Criteria::clear()` could null out `dbName`.**
- **Two request-scoped query-cache bugs, both found while building the global
  tier.** `getQueryCacheKey()` ignored the formatter, so within one request an
  `ARRAY`-formatted and an object-formatted query producing identical SQL shared
  a cache entry and whichever ran second silently received the other's result;
  the formatter is now part of the key. And `FORMAT_STATEMENT` /
  `FORMAT_ON_DEMAND` results were cached despite being tied to a live statement,
  so a second hit handed back an exhausted cursor; both formatters now bypass
  caching entirely via `PropulsionFormatter::supportsRowCaching()`. The latter
  is a **behaviour change**: a cache-enabled query using either formatter is no
  longer cached at all, at either tier.
- A testcontainer startup race that silently skipped ~1300 MSSQL/Oracle tests,
  and an Oracle Instant Client download 404, both in CI.
- **Oracle CI job could not build `pdo_oci`.** The Instant Client Basic and SDK
  archives both contain `META-INF/MANIFEST.MF`, so the second `unzip` stopped
  to ask whether to replace it; with no TTY on a runner it read EOF, answered
  "[N]one", and skipped the remainder of the archive, so the SDK headers never
  landed and `./configure --with-pdo-oci` failed with no obvious cause (`-q`
  suppresses the file listing, not the prompt). Now unzipped with `-o`, with an
  explicit `test -f sdk/include/oci.h` so an incomplete extraction fails at the
  point it happens. The download step's `actions/cache` key was bumped as well —
  otherwise a cache hit would restore the previously-saved partial extraction
  and skip the download entirely, and the fix would never take effect.
- CI coverage reporting: pcov instrumented nothing on PHP 8.5.
- Test harness now bootstraps Propulsion config unconditionally; Postgres-only
  generator tests skip cleanly under other `PROPULSION_TEST_DB` values.

### Performance

Measured with `bench/` on PHP 8.5 with JIT on; see `bench/RESULTS.md`.

- Generated ORM hot paths (instance-pool state hoisted out of per-row loops,
  modified-column tracking as a set instead of a list, single-read hydration
  cells):
  - `doSelectJoin` (cold): 4330 → 1117 ns/op (**−74%, ≈3.9×**)
  - read, pool on, warm hits: 763 → 661 ns/op (**−13%**)
  - read+hydrate, pool on, cold: 2460 → 2282 ns/op (**−7%**)
  - read+hydrate, pool off: 2290 → 2148 ns/op (**−6%**)
  - setter churn + `buildCriteria()`: 1855 → 1769 ns/op (**−5%**)
- Query result cache on repeated queries: ~139× on `find()`, ~31× on `count()`,
  ~181× on raw `doSelect()`.
- Global (cross-request) cache tier: an L2 hit costs ~45µs against ~89µs
  uncached and ~6µs for a request-scoped hit — the two tiers are complementary,
  since L2 skips the query but still hydrates while L1 skips both. The three
  drivers land within ~20% of each other on the hit path, so **choose a driver
  on sharing semantics, not speed** (`docs/CACHING.md`); the `file` driver's
  real cost is on writes (~769µs to store, ~98µs to bump a table version,
  against ~80µs and ~1.5µs in-memory), because each is an atomic
  temp-file-plus-rename. Note the 2× headline understates production: the
  benchmark's baseline is in-memory SQLite, where the round trip an L2 hit
  avoids is only ~89µs, so it largely measures hydration against a free
  database — against a networked Postgres the same hit avoids 0.5–2ms.
- Compiled-query cache: ~1.5× faster `createSelectSql()` for a joined query.
- Bulk load (`COPY`/`LOAD DATA`) is an order of magnitude faster than multi-row
  INSERT for seeding and imports.

## [1.0.0] — 2026-07-07

Initial release of Propulsion: [Propel 1](https://github.com/propelorm/Propel1)
forked, renamed, and modernized for PHP 8.5+ — modern syntax and types
throughout, Phing replaced by a plain Symfony Console app, PostgreSQL promoted
to the default and recommended database, PSR-3 logging, and a PHPStan level 6
baseline. See `NOTICE.md` for attribution.

[2.0.0]: https://github.com/quioteframework/propulsion/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/quioteframework/propulsion/releases/tag/v1.0.0
