# Known issues and remaining work

This file tracks two things: **currently open issues** and **modernization
work not yet done**. It's meant to be short enough to skim — for the
detailed story of how any past issue was found and fixed, read the commit
history (`git log`); every fix commit explains its own root cause in full.

## Test suite status

**Full suite (Docker/Postgres) is green: 2233 tests, 0 errors, 0 failures, 0
risky, 13 skipped**, *except* for a pre-existing order-dependent flake in
`MssqlPlatformTest` (see that bullet under "Open issues") that the generator
Task-class tests added below happen to reliably trigger — not a regression in
the Task classes or the fix that unblocked them, just the first thing to
surface it in a full run instead of only a filtered one.

```
cd test
rm -rf fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
../vendor/bin/phpunit -c phpunit.xml
```

First run pulls a `postgres:latest` image and builds fixtures into a
testcontainer (a few minutes). Set `PROPULSION_SKIP_INTEGRATION=1` to skip
everything that needs it if Docker isn't available — see the "No-Docker
mode" issue below, though; that path isn't fully green yet. Set
`PROPULSION_TEST_DB=mysql` to run the main bookstore fixture against a MySQL
testcontainer instead, useful for double-checking whether a failure is a
real bug or a genuine MySQL/Postgres SQL-semantics difference before writing
a platform-conditional branch into a test (see `IntegrationDatabase::currentPlatform()`).

CI (`.github/workflows/tests.yml`) runs both the no-Docker `unit` tier and
the Docker-backed `integration` tier on every push/PR; `integration` is
blocking.

## Open issues

