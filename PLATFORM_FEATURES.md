# Platform & feature roadmap

Gaps between what each database platform (Postgres, MySQL/MariaDB, SQLite,
MSSQL, Oracle) supports and what Propulsion's generator
(`generator/Lib/Platform/`) and runtime adapters (`runtime/Lib/Adapter/`)
currently implement — plus, since the 2026-07-26 second pass, the
*query-layer* capabilities that no platform can express today because the
shared `Criteria`/`BasePeer` SQL builder has no API for them at all.

Found via codebase surveys on 2026-07-26. Check items off as they're
implemented; see `KNOWN_ISSUES.md` for non-feature bugs/gaps, and
`UNIT_OF_WORK.md` for the Unit-of-Work / change-tracker roadmap (there is
some deliberate overlap; cross-references are noted inline).

Relevant files per platform:
- Postgres: `generator/Lib/Platform/PgsqlPlatform.php`, `runtime/Lib/Adapter/DBPostgres.php`
- MySQL/MariaDB: `generator/Lib/Platform/MysqlPlatform.php`, `runtime/Lib/Adapter/DBMySQL.php`
- SQLite: `generator/Lib/Platform/SqlitePlatform.php`, `runtime/Lib/Adapter/DBSQLite.php`
- MSSQL: `generator/Lib/Platform/MssqlPlatform.php`, `runtime/Lib/Adapter/DBMSSQL.php` (+ `DBSQLSRV.php`)
- Oracle: `generator/Lib/Platform/OraclePlatform.php`, `runtime/Lib/Adapter/DBOracle.php`
- Shared base: `generator/Lib/Platform/DefaultPlatform.php`, `runtime/Lib/Adapter/DBAdapter.php`
- Shared query builder: `runtime/Lib/Query/Criteria.php`, `runtime/Lib/Query/ModelCriteria.php`, `runtime/Lib/Util/BasePeer.php`

**Note:** per `KNOWN_ISSUES.md`, only Pgsql/Mysql have been audited against
`DefaultPlatform` — treat Mssql/Oracle gaps below as less battle-tested and
double-check assumptions before implementing.

## How this document is organized

The original version of this file mixed two very different kinds of work:
"emit different DDL for this platform" and "the query builder cannot express
this on *any* platform." They're now split, because the second group needs a
single shared API design *before* per-platform SQL generation is worth
starting:

1. **Query-layer capabilities** — missing from the shared SQL builder.
   Design once in `Criteria`/`ModelCriteria`, then implement per platform.
2. **Type-system additions** — new column types / PHP-side mappings.
3. **Per-platform DDL & adapter parity** — the original per-platform lists.
4. **Cross-ORM ideas** — architectural features worth stealing that aren't
   platform-specific at all.

Rough priority, if you want one: query-layer locking and upsert first (both
are correctness-affecting, not just convenience), then `RETURNING`/ID-folding,
then SQLite parity (cheapest platform, no container needed in CI), then the
type-system work.

## 1. Query-layer capabilities (all platforms)

These are gaps in the shared query builder — confirmed absent by grep across
`runtime/Lib/`, not merely un-generated for some platform.

- [x] **Pessimistic locking** — no `FOR UPDATE` / `FOR SHARE` support
  anywhere in `Criteria`/`BasePeer`. Per-platform: Pg/MySQL/Oracle
  `FOR UPDATE [NOWAIT | SKIP LOCKED]`, MSSQL `WITH (UPDLOCK, ROWLOCK)`,
  SQLite N/A (whole-database locking). `SKIP LOCKED` on its own is what makes
  an ORM usable for job-queue workloads. Prior art: Doctrine `LockMode`,
  Rails `lock!`, SQLAlchemy `with_for_update()`. Probably the most
  conspicuous single omission in the runtime.
