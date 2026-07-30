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

- **Query result cache (`Criteria::setQueryCache()`) is request-scoped only.**
  No process-scoped (cross-request) tier, so it only helps repeated identical
  queries within one request/script run. A cache hit is also invisible to
  writes that bypass the ORM (raw SQL on the same connection, a different
  process/connection).
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
