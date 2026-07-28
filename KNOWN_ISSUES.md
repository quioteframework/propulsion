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
- **MSSQL: shared bookstore fixture can't fully load.** Some tables have
  multiple `CASCADE` FKs to the same target table, which SQL Server
  disallows (error 1785) but Postgres/MySQL allow. Needs either a schema
  change or per-platform `NO ACTION` downgrade logic.
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
- **MSSQL/Oracle platform parity**: unaudited against `DefaultPlatform`
  (only Pgsql vs Mysql has had that pass). Both now have real
  `PROPULSION_TEST_DB` testcontainer options; MySQL now has CI coverage (see
  below), MSSQL/Oracle still don't.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.

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

**Separate, pre-existing, NOT part of MySQL parity**: running the suite with
`PROPULSION_TEST_DB=mysql` still shows ~32 errors, all from
`PgsqlSchemaParserTest`/`SchemaReverseManagerTest`/`SqlExecManagerTest`/
`SqlDiffCommandTest`/`DataDumpCommandTest`/`SqlExecCommandTest`/
`SchemaReverseCommandTest`/`DataDumpAndSqlManagerTest`. These hardcode their
own `pgsql:` DSN regardless of `PROPULSION_TEST_DB` (by design -- they test
Postgres-specific reverse-engineering/schema-diff tooling) and open a second,
independent Postgres testcontainer alongside whatever `PROPULSION_TEST_DB`
started. Consistently hit transient SSL-negotiation errors from that second
container under container-resource contention (running two testcontainers at
once) across every audit run in this session -- worth investigating
separately if it persists in CI, but unrelated to MySQL parity itself.
