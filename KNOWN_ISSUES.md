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
- `PROPULSION_TEST_DB=mysql|mariadb|mssql|oracle` — run the bookstore fixture
  against that platform's testcontainer instead of the default Postgres one.
  MSSQL needs `pdo_dblib`; Oracle needs `pdo_oci` built against an Instant
  Client (see `IntegrationDatabase`'s docblock for setup).
- `composer test:worker` — FrankenPHP worker-mode harness (`test/worker/`).
  Runs the same request-boundary-safety matrix against SQLite and Postgres
  (single worker thread each), plus a cross-thread matrix
  (`WORKER_THREAD_COUNT=4`). Empirically, `Propulsion::$session` and every
  connection it holds are one instance shared by *every* FrankenPHP worker
  thread in a process, not one per thread -- so request-boundary isolation
  (`Session::reset()`) is what makes concurrent requests safe, not any
  per-thread separation the runtime provides for you.
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.

## Open issues

- **Query result cache: coherence with non-ORM writes, and driver-specific
  sharing limits.** The cache now has a global (process-/host-shared) tier on
  top of the request-scoped one — any PSR-16 pool, selected by the `cache.query`
  config section; see `docs/CACHING.md` — invalidated by table version tokens.
  What remains:
  - A write that bypasses the ORM (raw SQL, another application, a migration, a
    DBA) bumps no version token, so entries covering that table stay served
    until their TTL lapses. TTL is the only backstop, which is why it defaults
    to a finite 300s rather than "never".
    `Propulsion::invalidateQueryCacheForTables()` is the escape hatch.
  - `getQueryCacheTouchedTables()` does not descend into subqueries or CTEs, so
    a query referencing a table only from inside one is not invalidated by
    writes to it (the same deliberate scope-down the compiled-query cache makes).
  - Strict single-flight needs an atomic create-if-absent, which PSR-16 cannot
    express. Propulsion's own drivers implement it; a third-party pool gets
    probabilistic early recomputation only, so N truly simultaneous cold misses
    on one key can still issue N queries there.
  - The `file` driver has no automatic expiry GC beyond lazy unlink-on-read: an
    entry that expires and is never requested again occupies disk until
    `FileCache::prune()` runs (schedule it; see `docs/CACHING.md`).
- **The `array` and `apcu` cache drivers share less than they appear to.**
  `array` is per-process and, under a *threaded* worker, per-thread — the
  `l2:array-cross-thread` profile in `test/worker/` measures this directly (7 of
  16 concurrently-written keys visible to the reading thread). `apcu` is
  per-host and dies with the php-fpm master, and because `apc.enable_cli` gives
  each CLI process its own segment, a cron job can neither read nor *invalidate*
  the web tier's entries. If anything writes to the database from CLI, use
  `file` or a shared network pool.
- **MSSQL full-suite parity**: `PROPULSION_TEST_DB=mssql` is 0 errors/0
  failures as of the last audit, but has much less scrutiny overall than
  Postgres/MySQL.
- **MariaDB**: served by `DBMySQL`/`MysqlPlatform` as though it were MySQL
  (`DBMySQL::isMariaDb()` detects it at the runtime-connection level for the
  few things that already need to differ, e.g. `RETURNING`), no dedicated
  `MariadbPlatform`. Not run in CI.

## Known-open, deliberately not fixed

Findings from the code-review pass that landed in `e06386a`/`cfe545a`/`547af90`,
left open with a reason rather than silently:

- **A dropped connection is detected on `exec()`/`query()`/`DebugPDOStatement::execute()`
  only.** Almost all ORM traffic goes through `PDOStatement::execute()` on a
  plain (non-debug) statement, which nothing wraps, so most dropped connections
  surface as an ordinary `PDOException` with no pool eviction. Closing the gap
  means either routing every execute through a Propulsion statement class or
  checking at the `BasePeer`/`ModelCriteria` call sites; both are wider than a
  bug fix. Note that the *recovery* story does not change either way — see
  `PropulsionPDOTrait::handleDroppedConnection()` on why transparent retry
  cannot be made safe below the transaction boundary.
- **`Propulsion::initialize()` only resets `$connectionMap`.** `$adapterMap`,
  `$dbMaps` and the memoised `$defaultDBName` survive a `setConfiguration()`, so
  reconfiguring a live process (multi-tenant hosts, test harnesses swapping
  datasources) keeps the previous default datasource and adapters. Resetting
  them is easy; knowing whether anything relies on the current behaviour is not.
- **`Criterion::equals()` compares chained sub-clauses by identity** (`===`)
  rather than recursively, so two structurally identical chained criterions
  never compare equal. Feeds `Criteria::equals()` and `addJoinObject()`'s
  `in_array()` dedupe. Inherited from Propel 1; fixing it changes when joins get
  deduplicated, which wants its own test pass.
- **The shared tier stores one entry per formatter.** `SharedQueryCache::buildKey()`
  folds in the `$variant` discriminator that the request-scoped tier genuinely
  needs (it stores *formatted* results). L2 stores raw rows, so an
  ARRAY-formatted and an OBJECT-formatted query with identical SQL could share
  one entry and currently do not. Worth doing; needs coverage proving the two
  formatters really can consume one another's stored rows.

## Missing modernization work

- **PSR-18**: not started, nothing to wire it into yet.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
