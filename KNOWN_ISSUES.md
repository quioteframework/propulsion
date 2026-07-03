# Known issues and remaining work

Status as of the test-fixing pass on `main` (commits `f8675fc`..`0186ecb`). This
tracks two separate things: **remaining test failures** (from the PHPUnit
pass) and **modernization plan phases not yet done** (from the original
scoping conversation, before it detoured into fixing tests).

## Current test suite state

2135 tests, **128 errors, 174 failures**, 27 skipped, 10 risky, out of a
suite that couldn't run a single test at the start of this work (bootstrap
was completely broken) and had 1137+ errors once it could run at all. See
git log on `main` for the detailed fix history — each commit message
documents the specific root cause found and fixed.

Reproduce with:
```
cd test
rm -rf fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
../vendor/bin/phpunit -c phpunit.xml
```
(First run pulls a `postgres:latest` image and builds fixtures into a
testcontainer — expect it to take a few minutes. Set
`PROPULSION_SKIP_INTEGRATION=1` to skip the ~63 Bookstore/Cms/Namespace
tests that need it, if Docker isn't available.)

### Remaining failures, by cluster (highest count first)

1. **~30 "not null violation" errors** — scattered across many individual
   test methods (`PropelModelPagerTest`, `PropelObjectCollectionTest`,
   `PropelArrayFormatterWithTest`, `PropelOnDemandFormatterTest`, others)
   that construct `new Book()` / `new Author()` with required columns
   (`isbn`, `first_name`, `last_name`) left unset. MySQL's non-strict mode
   silently coerced this to empty strings; Postgres correctly rejects it.
   Same root cause as the two tests already fixed in
   `GeneratedObjectTest.php` (commit `6f6b08e`) — no shared fix point, each
   needs its own required-field populated. Mechanical but has to be done
   test-by-test; a good task to batch with an agent given the pattern is
   now well understood.

2. **1 real runtime bug**: `Join::getLeftTableAliasOrName(): string` throws
   `TypeError: ... none returned` — `leftTableName` is never populated for
   at least one join-construction path (surfaces on the
   `bookstore_employee` self-referencing `supervisor_id` join used by
   nested-set/employee-hierarchy tests: the generated SQL comes out as
   `FROM  INNER JOIN bookstore_employee s ON (...)`, i.e. the left table
   name is genuinely empty, not just a return-type mismatch).
   `setLeftTableName()` (`runtime/Lib/Query/Join.php:299`) is never called
   anywhere in `runtime/Lib/` except its own docblock example — worth
   tracing which of `Criteria`/`ModelCriteria`'s several join-building
   paths (`addJoinObject`, `mergeWith`, `useQuery`, behavior-injected
   joins) is responsible for this specific self-join case and isn't
   setting it. Affects 6 test methods directly, contributes to the ~11
   "column does not exist" and syntax-error failures nearby too (a broken
   `FROM` clause cascades into bogus column references in the same query).

3. **~11 "column does not exist" PDOExceptions** — not yet triaged
   individually; at least some are downstream of issue #2's malformed
   `FROM` clause. Worth re-triaging once #2 is fixed, since the count may
   drop on its own.

4. **~27 "Failed asserting that two strings are equal"** — not yet
   triaged. Likely a mix of genuine small bugs and legitimate
   MySQL-vs-Postgres output differences (quoting style, error message
   text, floating point formatting) similar to what `BasePeerExceptionsTest`
   needed (commit `024cec0`). Needs case-by-case inspection.

5. **10 "This test did not perform any assertions"** (risky, not
   failing) — likely tests whose only verification was a removed
   `@expectedException`/`@dataProvider` annotation on a branch that no
   longer throws, or a genuinely incomplete test. Low priority.

6. **4 "Unkown column BookClubList in model Book"** — likely a schema/
   generated-code mismatch for a behavior-added relation; not yet
   investigated.

7. **3 `PgsqlPlatformMigrationTest` "data provider ... is invalid"** —
   a provider method itself is probably throwing or returning the wrong
   shape; separate from the general `@dataProvider`-to-attribute fix
   already applied everywhere else.

8. **~5 `aggregate_poll`/`aggregate_post` "lock timeout" errors** — the
   `AggregateColumnBehaviorTest` connection-handling bug mentioned in the
   previous status update (a connection left open uncommitted, deadlocking
   a later test's cleanup). The lock/statement timeout added in commit
   `a5b1daa` makes this fail fast instead of hanging the whole suite, but
   the underlying test bug (probably a manually-managed second connection/
   transaction that isn't committed) is still there and still fails those
   specific tests.

### Structural/tooling gaps found but not fixed

- **`test/fixtures/schemas/` project is not wired up** — 5 tests
  (`ModelCriteriaWithSchemaTest`, `GeneratedRelationMapWithSchemasTest`,
  `RelatedMapSymmetricalWithSchemasTest`,
  `AggregateColumnBehaviorWithSchemaTest`,
  `ConcreteInheritanceBehaviorWithSchemaTest`) extend `SchemasTestBase`,
  which already has correct lazy-skip behavior
  (`test/tools/helpers/schemas/SchemasTestBase.php`) but nothing builds
  `test/fixtures/schemas/build/`. Same pattern as the `bookstore` and
  `namespaced` projects (see `IntegrationDatabase::ensureNamespacedReady()`
  in commit `306ee1b` for the template to copy) — a third
  `ensureSchemasReady()` method plus a database on the same container.
- **Directory casing inconsistency in namespaced-project codegen**: the
  namespaced fixture's generated files end up split across `Foo/Bar/om/`
  and `Foo/Bar/OM/` (both actually used, by different builders, for
  logically the same "base classes" subfolder) — cosmetically messy and
  fragile on case-sensitive filesystems, though the classmap autoloader
  added in commit `306ee1b` works around it by parsing each file's actual
  `namespace`/`class` declaration rather than relying on directory
  layout. Worth tracking down which builder(s) disagree on the casing and
  fixing at the source if `bin/propulsion` is ever used against a
  namespaced schema for real (not just in this test harness).
- **`QuickGeneratorConfig` still hardcodes `PHP5*Builder` class names**
  (`generator/Lib/Config/QuickGeneratorConfig.php`) and its
  `getConfiguredBuilder()` instantiates them via a bare (non-namespaced)
  string, which only resolves correctly in this test suite because
  `test/bootstrap.php` eagerly aliases every generator class to its bare
  name (commit `8071c32`). Outside a context that does that aliasing
  (e.g. real production use of `PropelQuickBuilder`), `new $class(...)`
  with a bare class name will fail to resolve. Low priority since
  `PropelQuickBuilder` is a dev/test-time convenience, but worth fixing
  properly if it's ever used outside this test suite.
- **`generator/Lib/Builder/Util/PropelStringReader.php`** still has a
  broken `include_once 'phing/system/io/Reader.php'` (old lowercase path,
  same class of bug fixed elsewhere in `PropelQuickBuilder`/
  `QuickGeneratorConfig` in commit `3b7c64d`) — not hit by the current
  test run so it was never surfaced/fixed. Worth a proactive check.
- **No CI configuration exists** to actually run this suite automatically
  (no `.github/workflows/`). Everything in this document was found by
  running PHPUnit manually. Setting up CI (even just "run the unit tier,
  skip integration if Docker unavailable, or run integration in a
  Docker-in-Docker runner") would catch regressions going forward instead
  of relying on someone remembering to run this by hand.
- **testcontainers cleanup**: `IntegrationDatabase` registers
  `register_shutdown_function()` to stop its container, which works for a
  normal PHPUnit exit but not for `kill -9` or `timeout`-killed processes
  (encountered directly during this session — had to manually
  `docker rm -f` 5 leaked containers). Not aware of a clean general fix
  for this (it's inherent to how shutdown functions work), but worth
  documenting so whoever hits it next isn't confused: `docker ps --filter
  ancestor=postgres:latest` to find them, `docker rm -f <id>` by explicit
  ID.

## Modernization plan phases not yet done

From the original plan (see conversation history / commit messages for
full context). Phases 0–2 are done (identity rename, CLI cutover, PSR-3
logging). Everything below is not started, in priority order:

### Phase 3 — Promote PHP84 builders to canonical, flip the default

Not started. Currently `generator/default.properties` still defaults
`propel.targetPlatform = php5` (confirmed still true as of this writeup),
meaning **the generator's out-of-the-box behavior is still the legacy PHP5
codegen path**, not the modern PHP84 one this session did substantial work
fixing and validating (single-table-inheritance support, namespace
handling, etc.). Concretely, still needed:

- Rename the collision-prone classes as scoped in the original planning
  conversation: `AbstractPeerBuilder`/`AbstractObjectBuilder` for the two
  abstract bases, promote `PHP84PeerBuilder`→`PeerBuilder`,
  `PHP84ObjectBuilder`→`ObjectBuilder`, merge `PHP84QueryBuilder`'s
  additions into `QueryBuilder` (not a rename — `QueryBuilder` is already
  a concrete, non-abstract class today), `PHP84TableMapBuilder`→
  `TableMapBuilder` (after resolving the fact that it currently `extends
  PHP5TableMapBuilder` — needs its still-needed logic inlined first, or
  it isn't safe to treat PHP5 as pure archaeology).
- Straight prefix-drop rename for the rest (no naming collisions):
  `PHP84ExtensionObjectBuilder`, `PHP84ExtensionPeerBuilder`,
  `PHP84ExtensionNodeBuilder`, `PHP84ExtensionNodePeerBuilder`,
  `PHP84InterfaceBuilder`, `PHP84MultiExtendObjectBuilder`,
  `PHP84NestedSetBuilder`, `PHP84NestedSetPeerBuilder`, `PHP84NodeBuilder`,
  `PHP84NodePeerBuilder`.
- Flip `default.properties`: unsuffixed `propel.builder.*.class` keys
  become the promoted (formerly PHP84) classes; add `.php5.class`
  overrides for the now-demoted legacy path; change the default
  `targetPlatform` away from `php5`.
- Update the worker-safety rework doc's references to `PHP84PeerBuilder`
  once it's renamed.
- This session's PHP84-builder bug fixes (commit `306ee1b` especially)
  make this phase considerably safer to do now than before — the
  single-table-inheritance path that this rename would promote to default
  is now actually tested and working, whereas before this session it had
  never been exercised end-to-end.

### Phase 4 — Worker-safety rework (ServiceContainer/Session split)

Not started at all. This is the actual motivating goal from
`PROPULSION_WORKER_REWORK.md` (FrankenPHP/worker-mode safety — moving
instance pools, `forceMasterConnection`, and dangling transactions off
`Propel`'s process-global statics into a request-scoped `Session`, while
keeping connections/adapters/maps process-scoped in a `ServiceContainer`).
Phased as:

- **4a**: Introduce `ServiceContainer` + `Session` behind
  `Propel::getServiceContainer()`/`getSession()`; interim pool-registry
  hack to clear all static Peer pools at once; wire transaction-rollback-
  on-reset (the `PropelPDO::forceRollBack()` mechanism this session
  started actually exercising in the test harness, commit `6f6b08e`, is
  directly relevant groundwork here — the worker-safety doc's concern
  about dangling transactions is the same failure mode, just at a request
  boundary instead of a test boundary); move `forceMasterConnection` into
  `Session`.
- **4b**: Rework the (renamed, per Phase 3) `PeerBuilder` template so pool
  methods delegate to `Session`; regenerate models; drop the interim
  pool-registry hack.
- **4c**: Delete legacy `PHP5*` builders (gated on 4a/4b proving the new
  pool delegation works, and on Phase 3's TableMapBuilder inlining removing
  the last real dependency on PHP5 code).
- **4d**: Quiote adapter integration (`PropelDatabase` wiring,
  `ResetInterface`) — tracked in the Quiote-side doc, not this repo.

Decision already made in an earlier conversation: instance pooling
defaults to **on** once 4a's reset-on-request-boundary wiring is in place
and the worker test matrix (§8 of the rework doc — no object bleed,
transaction cleanup, connection persistence, forceMaster isolation,
memory doesn't grow) passes.

### Deferred, no target phase yet

- **Delete the Phing `Task` classes** (`generator/Lib/Task/*`, 15 files) —
  explicitly gated on proving generator parity between the Phing path and
  the `bin/propulsion` console path. This session's work (getting
  `ModelManager`/`SqlManager` to actually generate correct, tested output
  for both the `bookstore` and `namespaced` fixture projects) is concrete
  progress toward that parity case, but no formal side-by-side comparison
  has been done yet. `generator/bin/propel-gen` (the Phing entry point)
  and `test/reset_tests.sh` still depend on these.
- **PSR-18** — never scoped beyond "say the word when there's a concrete
  need" in the original planning conversation. No HTTP client usage
  exists anywhere in this codebase yet, so there's nothing concrete to
  wire it into.
- **OTEL instrumentation** — explicitly skipped per your instruction, not
  planned.
- **Postgres-as-primary-database follow-through** — you asked to promote
  PostgreSQL as the "go-to" database. This session's test work *used*
  Postgres extensively (testcontainers, all fixture builds target
  `pgsql`), but that's incidental to fixing tests, not the same as
  actually making Postgres the documented/default choice for real
  projects: `generator/default.properties`'s `propel.database` is still
  empty (matches upstream Propel's original "you must configure this"
  stance), the README doesn't mention a recommended database, and
  `PgsqlPlatform` hasn't had a pass to check it's on equal footing with
  `MysqlPlatform` feature-wise (this session only found and fixed the two
  bugs that blocked fixture loading — `DROP TABLE`/`DROP SEQUENCE` missing
  `IF EXISTS`, commit `024cec0` — not a general audit).
