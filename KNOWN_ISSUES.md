# Known issues and remaining work

This file tracks two things: **currently open issues** and **modernization
work not yet done**. It's meant to be short enough to skim — for the
detailed story of how any past issue was found and fixed, read the commit
history (`git log`); every fix commit explains its own root cause in full.

## Test suite status

**Full suite (Docker/Postgres) is green: 2222 tests, 0 errors, 0 failures, 0
risky, 13 skipped.**

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
  `timeout`-killed process. If test runs get interrupted, leaked
  `postgres:latest`/`mysql:latest` containers can pile up:
  `docker ps -a --filter ancestor=postgres:latest` /
  `--filter ancestor=mysql:latest` to find them, `docker rm -f <id>` to
  clean up. No general fix known (inherent to how shutdown functions work).
- **Worker-safety test matrix not run.** Phase 4a/4b (below) built the
  `ServiceContainer`/`Session` split and unit-tested it directly, but the
  actual worker-mode property (no object bleed across requests, connection
  persistence, memory doesn't grow under sustained load) needs a real
  worker harness (FrankenPHP or equivalent) this repo doesn't have. Nothing
  to do here until such a harness exists.
- **Phing `Task` classes** (`generator/Lib/Task/*`, 15 files) are still
  present, gated on proving output parity between the Phing path
  (`generator/bin/propel-gen`) and the `bin/propulsion` console path. No
  formal side-by-side comparison has been done.
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
