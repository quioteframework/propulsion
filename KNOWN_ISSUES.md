# Known issues and remaining work

This file tracks two things: **currently open issues** and **modernization
work not yet done**. It's meant to be short enough to skim — for the
detailed story of how any past issue was found and fixed, read the commit
history (`git log`); every fix commit explains its own root cause in full.

## Test suite status

**Full suite (Docker/Postgres): 2222 tests, 0 errors, 2 failures, 13
skipped.** **No-Docker mode (`PROPULSION_SKIP_INTEGRATION=1`): same 2222
tests, 0 errors, 2 failures, 1114 skipped.** Both modes now agree exactly on
errors/failures — see the "MssqlPlatformTest static-counter flake" entry
below for the 2 pre-existing failures (unrelated to Docker, present in both
modes, not caused by or fixed as part of the no-Docker triage below).

```
cd test
rm -rf fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
../vendor/bin/phpunit -c phpunit.xml
```

First run pulls a `postgres:latest` image and builds fixtures into a
testcontainer (a few minutes). Set `PROPULSION_SKIP_INTEGRATION=1` to skip
everything that needs a live database if Docker isn't available. Set
`PROPULSION_TEST_DB=mysql` to run the main bookstore fixture against a MySQL
testcontainer instead, useful for double-checking whether a failure is a
real bug or a genuine MySQL/Postgres SQL-semantics difference before writing
a platform-conditional branch into a test (see `IntegrationDatabase::currentPlatform()`).

CI (`.github/workflows/tests.yml`) runs both the no-Docker `unit` tier and
the Docker-backed `integration` tier on every push/PR. Both are now
effectively equally green (mod the pre-existing Mssql flake below), so the
`unit` job's `continue-on-error: true` can be flipped to blocking.

**No-Docker mode used to error on ~77 tests instead of skipping cleanly (or
running).** Triaged individually; they split roughly evenly into two real
categories, not one:

- **~22 were genuine DB round-trip tests** (`PropulsionPDOTest`: real
  transactions/commits/rollbacks over a live PDO connection;
  `PropulsionPagerTest`: `save()`/`doSelect()` against real rows;
  `MysqlSchemaParserTest`: reverse-engineering a live MySQL schema) that
  correctly belong in the integration tier. Most already had a
  `markTestSkipped()` guard (via `BookstoreTestBase`/`BookstoreEmptyTestBase`,
  or their own `try`/`catch` around `getConnection()`) — the actual bug was
  that their **`tearDown()`** unconditionally ran DB cleanup / reset
  Propulsion's config, even on a test that had just skipped itself in
  `setUp()`, turning a clean skip into an error. Fixed by guarding those
  `tearDown()` bodies (only clean up / reset config if setup actually got
  that far). `PropulsionPDOTest` had no guard at all — added one (deliberately
  not by extending `BookstoreTestBase`, since that opens an outer transaction
  that would throw off this file's own nested-transaction-count assertions).
- **~57 were pure generator-output/object-model tests that never open a
  database connection at all** (`FieldnameRelatedTest`, `OMBuilderTest`,
  `TableBehaviorTest`: inspect generated PHP class shape/constants/methods;
  `CriteriaTest`, all 45 methods: build SQL strings in memory against a
  swapped-in `DBSQLite()`/`DBMySQL()` adapter object, never executing
  anything). These were only Docker-gated because generating the Bookstore
  fixture *classes* used to happen as a side effect of
  `IntegrationDatabase::ensureReady()`, which tries to start a Postgres
  testcontainer *before* it ever gets to the (database-free) codegen step.
  Fixed by splitting codegen out into `IntegrationDatabase::
  ensureClassesGenerated()` — pure schema-XML-to-PHP generation via the same
  `ModelManager`/`SqlManager` classes `bin/propulsion` uses, no database
  connection of any kind — and running it unconditionally, before the
  Docker/live-DB half of `ensureReady()` is attempted. `CriteriaTest` also no
  longer extends `BookstoreTestBase` (it never used the inherited `$this->con`
  and doesn't need one). Net effect: these tests now run for real (not just
  skip) under `PROPULSION_SKIP_INTEGRATION=1` or with no Docker at all.

## Open issues

- **`MssqlPlatformTest` static-counter flake (2 failures, both modes).**
  `MssqlPlatform::$dropCount` is a static counter baked into generated
  `DROP TABLE` DDL (`@reftable_N`/`@constraintname_N` cursor variable
  names), and the test file hardcodes the exact counter value each
  assertion expects, implicitly depending on running every preceding test
  method (including every `@dataProvider` iteration) in exactly the order
  this file was originally written against. `testGetDropTableDDL` and
  `testGetDropTableDDLSchema` currently expect off by one from what the
  suite's actual (still internally consistent, just numbered one higher/
  lower than hardcoded) execution order produces. Reproduces identically
  standalone (`phpunit testsuite/generator/platform/MssqlPlatformTest.php`)
  and Docker or no-Docker, so it's unrelated to the no-Docker triage below —
  a pre-existing test-ordering fragility, not a real DDL-generation bug.
  Not yet fixed; the real fix is to stop hardcoding the counter value (reset
  `MssqlPlatform::$dropCount` in `setUp()` and compute the expected value
  from a local counter, or assert on the DDL with the counter value
  interpolated symbolically) rather than to reorder tests to match the
  hardcoded numbers.
