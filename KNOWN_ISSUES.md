# Known issues and remaining work

This file tracks two things: **currently open issues** and **modernization
work not yet done**. It's meant to be short enough to skim — for the
detailed story of how any past issue was found and fixed, read the commit
history (`git log`); every fix commit explains its own root cause in full.

## Test suite status

**Full suite (Docker/Postgres) is green: 2263 tests, 0 errors, 0 failures, 0
risky, 14 skipped.** (The `MssqlPlatformTest` order-dependent flake mentioned in earlier
drafts of this section is fixed — see the `Propel*`/rename-era commit history
around `MssqlPlatform::$dropCount` if curious; it's no longer an issue.)

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
the Docker-backed `integration` tier on every push/PR; both are blocking.

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

- **Coverage reporting was silently broken (fixed): `pcov` (1.0.12) doesn't
  instrument anything on PHP 8.5** -- no error, no warning, just a `0.00%`
  report regardless of what actually ran, because `php-code-coverage`
  prefers pcov over Xdebug whenever both are present and pcov claims to be
  available. `.github/workflows/tests.yml`'s `integration` job (the source
  of the Codecov number) requested `coverage: pcov`, so the uploaded
  coverage was never real. Switched that job to `coverage: xdebug` (with
  `XDEBUG_MODE: coverage` on the PHPUnit step) -- confirmed working
  end-to-end locally: a trivial script under `pcov\start()`/`pcov\collect()`
  reports zero covered lines even for code that just ran, while the
  identical scenario under Xdebug's `xdebug_get_code_coverage()` reports
  correctly. If pcov gets fixed upstream for PHP 8.5 and its speed advantage
  is wanted back, verify with the same kind of standalone script before
  switching back -- a silent 0% is easy to miss in a coverage report that
  otherwise looks plausible.

