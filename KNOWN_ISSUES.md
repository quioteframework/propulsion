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
  `PROPULSION_TEST_DB` testcontainer options; no CI coverage yet.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