- **Testcontainer cleanup**: `IntegrationDatabase` stops its container via
  `register_shutdown_function()`, which doesn't run on `kill -9` or a
  `timeout`-killed process, so an interrupted run can leak a container.
  Since a leaked container's own randomly-generated name isn't predictable
  (and there's no portable, non-OS-specific way to signal an arbitrary
  process anyway), every testcontainer this class starts carries the label
  `propulsion.test-container=true`. Run `composer test:cleanup-containers`
  to find and remove them via `docker stop`/`docker rm` — works the same
  regardless of host OS, since it goes through the Docker daemon rather
  than sending a signal directly.
- **Worker-safety test matrix**: built, green. `test/worker/` is a real
  FrankenPHP (`dunglas/frankenphp:php8.5`) worker-mode Docker harness plus a
  black-box driver (`test/worker/driver.php`) that builds the image, starts a
  container, and makes real sequential HTTP requests against it with curl,
  asserting on JSON responses -- proving the properties Phase 4a/4b's
  `ServiceContainer`/`Session` split exists to deliver actually hold under a
  real persistent-worker process, not just in the unit tests
  (`test/testsuite/runtime/{ServiceContainer,Session,SessionResetTransaction}Test.php`)
  that exercise `Session::reset()` directly. Run via `composer test:worker`
  (skips cleanly if Docker isn't available, or if `PROPULSION_SKIP_INTEGRATION=1`
  is set, matching `IntegrationDatabase`'s convention; its container is
  labeled `propulsion.test-container=true` so a leaked container is covered
  by `composer test:cleanup-containers` same as the Postgres/MySQL
  testcontainers). Set `WORKER_TEST_LOAD_REQUESTS` to change the sustained-load
  request count (default 500).

  What it proves, all six green as of this writing:
  - No object bleed across requests: request A pools an instance
    (`Session::addPooledInstance()`, the same call a generated Peer's
    `addInstanceToPool()` makes), request B on the same worker process
    doesn't see it after the boundary `Session::reset()` call.
  - Dangling transaction cleanup: request A opens a transaction against a
    real SQLite-backed `PropulsionPDO` connection and returns without
    committing/rolling back (simulating an app bug); request B neither
    inherits an open transaction nor sees the uncommitted row -- proving
    `Session::reset()`'s `forceRollBack()` call actually runs at the request
    boundary. A control case (a *committed* row surviving the reset) rules
    out the trivial "wipe the whole DB every request" false-pass.
  - Connection persistence: the same `PropulsionPDO` object (by
    `spl_object_id()`) is reused across requests in the same worker --
    process-scoped state is not torn down per request, which would defeat
    the performance point of worker mode.
  - `forceMasterConnection` isolation: request A sets it `true`; request B
    starts back at the default `false`.
  - Memory doesn't grow unboundedly under sustained load: 500 requests each
    adding a uniquely-keyed pooled instance and committing a row show
    exactly flat memory (same `memory_get_usage()` reading at the start and
    end of the run in local testing) and an empty instance pool afterward --
    a real regression test for the "growing instance pools never getting
    cleared" failure mode this whole rework was meant to prevent.

  Deliberate scope choices, worth knowing if this needs extending later:
  - Uses SQLite, not Postgres: `dunglas/frankenphp` images ship `pdo_sqlite`
    but not `pdo_pgsql` (would need a custom image layer with
    `install-php-extensions pdo_pgsql`, plus a Postgres testcontainer wired
    up the way `IntegrationDatabase` does it). SQLite still exercises a real
    `PropulsionPDO` connection/transaction/adapter code path end-to-end, so
    it proves the same property; it does not prove anything Postgres-adapter-
    specific.
  - Drives `Session`'s pooling API directly (`addPooledInstance()`/
    `getPooledInstance()`) rather than through generated Peer classes --
    running the code generator inside the worker image would add moving
    parts (schema, build step, fixture DB) without adding certainty, since
    the actual worker-safety contract lives entirely in
    `Session`/`ServiceContainer`/`PropulsionPDO`, not in generated-code
    boilerplate that just calls through to it.
  - The FrankenPHP worker is pinned to exactly one thread/instance
    (`worker /app/test/worker/public/index.php 1` in `test/worker/Caddyfile`)
    so the test driver's sequential requests are guaranteed to hit the same
    process -- FrankenPHP's default is one worker instance per CPU thread,
    which would make "did request B share a process with request A"
    non-deterministic otherwise. A production deployment would run more than
    one worker thread; this harness doesn't test cross-thread behavior
    (each thread has its own independent `Propulsion`/`Session` statics
    anyway, so there is nothing cross-thread to bleed).