- **Single-table-inheritance discriminator resolution now uses real FQCNs
  (fixed), but the `extends="..."` cross-namespace override is still
  unaddressed.** `<inheritance>` elements' `CLASSNAME_*` peer constants used
  to hold a legacy Propel dot-path (`Foo.Bar.SomeClass`, derived from the
  `package` attribute, not the real PHP namespace); the discriminator
  lookup (`getOMClass()`) stripped that down to a bare class name before
  `new $cls()`. Since dynamic `new $variable()` in PHP is always resolved
  from the *global* namespace regardless of the calling file's own
  namespace (unlike a literal `new SomeClass()` or `class X extends Y`,
  both of which go through normal `use`-import/current-namespace
  resolution), this silently threw "Class not found" for any namespaced
  schema with single-table inheritance — but only on a cold read (a fresh
  process/request with an empty instance pool); a same-request
  save-then-find round trip masked it by serving the just-created object
  straight out of the pool instead of re-instantiating from the row. Fixed
  by using the real fully-qualified class name for `CLASSNAME_*` instead of
  the dot-path (`PeerBuilder::addInheritanceColumnConstants()`); added a
  `clearInstancePool()` call to `NamespaceTest::testSingleTableInheritance()`
  so this doesn't silently regress again. Still open: an `<inheritance>`
  element's `extends="..."` attribute (naming an ancestor class outside the
  normal single-table hierarchy) is a raw, user-typed string with no
  namespace resolution at all (`MultiExtendObjectBuilder::getParentClasspath()`
  falls back to it verbatim) — this works today only because every existing
  schema's inheritance ancestor happens to share its child's namespace; an
  ancestor in a genuinely different namespace would hit the same class of
  bug in the generated `class X extends Y` declaration. No fixture exercises
  this case.

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
- **Phing is fully removed from this project.** `phing/phing` is gone from
  `composer.json`, `generator/Lib/Task/*` (all 15 files, including
  `BasePropulsionMigrationTask`), `generator/bin/propel-gen`(`.bat`),
  `generator/autoload-fix.php`, `generator/build*.xml`/`build.properties*`,
  and the `generator/pear/`/`runtime/pear/` Phing PEAR-packaging Tasks are all
  deleted, and the six `Reverse/*SchemaParser` classes no longer import
  `Phing\Task`/`Phing\Project` (their optional `$task` logging parameter is
  now `mixed $task = null`, since it was only ever used behind an
  `if ($task)` guard and every real caller already passed `null`). The
  console app (`bin/propulsion`) is now the only entry point; every Phing
  task listed below has a console command doing the same job (bullet history
  kept for context on the bugs found/fixed along the way).
  - **Shared migration-execution logic**: the transaction-wrapping/
    per-statement-ledger-recording/stop-at-first-failure logic that used to
    live on `BasePropulsionMigrationTask::runMigrationDirection()` was
    extracted into `PropulsionMigrationManager::runMigrationDirection()`
    (throws a plain `MigrationExecutionException`, no Phing types) before the
    Phing task was deleted, so the `migration:up`/`migration:down` console
    commands and the old Phing task always executed migrations through
    exactly the same code path — there was never a second, divergent
    implementation to keep in sync.
  - **Test coverage kept, not lost**: deleting `test/testsuite/generator/task/*`
    (the Phing-task-specific tests) did not remove real regression coverage --
    it was either redundant with per-Manager/Command tests that already existed
    (`SchemaReverseManagerTest`/`SchemaReverseCommandTest`,
    `SqlExecManagerTest`/`SqlExecCommandTest`, `GraphvizManagerTest`) or moved
    to a Phing-free equivalent: `PropulsionMigrationManagerTest` (direct
    coverage of `runMigrationDirection()`, including the transactional-vs-
    non-transactional-DDL-platform split and the statement-failure/ledger
    bug-fix regressions) and `PropulsionDatabaseComparatorTest` (the
    two-schema-versions structural-diff case, which never depended on Phing
    to begin with), plus `MigrationCommandsTest`/`SqlDiffCommandTest` proving
    the console entry points wire the shared logic up correctly (including
    the non-zero-exit-code-on-failure regression guard). `PropulsionOMTask`'s
    old Task-vs-console byte-for-byte parity test is gone since there's only
    one path left to test now; OM generation itself remains exercised
    end-to-end by every fixture-backed test in the suite (via
    `IntegrationDatabase::ensureClassesGenerated()`, which calls the same
    `ModelManager` the console `model:build` command uses).
  - **Revisited: `data:dump`/`data:sql` console commands added.** Originally
    left un-ported (see the stale "Lower priority" writeup a few paragraphs
    below, kept for context) on the theory that the pair was only useful
    inside a Phing multi-database build with no natural single-command
    reduction -- reconsidered, and it turns out that's not actually true:
    each command just needed an explicit `--dsn`/schema-file/output-file
    argument instead of Phing's `datadbmap`/`sqldbmap` file-based routing,
    the same simplification `sql:exec` already used for `PropulsionSQLExec`.
    `DataDumpManager`/`DataDumpCommand` (`data:dump`) and
    `DataSqlManager`/`DataSqlCommand` (`data:sql`) are the plain-PHP ports,
    in `generator/Lib/Manager/`/`generator/Lib/Command/`. Two real gaps had
    to be fixed to make this work, both left behind when the originals were
    deleted:
    - `generator/Lib/Builder/Util/DataRow.php` and `ColumnValue.php` didn't
      exist as standalone files -- they only ever existed as "inner classes"
      at the bottom of the now-deleted `XmlToDataSQL.php`, declared in the
      `Propulsion\Generator\Builder\Util` namespace that `DataSQLBuilder.php`
      (never deleted, never had Phing coupling) `use`s them from. Deleting
      `XmlToDataSQL.php` alongside the Phing removal silently broke every
      `DataSQLBuilder` subclass's `use` imports (latent until something
      actually instantiated one, which nothing did -- matching the
      "deliberately not ported" status at the time). Recreated both as
      proper standalone value-object classes.
    - `XmlToDataSQL` itself (the real dump-XML -> INSERT-SQL transform logic,
      minus the Phing-Task wrapper) used a `Phing\Parser\AbstractHandler`/
      `Phing\Parser\ExpatParser` SAX-callback pattern to stream-parse the
      dataset XML. `DataSqlManager::transform()` reimplements the same
      logic with a plain `DOMDocument` walk instead -- these dataset files
      are small, flat XML (one child element per row, no nesting), so a SAX
      streaming parser was never actually necessary for this format, and a
      DOM walk needs no Phing dependency at all. Also found and fixed a
      leftover `namespace ...;;` (double-semicolon) typo in
      `PgsqlDataSQLBuilder.php`/`OracleDataSQLBuilder.php` while touching
      this area (harmless -- an empty statement -- but worth a two-line
      cleanup since it was right there).

    Regression coverage in `DataDumpAndSqlManagerTest`: dumps a live table
    (including a `NULL` column value, confirmed omitted as an XML attribute
    entirely rather than written as an empty string) to XML, converts that
    XML to INSERT SQL via a *second* schema describing a differently-named
    target table (proving the round trip resolves by phpName, not by
    literal table-name match), executes the SQL via `sql:exec`, and confirms
    the target table's rows match the source exactly (including the
    `NUMERIC` and `BOOLEAN` column values, which need the `PgsqlDataSQLBuilder`
    quoting overrides this port didn't touch).

    Still not ported: `PropulsionConvertConfTask` (should be deprecated, not
    preserved -- see its own entry below, unaffected by this revisit).
    `generator/Lib/Builder/Util/XmlToDataSQL.php` (the old, Phing-coupled
    driver class -- superseded by `DataSqlManager`, not resurrected) and
    `PropulsionStringReader.php` (existed solely to support the now-deleted
    `PropulsionDataSQLTask`/`PropulsionConvertConfTask` Phing tasks) stay
    deleted; the one test that used to reach `PropulsionConvertConfTask` for
    its `simpleXmlToArray()` XML-to-array helper (`MysqlSchemaParserTest`)
    still carries its small test-local, Phing-free port of just that one
    static method rather than resurrecting the Task.
  - Below is the history of how these tasks were audited, confirmed correct,
    and ported, kept for context:
  - **Root cause fixed**: `generator/default.properties` declared
    `propulsion.project`, `propulsion.project.dir`, and `propulsion.targetPackage` as
    templates referencing each other in the same file (e.g.
    `propulsion.project.dir = ${propulsion.home}/projects/${propulsion.project}`). Phing's
    `<property file=...>` resolves placeholders against that file's own
    just-parsed table before ever falling back to the live project table, so
    every directory property derived from `propulsion.project.dir`
    (`propulsion.schema.dir`, `propulsion.output.dir`, `propulsion.php.dir`,
    `propulsion.sql.dir`, `propulsion.migration.dir`, ...) silently resolved to the
    file-local empty default instead of whatever `propel-gen`/`build.xml` had
    actually set — meaning `propel-gen <task>` failed with "No schema files
    were found" for every single project, unconditionally. Moved those three
    properties into `generator/build-propel.xml` as plain `<property>` tasks
    (which substitute against the live project table) instead. Also fixed
    `propulsion.reverse.parser.class` and `propulsion.builder.datasql.class`, which
    had the same "`${propulsion.database}`-templated path" bug in a different
    shape: `Reverse\${propulsion.database}\${propulsion.database}SchemaParser` and
    `Builder\SQL\${propulsion.database}\${propulsion.database}DataSQLBuilder` can
    never resolve, because those directories/namespaces are cased `MySQL`/
    `PgSQL`/`MSSQL`/... while `propulsion.database` is always lowercase — replaced
    both with explicit per-database class properties, matching the existing
    `propulsion.platform.*.class` convention.
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
  - **Migration ledger redesign (BREAKING CHANGE to the migration-tracking
    table shape)**: a follow-up pass found three real bugs the parity
    coverage above didn't exercise, all stemming from the migration table
    being a single mutable-pointer row instead of a history:
    1. `propel_migration` held one `version` column, rewritten via
       `DELETE FROM propel_migration` + `INSERT` on every run — no audit
       trail of what ran, when, or whether a migration file was edited since.
    2. `PropulsionMigrationDownTask`'s per-statement loop caught a failed
       statement's `PDOException`, logged it, and kept going — if *any*
       statement in the direction succeeded, the migration was still recorded
       as fully reverted even though a later statement failed.
    3. Neither Up nor Down task wrapped a migration's statements in a
       transaction, so on non-transactional-DDL platforms a partial failure
       left the schema half-migrated with no clean way to retry; worse, both
       tasks signaled failure via `return false` from `Task::main()`, which
       does **not** fail a Phing build (Phing only fails a target on an
       uncaught exception) — so a silently-aborted, half-applied migration
       exited the `propel-gen` process with status 0.

    Fixed by replacing the single-row table with an **append-only ledger**:
    every migration run/reversion attempt gets a new row (`id` autoincrement
    PK, `migration_timestamp`, `migration_name`, `direction` ('up'/'down'),
    `checksum` — sha256 of the exact SQL string executed, for future
    edited-after-running detection —, `applied_at`, `success`, and
    `statement_log` — a JSON array of `{sql, status: success|failed|
    not_attempted, error?}` for every statement in that direction, so a
    partial failure's exact per-statement outcome is always recoverable).
    Nothing is ever updated or deleted; "currently applied" state is
    *derived* by `PropulsionMigrationManager::getCurrentVersion($datasource)`
    from the ledger (the highest timestamp whose most recent **successful**
    row is direction='up') rather than read directly, and
    `getMigrationLedger($datasource)` exposes the full history for reporting.
    Failed attempts (either direction) never move the applied-state pointer
    at all — this matters most for a failed "down": on a transactional-DDL
    platform the failed attempt's DDL is rolled back to the still-applied
    "up" state, and the ledger must agree, not flip to "not applied" just
    because the most recent row happens to be a failed down.

    Statement execution now stops at the first failure (remaining statements
    are logged `not_attempted`, not run) and a new
    `PropulsionPlatformInterface::supportsTransactionalDDL()` flag (`true`
    only for `PgsqlPlatform`/`SqlitePlatform`, `false` by default — MySQL's
    DDL causes an implicit commit, and MSSQL/Oracle are left conservative
    since this fork has no live instance to verify against) controls whether
    the batch is wrapped in a transaction that gets rolled back in full on
    failure. On a non-transactional platform, whatever succeeded before the
    failure remains applied for real — an inherent limitation of
    non-transactional DDL, recorded accurately via the per-statement log and
    `success = false` rather than papered over; a human needs to reconcile
    manually before retrying. Both `*UpTask` and `*DownTask` (and the combined
    `PropulsionMigrationTask`) now throw a `Phing\Exception\BuildException` on
    a statement failure instead of `return false`, actually failing the
    build. The ledger insert always goes through a dedicated, separate PDO
    connection from whatever connection ran the migration's DDL statements —
    otherwise, on a transactional platform, a failed attempt's rollback would
    wipe out its own failure-record insert if that insert had been part of
    the same transaction, defeating the "log every attempt, successful or
    not" requirement.

    **This is a breaking schema change.** A project with an existing
    old-shape `propel_migration` table (single `version` column) must drop it
    and let `PropulsionMigrationStatusTask`/`PropulsionMigrationManager::
    createMigrationTable()` recreate it in the new ledger shape on next run —
    there is no automatic migration-of-the-migration-table, by design (silently
    reinterpreting an old single-row `version` as a synthetic ledger history
    would be guessing at data that was never recorded, e.g. which migrations
    actually succeeded per statement). The default table name (configurable
    via `getMigrationTable()`/`setMigrationTable()`/`propulsion.migration.table`,
    unchanged) is also renamed from `propel_migration` to
    `propulsion_migration` as part of this pass, matching this fork's
    `Propel*` → `Propulsion*` identity rename elsewhere — a project relying on
    the old default name needs to set it explicitly (or, again, just let it
    get created fresh under the new default name).
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
    also what surfaced the `propulsion.builder.datasql.class` bug fixed above).