- **No-Docker mode (`PROPULSION_SKIP_INTEGRATION=1`) has ~77 errors.** These
  are tests that depend on the Docker-built fixtures but aren't individually
  guarded with `markTestSkipped()`/`class_exists()` the way most of the
  suite is — they error instead of skipping cleanly. Not yet triaged
  file-by-file. The `unit` CI job is `continue-on-error: true` because of
  this.
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
- **`MssqlPlatformTest` is order-dependent and flaky.** `MssqlPlatform` uses a
  plain `static $dropCount` counter (shared process-wide, across every
  `MssqlPlatform` instance) to name cursor variables in its drop-table-with-FK
  DDL, and several `MssqlPlatformTest` assertions hardcode the exact counter
  value they expect at that point (e.g. `@reftable_6`). Since PHPUnit
  evaluates every test class's data providers up front before running any
  test body, *any* change that shifts how many times some `MssqlPlatform`
  instance generates that DDL before `MssqlPlatformTest`'s own assertions run
  — including, concretely, just adding unrelated new test files anywhere in
  the suite (confirmed while adding the generator Task-class tests below,
  which don't touch MSSQL at all) — throws the expected counter value off by
  one and fails 1-2 assertions. Confirmed via `git stash` that a clean-tree
  full run is 0/0; do not attempt to fix the counter or the test as part of
  unrelated work — it needs a real fix (e.g. resetting the counter per test,
  or generating cursor variable names some other way) on its own.
- **Worker-safety test matrix not run.** Phase 4a/4b (below) built the
  `ServiceContainer`/`Session` split and unit-tested it directly, but the
  actual worker-mode property (no object bleed across requests, connection
  persistence, memory doesn't grow under sustained load) needs a real
  worker harness (FrankenPHP or equivalent) this repo doesn't have. Nothing
  to do here until such a harness exists.
- **Phing `Task` classes** (`generator/Lib/Task/*`, 15 files) now have real
  test coverage (`test/testsuite/generator/task/`) and are confirmed working,
  but `generator/bin/propel-gen` itself was actually broken for *every*
  project until this pass fixed it — see below. The tasks are not yet
  deletable: they're the only path for SchemaReverse/Diff/Migrations (the
  console app has no equivalents), and OM parity is now proven rather than
  assumed, not obsoleted.
  - **Root cause fixed**: `generator/default.properties` declared
    `propel.project`, `propel.project.dir`, and `propel.targetPackage` as
    templates referencing each other in the same file (e.g.
    `propel.project.dir = ${propel.home}/projects/${propel.project}`). Phing's
    `<property file=...>` resolves placeholders against that file's own
    just-parsed table before ever falling back to the live project table, so
    every directory property derived from `propel.project.dir`
    (`propel.schema.dir`, `propel.output.dir`, `propel.php.dir`,
    `propel.sql.dir`, `propel.migration.dir`, ...) silently resolved to the
    file-local empty default instead of whatever `propel-gen`/`build.xml` had
    actually set — meaning `propel-gen <task>` failed with "No schema files
    were found" for every single project, unconditionally. Moved those three
    properties into `generator/build-propel.xml` as plain `<property>` tasks
    (which substitute against the live project table) instead. Also fixed
    `propel.reverse.parser.class` and `propel.builder.datasql.class`, which
    had the same "`${propel.database}`-templated path" bug in a different
    shape: `Reverse\${propel.database}\${propel.database}SchemaParser` and
    `Builder\SQL\${propel.database}\${propel.database}DataSQLBuilder` can
    never resolve, because those directories/namespaces are cased `MySQL`/
    `PgSQL`/`MSSQL`/... while `propel.database` is always lowercase — replaced
    both with explicit per-database class properties, matching the existing
    `propel.platform.*.class` convention.
  - **OM** (`PropulsionOMTask`): confirmed at parity — `PropulsionOMTaskTest`
    generates the same schema (FK + a built-in behavior) through
    `PropulsionOMTask` and the console `model:build` path
    (`Propulsion\Generator\Manager\ModelManager`) and asserts the two output
    trees are byte-for-byte identical (modulo the autogenerated timestamp
    line).
  - **SchemaReverse** (`PropulsionSchemaReverseTask`): confirmed working
    end-to-end — `PropulsionSchemaReverseTaskTest` reverse-engineers a real
    two-table, one-FK Postgres schema (via the shared testcontainer) and
    checks the resulting schema.xml's tables/columns/types/FK. Caveat: the
    reversed `NUMERIC(p,s)` column's `size` attribute doesn't decode
    Postgres's packed typmod correctly (comes out as a raw encoded integer,
    e.g. `655362` instead of `10`); pre-existing in `PgsqlSchemaParserV12Plus`
    and out of scope for this pass (structure/FK/most types are all correct).
  - **Diff** (`PropulsionSQLDiffTask`): confirmed correct —
    `PropulsionSQLDiffTaskTest` covers both the shared diff engine
    (`PropulsionDatabaseComparator` + `Platform::getModifyDatabaseDDL()`) on
    two schema.xml versions with a real structural change (added columns,
    widened `VARCHAR`, added FK), and the Task itself end-to-end against a
    live, deliberately out-of-date Postgres schema, checking the generated
    `PropulsionMigration_<timestamp>.php` class' up/down SQL. Note:
    `PropulsionSQLDiffTask` only supports "live database vs schema.xml" (it
    reads a buildtime-conf for connections); there's no "two schema.xml
    files" mode on the Task itself, which is why that half of the coverage
    drives the comparator directly instead.
  - **Migrations** (`PropulsionMigrationTask`/`*UpTask`/`*DownTask`/
    `*StatusTask`/`BasePropulsionMigrationTask`): confirmed correct —
    `PropulsionMigrationTaskTest` runs a full `status` → `up` → `down` cycle
    against a real Postgres table (a hand-written migration class in exactly
    the format `PropulsionMigrationManager::getMigrationClassBody()`/
    `PropulsionSQLDiffTask` produce), checking the live schema actually
    changes and the tracked version updates each step. Along the way, fixed
    `PropulsionMigrationManager::getOldestDatabaseVersion()`: it used a
    truthy check on the fetched version column, so a legitimate version of
    `0` (the documented "nothing applied yet" baseline, and what a full "down"
    back to the start leaves behind) was indistinguishable from "no row
    fetched" and incorrectly returned `null`.
  - **Lower priority** (`PropulsionDataDumpTask`, `PropulsionDataSQLTask`,
    `PropulsionGraphvizTask`, `PropulsionSQLExec`, `PropulsionSQLTask`): all
    five now have a real smoke test (`LowerPriorityTaskSmokeTest`) exercising
    genuine minimal input (real schema, and for `DataDump`/`SQLExec` a real
    Postgres table) — **all five pass**. `PropulsionSQLTask` generates DDL
    containing `CREATE TABLE`; `PropulsionSQLExec` executes that DDL for real
    against a live database; `PropulsionGraphvizTask` produces a `.dot` file
    referencing the expected table; `PropulsionDataDumpTask` dumps a real row
    from a live table into `dataset`-format XML; `PropulsionDataSQLTask`
    converts a small hand-written data XML file into `INSERT` SQL (this is
    also what surfaced the `propel.builder.datasql.class` bug fixed above).
  - **Known side effect of this work**: adding the new generator/task tests
    reliably perturbs the pre-existing `MssqlPlatformTest` static-counter
    order-dependency into failing even in a *full* suite run, not just a
    filtered one — see that bullet below. Not a regression in the Task
    classes; confirmed via `git stash` that a clean-tree full run is
    genuinely 0/0 while the new tests exist alongside it.
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
for the actual contract. What's left: the worker test matrix (see "Open
issues" above).

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
