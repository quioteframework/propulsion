# Known issues and remaining work

Status as of the test-fixing pass on `main` (commits `f8675fc`..`0186ecb`). This
tracks two separate things: **remaining test failures** (from the PHPUnit
pass) and **modernization plan phases not yet done** (from the original
scoping conversation, before it detoured into fixing tests).

## Current test suite state

With Docker (full suite): as of the not-null-violation and Join/self-join
fixes above, 2135 tests, **118 errors, 176 failures**, 27 skipped, 10 risky.
`fixtures/schemas` is now wired up too (see "Structural/tooling gaps"
below) — its 5 `*WithSchema(s)Test` classes build and run instead of being
unconditionally skipped, 3 of the 5 passing and 2 hitting the pre-existing
`ModelCriteria` protected-property issue tracked elsewhere in this doc — so
the actual combined count once all of the above land together will differ
slightly from 118/176/27; re-run the suite for an exact number rather than
trusting this line. Started from a suite that couldn't run a single test at
the start of this work (bootstrap was completely broken) and had 1137+
errors once it could run at all. See git log on `main` for the detailed fix
history — each commit message documents the specific root cause found and
fixed.

Without Docker (`PROPULSION_SKIP_INTEGRATION=1`, now genuinely supported —
see "Structural/tooling gaps" below): 2187 tests, 127 errors, 37 failures,
1135 skipped.

(The failure count going *up* by a couple after the join-clause fix below is
expected, not a regression: a few tests that used to hard-error on a
malformed `FROM` clause now get far enough to hit a separate, already-known
quoting-style assertion mismatch instead — see cluster 3 below.)