- **Console-app migration status** (moving off Phing entirely in favor of
  `symfony/console`/`bin/propulsion`, tracked separately from the Phing-task
  parity audit above). Every `*Manager`/`*Command` pair below follows the
  `ModelManager`/`ModelBuildCommand` template: a plain-PHP `Manager` class in
  `generator/Lib/Manager/` with no Phing types anywhere in it, wrapped by a
  thin `Command` class in `generator/Lib/Command/` using Symfony Console's
  `Command`/`InputInterface`/`OutputInterface`/`ConsoleLogger` (PSR-3) API.
  - **Done, with console equivalents now**:
    - `model:build` (`ModelManager`) and `sql:build` (`SqlManager`) — done
      previously; `PropulsionOMTask` confirmed at byte-for-byte parity (see
      above), `PropulsionSQLTask` is fully superseded by `SqlManager` (its own
      docblock already said so; confirmed rather than re-ported).
    - `schema:reverse` (`Propulsion\Generator\Manager\SchemaReverseManager` /
      `Propulsion\Generator\Command\SchemaReverseCommand`, alias `reverse`) —
      new. Connects to a live database via plain `\PDO` (matching
      `Phing\Task\System\Pdo\PDOTask::getConnection()`'s exact behavior:
      `PDO::ATTR_ERRMODE_EXCEPTION` always, `PDO::ATTR_AUTOCOMMIT` best-effort),
      then calls the same `GeneratorConfig::getConfiguredPlatform()` /
      `getConfiguredSchemaParser()` / `SchemaParser::parse()` pipeline
      `PropulsionSchemaReverseTask` used, passing `null` for the optional
      `?Phing\Task $task` parameter (every concrete `SchemaParser::parse()`
      implementation only ever uses it for `Project::MSG_VERBOSE`-level
      logging behind an `if ($task)` guard — never a hard dependency — so this
      reproduces exactly what running the old task without `-verbose` did).
      `SchemaReverseCommand` takes `--dsn`/`--user`/`--password` directly
      (mirroring the old Task's own `setUrl`/`setUserid`/`setPassword`
      attributes) rather than reading a project's build-connections, since
      reverse-engineering an arbitrary live database is the whole point.
      `test/testsuite/generator/manager/SchemaReverseManagerTest.php` and
      `test/testsuite/generator/command/SchemaReverseCommandTest.php` cover it
      against a real Postgres testcontainer (same two-table/one-FK schema as
      `PropulsionSchemaReverseTaskTest`); the two paths agree on
      tables/columns/types/FK (a byte-for-byte comparison wasn't attempted —
      the two writers are free to differ on incidental XML formatting).
      While porting, found and fixed a real crash bug in the ported
      `addValidators()`/`getRuleMessage()` code (not otherwise
      test-covered, so never noticed either here or in the original Task,
      which has the identical bug): `compact()` produces a string-keyed
      array, and passing that through `call_user_func_array('sprintf', ...)`
      is interpreted as PHP 8.1+ named arguments, which `sprintf()` rejects
      outright (`ArgumentCountError`). Fixed in the port with
      `array_values(compact(...))`; **not** back-ported to
      `PropulsionSchemaReverseTask` itself (out of scope — that class is
      being left alone per this pass's own ground rules, and the bug is
      latent/untriggered there today since no test exercises
      `setAddValidators()`). The pre-existing `PgsqlSchemaParserV12Plus`
      `NUMERIC` precision/scale decoding bug (noted above) carries over
      unchanged, as instructed — this pass only ports, it doesn't fix.
    - `graph:build` (`GraphvizManager` / `GraphvizBuildCommand`, alias
      `graphviz`) — new. Pure in-memory schema-to-`.dot` generation (extends
      `AbstractSchemaManager` like `SqlManager`/`ModelManager` do, since it
      loads existing schema.xml files rather than connecting to a database);
      straightforward port, no behavioral surprises. Covered by
      `GraphvizManagerTest`/`GraphvizBuildCommandTest` (no database needed).
    - `sql:exec` (`SqlExecManager` / `SqlExecCommand`) — new, deliberately
      **simplified** relative to `PropulsionSQLExec`. The original Task read a
      Phing-`Properties`-format `sqldbmap` file mapping many `.sql` files to
      many different per-database DSNs in a single run — useful inside a
      multi-schema Phing build where `PropulsionSQLTask` had just produced one
      `.sql` file per database, with no other way to know which file belonged
      to which connection. A standalone console command has no such build
      context to draw that map from, so `sql:exec` instead takes one explicit
      `--dsn` and an explicit, ordered list of `.sql` file arguments and runs
      them all against that single connection — same execution semantics
      (`--autocommit`, `--on-error=abort|continue`) minus the file-to-database
      routing machinery. Covered by `SqlExecManagerTest`/`SqlExecCommandTest`
      against a real Postgres testcontainer, including both `abort` (stops and
      rolls back on the first bad statement) and `continue` (skips it, keeps
      going) behavior.
  - **Deliberately not ported this pass**:
    - `PropulsionDataDumpTask` (dumps live table rows to `dataset`-format XML)
      and `PropulsionDataSQLTask` (converts that XML into `INSERT` SQL) —
      these two only make sense as a matched pair forming a live-database-row
      round-trip fixture/snapshot pipeline, and (like `PropulsionSQLExec`
      above) their coordination between multiple schema files and multiple
      output files is entirely driven by Phing `Properties`-format
      `datadbmap`/`sqldbmap` files tied to a multi-schema Phing build — there
      is no natural "one invocation, one DSN, one file" reduction the way
      `PropulsionSQLExec` had (`sql:exec`'s simplification of dropping the
      file-routing map still leaves a coherent single-purpose command; doing
      the same to Dump+SQL would mean inventing a new, different two-command
      convention rather than "porting" anything recognizable). They're also
      the least-used of the five lower-priority tasks in practice (test-data
      snapshotting, not schema/DDL code generation, which is the console
      app's actual focus so far). Both remain fully working as Phing tasks
      (`LowerPriorityTaskSmokeTest`); revisit if a concrete use case for a
      console `data:dump`/`data:sql` pair shows up.
    - `PropulsionConvertConfTask` — see the dedicated entry below; explicitly
      **should not** get a console equivalent at all.
    - `migration:status` / `migration:up` / `migration:down`
      (`Propulsion\Generator\Command\Migration{Status,Up,Down}Command`, sharing
      option-wiring via `AbstractMigrationCommand`) — new, replacing
      `PropulsionMigration{Status,Up,Down}Task`/`BasePropulsionMigrationTask`.
      Execution itself (transaction wrapping, per-statement ledger recording,
      stop-at-first-failure) lives in
      `PropulsionMigrationManager::runMigrationDirection()`, called directly by
      these commands -- there is exactly one implementation of "how a migration
      direction executes" now that the Phing task adapter is gone (see the
      "Phing is fully removed" entry above for how the two were kept in sync
      right up until the task was deleted). A statement failure returns
      `Command::FAILURE` (non-zero exit) with the per-statement ledger detail
      printed, never a silent success. Covered by `MigrationCommandsTest`
      (a real `status` -> `up` -> `down` cycle, plus statement-failure exits
      non-zero for both directions without flipping applied state) and
      `PropulsionMigrationManagerTest` (the shared engine's detailed behavior,
      including the transactional-vs-non-transactional-DDL-platform split).
    - `sql:diff` (`Propulsion\Generator\Manager\SqlDiffManager` /
      `Propulsion\Generator\Command\SqlDiffCommand`, alias `diff`) — new,
      replacing `PropulsionSQLDiffTask`. Same scope as the original Task
      (deliberately **not** expanded): compares a live database (via a
      `--buildtime-conf` connection) against a schema.xml file and generates a
      `PropulsionMigration_<timestamp>.php` migration class; there is still no
      "two schema.xml files" comparison mode. Covered by `SqlDiffCommandTest`
      against a real Postgres testcontainer (live-database-drift generates a
      migration class; an already-matching schema reports nothing to migrate)
      and `PropulsionDatabaseComparatorTest` (the shared diff engine on two
      schema.xml versions, unchanged from before).
