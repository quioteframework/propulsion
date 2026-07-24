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
- `PROPULSION_TEST_DB=mysql` — run the bookstore fixture against MySQL instead of Postgres.
- `composer test:worker` — FrankenPHP worker-mode harness (`test/worker/`).
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.

## Open issues

- **Testcontainer leak on `kill -9`.** `IntegrationDatabase` stops its
  container via `register_shutdown_function()`, which doesn't run on
  `kill -9`/`timeout -s KILL`. Containers are labeled
  `propulsion.test-container=true` so `composer test:cleanup-containers` can
  find and remove them, but nothing does this automatically.
- **MSSQL/Oracle `supportsTransactionalDDL()` is unverified.** Both default to
  `false` (conservative) because there's no live instance in this project to
  confirm actual DDL transactionality against. Only `PgsqlPlatform`/
  `SqlitePlatform` are confirmed `true`.
- **`buildtime-conf.xml` (legacy XML build-time connection config) is still
  accepted alongside the plain-PHP format**, purely because there's no way to
  verify from this repo whether any consuming project still relies on it.
  Drop XML support once that's confirmed, or at a major-version boundary.
- **Worker-safety harness (`test/worker/`) only covers SQLite on a single
  FrankenPHP worker thread.** Proves the `Session`/`ServiceContainer`
  contract, not Postgres-adapter specifics or cross-thread behavior. Extend
  if either becomes a real question.

## Missing modernization work

- **PSR-18**: not started. No HTTP client usage exists anywhere in this
  codebase yet, so there's nothing to wire it into.
- **MSSQL/Oracle platform parity**: never audited. Only `PgsqlPlatform` vs
  `MysqlPlatform` has had a feature-parity pass against `DefaultPlatform`.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