Reproduce with:
```
cd test
rm -rf fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
../vendor/bin/phpunit -c phpunit.xml
```
(First run pulls a `postgres:latest` image and builds fixtures into a
testcontainer — expect it to take a few minutes. Set
`PROPULSION_SKIP_INTEGRATION=1` to skip every test that needs it, if Docker
isn't available.)

### Remaining failures, by cluster (highest count first)

1. ~~**~30 "not null violation" errors**~~ — **fixed.** 40 test methods
   across `PropelModelPagerTest`, `PropelObjectCollectionTest`,
   `PropelObjectCollectionWithFixturesTest`, `PropelArrayFormatterWithTest`,
   `PropelObjectFormatterWithTest`, `PropelOnDemandFormatterTest`,
   `PropelOnDemandFormatterWithTest`, `BaseObjectSerializeTest`,
   `ModelCriteriaTest`, `Ticket520Test`, and `GeneratedObjectTest`
   constructed `new Book()` / `new Author()` / `new Review()` with required
   columns (`isbn`, `first_name`, `last_name`, `reviewed_by`,
   `recommended`) left unset. MySQL's non-strict mode silently coerced this
   to empty strings; Postgres correctly rejects it. Same root cause as the
   two tests already fixed in `GeneratedObjectTest.php` (commit `6f6b08e`)
   — populated the missing required field(s) in each test, following the
   same value conventions already used elsewhere in each file. No behavior
   or assertions changed. Dropped the suite's error count from 128 to 88.

2. ~~**1 real runtime bug**: `Join::getLeftTableAliasOrName(): string`
   throws `TypeError: ... none returned`~~ **Fixed.** This was actually two
   separate bugs that happened to share a stack trace:

   - `Join::getLeftTableAliasOrName()` (`runtime/Lib/Query/Join.php:328`)
     had a `: string` return type added during the PHP8.4 modernization
     pass, but returning `null` here is legitimate by design: `Join`'s
     legacy `addCondition($left, $right)` form (still exercised directly by
     `JoinTest`, e.g. `addCondition('foo', 'bar')` with no `table.column`
     dot) never sets `leftTableName`/`leftTableAlias` at all, and
     `getLeftColumn()` already has a ternary that tolerates that
     (`$tableName ? $tableName . '.' . ... : ...`). Traced every
     join-construction path (`Criteria::addJoin`/`addMultipleJoin`,
     `ModelJoin::setRelationMap` via `ModelCriteria::join()`, `mergeWith`,
     `useQuery`, `addJoinObject`) — none of them fail to set
     `leftTableName` when a table is actually known; `addExplicitCondition()`
     (used by every production path) always sets it directly (not via the
     `setLeftTableName()` setter, which is why grepping for that setter's
     call sites was a dead end). Fixed by widening the return type to
     `?string`, matching the getter's actual, always-been-nullable
     contract, instead of inventing a table name that was never there.
     Fixed 6 `JoinTest` methods.
   - The `bookstore_employee` self-join (`Supervisor`/`Subordinate`) SQL
     really was malformed (`FROM  INNER JOIN bookstore_employee s ON
     (...)`, empty `FROM`), but the cause was in
     `BasePeer::createSelectSql()` (`runtime/Lib/Util/BasePeer.php`,
     "tables should not exist in both the from and join clauses" block),
     not in `Join`. That block deduplicated `$fromClause` against
     `$joinTables` by *base table name*, ignoring aliases. For a self-join,
     the primary table's own unaliased `FROM` entry (`bookstore_employee`)
     and the joined table's aliased entry (`bookstore_employee s`) share a
     base name but are different table references — the base-name-only
     comparison stripped the real `FROM` entry, leaving an empty `FROM`
     before the `JOIN` keyword. This block predates this modernization
     session (present unchanged since the original `b474bb8` PHP8.4-port
     import), so it wasn't something introduced by recent fix commits.
     Fixed by narrowing the dedup back to an exact string match (table
     name *and* alias, or lack thereof) — the only case it actually needs
     to handle (a criterion or select-column referencing the same,
     identically-aliased/unaliased table that's also the right side of a
     join). Fixed `ModelCriteriaTest::testJoinOnSameTable` (now only fails
     on an unrelated, already-known MySQL-vs-Postgres backtick-quoting
     mismatch, cluster 4) and the 3 `*FormatterWithTest::testFindOneWithRelationName`
     tests that exercised the same `Supervisor` self-join end to end.

3. ~~**~11 "column does not exist" PDOExceptions**~~ **Re-triaged, not a
   bug.** All 11 are the exact same cause, unrelated to issue 2 above (they
   throw `SQLSTATE[42703]` "Undefined column", not the `42601` "syntax
   error" that the issue-2 malformed `FROM` clause actually produced — the
   two clusters never overlapped). Every one comes from hand-written raw
   SQL in `PropelArrayFormatterTest`/`PropelObjectFormatterTest`/
   `PropelOnDemandFormatterTest`/`PropelStatementFormatterTest`, e.g.
   `$con->query('SELECT * FROM book WHERE book.TITLE = "Quicksilver"')`.
   MySQL's non-standard extension treats double-quoted string literals
   as strings; standard SQL (and Postgres) treats `"..."` as a quoted
   *identifier*, so Postgres correctly reports `column "Quicksilver" does
   not exist`. This is a legitimate MySQL-vs-Postgres semantic difference
   in the test's own SQL, not a library bug — left alone rather than
   papered over (would require rewriting the test SQL to use single
   quotes, which is a test-content call the original test author didn't
   make and isn't in scope here).

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

The five items below (schemas fixture wiring, namespaced-codegen casing,
`QuickGeneratorConfig` hardcoded class names, `PropelStringReader`'s broken
include, and CI) were fixed in a follow-up pass. Notes on what actually
happened, since a couple had surprises:

- **`test/fixtures/schemas/` project wiring**: fixed.
  `IntegrationDatabase::ensureSchemasReady()` (+ `schemasConfFile()` /
  `schemasClassesDir()`) was added following the `ensureNamespacedReady()`
  template, and `SchemasTestBase` now calls it instead of just checking
  `file_exists()` on a conf file nothing ever wrote. One real bug had to be
  fixed to make the fixture buildable at all: the schema's tables use a
  `schema="..."` attribute (Propel's multi-schema-per-database support), and
  on Postgres (unlike MySQL, where `schema` just becomes a cross-database
  reference) `Table::getName()` qualifies the real SQL identifier as
  `schema.table` whenever the platform's `supportsSchemas()` is true --
  requiring an actual `CREATE SCHEMA`, which the generator's own
  `PgsqlPlatform::getAddSchemasDDL()` only emits for pgsql *vendor-info*
  parameters, not this attribute. Fixed at the test-harness level
  (`IntegrationDatabase::schemaNamesUsedBy()` parses the schema.xml's
  `schema="..."` attributes and issues `CREATE SCHEMA IF NOT EXISTS` before
  loading the generated DDL) rather than teaching the generator a new
  implicit-schema code path. Also had to add a `<unique>` constraint on
  `bookstore_contest(bookstore_id, id)` in `schema.xml` --
  `bookstore_contest_entry`'s composite FK targets those two columns, which
  MySQL tolerates (it only requires an index on FK target columns, not
  uniqueness) but Postgres rejects outright without a real unique
  constraint. Result: 3 of the 5 tests
  (`GeneratedRelationMapWithSchemasTest`, `RelatedMapSymmetricalWithSchemasTest`,
  `ConcreteInheritanceBehaviorWithSchemaTest`) pass cleanly now.
  `AggregateColumnBehaviorWithSchemaTest` and `ModelCriteriaWithSchemaTest`
  build and run but still fail -- on the exact same not-null-violation and
  protected-property-visibility bug clusters documented above (#1 and the
  fix already applied to the non-schema `ModelCriteriaTest` twin in commit
  `0186ecb`), left alone here since other work was in progress on those
  clusters in parallel.
- **Directory casing inconsistency in namespaced-project codegen**: fixed.
  `PHP84ObjectBuilder::getPackage()` and `PHP84PeerBuilder::getPackage()`
  appended `.OM` while `QueryBuilder`/`PHP84QueryBuilder`/
  `QueryInheritanceBuilder` (and the PHP5 builders) all appended lowercase
  `.om` -- the single-table-inheritance builders
  (`PHP84NodeBuilder`/`PHP84NodePeerBuilder`/`PHP84NestedSetBuilder`/
  `PHP84NestedSetPeerBuilder`) had the same `.OM` typo. All six switched to
  lowercase `.om` to match the majority convention. Verified: rebuilding the
  `namespaced` fixture now produces a single `Foo/Bar/om/` per package (no
  more `OM/` sibling), and `NamespaceTest` (12/12) still passes. The
  classmap-autoloader workaround from commit `306ee1b` is untouched and can
  stay regardless.
- **`QuickGeneratorConfig` hardcoded `PHP5*Builder` class names**: fixed.
  The `$builders` map now references the proper `Propulsion\Generator\Builder\OM\*`
  classes via `::class` instead of bare strings, so `getConfiguredBuilder()`
  no longer depends on `test/bootstrap.php`'s bare-name aliasing hack to
  resolve. That aliasing hack itself is untouched (still needed for
  generated *code*, which declares bare `use ModelCriteria;`-style imports at
  codegen time, independent of this class). Verified with a standalone
  script instantiating `QuickGeneratorConfig` outside the test suite's
  bootstrap, and the full suite still passes at the same baseline.
- **`generator/Lib/Builder/Util/PropelStringReader.php`**: fixed. Deleted
  the broken `include_once 'phing/system/io/Reader.php'` the same way
  commit `3b7c64d` did for the other three call sites -- the file already
  has `use Phing\Io\StringReader;` at the top, making the require both
  broken and redundant. Verified by instantiating the class directly via
  the composer autoloader.
- **No CI configuration**: added `.github/workflows/tests.yml` with two
  jobs. `unit` runs on every push/PR with `PROPULSION_SKIP_INTEGRATION=1`
  and no Docker; `integration` runs the full suite with the real
  testcontainer-backed Postgres (Docker is available by default on
  `ubuntu-latest` runners). Both are `continue-on-error: true` for now,
  since the suite has known, tracked failures independent of CI itself
  (see above) -- flip to blocking once those clusters are fixed.

  Getting the `unit` job to actually finish (rather than crash PHPUnit
  entirely) required fixing a real, previously-undiscovered bug:
  `PROPULSION_SKIP_INTEGRATION=1` was already documented (in this file's
  own "Reproduce" section) as the way to skip Docker-dependent tests when
  Docker isn't available, but it never actually worked standalone --
  several `*Test.php` files declare auxiliary classes at *file scope* that
  extend generated bookstore-fixture classes (e.g.
  `class UndeletableTable4 extends Table4` in `SoftDeleteBehaviorTest.php`,
  or `PublicTable9 extends Table9` in `BookstoreNestedSetTestBase.php`).
  PHP evaluates `class X extends Y` the moment the file is `require`d, not
  lazily on first use, and PHPUnit's `TestSuiteLoader` requires every test
  file up front during suite discovery -- so an undefined `Y` (fixtures not
  built) fatals the *entire* PHPUnit process before a single test runs,
  rather than the individual test skipping gracefully. `test/bootstrap.php`
  even had a comment acknowledging this ("an inherent constraint of this
  suite's structure, not something a lazier build step could fix").
  Fixed the specific instances found by wrapping each fixture-dependent
  file-scope class declaration in `if (class_exists(FixtureClass::class))`:
  `test/testsuite/generator/behavior/SoftDeleteBehaviorTest.php`,
  `.../aggregate_column/AggregateColumnBehaviorTest.php`,
  `.../sluggable/SluggableBehaviorTest.php`,
  `test/testsuite/runtime/query/{PropelQueryTest,SubQueryTest}.php`,
  `test/testsuite/generator/builder/om/QueryBuilderTest.php`, and
  `test/tools/helpers/bookstore/behavior/{BookstoreNestedSetTestBase,TestAuthor}.php`.
  Also found two files (`JoinTest.php`, `CriteriaCombineTest.php`) that call
  `Propel::init(bookstore-conf.php)` unconditionally at file scope, and one
  more root cause: `test/bootstrap.php` only triggered
  `Propulsion\Propel`'s legacy bare-class-name aliasing (`PropelException`,
  `PropelCollection`, `PropelArrayCollection`, ...) as a side effect of the
  bookstore fixture build *succeeding*, so ordinary runtime tests with no
  fixture dependency at all fataled on missing bare names whenever
  `PROPULSION_SKIP_INTEGRATION=1` was set -- moved that trigger to always
  run, unconditionally, near the top of bootstrap. With all of the above,
  `PROPULSION_SKIP_INTEGRATION=1` now runs the full 2187-test suite to
  completion with zero Docker access: ~1135 tests skip themselves via
  `markTestSkipped()` as designed, and the rest run and pass/fail normally
  (127 errors/37 failures at the time of this writeup, all pre-existing and
  unrelated to this fix -- e.g. `TableMapTest`'s column-name-casing
  failures are part of cluster #4 above). This was a wider fix than
  originally scoped ("just add a CI file"), but was necessary for the
  requested `PROPULSION_SKIP_INTEGRATION=1`-based unit job to produce any
  signal at all instead of a single opaque crash. It is very likely *not*
  exhaustive -- only the file-scope patterns actually hit during this pass
  were found and fixed; another currently-untriggered one may exist
  elsewhere in the ~2000 remaining test files.
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
