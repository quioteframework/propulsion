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
  needs `pdo_dblib` on the host PHP; Oracle needs `pdo_oci` built against a
  real Instant Client (see `IntegrationDatabase`'s class docblock for exact
  setup steps — neither is a standard PHP build).
- `composer test:worker` — FrankenPHP worker-mode harness (`test/worker/`).
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.

## Open issues

- **Testcontainer leak on `kill -9` (theoretical, mitigated, not seen in
  practice).** `IntegrationDatabase` stops its container via
  `register_shutdown_function()`, which can't run on `kill -9`/`timeout -s
  KILL`. Containers are labeled `propulsion.test-container=true` so
  `composer test:cleanup-containers` can find and remove any that do leak.
- **MSSQL: the shared bookstore fixture can't fully load — not a bug, a real
  SQL Server restriction.** `essay`/`composite_essay`/etc. have more than one
  `ON {UPDATE,DELETE} CASCADE` foreign key reaching the same target table,
  which SQL Server disallows outright ("may cause cycles or multiple cascade
  paths", MSSQL error 1785) — Postgres/MySQL both allow this freely. 84/90,
  2/2, and 52/56 statements across the three fixture files succeed; the ~10
  failures are all this one restriction. Fixing it means either changing the
  shared fixture schema (affects every platform) or having the generator
  detect multi-cascade-path tables and downgrade to `NO ACTION` for MSSQL
  specifically — neither attempted here.
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
- **MSSQL/Oracle platform parity**: mostly still unaudited (only `PgsqlPlatform`
  vs `MysqlPlatform` has had a full feature-parity pass against
  `DefaultPlatform`), but wiring both up as real `PROPULSION_TEST_DB` options
  and running them against the actual bookstore fixture already surfaced and
  fixed three real bugs nobody had hit before:
  - `MssqlPlatform::getDropTableDDL()`'s per-table DROP-guard cursor was
    always named the bare `refcursor` (only the two scalar variables were
    disambiguated, via a `$dropCount` counter that — separately — turned out
    not to reliably increment across a real multi-file generation run
    either). Both are now derived from the table's own name instead of a
    counter, which can't collide.
  - `MssqlPlatform` never overrode `getAddTablesDDL()` the way
    `Pgsql`/`OraclePlatform` do, so a table's FK constraints were emitted
    right after that table instead of in a second pass — broken the instant
    a table's FK references another table declared *later* in the schema
    (exactly this fork's own bookstore fixture: `book` → `publisher`/`author`).
  - `OraclePlatform::quoteIdentifier()` was a hardcoded no-op — any
    identifier colliding with an Oracle reserved word (the bookstore
    fixture's own `acct_audit_log.uid` column collides with `UID`) failed
    outright. Now quotes (and uppercases) only actual reserved words, so
    every other identifier's behavior is unchanged.
  Full remaining gap: no CI coverage for either platform, and the MSSQL
  cascade-path fixture limitation above.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