- **`PropulsionConvertConfTask` is gone.** It converted the old XML *runtime*
  config format (`runtime-conf.xml`) into the PHP array config
  `Propulsion::init()`/`setConfiguration()` actually consumes — it was
  deleted along with the rest of the Phing `Task` classes during the Phing
  removal (see that entry above), and didn't need a console-app
  replacement: the runtime side already only accepts plain PHP arrays
  directly (no XML involved at all), so there was nothing left to convert.
  The one remaining live XML config surface is *build-time* connection
  config (`buildtime-conf.xml`, read by `GeneratorConfig::
  getBuildConnections()`, used by the migration/diff console commands) —
  tracked in the next entry.
- **Build-time connection config: plain-PHP format added, XML kept
  (deprecated, not removed).** `Propulsion::init()`/`setConfiguration()` (the
  *runtime* side) already only ever accepted PHP arrays; the one remaining
  live XML surface was *build-time* database connection info -- the
  `--buildtime-conf` option on `migration:status`/`migration:up`/
  `migration:down`/`sql:diff` (`GeneratorConfig::getBuildConnections()`/
  `parseBuildConnections()`), which read a `buildtime-conf.xml` file like:
  ```xml
  <config><propel><datasources default="bookstore">
    <datasource id="bookstore"><adapter>pgsql</adapter>
      <connection><dsn>pgsql:host=localhost;dbname=mydb</dsn><user>me</user><password>secret</password></connection>
    </datasource>
  </datasources></propel></config>
  ```
  **Recommended now**: the same `--buildtime-conf` option also accepts a
  plain PHP file (dispatched on file extension, in
  `GeneratorConfig::loadBuildConnectionsFile()`) that just `require`s and
  returns an array in the same shape `getBuildConnections()` has always
  returned internally:
  ```php
  <?php
  return [
      'default' => 'bookstore',
      'datasources' => [
          'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
      ],
  ];
  ```
  A `propulsion.buildtimeConfigArray` build property (an array set directly,
  rather than a file path) is also supported for programmatic/ad-hoc use, in
  the same shape.

  **Kept both formats, deliberately not a hard cutover** -- unlike the
  Phing removal and the `Propel*` → `Propulsion*` rename (both entirely
  internal to this repo, so every caller was auditable and provably safe to
  cut over in one pass), a `buildtime-conf.xml` file is *user-authored
  content living in a consuming project's own repo*, not code inside this
  one. There is no way to grep an external project's config files from here,
  so whether removing XML support would break existing consumers "in the
  wild" genuinely can't be verified from within this codebase alone --
  exactly the kind of concrete uncertainty that argues for keeping both
  paths rather than guessing. All in-repo test coverage
  (`MigrationCommandsTest`, `SqlDiffCommandTest`) still exercises the legacy
  XML path unchanged, plus new parallel test methods
  (`...UsingPhpConfigFile()`) proving the PHP path works end-to-end through
  the same console commands; `GeneratorConfigTest` covers all three input
  forms (`buildtimeConfigArray`, a `.php` file, a legacy `.xml` file)
  directly at the `GeneratorConfig` unit level. Revisit removing XML
  support outright once there's actual evidence (a changelog/major-version
  point, or direct confirmation) that no consuming project still relies on
  it.
