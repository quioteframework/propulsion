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

Rough priority, if you want one: locking and upsert are done (both were
correctness-affecting, not just convenience); `RETURNING`/ID-folding next,
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
- [x] **Upsert abstraction** — `ON CONFLICT` (Pg/SQLite) /
  `ON DUPLICATE KEY UPDATE` (MySQL) / `MERGE` (MSSQL/Oracle), all five
  platforms, via `BasePeer::doUpsert()` plus a `ModelCriteria::doUpsert()`
  convenience wrapper. Django's `update_or_create`/
  `bulk_create(update_conflicts=)` was the API reference.
  - [x] Postgres/SQLite (`ON CONFLICT (...) DO UPDATE SET ...`/`DO NOTHING`)
    and MySQL/MariaDB (`ON DUPLICATE KEY UPDATE ...`) via
    `DBAdapter::supportsUpsert()`/`getUpsertSql()`. Conflict target defaults
    to the table's primary key (no unique-index metadata exists at runtime
    beyond the PK — see below); update values reuse the existing
    `ColumnExpression`/`Criteria::CUSTOM_EQUAL` raw-expression convention, so
    e.g. `view_count = view_count + 1` works on conflict too.
  - [x] MSSQL/Oracle `MERGE` — `MERGE` is a structurally different statement
    (`MERGE INTO target USING (...) AS source ON (...) WHEN MATCHED THEN
    UPDATE ... WHEN NOT MATCHED THEN INSERT ...`), not a clause appended to
    `INSERT`, so it doesn't fit `getUpsertSql(string $sql, ...)`'s
    rewrite-an-existing-string shape — got its own hook,
    `DBAdapter::usesMergeUpsert()`/`getMergeUpsertSql()`, which builds the
    whole statement from scratch instead. The target table is deliberately
    left un-aliased in the generated SQL (`MERGE INTO book USING (...) s ON
    (book.ID = s.ID) ...`, not `... AS t ... ON (t.ID = s.ID) ...`) because a
    raw `ColumnExpression` in the update clause (e.g. `view_count =
    view_count + 1`, built from the same fully-qualified `book.VIEW_COUNT`
    constant plain `doUpdate()` uses) references the table by its real name,
    which only resolves if that name stays in scope. On MSSQL, an explicit
    primary-key value in the upsert (the common case — the conflict target
    usually *is* the PK) needs the same `IDENTITY_INSERT ON`/`OFF` wrapping
    `doInsert()` already does for `allowPkInsert`, now added to `doUpsert()`
    too — and, since that explicit value permanently advances SQL Server's
    internal identity counter even after the row is later deleted, this
    surfaced a real test-isolation hazard: literal high test IDs shared
    across upsert tests could leak into an unrelated later auto-increment
    insert's generated id (confirmed against a live `azure-sql-edge`
    container while implementing this; the tests now reseed the identity
    counter back down afterward). Verified against live MSSQL
    (`azure-sql-edge`) and Oracle testcontainers, plus dedicated
    SQL-string-shape unit tests (`DBMSSQLTest`, `DBOracleTest`) that need no
    live connection.
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
- [x] **Fold ID retrieval into INSERT** — every platform now folds id
  retrieval into the INSERT itself instead of a separate round trip
  (`lastInsertId()`, or an explicit pre-INSERT `nextval` query), via
  `DBAdapter::supportsInsertReturning()`/`getInsertReturningSql()`/
  `extractInsertedId()` (plus `prepareInsertReturning()` for Oracle's
  different call shape — see below) consulted from `BasePeer::doInsert()`.
  Also unlocked `UPDATE/DELETE ... RETURNING` for affected-row hydration —
  see its own bullet below.
  - [x] MSSQL: `OUTPUT INSERTED.<col>`, replacing its `lastInsertId()` round
    trip (whose behavior varies across the pdo_sqlsrv/pdo_dblib drivers).
  - [x] SQLite: a trailing `RETURNING <col>` clause. Assumed available
    unconditionally (no runtime version probe exists anywhere in the
    codebase — every still-supported PHP version bundles a far newer SQLite
    than the 3.35 (2021) RETURNING was added in); revisit only if that
    assumption ever breaks against an unusually old libsqlite3.
  - [x] Postgres: same trailing `RETURNING <col>` clause. `DBPostgres::getId()`
    (the explicit pre-INSERT `nextval()` query) is kept for any direct caller
    still relying on `isGetIdBeforeInsert()`, but `doInsert()` itself now
    skips straight to `supportsInsertReturning()` and never calls it. This
    *did* need the bigger behavioral change flagged when this was still
    open: the PK column is no longer included explicitly in the INSERT at
    all (relying on the column's own `SERIAL` default) — surfaced a real bug
    while implementing it (see below) and needed
    `BasePeerExceptionsTest::testDoInsert()`'s exact-SQL assertion updated.
  - [x] MariaDB: same trailing `RETURNING <col>` clause (10.5+). There is
    still no separate `MariadbPlatform`/`DBMariadb` (see "MariaDB
    divergences" below) — `DBMySQL::isMariaDb()` detects it at connection
    time instead, via `PDO::ATTR_SERVER_VERSION` (handling the "5.5.5-"
    backward-compatibility prefix some MariaDB builds still report), and
    gates every RETURNING-related hook on that. A `PROPULSION_TEST_DB=mariadb`
    testcontainer path (`test/tools/helpers/IntegrationDatabase.php`) was
    added to verify this against a live `mariadb:11` server — not run in CI.
    Confirmed, and unrelated to this feature: MariaDB has no
    `ONLY_FULL_GROUP_BY`-equivalent default `sql_mode`
    (`ModelCriteriaTest::testMagicGroupBy()` skips itself there now).
  - [x] Oracle: `RETURNING <col> INTO :ret_id`, an OUT bind PDO_OCI populates
    by reference rather than a normal post-execute result set —
    structurally different from every other platform's hook shape, so it
    needed its own `DBAdapter::prepareInsertReturning(PDOStatement, string):
    void` hook (called just before `execute()`; a no-op everywhere else) for
    `DBOracle` to bind `:ret_id` against an instance property, which
    `extractInsertedId()` then reads back. Also needed its own
    `getEmptyInsertSql()` override (Oracle has no `DEFAULT VALUES` syntax at
    all): `INSERT INTO t (id) VALUES (NULL) RETURNING id INTO :ret_id`, an
    explicit NULL satisfying the same `BEFORE INSERT ... WHEN (new.id IS
    NULL)` trigger condition that omitting the column from a non-empty
    column list already relies on. Verified against a live `gvenzl/oracle-free`
    container.
  - A real, only-live-instance-catchable bug surfaced implementing the
    Postgres/Oracle cases above: omitting the PK column from a no-longer-
    pre-fetched INSERT only works if it's *actually* omitted — but a plain
    auto-increment table's generated code leaves an explicit `NULL` entry in
    the `Criteria` whenever `supportsInsertNullPk()` is true (Postgres/Oracle
    both are), previously overwritten by the pre-fetch branch's
    `$criteria->add($pk, $id)` before the INSERT ever ran. Skipping that
    branch for `$useInsertReturning` left the explicit `NULL` in place,
    which Postgres's `NOT NULL` `SERIAL` column (explicit `NULL` bypasses a
    column default, unlike omission) and Oracle's trigger (which only fires
    `WHEN (new.id IS NULL)`, though an explicit `NULL` insert satisfies that
    too, so this specific failure mode was Postgres-only in practice)
    rejected outright. Fixed by broadening the existing null-PK-stripping
    check in `BasePeer::doInsert()` to also strip whenever
    `$useInsertReturning` is true, not just when `!supportsInsertNullPk()`
    (MSSQL's original reason for that check). MySQL/MariaDB's
    `AUTO_INCREMENT` and SQLite's `INTEGER PRIMARY KEY` both already
    special-case an explicit `NULL` as "generate one", so this never bit
    them either way.
- [x] **`UPDATE/DELETE ... RETURNING` for affected-row hydration** —
  `BasePeer::doUpdate()`/`doDelete()` take an optional trailing
  `?array $returningColumns` parameter; when given, return the affected/
  removed rows themselves (each an associative array) instead of a plain
  count, via the new `DBAdapter::supportsRowReturning()`/
  `getUpdateReturningSql()`/`getDeleteReturningSql()` hooks (the same
  "rewrite the complete, already-built statement" shape `getUpsertSql()`/
  `getInsertReturningSql()` already use). Implemented for Postgres, SQLite,
  MariaDB (trailing `RETURNING col, ...`, gated on `DBMySQL::isMariaDb()`
  the same as the INSERT case above) and MSSQL (`OUTPUT INSERTED.col`/
  `OUTPUT DELETED.col`, regex-spliced into the statement between SET/FROM
  and FROM/WHERE respectively, the same splice-into-a-built-string approach
  MSSQL's own `getInsertReturningSql()`/upsert `MERGE` building already
  use). **Not implemented for Oracle** — `RETURNING ... INTO` only populates
  a scalar OUT bind per statement; returning more than one affected row
  needs `BULK COLLECT INTO` array binds instead, a materially different and
  larger mechanism than the single-row `INSERT`'s OUT bind above, deferred
  rather than attempted here. `ModelCriteria`'s own `update()`/`BasePeer`-level
  callers don't yet have a convenience wrapper exposing this — only the
  `BasePeer::doUpdate()`/`doDelete()` primitives do.
- [x] **Common table expressions** — `Criteria::withCte(string $name, Criteria
  $query, array $columns = [], bool $recursive = false)` prefixes a `WITH name
  AS (<$query>)` (or `WITH RECURSIVE name AS (...)` when `$recursive`) onto
  this query, via `BasePeer::createCommonTableExpressionSql()`, consulted
  first in `createSelectSql()` (ahead of the plain-SELECT/set-operation
  branches, which the CTE'd body then falls straight through to via an
  ordinary recursive `createSelectSql()` call on a clone with the CTE list
  cleared — the same "clone, strip, recurse" shape `createSetOperationSql()`
  already uses for `UNION`/etc., and composable with it: a CTE's own body may
  itself carry set operations). `$name` is resolved purely by string
  identity, the same way a real table name already is — `setPrimaryTableName('name')`/
  `addJoin()`/a plain `"name.column"` passed to `where()`/`addSelectColumn()`
  all already work against a CTE name with no new "reference a CTE" API
  needed, since `createSelectSql()`'s `FROM`-clause construction was never
  restricted to only known `DatabaseMap` tables in the first place. A
  recursive CTE's `$columns` is **required** (throws otherwise) rather than
  left to per-platform inference: Postgres/MySQL/MariaDB/SQLite can infer a
  recursive CTE's column list from its anchor branch's own `SELECT`, but
  Oracle cannot, so an explicit list is required uniformly rather than only
  on the one platform that strictly needs it (a recursive CTE's own
  recursive branch also needs `$name`'s columns to already be known to
  reference them, before `$query` itself can even be built — see
  `withCte()`'s own docblock). New `DBAdapter::supportsRecursiveCteKeyword()`
  hook (default `true`) gates the literal `RECURSIVE` keyword itself:
  Postgres/MySQL/MariaDB/SQLite all require it on a recursive CTE, but MSSQL
  rejects it outright and Oracle has never needed it (has supported a
  self-referencing plain `WITH` since 11gR2) — both override the hook to
  `false`. `ModelCriteria::useCteQuery(string $name, string $modelName,
  callable $callback, array $columns = [], ?string $modelAlias = null)` adds
  a closure-scoped convenience for the common non-recursive case, mirroring
  `useExistsQuery()`/`useInQuery()`'s own style — a recursive CTE's query
  (anchor `unionAll()` a self-referencing recursive branch) doesn't fit a
  single `$modelName`'s worth of subquery, so it stays a plain `Criteria`
  built by hand and passed to `withCte()` directly. Recursive adjacency-list
  tree queries as a `NestedSetBehavior` alternative (this item's own original
  motivation) are unlocked but not themselves implemented — no new
  behavior/query-builder wrapper for a tree walk ships here, just the
  primitive that would make one possible. Covered by
  `CommonTableExpressionTest`.
- [x] **Window functions** — new `Propulsion\Query\WindowExpression`, a
  fluent `<function>(...) OVER (PARTITION BY ... ORDER BY ... <frame>)`
  builder (`WindowExpression::rowNumber()`/`rank()`/`denseRank()`/
  `percentRank()`/`ntile()`/`lag()`/`lead()`/`firstValue()`/`lastValue()`/
  `sum()`/`avg()`/`count()`/`min()`/`max()`/`raw()` for anything else,
  chained with `partitionBy()`/`orderBy()`/`rowsBetween()`/`rangeBetween()`),
  replacing the "smuggle one in as a raw string" escape hatch with real
  ergonomics on top of the same mechanism — `ModelCriteria::withColumn()` now
  accepts `string|WindowExpression` (converted to its SQL string form via
  `__toString()` before the existing `Model.Column` name-replacement pass
  runs, so `WindowExpression`'s own column arguments get the same
  `replaceNames()` treatment a raw string clause already did). No new
  `Criteria`/`BasePeer` plumbing was needed beyond that — a window function
  is syntactically just another `SELECT`-list expression, which
  `withColumn()`/`addAsColumn()` already had a general-purpose slot for; this
  item was purely about not hand-writing the `OVER` clause as a string
  anymore. `ROW_NUMBER() OVER (...)` replacing the legacy `applyLimit()`
  rewriters this item's own original text flagged (MSSQL, Oracle) turned out
  to be moot — both were already modernized to native `OFFSET ... FETCH`
  independently of this (see MSSQL/Oracle's own per-platform sections below),
  so there was nothing left to delete. Covered by `WindowExpressionTest`.
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
    `bulkLoad()` and `BasePeer::doBulkInsert()`. Postgres uses
    `\Pdo\Pgsql::copyFromArray()` (real `COPY FROM STDIN`, no temp file
    needed); MySQL uses `LOAD DATA LOCAL INFILE` against a temp file (no
    rows-array variant exists for MySQL the way Postgres has one), which
    needs the connection created with `Pdo\Mysql::ATTR_LOCAL_INFILE`
    enabled (can't be toggled after connecting) *and* the server's
    `local_infile` global variable set to 1 (defaults OFF on stock MySQL
    8+) -- `bulkLoad()` throws a clear, actionable error up front if the
    connection-side half isn't set, rather than surfacing MySQL's own less
    obvious error.

    Both use the driver-specific `\Pdo\*` API rather than the
    bolted-on-`\PDO` spellings (`PDO::pgsqlCopyFromArray()`,
    `PDO::MYSQL_ATTR_LOCAL_INFILE`) that PHP 8.5 deprecates or has already
    removed. That is possible because `PropulsionPDO` is an interface and
    every connection Propulsion constructs is the matching driver-specific
    subclass (`PgsqlPropulsionPDO extends \Pdo\Pgsql`,
    `MysqlPropulsionPDO extends \Pdo\Mysql`), so no deprecation notice is
    emitted on either path.
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

- [x] **Native enum types at the DDL level** — opt-in via `nativeEnum="true"`
  on an ENUM column (`Column::isNativeEnum()`/`setNativeEnum()`), independent
  of `enumClass` (either, neither, or both may be set). Postgres emits a real
  `CREATE TYPE <table>_<column>_enum AS ENUM (...)` before the table (and
  `DROP TYPE IF EXISTS` after dropping it) via
  `PgsqlPlatform::getAddEnumTypesDDL()`/`getDropEnumTypesDDL()`, wired into
  `getAddTablesDDL()`; MySQL emits inline `ENUM('a', 'b', ...)`
  (`MysqlPlatform::getEnumSqlType()`); SQLite/Oracle stay on the emulated
  text/int domain but gain a `CHECK (col IN (...))` constraint
  (`DefaultPlatform::getEnumCheckConstraintDDL()`, gated by the new
  `supportsNativeEnumDDL()`/`usesNativeEnumStorage()` platform hooks); MSSQL
  has no native enum mechanism and, per the original scoping here, doesn't
  get the emulated CHECK either (`MssqlPlatform::supportsNativeEnumDDL()`
  returns `false`, the one platform override) — `nativeEnum` is silently a
  no-op there. A native-storage column holds the label text itself, not the
  emulated integer index — `DefaultPlatform::getColumnDefaultValueDDL()`
  writes the quoted label as the DDL default, `ColumnMap::getPdoType()` binds
  it as `PDO::PARAM_STR` instead of the emulated column's `PARAM_INT`
  (`TableMapBuilder` now emits `setNativeEnum(true)` into the generated
  `TableMap` for this), and `ObjectBuilder`'s `addHydrate()`/
  `addBuildCriteria()`/`addBuildPkeyCriteria()` convert between the
  in-memory representation (still the emulated index for a plain ENUM
  property, or the enum instance for `enumClass`) and the label text at the
  DB boundary, instead of passing the index straight through.
- [x] **Native PHP enum mapping** — a column's `enumClass` attribute names a
  backed PHP `enum` (Doctrine `enumType`/Laravel-cast style); the generated
  property/getter/setter/hydrate/`buildCriteria()` work with the enum
  instance directly instead of the raw label string (`Column::hasEnumClass()`
  /`getEnumClass()`, `ObjectBuilder::getEnumShortName()` and the
  `hasEnumClass()` branches in `getPhp84PropertyType()`/`getPhp84TypeHint()`/
  `addEnumAccessor()`/`addEnumMutator()`/`addHydrate()`/`addBuildCriteria()`/
  `addBuildPkeyCriteria()`/`getDefaultValueString()`). `valueSet` is derived
  from the enum's own case values at parse time (`Column::setAttributes()`)
  rather than hand-duplicated in the schema, so the two can't drift. Storage
  representation is unchanged (still the emulated integer index — native DDL
  is the separate item below); `copyInto()` needed no change since it already
  round-trips enum columns through the getter/setter. Orthogonal to the DDL
  item above — either can land first; this one did.
- [x] **`DECIMAL`/`NUMERIC` → `BcMath\Number`** (PHP 8.4+) instead of
  `string`. Opt-in, reusing the existing generic `phpType` column attribute
  (already there for exactly this kind of override, no new schema syntax
  needed) — `phpType="\BcMath\Number"` on any column (`Column::isBcMathNumberType()`
  detects it, tolerating a missing leading backslash). Property/getter/setter
  are typed `?Number`; the mutator additionally accepts
  `string|int|float|null` and normalizes to `Number` (`ObjectBuilder::addColumnMutator()`'s
  new `isBcMathNumberType()` branch), matching the existing temporal/boolean
  mutators' style of accepting a wider input union than the strict property
  type. `addHydrate()` needed its own branch *before* the existing
  `isNumericType()` dispatch, which builds a `($castType) $v` cast from
  `getPhpType()` — `(BcMath\Number) $v` isn't legal cast syntax, so falling
  through to it would have been a generated syntax error, not just a wrong
  value; the same hazard existed in the primary-key `getColumnValueCastExpr()`
  helper `fromArray()`/`isPrimaryKeyNull()` share, fixed the same way.
  `addBuildCriteria()`/`addBuildPkeyCriteria()` cast back to `(string)` for
  the DB layer. Property declarations default a `BcMath\Number` column to
  `NULL` the same way temporal/enum columns already do (PHP rejects `new
  Number(...)` — or any `new` expression — as a *property declaration*
  default, unlike a parameter default; confirmed by hitting exactly that
  fatal error implementing this), with the real default applied via
  `applyDefaultValues()` in the constructor instead, where a plain assignment
  is allowed. `hasOnlyDefaultValues()` compares via `!=` rather than `!==`
  for the same reason the enum branch already does: a freshly-constructed
  `Number` default is never `===` the stored instance even when equal in
  value, but `BcMath\Number` supports the loose/value comparison operators.
- [x] **Vector types** — DDL + hydration only, as scoped: `vector(n)` native
  on Postgres (pgvector; `CREATE EXTENSION IF NOT EXISTS vector` emitted the
  same way `citext`'s extension is, generalized into a single
  `getAddExtensionsDDL()` covering both), emulated as unbounded text (not a
  sized `VARCHAR` — an embedding vector's JSON-encoded text can be long) on
  every other platform, **including MySQL/MariaDB despite both having a real
  native `VECTOR(n)` type** (MariaDB 11.7+/MySQL 9.0+) — live-verified against
  a real MariaDB 11.8 server that its native `VECTOR` column rejects a plain
  bound/literal bracket-JSON string outright ("Incorrect vector value"): both
  engines require `VEC_FromText()`/`VEC_ToText()` (MariaDB) or
  `STRING_TO_VECTOR()`/`VECTOR_TO_STRING()` (MySQL) wrapped around the value
  at the SQL level, the same "needs query-layer SQL-rewriting a plain bind
  can't do" problem GEOMETRY's own comment already flags — this codebase has
  no hook anywhere to wrap a column's SQL like that yet (see BasePeer/
  Criteria). The originally-shipped `VECTOR(n)`-native MySQL/MariaDB mapping
  was accordingly **downgraded to the same text emulation** every other
  non-Postgres platform already uses (`MysqlPlatform::initialize()`'s VECTOR
  domain mapping, `hasSize()` updated alongside it so the emulated TEXT column
  doesn't pick up a spurious size suffix) — a real bug caught by this live
  verification pass, not a design change; see `MariaDB divergences` below for
  what MySQL-family VECTOR support would need to go native for real.
  Dimension reuses the existing `size` column attribute (`size="1536"`)
  rather than a new one, flowing through the same `printSize()`/`hasSize()`
  machinery `VARCHAR(n)` already does. Hydrates to/from a plain
  `array<float>`; a vector's wire format (pgvector's own output, and this
  codebase's text/JSON emulation elsewhere) is a bracketed comma-separated
  number list, which is already valid JSON, so `ObjectBuilder`'s
  `isVectorType()` branches reuse `BaseObject::decodeJsonColumn()`/
  `encodeJsonColumn()` rather than `PHP_ARRAY`'s `" | "`-delimited format or a
  new helper. Postgres's own pgvector mapping (confirmed live: round-trip,
  `<->` distance operator) has no such problem — pgvector accepts/returns
  plain bracket-JSON text through an ordinary parameterized bind, unlike
  MariaDB/MySQL's own native type. `<=>`/`<->` distance operators and
  HNSW/IVFFlat index support are explicitly out of scope here (section 1
  query-layer work, as this bullet originally noted) — this item is DDL +
  hydration only.
- [x] **Pg range types** (`tstzrange` et al.) — `int4range`/`int8range`/
  `numrange`/`daterange`/`tsrange`/`tstzrange`, native on Postgres, emulated
  as a `VARCHAR(64)` storing the range literal text (`"[1,10)"`) elsewhere —
  the first item in this section needing a real "rich value object" beyond
  `DateTimeInterface`/`DateInterval`, since range bounds have no fixed PHP
  type (an `int4range`'s bounds are ints, a `daterange`'s are dates, etc.).
  New `Propulsion\Type\Range` (`runtime/Lib/Type/Range.php`) keeps bounds as
  raw strings rather than guessing a subtype-specific PHP type, with
  `Range::parse()`/`__toString()` round-tripping Postgres's bracket notation
  (`[1,10)`, `(,5]`, `empty`) including its escaped-double-quote form for
  bound values containing a comma. `ObjectBuilder`'s `isRangeType()` branches
  mirror the `INTERVAL` item's shape exactly (property/getter/setter typed
  `?Range`; `applyDefaultValues()`/`getColumnValueCastExpr()` needing their
  own branches for the same "can't be a property-declaration default"/
  "would build an invalid cast" reasons `DateInterval` and `BcMath\Number`
  already did). Query-layer range operators (`&&`, `@>`) and the
  `EXCLUDE USING gist` exclusion-constraint companion feature stay out of
  scope here, tracked separately under PostgreSQL DDL parity/section 1.
- [x] **Pg network types** (`inet`, `cidr`, `macaddr`) and `citext`. New
  `PropulsionTypes::INET`/`CIDR`/`MACADDR`/`CITEXT`, native on Postgres,
  emulated as a sized `VARCHAR` (`TEXT`/`MEDIUMTEXT`/`NVARCHAR2` for `citext`,
  matching each platform's own `LONGVARCHAR` convention) everywhere else. No
  rich PHP value object for v1 -- hydrate as plain strings the same way
  `UUID` already does, which meant zero `ObjectBuilder` changes were needed
  at all (the existing generic accessor/mutator/hydrate/`buildCriteria()`
  paths already handle a plain-string-native type correctly; this item is
  pure `PropulsionTypes`/`Platform::initialize()`/`PgsqlSchemaParser` plumbing).
  `citext` ships as a Postgres contrib extension, not a built-in type, so
  `PgsqlPlatform::getAddExtensionsDDL()` emits `CREATE EXTENSION IF NOT
  EXISTS citext` before a table, but only when one of its columns actually
  uses it.
- [x] **`INTERVAL` → `DateInterval`** — new `PropulsionTypes::INTERVAL`,
  native on Postgres (`interval` column type; `PgsqlSchemaParser`'s
  `'interval'` type-map entry now actually resolves, activating what was
  previously dead code in its typmod-length parsing branch) and emulated as
  `VARCHAR(32)` everywhere else, storing an ISO-8601 duration string
  (`"P1DT2H"`) on every platform uniformly. Postgres's *default*
  `intervalstyle` GUC doesn't output ISO-8601 (e.g. `"1 day 02:03:04"`), so
  rather than fuzzy-parsing that on read, `DBPostgres::initConnection()` now
  forces `SET intervalstyle = 'iso_8601'` on every new connection (same
  `initConnection()` hook `DBOracle` already uses for its own session-level
  `NLS_DATE_FORMAT` setup), making the wire format identical across every
  platform and letting `ObjectBuilder`'s generated code stay a single
  `new DateInterval($v)` (hydrate) / `$this->prop->format('P%yY%mM%dDT%hH%iM%sS')`
  (buildCriteria) pair with no platform branching at all. Live-verified
  against a real Postgres 17 server: `SHOW intervalstyle` reads back
  `iso_8601` after `DBPostgres::initConnection()` runs, and an
  `INTERVAL` column round-trips through `new DateInterval($v)` correctly.
  Property declarations default to `NULL` and the real default is applied
  via `applyDefaultValues()` as `new DateInterval(...)`, the same
  can't-be-a-property-declaration-default story as `DateTime`/`BcMath\Number`
  above. `getColumnValueCastExpr()` (shared by `fromArray()`/
  `isPrimaryKeyNull()`) needed its own branch for the same reason `BcMath\Number`
  did — falling through to the generic numeric/string cast would have built
  invalid `(DateInterval) $expr` PHP.
- [x] **Spatial types as a first-class type**, not just the MySQL-only DDL
  bullet below — new `PropulsionTypes::GEOMETRY`, deliberately emulated as
  plain text (storing WKT, "well-known text", e.g. `"POINT(1 2)"`) on
  *every* platform, including Postgres/MySQL/MSSQL, rather than attempting
  each one's real native geometry column type (PostGIS `geometry`, MySQL
  `GEOMETRY`, MSSQL `geometry`, Oracle `SDO_GEOMETRY`). This is a narrower
  scope than every other item in this section shipped with a "native"
  mapping for, for a real reason found while implementing it: unlike UUID/
  JSON/`vector`/range types, none of those four native geometry types
  accept or return raw WKT text through a plain parameterized bind — each
  needs the bound value wrapped in a platform-specific conversion function
  (`ST_GeomFromText()`/`STGeomFromText()`/`SDO_UTIL.FROM_WKTGEOMETRY()`) at
  the SQL-statement level to write, and a matching `ST_AsText()`-equivalent
  wrapped around the column in the `SELECT` list to read back as text at
  all — genuinely a query-layer change (`BasePeer`/`Criteria` needing to
  rewrite specific columns' SQL, not just bind a value), not a type-system
  one. Shipping a "native" mapping without that would have produced
  silently broken round-trips. WKT-parsing into a rich geometry value
  object, and the native-column-plus-query-layer-rewriting work this bullet
  originally also implied, are the natural v2 follow-ups — flagged the same
  way `vector`'s distance operators are flagged as deferred, not silently
  dropped.
- [ ] **Custom type registry** — a user-extensible type/converter layer
  (Doctrine's `Type` registry, SQLAlchemy `TypeDecorator`). Would let most of
  the items above ship as plugins rather than as new `PropulsionTypes`
  constants, and covers value objects / embeddables at the same time.
  Preparatory cleanup done as of 2026-07-29: the type system used to be
  wired through *two* hand-duplicated hardcoded maps
  (`generator/Lib/Model/PropulsionTypes.php` and the runtime's own
  `PropulsionColumnTypes`, which had already drifted out of sync — CLOB and
  BIGINT had different PDO param types in each copy, a real latent bug).
  `runtime/Lib/Util/PropulsionColumnTypes.php` is deleted; `ColumnMap`/
  `DBAdapter`/`DBOracle` now reference the generator's `PropulsionTypes`
  directly (both PSR-4 roots live in the same Composer package, so this was
  a same-package reference, not a new dependency), with the legacy
  `PropelColumnTypes`/`PropulsionColumnTypes` bare-name aliases in
  `legacy-class-map.php` repointed accordingly. This is *not* the
  user-extensible registry itself (still not started) — just removing the
  duplication every new type in this section would otherwise have had to
  touch twice.

## 3. Per-platform DDL & adapter parity

### Cross-cutting

- [ ] Table partitioning — unsupported on any platform. Classic table
  inheritance (`INHERITS`) shipped for Postgres specifically; see that
  platform's own section below.

### PostgreSQL (16/17/18)

- [x] **`GENERATED ... AS IDENTITY`** (PG10+ standard syntax) — opt-in via a
  new `identity="true"` column attribute (`Column::isIdentity()`/
  `setIdentity()`), independent of `autoIncrement` the same way `nativeEnum`
  is independent of `enumClass`. `PgsqlPlatform::getColumnDDL()` emits the
  column's real mapped type (`INT4`/`INT8`, not the `serial`/`bigserial`
  pseudo-type) followed by `GENERATED BY DEFAULT AS IDENTITY` when both
  `autoIncrement` and `identity` are set and the table has no explicit
  `idMethodParameters` (a named sequence, same guard the existing
  serial/bigserial branch already used). Left opt-in rather than the new
  default: countless existing schemas/tests assert the exact `serial`/
  `bigserial` DDL string, and Postgres's implicit sequence naming
  (`<table>_<column>_seq`) is identical either way, so nothing downstream
  (`getSequenceName()`, `RETURNING`-based id retrieval) needed to change.
  `getModifyColumnDDL()`'s TYPE-change branch also checks the flag so an
  identity column's real type isn't overwritten with `serial`/`bigserial` on
  a size/scale ALTER — converting an existing column's identity-ness itself
  via `ALTER COLUMN ... ADD/DROP GENERATED AS IDENTITY` is a structurally
  different statement from a plain `ALTER COLUMN ... TYPE`, and is not
  attempted here (matches the existing serial/bigserial migration path's own
  limitations, not a new gap).
- [x] **Native array types** — opt-in via a new `nativeArray="true"` column
  attribute (`Column::isNativeArray()`/`setNativeArray()`), independent of
  `PgsqlPlatform::initialize()`'s default `PHP_ARRAY` → `TEXT` domain mapping
  the same way `nativeEnum`/`identity` are independent of their own base
  type. Deliberately **not** made the default: this is a breaking wire-format
  change for any column that already has data, and this codebase has at
  least one known real consumer (a separate .NET reader) that parses the
  existing `" | "`-delimited emulated format directly off the raw column,
  not through this ORM -- flipping the default would silently break that
  reader with no compile-time signal on either side.
  - `PgsqlPlatform::getColumnDDL()`/`getModifyColumnDDL()` emit a plain
    `TEXT[]` (not a typed `int[]`/etc. -- a generic PHP_ARRAY column has no
    declared element type to native-map, the same "no rich subtype" call
    `INET`/`CIDR`/`MACADDR` already made) when `nativeArray` is set, gated
    by a new `Platform::supportsNativeArrayDDL()` hook (declared on
    `PropulsionPlatformInterface` itself, unlike `supportsNativeEnumDDL()` --
    see below for why) that only `PgsqlPlatform` overrides to `true`; every
    other platform stays on the emulated `TEXT` + `" | "` format regardless
    of the attribute, so a schema authored with `nativeArray="true"` but
    built against MySQL/SQLite/MSSQL/Oracle is unaffected.
  - New `Propulsion\Type\PgArray` (`runtime/Lib/Type/PgArray.php`):
    `encode()`/`decode()` for Postgres's one-dimensional array literal
    syntax (`{a,"b,c",NULL}`), handling quoting/escaping of
    commas/braces/quotes/backslashes/whitespace/empty-string/the literal
    word `NULL`, and distinguishing an unquoted `NULL` (the SQL null) from a
    quoted `"NULL"` (the four-character string). Elements are kept as plain
    strings/null, the same per-element-type-agnostic choice `Range` already
    made for its bounds. `ObjectBuilder::addHydrate()`/`addBuildCriteria()`/
    `addBuildPkeyCriteria()` and `QueryBuilder::addFilterByCol()` switch to
    it via `Column::isNativeArrayStorage()` (type + `nativeArray` + the
    platform capability check combined, added to `Column` rather than left
    as a bare `isNativeArray()` check in each builder -- unlike
    `isNativeEnum()`, whose call sites never actually re-check
    `supportsNativeEnumDDL()`, which would be a real hydration bug on a
    platform where nativeEnum is a no-op if it followed the same pattern;
    not fixed here as out of scope, but not repeated for this new feature
    either). `addFilterByCol()`'s CONTAINS_ALL/SOME/NONE LIKE-based
    containment shortcut (built entirely on the emulated format) isn't
    offered for a native array column -- a plain value is still encoded for
    equality/IN comparisons, but the plural-name `addFilterByArrayCol()`
    convenience method is skipped entirely for a `nativeArrayStorage()`
    column so it can't emit a LIKE comparison against a real `text[]`
    column. Query-layer array operators (`@>`, `&&`, `ANY`) are out of scope
    here, the same "DDL + hydration only" deferral `vector` and `GEOMETRY`
    already used for their own query-layer companions.
  - **Migration is manual and the schema author's responsibility.** Flipping
    `nativeArray="true"` on an existing column with data already written in
    the `" | "`-delimited format requires, in order: (1) stop writing the
    old format (deploy this flag off, or handle both formats at the
    application layer during the transition), (2) `ALTER TABLE ... ALTER
    COLUMN ... TYPE text[] USING string_to_array(trim(both ' | ' from
    col), ' | ')`-style conversion of existing rows (the exact expression
    depends on whether existing values have leading/trailing `" | "`
    delimiters -- see the regex in `ObjectBuilder::addHydrate()`'s legacy
    PHP_ARRAY branch for the two formats actually in the wild), (3) update
    any non-Propulsion consumer reading the raw column (the .NET reader
    above, or direct SQL) to parse Postgres array literal syntax instead of
    splitting on `" | "`. No automated migration tool is provided -- the
    " | " → native transition touches data semantics (element splitting,
    escaping of commas already present inside element values) that only the
    schema author can safely resolve for their own data.
- [x] **Full-text search** — DDL + index support, scoped like `vector`'s own
  "DDL + hydration only" bullet: the `@@`/`to_tsquery()`/`plainto_tsquery()`
  query-layer operators are out of scope here (the existing raw-expression
  escape hatch -- `Criteria::CUSTOM`/a raw `ColumnExpression`, the same one
  `view_count = view_count + 1` uses elsewhere -- already covers querying a
  tsvector column today; see the deferred "JSON-path query helpers"/window
  functions items in section 1 for the general shape a first-class query
  builder for this would take).
  - New `PropulsionTypes::TSVECTOR`, native `tsvector` on Postgres, emulated
    as each platform's own existing long-text fallback everywhere else
    (`TEXT`/`MEDIUMTEXT`/`VARCHAR(MAX)`/`CLOB`, matching `OBJECT`/`GEOMETRY`'s
    per-platform choices exactly) -- hydrated as a plain string (no rich PHP
    value object; a tsvector's own lexeme:position text isn't meant to be
    read back by application code).
  - New `tsvectorFrom="col1, col2"` (+ optional `tsvectorConfig`, default
    `"english"`) column attributes (`Column::getTsvectorSourceColumns()`/
    `getTsvectorConfig()`) auto-populate a TSVECTOR column via a real
    `GENERATED ALWAYS AS (to_tsvector(...)) STORED` column (PG12+, well
    within this codebase's PG16+ floor) instead of the more common
    trigger-based approach -- `PgsqlPlatform::getColumnDDL()` builds
    `to_tsvector('config', coalesce("col1", '') || ' ' || coalesce("col2",
    ''))` from the listed source columns. Postgres-only, the same
    "unhonored elsewhere" story `identity`/`nativeArray` already have: an
    ordinary, manually-populated TSVECTOR column (no `tsvectorFrom`) is the
    fallback on every platform including Postgres. A generated column can't
    also carry a `DEFAULT`, so this branch is mutually exclusive with the
    column's own default-value DDL, mirroring how the `identity` branch
    already excludes it.
  - **New `Index::getIndexType()`/`setIndexType()`** (`indexType="gin"`
    schema attribute) -- a real model-level index-access-method flag, filling
    the gap this document's own MySQL section flagged ("no model-level flag
    e.g. `Index::isFulltext()`"), generalized to any access method rather
    than a single boolean (GIN is the one FTS needs, but the same mechanism
    covers GiST/BRIN/hash too). `PgsqlPlatform::getAddIndexDDL()` is
    overridden to splice in `USING <type>` (`CREATE INDEX name ON table
    USING gin (col);`); every other platform is untouched (null `indexType`
    -- the default -- reproduces the exact prior DDL unchanged, verified by
    a regression test asserting the unset case).
  - Live-verified against a real Postgres 17 server: a `tsvectorFrom`-generated
    column actually computes real lexeme data (`@@ to_tsquery(...)` matched
    correctly), and `EXPLAIN` confirmed the `USING gin` index is genuinely
    selected by the planner over a sequential scan, not just accepted as DDL.
    DDL shape and schema-attribute parsing are additionally covered by
    unit/string-shape tests (`PgsqlPlatformTest`, `ColumnTest`,
    `PgsqlFullTextSearchDDLTest`) that need no live connection.
- [x] **Partial and expression indexes** — `Index` gained a `where="..."`
  attribute (`getWhereClause()`/`setWhereClause()`) for `CREATE INDEX ...
  WHERE ...`, and `<index-column>` now accepts `expression="..."` as an
  alternative to `name` (`Index::isExpressionAtPosition()` tracks which
  column-list entries are raw SQL rather than a plain column name).
  `PgsqlPlatform::getIndexColumnListDDL()` quotes plain names as identifiers
  as before but emits an expression entry verbatim, wrapped in an extra pair
  of parentheses (always valid, and required for e.g. `a || b` even though
  redundant for a bare function call like `lower(title)`). Only honored by
  PgsqlPlatform; the `expression`/`where` attributes are ignored by every
  other platform's `Index` handling.
- [x] **Exclusion constraints** — new `Exclusion` model class (mirrors
  `Unique`'s shape, but each column pairs a name with its own comparison
  operator instead of just a name, since `EXCLUDE USING gist (col1 WITH
  operator1, ...)` needs one). Wired in as a table child element
  (`<exclusion name="..."><exclusion-column name="..."
  operator="..."/></exclusion>`) through `Table::addExclusion()`/
  `getExclusions()` and the schema XML parser
  (`XmlToAppData`'s `exclusion`/`exclusion-column` tag handling), emitted as
  an inline `CONSTRAINT ... EXCLUDE USING <method> (...) [WHERE (...)]`
  fragment alongside `UNIQUE`/`PRIMARY KEY` inside `CREATE TABLE` by
  `PgsqlPlatform::getExclusionDDL()`. Defaults to `gist` (the access method
  with the widest operator-class support, and what every exclusion-
  constraint example in Postgres's own docs uses), overridable via
  `indexType`. Only honored by PgsqlPlatform -- an `<exclusion>` element is
  silently a no-op on every other platform (no table-level hook consults
  `Table::getExclusions()` there).
- [x] **Index storage parameters, `CONCURRENTLY`, covering indexes** — three
  independent, purely-additive `Index` attributes, all only honored by
  PgsqlPlatform: `storageParameters="fillfactor=70"` (raw text emitted
  verbatim inside a trailing `WITH (...)` clause -- deliberately not parsed
  into individual key/value pairs, since Postgres has dozens of
  access-method-specific storage parameters and this avoids needing to know
  about each one), `include="col1,col2"` (`INCLUDE (...)`, non-key
  "covering" columns for index-only scans), and `concurrently="true"`
  (`CREATE INDEX CONCURRENTLY`). The last one carries a real caveat, noted
  on `Index::isConcurrent()`'s docblock and the XSD: Postgres rejects
  `CREATE INDEX CONCURRENTLY` inside a transaction block, and this generator
  doesn't wrap its own DDL output in one (`PgsqlPlatform::getBeginDDL()`/
  `getEndDDL()` are both empty strings, unlike some other platforms) -- but
  a caller piping the generated SQL through a migration tool that wraps
  everything in one transaction would need to run that statement
  separately. All four `USING`/`WHERE`/`INCLUDE`/`WITH`/`CONCURRENTLY`
  clauses compose freely in `PgsqlPlatform::getAddIndexDDL()`'s single
  `sprintf()` pattern.
- [x] **Classic table inheritance** (`INHERITS`) — new `inheritsFrom="parent"`
  table attribute (`Table::getInheritsFrom()`/`setInheritsFrom()`).
  `PgsqlPlatform::getAddTableDDL()` appends ` INHERITS (parent)` after the
  column-list's closing parenthesis when set. A child table still needs its
  own primary key declared explicitly (this generator requires every table
  to have one; Postgres's `INHERITS` doesn't propagate the parent's PK
  constraint to the child on its own, only its columns), and a child
  redeclaring a same-named column as its parent is the schema author's
  responsibility to get right (Postgres merges compatible re-declarations,
  but this generator doesn't validate that). Only honored by PgsqlPlatform;
  ignored elsewhere -- no other supported platform has an equivalent
  mechanism.
- [ ] **Declarative partitioning** (`PARTITION BY`) remains unimplemented --
  out of scope here (see the classic-inheritance bullet below for what did
  ship instead). Partitioning needs partition-bound syntax
  (`FOR VALUES FROM (...) TO (...)`/`FOR VALUES IN (...)`/`FOR VALUES WITH
  (MODULUS ..., REMAINDER ...)`) and a parent/child table relationship
  structurally different from a plain foreign key or the `INHERITS` bullet's
  single `inheritsFrom` attribute -- each partition is its own `CREATE
  TABLE ... PARTITION OF parent FOR VALUES ...` statement, needing a new
  child-table concept beyond what `Table`/`Database` model today. A
  meaningfully larger effort than every other item in this section;
  deliberately deferred rather than attempted partially.
- [ ] `LISTEN`/`NOTIFY` exposure on the adapter (low priority, but nothing
  else gives event-driven invalidation for the query result cache).

### MySQL / MariaDB

- [x] **Native `ENUM`/`SET` types** — native ENUM already shipped (see the
  type-system section's `nativeEnum` entry); the `SET` gap is now closed
  too. New `PropulsionTypes::SET`, reusing the same `valueSet` schema
  attribute ENUM already has (no new schema syntax needed for the
  vocabulary itself). Unlike ENUM, this needed no opt-in flag: MySQL always
  emits the real inline `SET('a', 'b', ...)` column type
  (`MysqlPlatform::getSetSqlType()`), and every other platform emulates it
  as a comma-joined string in a plain long-text column
  (`TEXT`/`MEDIUMTEXT`/`VARCHAR(MAX)`/`CLOB`, the same per-platform choices
  `OBJECT`/`TSVECTOR` already made). The reason no flag was needed: unlike
  ENUM's real behavioral choice (a compact emulated integer index vs. the
  label text itself), a SET column can have several labels selected at
  once, so a comma-joined string of labels is the *only* sane
  representation off of MySQL -- there's no meaningful "emulated index"
  alternative to opt out of. This also means the wire format is identical
  whether the column is MySQL's real native `SET` or another platform's
  emulated text (PDO returns/accepts a SET value as a single comma-joined
  string either way, no special array binding involved), so
  `Column::isSetType()`'s `ObjectBuilder`/`QueryBuilder` hydrate/
  `buildCriteria()`/filter branches need no platform branching at all --
  contrast `PHP_ARRAY`'s native-vs-emulated split. Hydrates to/from
  `array<string>`; plural-named SET columns get the same
  has/add/remove-element convenience methods `PHP_ARRAY` columns with a
  plural name already do.
- [x] **Generated/virtual columns** (`GENERATED ALWAYS AS (...) VIRTUAL|STORED`)
  — reuses the same platform-generic `generatedAs`/`generatedType` `Column`
  attributes SqlitePlatform's own generated-column support introduced (and
  MssqlPlatform's computed columns already reuse), via a new
  `MysqlPlatform::getGeneratedColumnDDL()`. MySQL's grammar turned out closer
  to SQLite's than MSSQL's: `GENERATED ALWAYS AS (expr) VIRTUAL|STORED` is
  spelled out in full either way (unlike MSSQL's bare `AS (expr)` with
  `PERSISTED` only for the stored case), and `NOT NULL` is legal on both
  `VIRTUAL` and `STORED` generated columns (unlike MSSQL, which only allows it
  alongside `PERSISTED`) — confirmed live against a real MariaDB 11.8 server:
  a `VIRTUAL` column computed correctly on read, a `STORED` one persisted and
  was queryable, `information_schema.columns.EXTRA` reported the correct
  `VIRTUAL GENERATED`/`STORED GENERATED`, and a plain `INSERT` naming the
  generated column directly was genuinely rejected by the server (proving it
  really is generated, not just a convenience default). Covered by new
  `MysqlPlatformTest` DDL-shape assertions (virtual, stored, and `NOT NULL`
  on a virtual column).
- [x] **Unsigned integer types** — new `unsigned="true"`/`zerofill="true"`
  column attributes (`Column::isUnsigned()`/`isZerofill()`), only honored by
  MysqlPlatform and only for a numeric column there (silently ignored
  otherwise, e.g. on a text column or any other platform).
  `MysqlPlatform::getColumnDDL()` appends `UNSIGNED`/`UNSIGNED ZEROFILL`
  right after the type/size (`ZEROFILL` implies `UNSIGNED` in MySQL even
  without the `unsigned` attribute also set, matched here). No new schema
  syntax needed for `ALTER`-path DDL: `getModifyColumnDDL()` already
  delegates entirely to `getColumnDDL()` via its `CHANGE` clause, so it
  picked this up for free.
- [ ] Spatial types (`GEOMETRY`, `POINT`, ...) and spatial indexes -- out of
  scope here, for the same reason `GEOMETRY`'s own type-system entry above
  deliberately stayed WKT-text-only: MySQL's real native geometry column
  type doesn't accept/return raw WKT text through a plain parameterized
  bind, so shipping it needs the same query-layer SQL-rewriting work
  (`ST_GeomFromText()`-equivalent wrapping) already flagged as a deferred
  v2 follow-up there, not a type-system change.
- [x] **First-class FULLTEXT/SPATIAL index support** — `Index::getIndexType()`
  (already added for Postgres's `USING gin`/`gist`, see that platform's
  own section) is now also consulted by `MysqlPlatform::getIndexType()`,
  ahead of the legacy `<vendor type="mysql"><parameter name="Index_type"
  .../>` convention (kept working as a fallback for any schema still using
  it): `indexType="fulltext"` on an `<index>` now gets a model-level flag
  instead of the vendor-parameter escape hatch this document itself used to
  flag as the gap here. A FULLTEXT/SPATIAL index can't also be `UNIQUE`, so
  `indexType` (or the legacy `Index_type` vendor parameter) takes priority
  over `isUnique()` when both are set, same as before.
- [ ] Partitioning (`PARTITION BY`).
- [x] **Fixed default storage engine** — `MysqlPlatform::$defaultTableEngine`
  and `generator/default.php`'s `propulsion.mysql.tableType` both now
  default to `InnoDB` instead of `MyISAM` (InnoDB has been MySQL's own
  default since 5.5 (2010), and is required for
  `supportsNativeDeleteTrigger()`'s FK-driven `ON DELETE` triggers, which
  MyISAM never supported at all) -- every one of this repo's own test
  fixtures (`test/fixtures/*/build.php`) already explicitly overrode to
  `InnoDB` regardless, confirming the shipped default was stale. `MyISAM`
  is still available via `mysqlTableType`/`propulsion.mysql.tableType` for
  anyone who still needs it. Updated the dozen `MysqlPlatformTest`/
  `MysqlPlatformMigrationTest` DDL-string assertions that had `ENGINE=MyISAM`
  hardcoded.
- [x] **MariaDB divergences: `CREATE SEQUENCE` and native `UUID`.** MariaDB is
  still served by `MysqlPlatform` (generator) / `DBMySQL` (runtime) as though
  it were MySQL — no separate `MariadbPlatform`/`DBMariadb` — and, unlike the
  runtime-only `RETURNING` gate (`DBMySQL::isMariaDb()`, which probes a live
  connection's version string), these two are build-time/DDL-generation
  concerns with no live connection available to auto-detect the real target
  server at all. Both are therefore opt-in `Column`/`Table` attributes (the
  same "schema author's responsibility" convention `identity`/`nativeArray`/
  `rowVersion` already use), defaulting to the current MySQL-safe behavior
  unchanged, rather than gated on any runtime probe:
  - **`nativeSequence="true"` `Table` attribute** — a real MariaDB 10.3+
    `CREATE SEQUENCE %s START WITH 1 INCREMENT BY 1;` for a table's named
    `<id-method-parameter>`, mirroring `MssqlPlatform::getAddSequenceDDL()`
    exactly (same `getSequenceName()` override to look past
    `Table::finalize()`'s NATIVE-downgrade rule) except for the opt-in gate
    itself (MSSQL needs none — every supported SQL Server version has
    sequences; plain MySQL has none at any version) and the value a column
    pulls its next id from, MariaDB's own `NEXTVAL(seq)` function-call syntax
    via the existing `defaultExpr` raw-expression column default (unlike
    Postgres's `nextval('seq')` or MSSQL's `NEXT VALUE FOR seq`). A named
    `<id-method-parameter>` with no `nativeSequence="true"` stays today's
    silent no-op (unchanged, since MySQL itself understands neither).
    `MysqlPlatform::getDropTableDDL()` drops the sequence alongside the table.
  - **`nativeUuid="true"` `Column` attribute** (on a `UUID` column) — MariaDB
    10.7+'s real native `UUID` column type in place of the default CHAR(36)
    emulation every platform (including plain MySQL, which has no native UUID
    type at any version) otherwise uses.
  - Both live-verified end-to-end against a real MariaDB 11.8 server:
    `CREATE SEQUENCE`/`DROP SEQUENCE` objects actually exist and disappear
    correctly, `NEXTVAL(...)` populates a PK on insert with distinct values,
    and a native `UUID` column round-trips a standard hyphenated string via a
    plain parameterized bind with no wrapping needed (confirmed
    `information_schema.columns.DATA_TYPE` reports `uuid`, not `char`) --
    unlike `VECTOR`'s own native type (see that item above), UUID has no
    "needs SQL-level wrapping" problem on MariaDB. Covered by new
    `MysqlPlatformTest` assertions (no-implicit-sequence, named-sequence-
    without-opt-in-stays-a-no-op, native sequence DDL, native UUID DDL,
    emulated-CHAR(36)-by-default).
  - **`VECTOR` gating**, originally scoped here too, turned out moot: live
    verification found MariaDB's (and MySQL's) real `VECTOR` type unusable via
    a plain bind regardless of version, so it was never made native for
    either engine in the first place (see the `Vector types` item above) --
    there is no MySQL/MariaDB divergence left to gate on `isMariaDb()`.
  - A `PROPULSION_TEST_DB=mariadb` testcontainer path still exists for the
    main integration suite (`test/tools/helpers/IntegrationDatabase.php`, not
    run in CI) — unrelated to the two build-time features above, which don't
    need a live connection to generate DDL for at all.

### SQLite

Not covered by the original version of this document at all, despite being a
supported platform and the one the worker-mode harness (`test/worker/`) runs
on. Modern SQLite has most of the cross-cutting query-layer items already, and
it needs no container in CI — so it's the cheapest platform to bring to
parity.

- [x] **`ON CONFLICT` upsert** and **`RETURNING`** (3.35+) — both implemented;
  see query-layer section above (`BasePeer::doUpsert()`,
  `DBSQLite::supportsInsertReturning()`/`getInsertReturningSql()`). Duplicate
  of the query-layer bullets, kept checked off here for cross-reference.
- [x] **Generated columns** (`GENERATED ALWAYS AS (expr) [VIRTUAL|STORED]`,
  3.31+) — new, platform-generic `Column` attributes `generatedAs="expr"`
  (the raw SQL expression) and `generatedType="virtual"|"stored"` (default
  `VIRTUAL`, SQLite's own default), `Column::isGenerated()`/
  `getGeneratedExpr()`/`getGeneratedType()`/`isGeneratedStored()`, currently
  only honored by `SqlitePlatform::getColumnDDL()` (falls through to
  `DefaultPlatform::getColumnDDL()` unchanged for any non-generated column,
  so the nativeEnum CHECK-constraint branch there is untouched). A generated
  column can't also carry a `DEFAULT`, the same mutual exclusivity
  PgsqlPlatform's `tsvectorFrom`-generated columns already have. Modeled as
  generic rather than SQLite-specific since MySQL's own "Generated/virtual
  columns" gap (still open, see that platform's section) could reuse the same
  attributes later. Live-verified against a real SQLite (`pdo_sqlite`) file
  -- including that a `STORED` generated column round-trips correctly through
  a plain `SELECT`.
- [ ] `STRICT` tables (3.37+) and `WITHOUT ROWID`. Note the current type
  mappings (`SqlitePlatform::initialize()`) emit MySQL-flavored type names
  (`MEDIUMTEXT`, `LONGBLOB`, `MEDIUMBLOB`), which SQLite accepts today only
  because of its permissive type affinity — those names are *not* valid under
  `STRICT`, so this item is a prerequisite for that one.
- [x] **Partial and expression indexes** (3.8+/3.9+) — `SqlitePlatform` now
  overrides `getAddIndexDDL()` to honor the same `Index::getWhereClause()`
  (`where="..."`) and `Index::isExpressionAtPosition()` (`<index-column
  expression="...">`) attributes PgsqlPlatform's own partial/expression index
  support already introduced (see the Postgres section above) -- SQLite's
  `CREATE INDEX` syntax accepts both a trailing `WHERE` predicate and
  expression columns the same way. Unlike Postgres, SQLite has no index
  access method (`USING`), `INCLUDE`, storage parameters, or `CONCURRENTLY`
  clause, so only the column list and `WHERE` clause differ from the base
  `DefaultPlatform` implementation. The shared column-list-building helper
  (`getIndexColumnListDDL()`, quotes a plain column name but emits an
  expression entry verbatim) moved from `PgsqlPlatform` up into
  `DefaultPlatform` so both platforms can use it -- surfaced one incidental
  fix along the way: `MysqlPlatform` already had its own unrelated
  same-named `getIndexColumnListDDL()` (handling per-column index-prefix
  *sizes*, a different feature) with no declared return type, which became a
  fatal "declaration must be compatible" error once the newly-shared parent
  method declared `: string` -- fixed by adding the matching return type to
  MySQL's own method (pure type annotation, no behavior change). Live-verified
  against a real SQLite database, including confirming via `EXPLAIN QUERY
  PLAN` that a partial index is actually selected by the query planner.
- [ ] FTS5 full-text search; JSON functions (JSON1, built in since 3.38).
- [x] **`ALTER TABLE` capability audit** — confirmed, no code changes needed.
  `SqlDiffManager::diff()` (the only caller of the migration path) checks
  `Platform::supportsMigrations()` per-database and skips the database
  entirely (with a logged message) when false; `SqlitePlatform::
  supportsMigrations()` returns `false`, so `SqlitePlatform::
  getAddForeignKeyDDL()`/`getDropForeignKeyDDL()` (a reference-only SQL
  comment and an empty string, respectively -- confirmed by reading them)
  are never invoked by that path at all. The 12-step table-rebuild dance
  this item's own text flags remains genuinely unimplemented, but it's
  correctly unreachable rather than silently broken -- `sql:diff` simply
  doesn't support SQLite today, which is what `supportsMigrations() === false`
  already promises.

### MSSQL

Lowest supported version raised to SQL Server 2012+ (Azure SQL is always
current) as part of this pass — every item below assumes at least that floor;
temporal tables additionally need 2016+/Azure SQL, called out again on that
item itself.

- [x] **Sequences** (`CREATE SEQUENCE`, SQL Server 2012+) — opt in via
  `<table><id-method-parameter value="my_sequence"/></table>` (the same
  schema-level mechanism PgsqlPlatform's own named-sequence support uses),
  `MssqlPlatform::getAddSequenceDDL()`/`getDropSequenceDDL()` emitting
  `CREATE SEQUENCE %s AS BIGINT START WITH 1 INCREMENT BY 1;`/a guarded
  `DROP SEQUENCE`, wired into `getAddTablesDDL()`/`getDropTableDDL()`. Unlike
  Postgres, MSSQL's default id method (`IDENTITY`) is a column property with
  no backing sequence object at all, so this is a fully independent,
  additive mechanism rather than an alternative naming path for the same
  underlying object — a column that wants its value from the sequence uses
  its own `defaultExpr="NEXT VALUE FOR my_sequence"` (this codebase's existing
  raw-expression column default, unmodified), and must *not* also be
  `autoIncrement="true"` (that would give the column two competing value
  sources). Deliberately does **not** gate on
  `$table->getIdMethod() == IDMethod::NATIVE` the way `PgsqlPlatform`'s
  equivalent guard does — `Table::finalize()` silently downgrades `NATIVE` to
  `NO_ID_METHOD` whenever a table has no `autoIncrement` column (see
  `Table.php`), which every real use of this defaultExpr-only pattern hits,
  unlike Postgres's own usage (always paired with `autoIncrement="true"`) —
  surfaced by live-verifying against a real `azure-sql-edge` container, where
  the first cut (gated on `IDMethod::NATIVE`) silently never emitted the
  `CREATE SEQUENCE` at all. `MssqlPlatform::getSequenceName()` is overridden
  only to look past that same downgrade (falling through to
  `DefaultPlatform`'s own `<table>_SEQ`-default/`IDMethod::NATIVE`-gated
  behavior — still relied on by `TableMapBuilder::setPrimaryKeyMethodInfo()`
  and this platform's own pre-existing `getSequenceName()` tests — whenever no
  `<id-method-parameter>` is declared at all). Verified end-to-end against a
  live `azure-sql-edge` container: `CREATE SEQUENCE` + a `defaultExpr`-driven
  column DEFAULT actually produced sequential ids on insert.
- [x] **Computed columns** (`AS (expression) [PERSISTED]`) — reuses the
  platform-generic `generatedAs="expr"`/`generatedType="stored"` `Column`
  attributes SQLite's own generated-column support introduced;
  `MssqlPlatform::getColumnDDL()` gains an `isGenerated()` branch emitting
  `AS (expr)` (`VIRTUAL`, the SQL Server default, needs no suffix at all —
  unlike SQLite there's no `VIRTUAL` keyword) or `AS (expr) PERSISTED[ NOT
  NULL]` for `generatedType="stored"` (T-SQL only allows `NOT NULL` alongside
  `PERSISTED`, and never allows a bare `NULL` keyword on a computed column at
  all, stricter than SQLite's own grammar). Verified end-to-end against a live
  `azure-sql-edge` container, including a real round-trip through a `PERSISTED`
  column after an `UPDATE` to one of its inputs — surfaced a real, purely
  environmental gap while doing so (see the `SET ANSI_NULLS ON` bullet below).
- [x] **`rowversion`/`timestamp` type mapping** — new `rowVersion="true"`
  column attribute (mirrors the existing MySQL-only `unsigned`/`zerofill`
  opt-in-boolean pattern), only honored by `MssqlPlatform::getColumnDDL()`,
  which emits the real `ROWVERSION` type in place of the column's own mapped
  domain type. Serves as the concurrency token for `UNIT_OF_WORK.md`'s
  optimistic-concurrency item; hydration/comparison at the runtime/ObjectBuilder
  layer for that use case is not implemented here — DDL + type mapping only,
  the same scoping convention `vector`/`GEOMETRY` used for their own
  DDL-only v1s. Verified live: a `ROWVERSION` column's value changed on
  `UPDATE` as expected.
- [x] **Clustered vs. nonclustered index control** — new `clustered="true"/
  "false"` attribute on `Index`/`Unique` (`Unique extends Index`, so both
  `<index>` and `<unique>` get it), defaulting to `null` ("unspecified" —
  reproducing the exact prior DDL unchanged when unset, verified by keeping
  every pre-existing index/unique DDL test passing byte-for-byte).
  `MssqlPlatform::getAddIndexDDL()`/`getUniqueDDL()` are now overridden
  (previously both inherited `DefaultPlatform`'s un-clustering-aware version
  unmodified) to splice `CLUSTERED`/`NONCLUSTERED` in when set. Since the
  primary key (not an `Index` instance in this model) is SQL Server's own
  implicit default clustered object, a new `primaryKeyClustered="false"`
  `Table` attribute (default `true`, reproducing prior DDL unchanged) lets a
  schema move clustering to a different index/unique constraint instead —
  `MssqlPlatform::getPrimaryKeyDDL()` emits an explicit `NONCLUSTERED` only
  when set to `false`. The schema author is responsible for not declaring
  more than one clustered index/constraint per table (SQL Server rejects that
  at DDL-execution time, not validated here). Verified live: a
  `primaryKeyClustered="false"` table plus a `clustered="true"` index actually
  swapped which object SQL Server reports as `CLUSTERED` in `sys.indexes`.
- [x] **Temporal tables** (`SYSTEM_VERSIONING = ON`, `PERIOD FOR SYSTEM_TIME`,
  SQL Server 2016+/Azure SQL — a higher floor than every other MSSQL item in
  this pass, called out explicitly since it's the one exception) — new
  `temporal="true"` + optional `historyTable="..."` `Table` attributes, and
  `periodRowStart="true"`/`periodRowEnd="true"` `Column` attributes marking
  the table's two system-time boundary columns (the schema author declares
  them explicitly, like every other column, rather than this generator
  synthesizing hidden ones from nothing). `MssqlPlatform::getColumnDDL()`
  emits `col DATETIME2 GENERATED ALWAYS AS ROW START|END NOT NULL` for those
  two; `getAddTableDDL()` is overridden (previously inherited
  `DefaultPlatform`'s version unmodified) to append a `PERIOD FOR SYSTEM_TIME
  (start, end)` clause and a trailing `WITH (SYSTEM_VERSIONING = ON
  (HISTORY_TABLE = ...))` clause, throwing an `EngineException` if a
  `temporal="true"` table doesn't declare exactly one of each boundary column.
  `getDropTableDDL()` now unconditionally checks
  `sys.tables.temporal_type = 2` (not just `$table->isTemporal()`, so this
  still works against a live database left over from a schema that used to
  declare `temporal="true"` and no longer does) and runs `ALTER TABLE ... SET
  (SYSTEM_VERSIONING = OFF)` before the normal drop path -- SQL Server
  otherwise rejects dropping (or altering FK/PK constraints on) a
  still-versioned table outright (error 13552) -- then also drops the
  now-demoted-to-ordinary history table explicitly (SQL Server doesn't do
  that automatically when the base table is dropped). The history table name
  defaults to `<table>_History`, always auto-qualified with an explicit
  schema (`dbo` when the base table has none) even when named explicitly via
  `historyTable="..."` — surfaced live against a real `azure-sql-edge`
  container: `HISTORY_TABLE` is rejected outright ("not specified in
  two-part name format", error 13735) for a bare single-part name, which
  `$table->getName()`'s own schema-qualification (only present when the table
  itself has a schema) doesn't cover for the common schema-less case. Verified
  end-to-end live: `sys.tables.temporal_type` read back as `2`, an `UPDATE`
  actually produced a row in the history table, and `DROP` cleanly removed
  both tables.
- [x] **`SET ANSI_NULLS/ANSI_PADDING/ANSI_WARNINGS/ARITHABORT/
  CONCAT_NULL_YIELDS_NULL/QUOTED_IDENTIFIER ON, NUMERIC_ROUNDABORT OFF`** —
  not itself a roadmap item, but a real, only-live-instance-catchable gap
  surfaced implementing the computed-columns item above:  all seven of these
  session-level `SET` options must be in this exact state for the *session
  that creates it* for several object types SQL Server otherwise rejects
  outright at `CREATE`/`ALTER` time (computed columns among them — also
  indexed views, filtered indexes, indexes on computed columns). SSMS and the
  `pdo_sqlsrv` driver (`DBSQLSRV`) already default all seven correctly, but
  FreeTDS's `pdo_dblib` (the driver this codebase's own MSSQL integration
  tests connect over) does not, and `bin/propulsion sql:exec`
  (`SqlExecManager`) opens a bare `PDO` with no MSSQL-specific session setup
  of its own — a generated DDL file with a `PERSISTED` computed column failed
  outright against a live `azure-sql-edge` container over `dblib` (SQL Server
  error 20018) until this was tracked down. Fixed by having
  `MssqlPlatform::getAddTablesDDL()` emit all seven `SET` statements once at
  the very top of the generated file (guarded on the database actually having
  any table to emit DDL for, so an all-`skipSql` schema still produces a
  truly empty string) — every one is a session-scoped option that persists
  for the rest of that one connection's statements, so this needed no
  platform-agnostic change to `SqlExecManager` itself to take effect.
- [x] Modernize `DBMSSQL::applyLimit()` — the ~150-line regex-based
  `ROW_NUMBER()` rewriter (parsed `SELECT ... FROM ...` via regex, required
  explicit aliases on aggregates, fragile against subqueries/CTEs) is gone
  entirely. The no-offset case still rewrites to `SELECT TOP n ...` (needs no
  `ORDER BY`, applies to any plain `SELECT ... FROM ...`); every offset case
  (including the query it previously couldn't parse structurally at all, e.g.
  `UNION`/`INTERSECT`/`EXCEPT`) now uses SQL Server 2012+'s native `OFFSET n
  ROWS [FETCH NEXT m ROWS ONLY]`, injecting a synthetic `ORDER BY (SELECT
  NULL)` first when the query has no `ORDER BY` of its own (required by
  T-SQL's `OFFSET`/`FETCH` syntax, satisfied here without imposing a real
  ordering). `test/tools/helpers/SqlAssertions.php`'s cross-platform SQL
  normalization updated to match (the old `ROW_NUMBER()`-unwrapping regex is
  now dead code, removed).
- [x] Modernize `getDropTableDDL`'s use of legacy `sysobjects`/`sysreferences`
  catalog views to `sys.foreign_keys`/`sys.tables` — both the per-FK
  existence check and the cross-database "who else references this table"
  cursor query now join `sys.foreign_keys` to `sys.tables` via
  `parent_object_id`/`referenced_object_id` instead of the legacy
  `sysobjects`/`sysreferences` views.
- [x] Auto-downgrade CASCADE FKs that would create multiple cascade paths to
  `NO ACTION` (SQL Server error 1785) so the shared bookstore fixture can
  load on MSSQL — `MssqlPlatform::computeCascadeDowngrades()`, handles
  self-referencing FKs, repeated same-table/same-target FKs, and diamonds via
  an intermediate table, for both `ON DELETE`/`ON UPDATE` independently.
  Fixture verified against a live `azure-sql-edge` container. (Tracked in
  `KNOWN_ISSUES.md`: the fixture builds now, but a full-suite parity audit
  against MSSQL — same shape as the MySQL one — is still a separate,
  much larger, not-yet-started task: 747 errors/4 failures out of 2818 tests.)

### Oracle

Lowest supported version raised to Oracle 12c+ as part of this pass (native
`GENERATED ... AS IDENTITY` columns and `OFFSET ... FETCH` both need it);
native `JSON` additionally needs 21c+, called out again on that item itself,
the same "one exception, called out explicitly" convention the MSSQL section
above uses for temporal tables' own higher sub-floor.

- [x] **12c+ `GENERATED ... AS IDENTITY` columns** — reuses the
  platform-generic `identity="true"` `Column` attribute
  (`Column::isIdentity()`/`setIdentity()`) PgsqlPlatform's own identity
  support already introduced. `OraclePlatform::getColumnDDL()` emits
  `GENERATED BY DEFAULT AS IDENTITY` right after the column's mapped type
  (still the plain `NUMBER`/etc., unlike Postgres's `serial`/`bigserial`,
  which *is* itself a pseudo-type) for an autoIncrement PK with no explicit
  named sequence (`<id-method-parameter>` -- same guard Postgres's own
  `identity` support uses, factored into a shared `usesNativeIdentity()`
  helper here). Since an identity column carries its own hidden, implicit
  sequence, `getAddSequencesDDL()`/`getAddAutoIncrementTriggerDDL()`
  (the legacy pattern, still the default for a plain, non-identity
  autoIncrement column) and `getDropTableDDL()`'s matching `DROP SEQUENCE`
  are all skipped for that table instead of emitting a second, redundant
  sequence object. `getModifyColumnDDL()` is now overridden (previously
  inherited `DefaultPlatform`'s version, which just delegates to
  `getColumnDDL()`) to pass a new `$allowIdentity = false` flag through to
  `getColumnDDL()` -- Oracle rejects re-declaring `GENERATED ... AS IDENTITY`
  on a column that already has it (ORA-30675), so an `ALTER TABLE ... MODIFY`
  doesn't attempt to change a column's identity-ness itself, the same
  "not attempted here" limitation PgsqlPlatform's own identity/serial
  migration path already has. Covered by `OraclePlatformTest`. Live-verified
  against a real `gvenzl/oracle-free:23-slim` container -- which caught a
  real bug this environment's earlier lack of Docker had let ship: the
  originally-emitted clause order, `NOT NULL GENERATED BY DEFAULT AS
  IDENTITY`, is rejected outright by real Oracle (ORA-03076 "unexpected item
  GENERATED in a column definition or inline constraint") -- `NOT NULL` must
  come *after* the identity clause, not before. Fixed in
  `OraclePlatform::getColumnDDL()` (now emits `GENERATED BY DEFAULT AS
  IDENTITY NOT NULL`, matching the order `PgsqlPlatform`'s own identity
  support already used correctly), with `OraclePlatformTest`'s two affected
  assertions corrected to match. Confirmed live afterward: the identity
  column auto-populates on insert with no app-level value, and no redundant
  legacy sequence/trigger exists alongside it.
- [x] **Auto-increment trigger generation** —
  `OraclePlatform::getAddAutoIncrementTriggerDDL()`, wired into
  `getAddTableDDL()`, emits a `BEFORE INSERT ... WHEN (new.<pk> IS NULL)`
  trigger doing `SELECT seq.NEXTVAL INTO :new.<pk>`, giving DB-level
  auto-population for inserts made outside the ORM too. Covered by
  `OraclePlatformTest`.
- [x] **Native JSON support** (21c+ native `JSON` column type) —
  `OraclePlatform::initialize()`'s domain mapping for
  `PropulsionTypes::JSON`/`JSONB` now points at Oracle's real `JSON` column
  type instead of a plain `CLOB`. Oracle enforces well-formed JSON on the
  type itself, giving the same guarantee 12c+'s separate `IS JSON` CHECK
  constraint would on a plain CLOB, without needing that constraint spelled
  out as its own DDL fragment. No binding/hydration changes needed in
  `DBOracle`: a JSON column was never routed through the `CLOB_EMU`-specific
  LOB bind branch to begin with (just a bare `"CLOB"` sqlType), so it's
  bound/fetched as a plain string exactly as before, just against a
  differently-typed column. Covered by `OraclePlatformTest`. Live-verified
  against a real Oracle 23ai (`gvenzl/oracle-free:23-slim`) container:
  well-formed JSON round-trips correctly, and inserting a malformed JSON
  string is genuinely rejected by the column type itself (an `ORA-`
  error), not silently accepted the way a plain CLOB would.
- [x] **Modernize `DBOracle::applyLimit()`** — the double-nested
  `ROWNUM`-based subquery rewrite (which required deriving an explicit outer
  column list to dodge a synthetic `PROPEL_ROWNUM` column leaking through a
  `B.*` wildcard, and pre-aliasing the `Criteria`'s select columns via
  `BasePeer::needsSelectAliases()`/`turnSelectColumnsToAliases()` to dodge an
  ORA-00918 "column ambiguously defined" error from a `SELECT A.*`
  star-expansion over a derived table with duplicate column names) is gone
  entirely, replaced by Oracle 12c+'s native `OFFSET ... ROWS FETCH NEXT
  ... ROWS ONLY`, appended directly to the existing query text with no
  subquery wrapping at all -- the same modernization MSSQL's own
  `applyLimit()` got in this doc's own MSSQL section, and for the same
  reason: none of the wrapping-related problems exist once pagination is
  just a clause appended to the original, unwrapped `SELECT`. Injects a
  no-op `ORDER BY (SELECT NULL FROM dual)` when the query has none (required
  by the same ANSI `OFFSET`/`FETCH` syntax rule MSSQL's rewrite already
  documented; Oracle additionally requires a `FROM` on every query, hence
  `FROM dual` where MSSQL's own no-op is a bare `SELECT NULL`). The now-dead
  `DBOracle::deriveOuterColumnList()`/`splitTopLevelSql()` helpers are
  removed; `DBOracleTest`'s `applyLimit()` assertions updated to match the
  new, much simpler emitted SQL.
- [ ] PL/SQL type support — no packages, stored procedures, custom object
  types, or PL/SQL table types. Out of scope here: unlike every other item
  in this section (all additive DDL/type-mapping changes to existing
  mechanisms), this would need a wholly new schema-modeling concept (a
  package/procedure/object-type declaration, parameter lists, a PL/SQL body)
  with no existing analog anywhere in this codebase's model classes --
  the same "meaningfully larger effort, deliberately deferred rather than
  attempted partially" reasoning the Postgres section above already gave for
  declarative partitioning.
- [x] **`BINARY_FLOAT`/`BINARY_DOUBLE` native float types** —
  `OraclePlatform::initialize()`'s domain mapping for
  `PropulsionTypes::REAL`/`DOUBLE` now points at Oracle's native IEEE-754
  binary floating-point types (available since 10g, long past this pass's
  12c+ floor) instead of the previous, decimal-exact `NUMBER`/`FLOAT`
  mapping -- `NUMBER` is exact decimal (SQL `NUMERIC`'s own semantics), a
  poor fit for REAL/DOUBLE's "approximate binary float" semantics that every
  other supported platform's own REAL/DOUBLE mapping already honors.
  Unconditional (no new opt-in flag): unlike `nativeArray`'s own breaking
  wire-format change for a column that may already hold data in the old
  emulated text format, a `NUMBER` and a `BINARY_FLOAT`/`BINARY_DOUBLE` both
  round-trip through PDO as plain PHP floats either way, so there's no
  existing-data wire-format compatibility concern to opt out of. `FLOAT`
  (a distinct Propulsion type from `REAL`/`DOUBLE`) is untouched -- it
  already had its own native Oracle `FLOAT` mapping via `DefaultPlatform`'s
  generic same-named-type fallback, not part of this item. Covered by
  `OraclePlatformTest`. Live-verified against a real Oracle 23ai container:
  `user_tab_columns` confirms the columns are really `BINARY_FLOAT`/
  `BINARY_DOUBLE`, and values with no exact binary representation (e.g.
  `0.1`, `1/3`) round-trip within float precision as expected.

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
- [x] **Global (cross-process) query result cache.** The result cache added in
  87e43c4 is request-scoped: it dies with `Session::reset()`, so it only pays
  off for a query repeated within one request -- precisely the wrong shape for
  the worker-mode deployments this ORM targets. This adds a second tier behind
  it, holding the same *raw pre-hydration rows* rather than formatted results.
  That choice is load-bearing in three ways: a serialized `PropulsionCollection`
  would carry a live object graph across a request boundary (the one failure
  mode this feature could not survive); re-hydrating on the way out runs the
  normal generated `populateObject()` path, instance pool included, so an L2 hit
  is indistinguishable from a fresh read; and because rows are
  formatter-independent, an `ARRAY`-formatted and an object-formatted query with
  identical SQL correctly share one entry. Hydration costs ~0.8-1µs/row, cheap
  against any real round trip.
  **Backend is PSR-16** (`psr/simple-cache ^3.0`) rather than PSR-6 or a bespoke
  interface: `get`/`set`/`getMultiple` is exactly the shape a query cache needs,
  and PSR-6's `CacheItemInterface` round trip buys nothing here. Four thin
  first-party drivers -- `Propulsion\Cache\Driver\{NullCache,ArrayCache,ApcuCache,FileCache}`,
  selected by `Propulsion\Cache\CacheDriverFactory::factory()`, whose
  `'' => NullCache` null-object entry mirrors `DBAdapter`'s `'' => DBNone` so
  "no cache configured" is an ordinary code path rather than a null check at
  every call site -- and **deliberately no Redis or Memcached driver**: both
  protocols have several mature PSR-16 implementations, and owning reconnect,
  cluster/sentinel, TLS and RESP3 to duplicate them is maintenance with no
  upside. `driver: 'psr16'` plus `Propulsion::setQueryCachePool()` takes any of
  them. The pool lives on `ServiceContainer` (process-scoped, exactly its
  documented charter) with logger-shaped `setQueryCachePool()`/
  `hasQueryCachePool()`/`queryCachePool()` delegators on `Propulsion` matching
  the PSR-3 and PSR-14 facades; deliberately *not* a new static on `Propulsion`,
  which phase 4c would only have had to move.
  **Invalidation is by table version token**, not by the L1 tier's key index: a
  shared pool has no cheap or atomic way to enumerate "every key touching table
  X", but folding each read table's token into the cache key means one write
  makes every derived key unreachable, with no scan and no coordination. Tokens
  are *random values, not counters* -- PSR-16 has no atomic increment, so
  read-add-write races (two writers both reading v=7 both write v=8, and a
  result cached in between stays stale for its whole TTL), whereas a blind
  random write has no read step. The decisive property is that losing a token
  then fails toward a **miss**, never toward staleness: an evicted token is
  reseeded to a never-used value, while a counter reseeded to 1 resurrects
  orphaned entries. Bumps are buffered until the outermost commit
  (`PropulsionPDOTrait::commit()`), and **no query inside a transaction is
  published at all** -- such a SELECT can see uncommitted rows, and publishing
  those to a shared cache would leak them.
  **Two overload defences, for two distinct failure modes that are easily
  conflated.** *Cache pollution*: a query whose parameters never repeat produces
  a distinct key every time, so every request misses and -- if every miss also
  wrote -- the cache grows without bound while never serving a hit; stampede
  protection is irrelevant, since nothing contends on a single key. So an entry
  is admitted only on its second sighting within a window, tracked by a tiny
  marker key batched into the round trip that already fetches version tokens; a
  never-repeating key never stores anything. *Stampede*: probabilistic early
  recomputation (XFetch) on every backend, plus strict single-flight via a new
  optional `Propulsion\Cache\AtomicCache::add()` capability that all three real
  first-party drivers implement (`apcu_add`, `O_EXCL`, array) -- PSR-16 cannot
  express atomic create-if-absent, so a third-party pool is detected with
  `instanceof` and gets the probabilistic defence alone rather than a lock that
  does not lock.
  Also **`Propulsion::rawQuery()`**, giving hand-written SQL the same stack
  (`->dependsOn(...)->cache()->hydrate(FooPeer::class)`), since the coordinator
  is generic over (dbName, SQL, params, tables, execute, format) and
  `ModelCriteria` merely derives those from a `Criteria`. Tables are declared,
  never inferred -- the same reasoning that rules out sniffing SQL for `NOW()`.
  **Scope-downs**, in the register this document already uses: no coherence with
  writes that bypass the ORM (TTL, default 300s, is the only backstop, which is
  why "never expire" is not the default);
  (`getQueryCacheTouchedTables()`'s failure to descend into subqueries and CTEs
  was also listed here, and has since been fixed -- it now follows FROM-clause
  subqueries, CTEs, set-operation branches and EXISTS/IN filters);
  no automatic GC for the `file` driver beyond lazy unlink-on-expired-read,
  since probabilistic in-request GC turns one unlucky request into a full-tree
  stat walk (a non-PSR `prune()` is provided for cron instead); and no
  `var_export`/opcache file driver -- Symfony's `PhpFilesAdapter` trick would
  work on this payload and is the only way a file cache approaches APCu, but
  `opcache.validate_timestamps=0` makes a mishandled overwrite serve stale data
  *forever*, the worst failure a cache can have, for microseconds.
  **On speed, measured rather than assumed** (`bench/global_query_cache_bench.php`,
  numbers in `bench/RESULTS.md`): the three drivers land within ~20% of each
  other on the hit path, so driver choice is about *sharing semantics*, not
  throughput. The `file` driver is **not** APCu-equivalent -- 3-10× slower on
  reads (3-4 syscalls plus stream overhead against a probe in already-mapped
  shared memory) and ~8× on writes (atomic temp-file-plus-rename) -- but its
  niche is zero infrastructure, surviving php-fpm restarts, and being the only
  driver a CLI process shares with the web tier at all, since `apc.enable_cli`
  gives each CLI process its own segment; for an application with CLI writers it
  is *more correct*, not merely slower. The benchmark's headline 1.7-2.0× also
  understates the real win, and the write-up says so: its baseline is in-memory
  SQLite, where the round trip an L2 hit skips is only ~89µs, so it is largely
  measuring hydration against a free database; against a networked Postgres the
  same hit avoids 0.5-2ms.
  Fixed two pre-existing bugs in the request-scoped tier while here: the cache
  key ignored the formatter (so two same-SQL queries with different formatters
  collided), and `FORMAT_STATEMENT`/`FORMAT_ON_DEMAND` results were cached
  despite being tied to a live statement, handing the next caller an exhausted
  cursor -- both formatters now bypass caching entirely via
  `PropulsionFormatter::supportsRowCaching()`, with `PropulsionOnDemandFormatter`
  needing an explicit `false` override because it extends the cacheable object
  formatter. Covered by a shared `Psr16DriverTestCase` conformance suite every
  driver passes identically, a real two-OS-process test
  (`FileCacheCrossProcessTest`, `proc_open`, no Docker), unit suites for the
  shared tier and the version registry (including a 2000-key pollution flood
  asserting nothing is stored), end-to-end `GlobalQueryResultCacheTest` and
  `RawQueryCacheTest` against a real database, and four new `test/worker/`
  profiles proving an entry written by request N is visible to request N+1 --
  and that the request-scoped tier correctly is not -- across FrankenPHP worker
  threads on the `array`, `apcu` and `file` drivers. Full user documentation in
  `docs/CACHING.md`.
- [x] **Compiled-query / SQL-string cache.** The opt-in cache added in
  87e43c4 caches *result rows*; new `Propulsion\Cache\CompiledQueryCache`
  (mirroring that class's own shape, owned by `Session` the same way, cleared
  at the same request boundary) caches the compiled SELECT *SQL string*
  instead, via `Criteria::setCompiledQueryCache(string $key)` /
  `isCompiledQueryCacheEnabled()` / `getCompiledQueryCacheKey()`, consulted
  from `BasePeer::createSelectSql()`. Unlike the result cache's key (built
  *after* SQL+params exist, from the rendered SQL itself), a compiled-query
  cache has to be keyed *before* paying the cost of building that SQL, and
  `Criteria` has no existing shape-fingerprint mechanism cheap enough to
  justify computing one automatically (limit/offset are written as literal
  integers straight into the SQL text on every platform -- see
  `DBAdapter::applyLimit()` -- not bound, and an `IN (...)` list's own
  placeholder count varies with its element count, so a naive
  table/column/comparison-only fingerprint would be unsafe); the key is
  therefore caller-supplied (a natural choice: `__METHOD__` of the generated
  Query/Peer method building the Criteria), the same tradeoff Doctrine's own
  query cache and EF Core's compiled queries make. On a cache hit, the SQL
  string itself isn't rebuilt -- only params are freshly collected via a new
  `BasePeer::collectSelectParams()` fast path that re-walks joins/WHERE/HAVING
  criterions (reusing `Join::getClause()`/`Criterion::appendPsTo()` as-is, so
  there's no duplicated dispatch logic to drift out of sync) without any of
  the SQL-text assembly (FROM/JOIN clause building, identifier quoting, ORDER
  BY/ignore-case resolution) that a shape-stable query doesn't need redone. As
  a safety net against the most common way a caller could violate the "key
  uniquely identifies one shape" contract -- reusing a key for a Criteria with
  a different number of bound parameters -- a cache hit whose freshly-collected
  param count doesn't match the count recorded when the entry was built throws
  a clear `PropulsionException` rather than silently returning mismatched SQL;
  this doesn't catch every possible shape mismatch (e.g. same count, different
  structure), only the common one. **Scope**: only the plain-SELECT path is
  covered -- a `Criteria` carrying common table expressions, set operations, or
  FROM-clause subqueries (`withCte()`, `union()`/`intersect()`/`except()`,
  `addSelectQuery()`) silently falls back to an uncached build every time (the
  cache is simply never consulted for those), since each recurses into further
  `Criteria` objects with their own params that the fast params-only path above
  doesn't attempt to mirror -- the same kind of deliberate scope-down this
  document already used for Oracle's multi-row `RETURNING`/MSSQL's
  `BULK INSERT`. Benchmarked at `bench/compiled_query_cache_bench.php`: ~1.5x
  faster `BasePeer::createSelectSql()` for a join + two WHERE conditions +
  ORDER BY + LIMIT/OFFSET shape (median 4.3µs → 2.8µs per call, 20k iterations,
  JIT enabled/pcov disabled) -- the saving scales with how much FROM/JOIN/WHERE
  text there is to skip re-deriving, so a simpler single-table query sees less
  benefit and a heavily-joined one sees more. Covered by
  `CompiledQueryCacheUnitTest` (storage API in isolation) and
  `CompiledQueryCacheTest` (end-to-end via `ModelCriteria`/BookstoreTestBase,
  including the different-shared-key-different-param-count exception).
- [x] **Execution strategy: connection liveness + transaction retry.** Three
  pieces, all documented in `docs/CONNECTIONS.md`; the two recovery features
  are opt-in via a new, strictly-validated `connection` runtime-config section
  (`ConnectionConfig`, memoised on `ServiceContainer` the same way
  `QueryCacheConfig` is), because each spends something real and neither is
  safe to enable without a deployment having thought about it.
  - **Detection, on the path traffic actually takes.** This item's own text
    understated the gap: it wasn't that reconnect-on-drop was "ad hoc", it was
    that detection lived on `exec()`/`query()`/`DebugPDOStatement::execute()`
    and *almost nothing goes through any of those* -- the generated Peer/
    ModelCriteria paths all prepare a statement and call `execute()` on it,
    which on a non-debug connection was a plain `\PDOStatement` nothing
    wrapped. New `Propulsion\Connection\PropulsionStatement` is now the
    statement class on every non-persistent connection (with
    `DebugPDOStatement` reparented onto it, so debug mode inherits the
    detection instead of repeating it, and `useDebug(false)` no longer resets
    the statement class back to a plain `\PDOStatement` -- which would have
    switched a correctness feature off along with a diagnostic one). Verified
    end-to-end against a real prepared-statement `execute()` by inducing a
    genuine dropped-connection-classified `PDOException` from a SQLite
    `RAISE(ABORT, ...)` trigger (`PropulsionStatementTest`), rather than by
    mocking PDO. The `error_log()` complaint in this item's original text was
    already stale -- `handleDroppedConnection()` had been moved onto PSR-3
    before this pass; the remaining `error_log()` calls are the
    `AGAVI_DEBUG_DATABASE_FORCE` env-gated deep-debug channel, deliberately
    separate.
  - **Pre-checkout ping** (`connection.liveness`), consulted from
    `getMasterConnection()`/`getSlaveConnection()` via `PropulsionPDO::ping()`
    (`SELECT 1`, overridden to `SELECT 1 FROM dual` on
    `OraclePropulsionPDO` -- the hook lives on the driver-specific connection
    subclass rather than on `DBAdapter` because a connection does not know the
    datasource name it was registered under and so cannot look its own adapter
    up). Only *idle* connections are pinged, tracked by a `lastActivityAt`
    stamp refreshed at the coarse seams that see real traffic, so under
    sustained load this collapses to ~zero extra round trips while still
    covering the quiet period after which connections actually get reaped.
  - **`Propulsion::transaction(callable, ?string $dbName, ?RetryPolicy)`**
    with exponential backoff and **full jitter** by default (un-jittered
    backoff is actively counterproductive for the one failure this exists to
    handle: a deadlock has at least two transactions in it, and equal delays
    collide again on the retry). Retryable errors come from a new
    `DBAdapter::isRetryableError()` hook -- base implementation matches
    SQLSTATE `40001`/`40P01`, with overrides where SQLSTATE alone is not
    enough: MySQL's 1205 lock-wait timeout and Oracle's ORA-00060/ORA-08177
    both hide under a generic `HY000`, MSSQL's 1205 is matched on the driver
    code as well because pdo_dblib and pdo_sqlsrv disagree on how faithfully
    they surface SQLSTATE, and SQLite's `SQLITE_BUSY`/`SQLITE_LOCKED` are the
    whole-database-lock equivalent. **A connection dropped while the COMMIT is
    in flight is deliberately not retried** -- the outcome is genuinely
    unknown (the server may have committed and died before saying so), so
    re-running the closure could apply the work twice; a drop *before* the
    commit is issued is retried, since nothing was committed. Nested calls run
    inside a savepoint and never retry, because the failures being retried
    abort the entire transaction and the outer one is already dead. Covered by
    `TransactionRetryTest` (real SQLite transactions with real rollback, only
    the failures synthetic), `RetryPolicyTest`, `ConnectionConfigTest`,
    `ConnectionLivenessTest` and `RetryableErrorTest`.
- [ ] **Observability hooks.** PSR-3 query logging exists; there is no
  OpenTelemetry span emission, query-timing metric, or slow-query-threshold
  callback (SQLAlchemy events, Doctrine middleware, EF Core interceptors).
- [x] **`->explain()`** on a query object, returning the platform's plan
  (Rails, Django, Laravel all ship this). New `ModelCriteria::explain(bool
  $analyze = false, ?PropulsionPDO $con = null): array` builds the exact same
  SELECT SQL `find()` would (same `prepareSelectSql()`/`executeSelectSql()`
  seam, so the plan reflects the query as it would really run, not a
  hand-reconstructed approximation), then executes it wrapped in the
  platform's EXPLAIN syntax via new `DBAdapter::supportsExplain()`/
  `getExplainSql(string $sql, bool $analyze): string` hooks -- `EXPLAIN
  [ANALYZE]` (Postgres), `EXPLAIN` (MySQL/MariaDB -- `$analyze` accepted but
  ignored, since MySQL's own `EXPLAIN ANALYZE` changes the *output shape* to a
  text execution tree rather than adding an option to the same tabular result
  plain `EXPLAIN` returns, and MariaDB's own analyze variant differs again),
  `EXPLAIN QUERY PLAN` (SQLite -- never executes the query either way, so
  `$analyze` is a no-op here too). Deliberately bypasses the query result
  cache entirely: a plan is diagnostic, not a result a caller would want
  served stale from an unrelated earlier call. **Not implemented for MSSQL/
  Oracle** -- unlike every other per-platform SQL-rewrite hook in this
  adapter interface (including Oracle's own RETURNING-INTO OUT bind), neither
  fits a single-statement "wrap already-built SQL" shape: MSSQL's plan needs
  a session option (`SET SHOWPLAN_ALL ON`) toggled around the query as
  separate statements, and Oracle's needs `EXPLAIN PLAN FOR <sql>` followed by
  a second, unrelated query against `PLAN_TABLE`/`DBMS_XPLAN.DISPLAY()`; no
  existing hook shape in this codebase covers "run more than one statement",
  so rather than force-fit one in, `supportsExplain()` returns `false` for
  both and `explain()` throws a clear `PropulsionException`, the same
  "deliberately deferred" story `DBAdapter::bulkLoad()` already uses for
  MSSQL's `BULK INSERT`. Covered by `DBPostgresTest`/`DBMySQLTest`/
  `DBSQLiteTest` (SQL-string-shape, no live connection needed) and
  `ModelCriteriaExplainTest` (end-to-end against whichever platform the test
  run targets, including the MSSQL/Oracle throw path and the query-result-
  cache bypass).
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