- **Phing `Task` classes** (`generator/Lib/Task/*`, 15 files) are still
  present, gated on proving output parity between the Phing path
  (`generator/bin/propel-gen`) and the `bin/propulsion` console path. No
  formal side-by-side comparison has been done. The tasks that actually
  matter going forward are **OM** (`PropulsionOMTask`), **SchemaReverse**
  (`PropulsionSchemaReverseTask`), **Diff** (`PropulsionSQLDiffTask`), and
  **migrations** (`PropulsionMigrationTask`/`*UpTask`/`*DownTask`/
  `*StatusTask`/`BasePropulsionMigrationTask`) — prioritize proving parity
  for those. `PropulsionDataDumpTask`/`PropulsionDataSQLTask`/
  `PropulsionGraphvizTask`/`PropulsionSQLExec`/`PropulsionSQLTask` are lower
  priority.
- **`PropulsionConvertConfTask` should be deprecated, not preserved.** It
  exists to convert the old XML runtime/buildtime config format
  (`runtime-conf.xml`/`build.properties`) into the PHP array config this
  codebase actually consumes at runtime. The XML config format itself is
  legacy baggage from upstream Propel that this fork should move away from
  entirely (config should just be authored as PHP arrays/a PHP config file
  directly) rather than keep a converter task around indefinitely as a
  crutch. Not scoped or started — noting the direction here so "should we
  port ConvertConf to the console app too" doesn't come up without this
  context.
- **Postgres isn't actually the documented/default database** despite being
  what all fixtures and CI use: `generator/default.properties`'s
  `propel.database` is still empty, the README doesn't recommend one, and
  `PgsqlPlatform` hasn't had a feature-parity audit against `MysqlPlatform`
  beyond what's needed to unblock fixture loading.
- **PSR-18**: not started — no HTTP client usage exists anywhere in this
  codebase, so there's nothing concrete to wire it into yet.
- **OTEL instrumentation**: explicitly out of scope, not planned.

## Modernization phases

Phases 0–2 (identity rename, CLI cutover, PSR-3 logging) and 3–4b are done.
4c/4d and beyond:

- **Phase 4c** (delete legacy `PHP5*` builders): done — see Phase 3.5 below,
  which did this ahead of the original 4a/4b-gated order per explicit
  request.
- **Phase 4d** (Quiote adapter integration): tracked in the Quiote-side doc,
  not this repo.

### Phase 3 — PHP84 builders promoted to canonical

`query`/`tablemap` builders were promoted and `default.properties` flipped
away from `targetPlatform=php5` as the default. `peer`/`object`/`node`/
`nestedset` builders needed real completeness fixes first (behavior-modifier
hooks, property-naming, missing methods) — see Phase 3.5, which finished the
promotion by removing PHP5 entirely once those fixes landed.

### Phase 3.5 — PHP5 builders removed entirely

All legacy `PHP5*` generator builders are deleted from
`generator/Lib/Builder/OM/`; archived unmodified at
`archaeology/php5-builders/` as a reference for the original PHP5 codegen
logic, in case a future bug needs comparing against what the old templates
used to generate. They are not autoloaded and not reachable from
`default.properties` — the promoted builders are the only code path now.

### Phase 4 — Worker-safety rework (ServiceContainer/Session split)

`Propulsion\ServiceContainer` (process-scoped: connections, adapters,
database maps) and `Propulsion\Session` (request-scoped: instance pools,
`forceMasterConnection`, transaction-rollback-on-reset) exist behind
`Propulsion::getServiceContainer()`/`getSession()`. Generated Peer classes'
instance pools delegate to `Session` (no more per-class `static $instances`
arrays); `Session::reset()` force-rolls-back open transactions and clears
pools. Instance pooling defaults to on. See `runtime/Lib/ServiceContainer.php`,
`runtime/Lib/Session.php`, and `test/testsuite/runtime/{ServiceContainer,Session,SessionResetTransaction}Test.php`
for the actual contract. The worker test matrix proving this actually holds
under a real persistent-worker process now exists too -- see `test/worker/`
and the "Worker-safety test matrix" entry under "Open issues" above.

### `Propel*` → `Propulsion*` class rename

Every `Propel`-prefixed class/interface/trait (the namespace was already
`Propulsion\`; only bare class basenames still said `Propel`) was renamed to
`Propulsion*`, including the main facade (`Propel::getConnection()` →
`Propulsion::getConnection()`). Hard cutover, no `class_alias()` compat
shims added for the renamed classes themselves.

`runtime/Lib/legacy-class-map.php` and
`test/tools/helpers/generator-legacy-class-map.php` are a *pre-existing*,
unrelated bare-global-name → FQCN aliasing system (for old, already-generated,
unnamespaced code that references runtime classes by bare name). Only their
FQCN *values* were updated; a second set of entries was added keyed by the
new bare `Propulsion*` names alongside the untouched old `Propel*` ones —
both spellings resolve.

Author/contributor attribution lines crediting the historical upstream
Propel/Torque projects (e.g. `@author ... (Propel)`) were left as-is —
those name real historical contributions, not this codebase's own naming.