- **`generator/default.properties` replaced with `generator/default.php`
  (plain PHP array, same content).** The same reasoning as the buildtime-conf
  precedent above applies one level up: `GeneratorConfig::parsePropertiesFile()`
  now dispatches on file extension, so a `.php` file is `require`d and
  expected to `return` a flat `['propulsion.foo' => ..., ...]` array, while
  anything else still falls through to the legacy Ant/Phing `.properties`
  text parser -- kept, again, because a project's own `build.properties` is
  user-authored content outside this repo, not provably safe to break.
  `generator/default.properties` itself, however, ships *with* this repo (like
  the `Propel*` → `Propulsion*` rename and the Phing removal, fully internal
  and auditable), so it was hard-cut to `generator/default.php` with no
  parallel legacy copy kept around -- every in-repo consumer (every generator
  Command's `loadConfiguration()`, `QuickGeneratorConfig`, `IntegrationDatabase`,
  and the handful of tests that construct a `GeneratorConfig` directly) was
  updated to the new path. The nine `test/fixtures/*/build.properties` files
  (all internal test fixtures, same reasoning) were converted the same way,
  to `build.php`/`build.propulsion.php`.
- **Fixed: Postgres promoted to the documented/default database.**
  `generator/default.php`'s `propulsion.database` now defaults to `pgsql`
  (still a plain per-project override via a project's own `build.php`
  or the console commands' `--database` flag -- every consumer of
  `propulsion.database` already goes through `GeneratorConfig`'s default ->
  project-override -> explicit-override merge order, so nothing relied on the
  old empty default). The README now has a "Database support" section
  recommending Postgres for new projects and explaining why (this codebase's
  own tests/CI/generator default to it).

  A `PgsqlPlatform` vs `MysqlPlatform` feature-parity audit (both against
  their shared `DefaultPlatform` base) found and fixed two real gaps, plus
  investigated and reverted one false lead:
  - **Fixed: `getAddSchemasDDL()` only created schemas declared via the
    legacy `<vendor type="pgsql"><parameter name="schema">` convention, not
    the primary, cross-platform `schema="..."` attribute on
    `<database>`/`<table>`.** That attribute (`Table::$schema`, set via
    `ScopedElement::loadFromXML()`) already gets every identifier
    schema-qualified everywhere (`Table::getName()`/
    `ForeignKey::getForeignTableName()`, used by `quoteIdentifier()`) for any
    platform where `supportsSchemas()` is true -- but the schema itself was
    never actually created, so DDL for a table using only this attribute
    failed against a fresh database (`CREATE TABLE "x"."book" ...` with no
    preceding `CREATE SCHEMA "x"`) unless something else created it out of
    band. `test/tools/helpers/IntegrationDatabase.php` carried exactly this
    workaround (manually pre-creating the "schemas" fixture's schemas) with a
    comment explaining the gap; the workaround is now removed since the
    generator does it correctly. See `PgsqlPlatformTest::
    testGetAddSchemasDDLNativeSchemaAttribute()`.
  - **Fixed: the same gap in the diff/migration path.**
    `getModifyDatabaseDDL()` (unlike `getAddTablesDDL()`, the full-rebuild
    path) was never overridden at all, so a migration/diff adding a
    brand-new schema-qualified table had the identical problem. `PgsqlPlatform`
    now overrides `getModifyDatabaseDDL()` to emit `CREATE SCHEMA IF NOT
    EXISTS` (`IF NOT EXISTS` here, unlike the full-rebuild path's plain
    `CREATE SCHEMA`, since a diff runs against a database that may already
    have other tables in that schema) for every newly-added table's schema
    first. See `PgsqlPlatformMigrationTest::
    testGetModifyDatabaseDDLCreatesSchemaForAddedTable()`.
  - **Fixed: `getMaxColumnNameLength()` returned 32** (PostgreSQL's limit
    prior to server version 7.3, from 2002) **instead of 63** (the real
    `NAMEDATALEN`-based limit on any currently-supported server -- see the
    "PostgreSQL 15+" entry below). This needlessly truncated auto-generated
    constraint/index names (`ConstraintNameGenerator`, `Index::getName()`)
    well before the real server-enforced limit.
  - **Investigated and reverted: `supportsInsertNullPk()`.** By analogy with
    `MssqlPlatform` (which correctly returns `false`, since SQL Server's
    IDENTITY value is fetched *after* insert and an explicit `NULL` for the
    PK would reach the SQL as-is and fail), it looked like `PgsqlPlatform`
    should override this too, since a `serial` column is a plain `NOT NULL
    DEFAULT nextval(...)` column that also rejects an explicit `NULL`.
    Setting it to `false` broke a real integration test
    (`BookstoreTest::testScenarioUsingQuery`, "Database insert attempted
    without anything specified to insert") for a new object whose only
    column besides the (always-implicitly-"modified") PK matches its schema
    default (so no other column is ever marked modified, and removing the
    null PK left the insert Criteria completely empty).  Root-caused to:
    `DBPostgres::getIdMethod()` is `ID_METHOD_SEQUENCE`, so
    `isGetIdBeforeInsert()` is `true` -- `BasePeer::doInsert()` already
    detects a present-but-`null`-valued PK key via `Criteria::
    keyContainsValue()` (which explicitly treats `null` as "no value", not
    just "absent") and fetches+overwrites it with a real sequence value
    *before* ever building the SQL, so an explicit `NULL` never actually
    reaches Postgres in the generated flow regardless of this flag -- unlike
    MSSQL's IDENTITY, which is `ID_METHOD_AUTOINCREMENT`/fetched *after*
    insert, so nothing pre-empts a `null` there. Confirmed correct as `true`
    (the `DefaultPlatform` default, same effective behavior as `MysqlPlatform`
    not overriding it either) and left alone; no code change.
  - **Confirmed correct, intentionally not changed** (real platform
    differences, not gaps): `getAutoIncrement()`/`getNativeIdMethod()`
    (`serial`/`bigserial` column-level vs. MySQL's `AUTO_INCREMENT` keyword);
    `getBooleanString()` (`'t'`/`'f'` literals vs. MySQL's `1`/`0`);
    `hasStreamBlobImpl()` (PDO pgsql actually returns BYTEA as a stream; PDO
    mysql doesn't); `supportsVarcharWithoutSize()` (real Postgres allows a
    bare `VARCHAR`; MySQL doesn't); `getRenameColumnDDL()`/
    `getRemoveColumnDDL()` (Postgres's native `ALTER TABLE ... RENAME
    COLUMN`/`DROP COLUMN` syntax, inherited unchanged from `DefaultPlatform`,
    already matches real Postgres -- MySQL is the one that needs an override,
    for its `CHANGE`-based syntax); `getDropIndexDDL()`/`getAddIndexDDL()`
    (Postgres index names are schema/database-scoped, not per-table, so no
    `ON <table>` clause is needed or emitted, unlike MySQL); no reserved-word
    list or column-length index-prefix support (`Index_type`-style vendor
    param) exists for *any* platform in this codebase, so there was nothing
    Postgres-specific to be behind on there. No native `JSON`/`JSONB`/`ARRAY`
    column type exists in `PropulsionTypes` for either platform to expose --
    `PHP_ARRAY` maps to plain `TEXT` (PHP-serialized) on both MySQL and
    Postgres alike, so this isn't a MySQL-has-it/Postgres-lacks-it gap either.
- **PostgreSQL 15+ is the minimum supported version.** PostgreSQL 14 reaches
  end-of-life in ~4 months from 2026-07-04; PostgreSQL 12-13 are already
  past EOL. Nothing in this codebase currently targets a version below 12
  (the reverse-engineering parser was the one place with an explicit
  version split — see the fixed bug immediately below — and it's been
  consolidated into a single, always-`pg_get_expr()`-based implementation
  now that pre-12 support is moot). No other code currently has
  version-conditional Postgres behavior to audit, but keep this floor in
  mind if any future Postgres-specific feature work considers gating on
  server version.
- **Fixed: `PgsqlSchemaParser` decoded `NUMERIC(p,s)` columns' precision/scale
  incorrectly** when reverse-engineering a schema (e.g. a live `price
  NUMERIC(10,2)` column came back with `size="655362"` instead of `size="10"
  scale="2"` — a still-packed, un-shifted Postgres `atttypmod` value, not
  the decoded precision). Root cause: `processLengthScale()`'s numeric
  branch checked `$strName == $this->getMappedNativeType(PropulsionTypes::NUMERIC)`,
  but this parser's own type map (`getTypeMapping()`) only ever maps
  Postgres's `numeric`/`decimal` native type names to
  `PropulsionTypes::DECIMAL` — nothing produces `PropulsionTypes::NUMERIC` —
  so `getMappedNativeType(PropulsionTypes::NUMERIC)` always returned `null`,
  the branch never matched a real numeric column, and every `NUMERIC(p,s)`
  column silently fell through to the generic fallback branch, which
  returns the raw `atttypmod - 4` value with no bit-shifting at all. Fixed
  by checking against `PropulsionTypes::DECIMAL` instead, which is what the
  type map actually produces. This was a real bug independent of the
  PostgreSQL version-support question above, and existed identically in the
  now-deleted pre-12 `PgsqlSchemaParser` variant this class absorbed (same
  copy-pasted logic) — not just the promoted one.

  While fixing this, also renamed `PgsqlSchemaParserV12Plus` →
  `PgsqlSchemaParser` and deleted the old pre-12, `pg_attrdef.adsrc`-based
  `PgsqlSchemaParser` it used to sit alongside — with a PostgreSQL 15+ floor,
  there's no supported version that variant was still relevant for, and
  keeping two classes with confusingly overlapping names (one of which had
  the exact same bug) wasn't worth preserving. Added direct regression
  coverage in `SchemaReverseManagerTest`/`SqlDiffCommandTest` (a
  `NUMERIC(10,2)` column reverse-engineers to `size="10" scale="2"`, and a
  live table with that column no longer produces a spurious diff against a
  matching schema.xml).
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
