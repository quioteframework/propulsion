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

## Missing modernization work

- **PSR-18**: not started, nothing to wire it into yet.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
