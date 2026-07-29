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
  `getAddExtensionsDDL()` covering both) and native `VECTOR(n)` on
  MySQL/MariaDB (both MariaDB 11.7+ and MySQL 9.0+ support the same
  `VECTOR(n)` syntax, and this generator doesn't distinguish MySQL from
  MariaDB for DDL purposes anywhere else either — see the "MariaDB
  divergences" bullet below), emulated as unbounded text (not a sized
  `VARCHAR` — an embedding vector's JSON-encoded text can be long) on
  SQLite/MSSQL/Oracle. Dimension reuses the existing `size` column attribute
  (`size="1536"`) rather than a new one, flowing through the same
  `printSize()`/`hasSize()` machinery `VARCHAR(n)` already does — no
  VECTOR-specific size handling needed in `getColumnDDL()` at all. Hydrates
  to/from a plain `array<float>`; a vector's wire format (pgvector's own
  output, and this codebase's text/JSON emulation elsewhere) is a bracketed
  comma-separated number list, which is already valid JSON, so
  `ObjectBuilder`'s `isVectorType()` branches reuse
  `BaseObject::decodeJsonColumn()`/`encodeJsonColumn()` rather than
  `PHP_ARRAY`'s `" | "`-delimited format or a new helper. Not live-verified
  against a real pgvector/MariaDB-VECTOR instance (no Docker in this
  environment) — flagging per this doc's convention for unverified items.
  `<=>`/`<->` distance operators and HNSW/IVFFlat index support are
  explicitly out of scope here (section 1 query-layer work, as this bullet
  originally noted) — this item is DDL + hydration only.
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
  (buildCriteria) pair with no platform branching at all. Not live-verified
  against Postgres (no Docker in this environment during implementation) --
  the `SET intervalstyle` fix itself is standard, well-documented Postgres
  behavior, but flagging per this doc's own convention for unverified items.
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
  - Not live-verified against a real Postgres instance in this environment
    (no Docker available) -- flagged per this doc's own convention for
    unverified items. DDL shape and schema-attribute parsing are covered by
    unit/string-shape tests (`PgsqlPlatformTest`, `ColumnTest`,
    `PgsqlFullTextSearchDDLTest`) that need no live connection, the same
    verification level several other unverified items in this document
    (`vector`, `INTERVAL`) already shipped at.
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
- [ ] **Generated/virtual columns** (`GENERATED ALWAYS AS (...) VIRTUAL|STORED`).
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
- [ ] **MariaDB divergences.** MariaDB is still served by `MysqlPlatform`
  (generator) / `DBMySQL` (runtime) as though it were MySQL — no separate
  `MariadbPlatform`/`DBMariadb` — but a runtime server-version probe now
  exists (`DBMySQL::isMariaDb()`, gating `INSERT`/`UPDATE`/`DELETE
  ... RETURNING`; see the query-layer section's "Fold ID retrieval into
  INSERT" item) and a `PROPULSION_TEST_DB=mariadb` testcontainer path exists
  to test against it (`test/tools/helpers/IntegrationDatabase.php`, not run
  in CI). Still open: real `CREATE SEQUENCE`, native `UUID` type (10.7+),
  and `VECTOR` (11.7+) — none of those are gated on `isMariaDb()` yet.

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
- [ ] Modernize `DBMSSQL::applyLimit()` — partially done: the fallback path
  (unparseable `FROM`, e.g. `UNION`) already uses native
  `OFFSET ... ROWS FETCH NEXT ... ROWS ONLY` (`DBMSSQL.php` ~line 174), but
  the main/common path (simple `SELECT` with an offset) still goes through
  the ~150-line regex-based `ROW_NUMBER()` rewriter (parses
  `SELECT ... FROM ...` via regex, requires explicit aliases on aggregates,
  fragile against subqueries/CTEs). SQL Server 2012+
  supports `OFFSET n ROWS FETCH NEXT m ROWS ONLY` natively, which would
  eliminate the whole rewriter.
- [ ] Modernize `getDropTableDDL`'s use of legacy `sysobjects`/`sysreferences`
  catalog views to `sys.foreign_keys`/`sys.tables` (works today, but dated).
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

- [ ] **12c+ `GENERATED ... AS IDENTITY` columns** — still assumes the
  legacy sequence-only pattern; no support for native identity columns.
- [x] **Auto-increment trigger generation** —
  `OraclePlatform::getAddAutoIncrementTriggerDDL()`, wired into
  `getAddTableDDL()`, emits a `BEFORE INSERT ... WHEN (new.<pk> IS NULL)`
  trigger doing `SELECT seq.NEXTVAL INTO :new.<pk>`, giving DB-level
  auto-population for inserts made outside the ORM too. Covered by
  `OraclePlatformTest`.
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