- [ ] **Upsert abstraction** — no `ON CONFLICT` (Pg/SQLite) /
  `ON DUPLICATE KEY UPDATE` (MySQL) / `MERGE` (MSSQL/Oracle) support in
  `Criteria`/`BasePeer`. Needs a query-builder API plus per-platform SQL
  generation. Django's `update_or_create`/`bulk_create(update_conflicts=)` is
  a good API reference.
  - [x] Postgres/SQLite (`ON CONFLICT (...) DO UPDATE SET ...`/`DO NOTHING`)
    and MySQL/MariaDB (`ON DUPLICATE KEY UPDATE ...`) via `BasePeer::doUpsert()`
    and `DBAdapter::supportsUpsert()`/`getUpsertSql()`, plus a
    `ModelCriteria::doUpsert()` convenience wrapper. Conflict target defaults
    to the table's primary key (no unique-index metadata exists at runtime
    beyond the PK — see below); update values reuse the existing
    `ColumnExpression`/`Criteria::CUSTOM_EQUAL` raw-expression convention, so
    e.g. `view_count = view_count + 1` works on conflict too.
  - [ ] MSSQL/Oracle `MERGE` — deferred. `MERGE` is a structurally different
    statement (`MERGE INTO target USING (...) AS source ON (...) WHEN
    MATCHED THEN UPDATE ... WHEN NOT MATCHED THEN INSERT ...`), not a clause
    appended to `INSERT`, so it doesn't fit the `getUpsertSql(string $sql,
    ...)` hook shape used for the other three platforms — it needs its own
    design, and is riskier to get right without a live instance to verify
    against (same reasoning as deferring Oracle's `RETURNING ... INTO`
    above).
  - [ ] Conflict target beyond the primary key (a named unique constraint)
    needs unique-index metadata to exist in the generated runtime
    `TableMap`/`ColumnMap` at all — today that only lives in the build-time
    `generator/Lib/Model/Table.php`/`Unique.php` model, never emitted into
    the generated runtime map classes. `BasePeer::doUpsert()`'s
    `$conflictColumns` parameter lets a caller name the columns explicitly as
    a workaround in the meantime.
- [x] **Column-expression updates (Django's `F()`)** —
  `ModelCriteria::update()` accepts literal values only, so there is no way
  to emit `SET counter = counter + 1`. Today every increment is a
  read-modify-write round trip, i.e. a lost-update race under concurrency.
  Small change relative to its correctness value.
- [ ] **Fold ID retrieval into INSERT** — every platform does a separate
  round-trip (`lastInsertId()` or an explicit `nextval`/`currval` query)
  instead of using `RETURNING` (Pg, SQLite 3.35+, MariaDB), `OUTPUT` (MSSQL),
  or `RETURNING ... INTO` (Oracle). Also unlocks `UPDATE/DELETE ... RETURNING`
  for affected-row hydration.
  - [x] MSSQL: `BasePeer::doInsert()` now asks the adapter
    (`DBAdapter::supportsInsertReturning()`) whether it can fold id retrieval
    into the INSERT itself; `DBMSSQL` implements it via an `OUTPUT
    INSERTED.<col>` clause, replacing its `lastInsertId()` round trip (whose
    behavior varies across the pdo_sqlsrv/pdo_dblib drivers).
  - [x] SQLite: same hook, `DBSQLite` implements it via a trailing
    `RETURNING <col>` clause. Assumed available unconditionally (no runtime
    version probe exists anywhere in the codebase — every still-supported
    PHP version bundles a far newer SQLite than the 3.35 (2021) RETURNING
    was added in); revisit only if that assumption ever breaks against an
    unusually old libsqlite3.
  - [ ] Postgres/MariaDB `RETURNING`, Oracle `RETURNING ... INTO` (needs an
    OUT-bound parameter via PDO_OCI, a different call shape than "append
    SQL, fetch a row" and materially riskier to get right without a live
    Oracle instance to verify against) are still open. Postgres in
    particular still does an explicit pre-INSERT `nextval()` query today
    (`DBPostgres::getId()`), which is a deliberate, already-working design
    (the sequence value is known before the row is built) rather than the
    same "extra round trip after INSERT" problem MSSQL's `lastInsertId()`
    had — lower priority than the MSSQL/SQLite fixes were, and switching it
    to RETURNING would also mean no longer including the PK column's value
    explicitly in the INSERT (relying on the column's own `SERIAL` default
    instead), a bigger behavioral change than SQLite's purely-additive one
    that would need `BasePeerExceptionsTest::testDoInsert()`'s exact-SQL
    assertion updated too.
  - [ ] `UPDATE/DELETE ... RETURNING` for affected-row hydration — not
    started.
- [ ] **Common table expressions** — no `WITH` / `WITH RECURSIVE` support.
  Supported by all five platforms. Recursive CTEs would additionally give
  native adjacency-list tree queries, an alternative to
  `NestedSetBehavior`'s write amplification.
- [ ] **Window functions** — no `OVER (PARTITION BY ... )` builder;
  `withColumn()` can only smuggle one in as a raw string. Worth noting:
  `ROW_NUMBER() OVER (...)` is exactly what would let both legacy
  `applyLimit()` rewriters (MSSQL, Oracle — see below) be deleted.
- [x] **Set operations** — no `UNION` / `UNION ALL` / `INTERSECT` / `EXCEPT`.
  `Criteria::union()`/`unionAll()`/`intersect()`/`except()` combine two full
  `Criteria` queries into `(<query>) <OP> (<other's query>)`, chainable and
  composable (`$other` may itself already carry set operations). A query's
  own `orderBy()`/`limit()`/`offset()` apply to the *combined* result, not
  to its own branch alone -- its own branch is built from an internal clone
  with those cleared first, then re-applied after every branch via
  `BasePeer::createSetOperationSql()`. Each `$other` branch keeps whatever
  `ORDER BY`/`LIMIT`/lock it has as part of its own parenthesized branch,
  which most platforms accept but isn't exhaustively verified beyond
  Postgres (the default test fixture) -- in particular, Oracle's
  `applyLimit()` rewrites `$sql` by locating the string `"FROM"` when
  `BasePeer::needsSelectAliases()` is true, which would misfire against a
  multi-branch combined string; this only matters if `LIMIT` and set
  operations are combined on Oracle specifically. `GROUP BY`/`HAVING`
  belong to a single branch (SQL doesn't allow them on a raw `UNION`
  result), so no special handling needed there -- they stay in the outer
  query's own body along with everything else that isn't `ORDER BY`/
  `LIMIT`/`OFFSET`/lock.
- [x] **`EXISTS` / `IN` subquery filters** — `addSelectQuery()` covers
  `FROM`-clause subqueries only; there's no correlated-subquery filter.
  Propel 2's `useExistsQuery()` / `useNotExistsQuery()` / `useInQuery()` is
  the direct steal, and maps cleanly onto the closure-scoped `withQuery()`
  style this codebase has already moved to (see `README.md`).
  Implemented at both layers: `Criteria::addExistsQuery()`/`addInQuery()`
  (the low-level primitives — nest an arbitrary sub-`Criteria` into the
  WHERE clause as `EXISTS (...)`/`IN (...)`, reusing the same
  recursive-`BasePeer::createSelectSql()`-with-shared-`$params` mechanism
  `addSelectQuery()`'s FROM-clause subqueries already use) and
  `ModelCriteria::useExistsQuery()`/`useNotExistsQuery()`/`useInQuery()`
  (closure-scoped, matching `withQuery()`'s style, building the subquery via
  `PropulsionQuery::from($modelName)`). Correlating the subquery to the
  parent is the caller's job (no relation lookup/auto-join the way
  `useQuery()` has, since `join()`-eligible relations aren't required here);
  a raw, unbound `where()` clause referencing the parent's table/alias
  works for this, since `where()` falls through to a literal expression
  when it finds no column to bind a value to.
- [ ] **Named / advisory locks** — Pg `pg_advisory_lock`, MySQL `GET_LOCK`,
  MSSQL `sp_getapplock`, Oracle `DBMS_LOCK`. A uniform cross-platform
  app-level mutex; nothing in the tree references any of them.
- [x] **Bulk load path** — Pg `COPY FROM STDIN`, MySQL `LOAD DATA`, MSSQL
  `BULK INSERT`. An order of magnitude faster than multi-row `INSERT` for
  seeding and imports. Complements (does not duplicate) the "Statement
  batching" item in `UNIT_OF_WORK.md`, which is about the flush path.
  - [x] Postgres and MySQL/MariaDB via `DBAdapter::supportsBulkLoad()`/
    `bulkLoad()` and `BasePeer::doBulkInsert()`. Postgres uses PDO's
    `pgsqlCopyFromArray()` (real `COPY FROM STDIN`, no temp file needed) --
    deprecated as of PHP 8.5 in favor of `Pdo\Pgsql::copyFromArray()`, but
    that only exists on an actual `Pdo\Pgsql` instance (the driver-specific
    subclass plain `new PDO('pgsql:...')` auto-selects), and `PropulsionPDO`
    extends `\PDO` directly for every driver rather than being
    auto-selected per-driver -- switching would mean restructuring that
    whole class hierarchy, well beyond this feature's scope, so the
    resulting deprecation notice is expected/unavoidable for now. MySQL
    uses `LOAD DATA LOCAL INFILE` against a temp file (no rows-array
    variant exists for MySQL the way Postgres has one); this needs the
    connection created with `PDO::MYSQL_ATTR_LOCAL_INFILE` enabled (can't
    be toggled after connecting) *and* the server's `local_infile` global
    variable set to 1 (defaults OFF on stock MySQL 8+) -- `bulkLoad()`
    throws a clear, actionable error up front if the connection-side half
    isn't set, rather than surfacing MySQL's own less obvious error.
  - [ ] MSSQL `BULK INSERT`/`OPENROWSET(BULK ...)` needs the file to be
    readable by the SQL Server *process itself*, not the PHP client -- a
    generic client library can't assume a shared filesystem with the
    database server, so this isn't implemented. Revisit if a
    table-valued-parameter-based approach (pdo_sqlsrv-specific, not
    available via pdo_dblib) turns out to be practical.
- [ ] JSON-path query helpers at the `Criteria` layer — JSON columns exist at
  the DDL level (Pg/MySQL/Oracle/SQLite) but there is no query-side support
  for `->>`/`JSON_EXTRACT`/`JSON_VALUE`.
- [ ] **Global query filters** — auto-applied `WHERE` predicates
  (soft-delete, multi-tenancy) suppressible per query. Also tracked in
  `UNIT_OF_WORK.md` under the EF Core steal list; recorded here too because
  it's a `Criteria`-level mechanism, and `SoftDeleteBehavior` is the existing
  per-table special case it would generalize.

## 2. Type-system additions

- [ ] **Native enum types at the DDL level** — Propulsion's `ENUM` column
  type is universally emulated as an integer column (`INT2`/`TINYINT`/
  `NUMBER`) with the value list tracked only in PHP
  (`Column::getValueSet()`). Never emitted as each platform's native enum
  mechanism (Pg `CREATE TYPE ... AS ENUM`, MySQL `ENUM(...)`, Oracle/SQLite
  `CHECK` constraint; MSSQL has no native enum, so N/A there).
- [ ] **Native PHP enum mapping** — `PropulsionTypes::ENUM_NATIVE_TYPE` is
  `string`. Letting a column declare a backing PHP `enum` class and hydrating
  to it is the most-requested modern-PHP mapping (Doctrine `enumType`,
  Laravel casts). Orthogonal to the DDL item above — either can land first.
- [ ] **`DECIMAL`/`NUMERIC` → `BcMath\Number`** (PHP 8.4+) instead of
  `string`. Removes the standing money-handling footgun.
- [ ] **Vector types** — Pg `pgvector` (`vector` column, `<=>`/`<->`
  operators, HNSW/IVFFlat indexes) and MariaDB 11.7+ `VECTOR` /
  `VEC_DISTANCE`. No PHP ORM ships this well today; a genuine
  differentiator, and it needs the query layer (operators) as much as the DDL.
- [ ] **Pg range types** (`tstzrange` et al.) — the natural companion to the
  exclusion-constraint item under PostgreSQL below: ranges plus
  `EXCLUDE USING gist` is what enforces booking/scheduling non-overlap in the
  database instead of in application code.
- [ ] **Pg network types** (`inet`, `cidr`, `macaddr`) and `citext`.
- [ ] **`INTERVAL` → `DateInterval`** — currently no interval type at all
  (`PgsqlSchemaParser` recognizes the DDL keyword on reverse-engineering
  only).
- [ ] **Spatial types as a first-class type**, not just the MySQL-only DDL
  bullet below — PostGIS is the more common target.
- [ ] **Custom type registry** — a user-extensible type/converter layer
  (Doctrine's `Type` registry, SQLAlchemy `TypeDecorator`). Would let most of
  the items above ship as plugins rather than as new `PropulsionTypes`
  constants, and covers value objects / embeddables at the same time.

## 3. Per-platform DDL & adapter parity

### Cross-cutting

- [ ] Table partitioning / inheritance — unsupported on any platform.

### PostgreSQL (16/17/18)

- [ ] **`GENERATED ... AS IDENTITY`** (PG10+ standard syntax) — still emits
  legacy `SERIAL`/`BIGSERIAL` exclusively (`PgsqlPlatform::getColumnDDL`,
  `getNativeIdMethod()` at `PgsqlPlatform.php:68`).
- [ ] **Native array types** — `PHP_ARRAY` maps to plain `TEXT`
  (`PgsqlPlatform::initialize()`, line 54), not `type[]`.
- [ ] Full-text search (`tsvector`/`tsquery`, GIN/GiST text indexes).
- [ ] Partial indexes (`CREATE INDEX ... WHERE ...`) and expression indexes
  (`ON (lower(col))`) — `Index` model has no predicate/expression fields.
- [ ] Exclusion constraints (`EXCLUDE USING gist (...)`) — see range types
  above.
- [ ] Table inheritance (`INHERITS`) / declarative partitioning
  (`PARTITION BY`).
- [ ] Index storage parameters (`fillfactor`), `CONCURRENTLY` index creation,
  covering indexes (`INCLUDE`).
- [ ] `LISTEN`/`NOTIFY` exposure on the adapter (low priority, but nothing
  else gives event-driven invalidation for the query result cache).

### MySQL / MariaDB

- [ ] **Native `ENUM`/`SET` types** — see type-system section; there is also
  no `SET` type at all.
- [ ] **Generated/virtual columns** (`GENERATED ALWAYS AS (...) VIRTUAL|STORED`).
- [ ] **Unsigned integer types** — no `UNSIGNED`/`ZEROFILL` modifier support
  in `PropulsionTypes`.
- [ ] Spatial types (`GEOMETRY`, `POINT`, ...) and spatial indexes.
- [ ] First-class FULLTEXT/SPATIAL index support — currently only reachable
  via manually setting the `Index_type` vendor parameter, no model-level
  flag (e.g. `Index::isFulltext()`).
- [ ] Partitioning (`PARTITION BY`).
- [ ] Check/fix default storage engine: `MysqlPlatform`'s default is still
  `MyISAM`; `supportsNativeDeleteTrigger()` gates on InnoDB, so it's silently
  disabled unless `mysqlTableType=InnoDB` is set explicitly in build config.
  Consider defaulting to InnoDB to match modern MySQL/MariaDB installs.
- [ ] **MariaDB divergences.** There is no `mariadb` string anywhere in the
  tree — MariaDB is served by `MysqlPlatform` as though it were MySQL, but
  the two have diverged enough to matter: `INSERT`/`DELETE ... RETURNING`,
  real `CREATE SEQUENCE`, native `UUID` type (10.7+), and `VECTOR` (11.7+).
  Needs at least a server-version probe before any of those can be used;
  possibly a `MariadbPlatform` subclass.

### SQLite

Not covered by the original version of this document at all, despite being a
supported platform and the one the worker-mode harness (`test/worker/`) runs
on. Modern SQLite has most of the cross-cutting query-layer items already, and
it needs no container in CI — so it's the cheapest platform to bring to
parity.

- [ ] **`ON CONFLICT` upsert** and **`RETURNING`** (3.35+) — both supported
  natively; see query-layer section.
- [ ] Generated columns (`GENERATED ALWAYS AS ... [STORED|VIRTUAL]`, 3.31+).
- [ ] `STRICT` tables (3.37+) and `WITHOUT ROWID`. Note the current type
  mappings (`SqlitePlatform::initialize()`) emit MySQL-flavored type names
  (`MEDIUMTEXT`, `LONGBLOB`, `MEDIUMBLOB`), which SQLite accepts today only
  because of its permissive type affinity — those names are *not* valid under
  `STRICT`, so this item is a prerequisite for that one.
- [ ] Partial and expression indexes (3.8+).
- [ ] FTS5 full-text search; JSON functions (JSON1, built in since 3.38).
- [ ] `ALTER TABLE` capability audit — `getAddForeignKeyDDL()`/
  `getDropForeignKeyDDL()` exist but SQLite cannot add or drop an FK on an
  existing table (the 12-step table-rebuild dance is the only route).
  Confirm what these actually emit and what `supportsMigrations()` promises.

### MSSQL

- [ ] **Sequences** (`CREATE SEQUENCE`, SQL Server 2012+) — only `IDENTITY`
  is supported; `getNativeIdMethod()` isn't overridden in `MssqlPlatform`.
- [ ] Computed columns (`AS (expression) [PERSISTED]`).
- [ ] Temporal tables (`SYSTEM_VERSIONING = ON`, `PERIOD FOR SYSTEM_TIME`).
- [ ] `rowversion`/`timestamp` type mapping — would also serve as the
  concurrency token for `UNIT_OF_WORK.md`'s optimistic-concurrency item.
- [ ] Clustered vs. nonclustered index control — `Index` model has no
  clustering flag; `getAddIndexDDL` inherited unmodified from
  `DefaultPlatform`.
- [ ] Modernize `DBMSSQL::applyLimit()` — currently a ~150-line regex-based
  SQL rewriter (parses `SELECT ... FROM ...` via regex, requires explicit
  aliases on aggregates, fragile against subqueries/CTEs). SQL Server 2012+
  supports `OFFSET n ROWS FETCH NEXT m ROWS ONLY` natively, which would
  eliminate the whole rewriter.
- [ ] Modernize `getDropTableDDL`'s use of legacy `sysobjects`/`sysreferences`
  catalog views to `sys.foreign_keys`/`sys.tables` (works today, but dated).
- [ ] (Tracked in `KNOWN_ISSUES.md`) auto-downgrade multiple CASCADE FKs to
  the same target table to `NO ACTION` (SQL Server error 1785) so the shared
  bookstore fixture can load on MSSQL.

### Oracle

- [ ] **12c+ `GENERATED ... AS IDENTITY` columns** — still assumes the
  legacy sequence-only pattern; no support for native identity columns.
- [ ] **Auto-increment trigger generation** — `getAddSequencesDDL` creates
  the sequence half only; no `BEFORE INSERT` trigger DDL generator. Works
  end-to-end via the ORM's own insert path (`DBOracle::getId()` does
  `SELECT seq.nextval FROM dual` before insert), but there's no DB-level
  auto-population for inserts made outside the ORM.
- [ ] Native JSON support (12c+ `IS JSON` constraint, 21c+ native `JSON`
  type) — currently falls through to `CLOB` unconditionally.
- [ ] Modernize `DBOracle::applyLimit()` — currently rownum-based
  double-nested subquery (`PROPEL_ROWNUM`); Oracle 12c+ supports
  `OFFSET ... FETCH NEXT ... ROWS ONLY` natively.
- [ ] PL/SQL type support — no packages, stored procedures, custom object
  types, or PL/SQL table types.
- [ ] `BINARY_FLOAT`/`BINARY_DOUBLE` native float types (currently
  everything numeric goes through `NUMBER`).

## 4. Cross-ORM ideas (not platform-specific)

Architectural features from other ORMs — several of these rank above items in
section 3 by value-per-effort. See `UNIT_OF_WORK.md` for the EF Core
change-tracker steal list, which is deliberately not repeated here.

- [ ] **Strict / no-implicit-lazy-load mode.** Generated relation getters
  silently issue a query on access, so N+1 is invisible in development. Add a
  mode that throws (or logs loudly) on implicit lazy load. Prior art: Rails
  `strict_loading`, SQLAlchemy `raiseload`, EF Core lazy-load warnings.
- [ ] **Split-query eager loading.** `with()` is JOIN-only, which row-explodes
  on one-to-many relations with wide parents. A second strategy that issues
  one `WHERE fk IN (...)` query per relation (Rails `preload`, Django
  `prefetch_related`, EF Core `AsSplitQuery`) is often substantially faster.
  `UNIT_OF_WORK.md` currently dismisses this as not mapping onto the
  generated-Peer architecture; that assessment is worth revisiting — the
  hydration side already handles collections and `findPks()` is the fetch
  primitive, so the pieces exist.
- [ ] **Compiled-query / SQL-string cache.** The opt-in cache added in
  87e43c4 caches *result rows*. Caching `Criteria` → SQL string is a separate
  and cheaper win (Doctrine's query cache, EF Core compiled queries),
  especially in long-lived worker processes where the same query shapes are
  rebuilt on every request.
- [ ] **Execution strategy: connection liveness + transaction retry.**
  Reconnect-on-drop exists but is ad hoc and undocumented
  (`PropulsionPDO.php:507`, `:546`, `DebugPDOStatement.php:99`) — and it
  writes to `error_log()` directly, bypassing the PSR-3 logger the rest of the
  codebase standardized on. Wanted: a pre-checkout ping, and retry-with-backoff
  on deadlock/serialization-failure SQLSTATEs (EF Core
  `EnableRetryOnFailure`). Matters most in FrankenPHP worker mode, where
  connections outlive requests.
- [ ] **Observability hooks.** PSR-3 query logging exists; there is no
  OpenTelemetry span emission, query-timing metric, or slow-query-threshold
  callback (SQLAlchemy events, Doctrine middleware, EF Core interceptors).
- [ ] **`->explain()`** on a query object, returning the platform's plan
  (Rails, Django, Laravel all ship this).
- [ ] **Schema drift check** — a `schema:validate`-style command comparing the
  live database against the schema, alongside the existing
  `migration:status`/`sql:diff` commands.
- [ ] **Joined-table inheritance.** Single-table and concrete-table
  inheritance both exist (`ConcreteInheritance` behavior, `extends=`);
  class-table/joined inheritance (Doctrine `JOINED`) is the missing third
  strategy.
- [ ] **Code-first schema definition** via PHP attributes as an alternative to
  XML (Doctrine attributes, EF Core fluent API, Prisma schema). By far the
  largest effort listed anywhere in this document, but it is the DX gap a new
  user notices first.
- [ ] **PHPStan extension.** Given the level-9 goal in `CLAUDE.md`, shipping an
  extension that teaches PHPStan about generated query/Peer classes and the
  magic `__call()` filters (as Doctrine and Larastan do for their ecosystems)
  would pay off for consumers as well as this repo.
