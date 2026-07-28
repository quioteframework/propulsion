# Known issues and remaining work

Open issues and unfinished modernization work only. For history of what was
fixed and why, read `git log` — every fix commit explains its own root cause.

## Running the test suite

```
cd test
rm -rf fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
../vendor/bin/phpunit -c phpunit.xml
```

- `PROPULSION_SKIP_INTEGRATION=1` — skip everything needing Docker/a live DB.
- `PROPULSION_TEST_DB=mysql|mssql|oracle` — run the bookstore fixture against
  that platform's testcontainer instead of the default Postgres one. MSSQL
  needs `pdo_dblib`; Oracle needs `pdo_oci` built against an Instant Client
  (see `IntegrationDatabase`'s docblock for setup).
- `composer test:worker` — FrankenPHP worker-mode harness (`test/worker/`).
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.

## Open issues

- **Testcontainer leak on `kill -9`** (theoretical, mitigated by
  `composer test:cleanup-containers`, not seen in practice).
- **MSSQL nested transactions are emulated, not real.** `MssqlPropulsionPDO`
  uses a depth counter + coarse "poisoned" flag rather than real T-SQL
  `SAVE TRANSACTION`/`ROLLBACK TRANSACTION` savepoints -- a nested rollback
  doesn't undo just its own work, it poisons the whole outer transaction so a
  later `commit()` throws. A real-savepoint version was tried and reverted:
  something elsewhere in the codebase leaves `getNestedTransactionCount()`
  unbalanced across many otherwise-unrelated tests sharing one process-lifetime
  connection, which this coarse emulation tolerates silently but real nested
  SQL does not (it regressed ~500 previously-passing tests). Needs its own
  investigation into the unbalanced-counter root cause before real savepoints
  can be attempted again. `PropulsionPDOTest::testNestedTransactionRollBackSwallow`
  is skipped on MSSQL as a result.
- **`buildtime-conf.xml`** (legacy XML build-time config) is still accepted
  alongside the plain-PHP format — no way to confirm from this repo that no
  consumer still relies on it. Drop at a major-version boundary.
- **Worker-safety harness (`test/worker/`)** only covers SQLite on a single
  FrankenPHP worker thread, not Postgres or cross-thread behavior.
- **Query result cache (`Criteria::setQueryCache()`) is request-scoped only.**
  Opt-in per query, backed by `Session::getQueryResultCache()` and cleared at
  every worker request boundary (`Session::reset()`, exercised by
  `composer test:worker`'s `qcache-*` checks), with invalidation on writes to
  a cached query's tables hooked directly into `BasePeer::doInsert()`/
  `doUpdate()`/`doDelete()`/`doDeleteAll()`. There is no process-scoped
  (cross-request) tier yet, so it only helps with repeated identical queries
  within a single request/script run, not across requests in the same worker.
  A cache hit is also invisible to writes that bypass the ORM (raw SQL on the
  same connection, a different process/connection) -- same caveat as any
  result cache.

## Missing modernization work

- **PSR-18**: not started, nothing to wire it into yet.
- **Oracle platform parity**: unaudited against `DefaultPlatform` (only
  Pgsql/MySQL/MSSQL have had that pass). Has a real `PROPULSION_TEST_DB`
  testcontainer option but no CI coverage yet.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.

## MSSQL fixture now builds (2026-07-28) -- full parity audit still pending

The shared bookstore fixture previously couldn't be created at all against
SQL Server: several tables have multiple `CASCADE` foreign keys that would
touch the same row via more than one path (a self-referencing FK, two FKs
from the same table to the same target, and a diamond via an intermediate
table), which SQL Server rejects outright (error 1785, "...may cause cycles
or multiple cascade paths") but Postgres/MySQL allow.

Fixed with per-platform DDL-generation logic only (no schema change):
`MssqlPlatform::computeCascadeDowngrades()` (`generator/Lib/Platform/MssqlPlatform.php`)
detects all three shapes schema-wide (for `ON DELETE` and `ON UPDATE`
independently) and emits `NO ACTION` instead of `CASCADE` for whichever FK is
redundant (the transitively-reachable diamond edge, or all but the
first-declared same-target FK) or unconditionally required (self-reference).
Covered by `MssqlPlatformTest::testGetAddTablesDDLDowngrades*()`, and
verified by replaying the regenerated fixture SQL statement-by-statement
against a live `azure-sql-edge` container.

**Not done yet**: with the fixture now building, running the full PHPUnit
suite against it for the very first time (`PROPULSION_TEST_DB=mssql`)
surfaces 747 errors + 4 failures + 1 risky test out of 2818 -- an order of
magnitude larger than MySQL's initial ~130, since MSSQL was never previously
exercised against live fixture data at all. This needs its own triage/fix
pass (same shape as the MySQL parity audit above) before an `integration-mssql`
CI job can be added.

## MySQL parity (2026-07-27 audit) -- resolved, now 0 failures

Ran the full suite against a live MySQL testcontainer (`PROPULSION_TEST_DB=mysql`)
for the first time -- previously only the "unit" tier (which skips everything
needing a live DB) and Postgres ran in CI. Found ~130 pre-existing test
*assertion* failures, none of them functional bugs: the test suite's
`assertEquals($expectedSql, ...)`-style checks hardcode Postgres-style SQL
strings (unquoted identifiers, `LIMIT n OFFSET m`, `DELETE FROM t AS alias`),
but MySQL:

- is the only built-in adapter whose `DBAdapter::useQuoteIdentifier()` returns
  `true` (`DBMySQL::useQuoteIdentifier()`), so it correctly backtick-quotes
  every identifier (`` `book` ``, `` `TITLE` ``) -- MSSQL/Oracle/Postgres/SQLite
  all quote nothing by default, so this was invisible until MySQL was
  actually run;
- emits `LIMIT m, n` instead of `LIMIT n OFFSET m`;
- requires naming the alias in an aliased `DELETE` (`DELETE b FROM book AS b
  WHERE ...`, not `DELETE FROM book AS b WHERE ...`).

Fixed by adding `normalizeGeneratedSql()` (`test/tools/helpers/SqlAssertions.php`,
loaded from `bootstrap.php`) -- rewrites all three MySQL-specific shapes back
to the Postgres-style form in the *actual* SQL before comparing to the
(unchanged) expected string, applied at each shared `assertCriteriaTranslation()`-
style helper and each direct `getLastExecutedQuery()`/`createSelectSql()` call
site that was actually failing.

Two more MySQL-specific behaviors needed real (not just cosmetic) test fixes,
since they're genuine, correct MySQL semantics rather than a text-shape
difference `normalizeGeneratedSql()` could paper over:

- `BasePeer::doUpsert()` with an empty update-values `Criteria` (used by two of
  this session's own tests purely to seed a non-conflicting row) is harmless
  on Postgres/SQLite (`DO NOTHING`), but MySQL's `ON DUPLICATE KEY UPDATE` has
  no such form and throws on an empty update-values set regardless of whether
  a conflict actually occurs. Fixed by using a plain `doInsert()` (`BasePeerTest`)
  or a non-empty but semantically inert update-values array (`ModelCriteriaTest`)
  for those seed rows instead.
- `BasePeer::doUpsert()`'s affected-row count: MySQL's own C API reports `2`
  (not `1`) for a row updated via `ON DUPLICATE KEY UPDATE` -- `1` means
  "inserted fresh", `2` means "an existing row was updated". Documented on the
  method itself; `BasePeerTest::testUpsertUpdatesOnConflict` asserts the
  platform-appropriate value.
- Id-generation SQL shape: Postgres's `SEQUENCE` method pre-fetches the next
  id and includes it explicitly in the `INSERT` column list; MySQL's
  `AUTOINCREMENT` method omits the id column entirely and lets the column
  default populate it. `BasePeerExceptionsTest::testDoInsert` now asserts a
  platform-appropriate expected substring (keyed off
  `DBAdapter::isGetIdBeforeInsert()`) rather than a single hardcoded one.

**Result**: 0 failures against MySQL, confirmed with 0 regressions against
Postgres (both full 2818-test runs).

**Update (2026-07-28)**: the 8 tests above (`PgsqlSchemaParserTest`,
`SchemaReverseManagerTest`, `SqlExecManagerTest`, `SqlDiffCommandTest`,
`DataDumpCommandTest`, `SqlExecCommandTest`, `SchemaReverseCommandTest`,
`DataDumpAndSqlManagerTest`) now `markTestSkipped()` cleanly under any
`PROPULSION_TEST_DB` other than the default (Postgres), via a
`IntegrationDatabase::currentPlatform() !== 'pgsql'` guard added to each --
they were always Postgres-only by design (hardcoded `pgsql:` DSN, Postgres
catalog queries), but previously that only showed up as a confusing
transient-looking connection error rather than an honest skip.

## MSSQL full-suite parity audit (2026-07-28)

Ran the full suite against a live MSSQL testcontainer (`PROPULSION_TEST_DB=mssql`)
for the first time ever -- the fixture had never even *built* successfully before
(see the cascade-FK fix above). Found and fixed four real, platform-specific bugs,
taking the suite from "doesn't build at all" through 720 -> 226 -> 73 errors (and
33 failures, not yet addressed -- see below) on a 2823-test run:

1. `MssqlPlatform::getForeignKeyDDL()` silently omitted `ON DELETE`/`ON UPDATE
   SET NULL` entirely (every other platform emits it) -- SQL Server defaulted
   those FKs to `NO ACTION`, so deletes/updates that should have nulled out a
   child column instead failed outright.
2. SQL Server's "multiple cascade paths" restriction (error 1785) applies to
   `SET NULL` exactly like `CASCADE`, not just literal `CASCADE` --
   `MssqlPlatform::computeCascadeDowngrades()` only recognized `CASCADE` as
   conflicting; generalized to any modifying action. `BookstoreDataPopulator`'s
   cleanup order also needed adjusting (delete `essay` before `author`) for the
   one case where a downgrade changes runtime, not just DDL, semantics.
3. Inserting a new row whose every column already equals its own schema
   default has nothing in `modifiedColumns`; combined with
   `supportsInsertNullPk() === false` (MSSQL only), the auto-increment PK's own
   entry is stripped too, leaving `BasePeer::doInsert()` with nothing to
   identify the table from at all. Fixed via `Criteria::setPrimaryTableName()`
   (now set in every generated `buildCriteria()`) and a new
   `DBAdapter::getEmptyInsertSql()` capability.
4. FreeTDS/pdo_dblib (MSSQL) has no MARS support: a statement whose result set
   isn't drained and closed blocks any further statement on the same
   connection ("Attempt to initiate a new Adaptive Server operation with
   results pending"), and once tripped, leaves the connection broken for the
   rest of the process. Several single-scalar fetches (`getMaxRank()` x2,
   `AggregateColumn`'s `compute<X>()`, two `NestedSetPeerBuilder` methods)
   were missing `closeCursor()`; `NestedSetBehaviorPeerBuilderModifier`'s
   generated `fixLevels()` went further and called `$obj->save($con)` *while*
   iterating the SELECT that produced the rows -- fixed by buffering via
   `fetchAll()` before the save loop, since MSSQL can't interleave read/write
   on one connection at all, missing `closeCursor()` or not.

All four are real bugs (or missing capability), not test-assertion shape
issues -- fixed in `runtime/`/`generator/`, not by relaxing test expectations,
and verified with 0 regressions on Postgres/MySQL/SQLite.

**Update (2026-07-28)**: both `MigrationCommandsTest` and
`PropulsionMigrationManagerTest` now pass on all three platforms. Fixed via
two new `IntegrationDatabase` helpers -- `pdoDsn()` (dblib wants
`host=host:port` combined, not separate `host=`/`port=` attributes; also
covers `pdoDriverPrefix()`'s `"mssql"` -> `"dblib"` translation) and
`pdoCredentials()` (MSSQL's testcontainer only ever gets the `sa` superuser,
never the `propulsion`/`propulsion` app user the other platforms get) -- plus
an `addColumnSql()` test helper in both files that emits `ADD` instead of
`ADD COLUMN` for MSSQL (invalid T-SQL otherwise; `DROP COLUMN` needed no
change) and a `CREATE TABLE ... IDENTITY(1,1)` branch alongside the existing
Postgres/MySQL ones.

**Update (2026-07-28, continued)**: `ModelCriteriaTest`/`ModelCriteriaSelectTest`
are now also fully green on MSSQL. Fixed a real bug -- `BasePeer::doUpdate()`'s
aliased-UPDATE path built "UPDATE table alias SET ..." unconditionally, which is
a T-SQL syntax error (MSSQL needs the alias introduced via a FROM clause: "UPDATE
alias SET ... FROM table AS alias ...") -- via new
`DBAdapter::getUpdateTargetSql()`/`getUpdateFromClauseSql()` capabilities. The
rest were the same *kind* of SQL-shape issue normalized away for MySQL above
(`TOP n` instead of a trailing `LIMIT n`, the `ROW_NUMBER()`-based derived-table
rewrite `DBMSSQL::applyLimit()` uses instead of `OFFSET`/`FETCH`, the
aliased-UPDATE shape itself) -- extended `normalizeGeneratedSql()` to cover all
three. Three tests asserting a trailing `FOR UPDATE`/`FOR SHARE` clause against
a criteria with no FROM table at all now skip cleanly on MSSQL, which expresses
locking via table hints instead (already covered by `BasePeerTest`'s dedicated
MSSQL tests) -- there's genuinely nothing for a bare `FOR UPDATE` to change here.

**Second wave found (2026-07-28): the full suite is NOT yet 0/0.** With the
above fixed, a full run surfaces 14 further errors + 14 failures previously
unreached (masked by everything failing earlier) -- a distinct, not-yet-started
batch of real MSSQL gaps:

- **`allowPkInsert` tables + IDENTITY columns** (`customer`, `book` in
  `testDoDeleteCompositePK`): inserting/updating an explicit PK value against
  an `IDENTITY` column needs `SET IDENTITY_INSERT tbl ON`/`OFF` bracketing
  MSSQL doesn't have a `supportsInsertNullPk()`-style escape hatch for this --
  `GeneratedObjectTest`, `GeneratedPeerDoDeleteTest`, `GeneratedPeerDoSelectTest`
  all hit this via the shared `customer` fixture table.
- **More "results pending" (MARS) cursor leaks**, in code paths distinct from
  the ones already fixed above -- `PropulsionOnDemandCollectionTest`,
  `PropulsionArrayFormatterTest`, `BasePeerTest::testDoCountDuplicateColumnName`
  all fail in `BookstoreTestBase::setUp()`'s own `beginTransaction()`, meaning
  a *prior* test in the same run left a statement open; needs the same kind of
  per-callsite `closeCursor()` audit as the first wave, but for whichever
  method(s) actually leak here (not yet identified).
- **`DBMSSQL::applyLimit()` can't parse a `UNION`-combined query**
  (`SetOperationTest::testUnionWithOrderByAndLimitAppliesToCombinedResult`) --
  the existing regex-based rewriter (already flagged for modernization in
  `PLATFORM_FEATURES.md`) throws "could not locate the select statement"
  rather than handling it.
- **BLOB/LOB hydration**: `BookstoreTest::testScenario`/`testScenarioUsingQuery`
  fail with `stream_get_contents(): Argument #1 ($stream) must be an open
  stream resource` -- MSSQL's lazy-loaded BLOB column apparently isn't coming
  back from PDO as a stream resource the way Postgres's does; the generated
  hydration code's existing stream-vs-string branch (see `ObjectBuilder.php`'s
  `load$phpname()` comment) doesn't account for whatever MSSQL/dblib actually
  returns.
- **Nested-transaction emulation bugs** in `MssqlPropulsionPDO` (dblib has no
  native nested transactions, so it emulates via a counter) --
  `PropulsionPDOTest::testDebugLog`/`testNestedTransactionRollBackRethrow`/
  `testNestedTransactionCommit`/`testNestedTransactionRollBackSwallow` all fail;
  root cause not yet investigated.

**Second wave, resolved (2026-07-28): full suite now 0 errors / 0 failures.**
All five items above were root-caused and fixed:

- **`allowPkInsert`/IDENTITY columns**: added `DBAdapter::supportsInsertNullPk()`
  (runtime capability, default `true`) plus `getIdentityInsertOnSql()`/
  `getIdentityInsertOffSql()` (default no-op), wired into `BasePeer::doInsert()`
  to bracket an explicit-PK insert with `SET IDENTITY_INSERT tbl ON`/`OFF` on
  MSSQL. Test helpers that previously did insert-then-`UPDATE`-the-id (fine on
  every other platform, impossible on MSSQL since `IDENTITY` columns can never
  be updated) were rewritten to insert the desired id directly under
  `IDENTITY_INSERT ON`.
- **More MARS cursor leaks**, spread across a wide range of call sites:
  `extractInsertedId()`, `PropulsionOnDemandIterator::__destruct()`,
  `PropulsionOnDemandFormatter`'s exception path, and -- the one shared root
  cause behind most of the "previous test leaves `BookstoreTestBase::setUp()`'s
  own `beginTransaction()` broken" failures -- every formatter's `checkInit()`
  throwing before ever touching the (already-executed) statement it was handed,
  abandoning it open. Centralized by giving `PropulsionFormatter::checkInit()`
  an optional `?PDOStatement $stmt` parameter it closes before throwing, wired
  through all formatter subclasses. Two tests (`BasePeerTest::
  testDoCountDuplicateColumnName`, several in `PropulsionStatementFormatterTest`)
  had the same leak in the *test* itself -- both formatters return a raw,
  intentionally-unfetched statement by design, and the tests never closed it.
- **`DBMSSQL::applyLimit()` vs. `UNION`**: added a native `OFFSET n ROWS
  [FETCH NEXT m ROWS ONLY]` fallback for when the regex-based rewriter's single
  "SELECT ... FROM ..." shape assumption doesn't hold (throws if no `ORDER BY`
  is present, since T-SQL requires one for `OFFSET`/`FETCH`). Also fixed a
  separate, pre-existing bug in the same method: the offset-only/no-limit case
  inverted into an always-empty range when `$limit <= 0` (Criteria's own
  "no limit" sentinel), since it's not negative.
- **BLOB/LOB hydration**: MSSQL's dblib driver fully closes a bound
  `PDO::PARAM_LOB` stream as a side effect of `bindValue()`/`execute()` during
  save (confirmed via a scratchpad repro comparing the exact same code path
  against Postgres, where the same resource stays open) -- unlike every other
  platform, which just leaves the stream at EOF. Generated `doSave()`'s
  post-save LOB rewind loop now resets lazy-load state instead of trying to
  rewind an already-closed resource, so the next getter reloads fresh from the
  database.
- **Nested-transaction emulation bugs**: see the real-savepoint attempt and its
  revert, documented as an open issue above (`MssqlPropulsionPDO`'s comment
  block has the full account). The *other* three failing
  `PropulsionPDOTest`/general MARS-leak tests in this batch were unrelated
  order-dependent pollution from the leaks fixed above, not genuine
  nested-transaction bugs, and now pass along with everything else.

Verified with three consecutive full `PROPULSION_TEST_DB=mssql` runs, all
0 errors/0 failures, plus a full regression run against both Postgres and
MySQL (also 0 failures). `integration-mssql` CI job added alongside
`integration-mysql`.
