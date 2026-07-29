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
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.

## Open issues

- **Testcontainer leak on `kill -9`** (theoretical, mitigated by
  `composer test:cleanup-containers`, not seen in practice).
- **MSSQL nested transactions are emulated, not real.** `MssqlPropulsionPDO`
  uses a depth counter + coarse "poisoned" flag rather than real T-SQL
  `SAVE TRANSACTION`/`ROLLBACK TRANSACTION` savepoints — a nested rollback
  doesn't undo just its own work, it poisons the whole outer transaction so a
  later `commit()` throws. A real-savepoint version regressed ~500 tests
  (something leaves `getNestedTransactionCount()` unbalanced across many
  otherwise-unrelated tests sharing one process-lifetime connection); needs
  that root cause found before real savepoints can be attempted again.
  `PropulsionPDOTest::testNestedTransactionRollBackSwallow` is skipped on
  MSSQL as a result.
- **MSSQL: a failed `INSERT` can silently "succeed" instead of throwing.**
  Every `INSERT` folds id retrieval in via `DBMSSQL`'s `OUTPUT INSERTED.<col>`
  clause; when that `INSERT` violates a constraint, pdo_dblib's
  `PDOStatement::execute()` returns `true` with an empty `OUTPUT` result set
  instead of throwing, so `extractInsertedId()` gets back `false`/`null` with
  no exception at all — a plain `INSERT` with no `OUTPUT` clause throws
  correctly. Needs a `rowCount() === 0` (or similar) check specifically on
  the insert-returning path.
- **`buildtime-conf.xml`** (legacy XML build-time config) is still accepted
  alongside the plain-PHP format — no way to confirm from this repo that no
  consumer still relies on it. Drop at a major-version boundary.
- **Worker-safety harness (`test/worker/`)** only covers SQLite on a single
  FrankenPHP worker thread, not Postgres or cross-thread behavior.
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
