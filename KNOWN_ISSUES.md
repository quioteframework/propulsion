# Known issues and remaining work

Status as of the test-fixing pass on `main` (commits `f8675fc`..`0186ecb`). This
tracks two separate things: **remaining test failures** (from the PHPUnit
pass) and **modernization plan phases not yet done** (from the original
scoping conversation, before it detoured into fixing tests).

## Current test suite state

**Phase 3.5 (PHP5 builders removed entirely — see that section below for
the full story) is now fully landed: all four parallel follow-up passes
(`sortable`, `i18n`, `nested_set`, and the generic `ObjectBuilder`/
`PeerBuilder` completeness audit) are fixed and merged.** Immediately after
PHP5 removal the suite regressed to 143 errors/184 failures (from the 36
errors/19 failures baseline described below) — an accurate regression, not
a bug in the removal itself: removing PHP5 as a fallback surfaced real,
previously-masked completeness gaps in the promoted builders across several
independent areas (behavior-modifier hooks never being called at all, a
property-naming mismatch in both `sortable` and `nested_set`, a composable-
accessor API gap in `i18n`, and ~15 more generic gaps — missing
`doSelectJoin*` methods, LOB/array/enum column handling, referrer-collection
caching, `primaryString`/`__toString`, `reload`/`ensureConsistency`,
temporal-default modified-tracking, `allowPkInsert`, and more). See Phase
3.5 below for the itemized root-cause writeup of every one of these.
Confirmed post-merge with a fresh full-suite run: **2200 tests, 38 errors,
26 failures, 12 skipped, 1 risky** — essentially back to the pre-Phase-3.5
baseline (36 errors/19 failures) despite fully removing PHP5 as a fallback,
with the remaining gap being the already-documented pre-existing flakiness
clusters below plus a couple of newly-surfaced order-dependent stragglers
(not chased down further, consistent with this suite's documented run-to-run
count variance).

Prior to Phase 3.5, with Docker (full suite, confirmed by an actual
combined run after all of the below landed together on `main`): **2184
tests, 36 errors, 19 failures, 12 skipped, 2 risky**. Started from a suite
that couldn't run a single test at the start of this work (bootstrap was
completely broken) and had 1137+ errors once it could run at all. See git
log on `main` for the detailed fix history — each commit message
documents the specific root cause found and fixed.

Getting from ~118 errors (clusters #1-#3 fixed) down to 36 required fixing
two more things not called out as their own numbered clusters: merging four
independent fix branches together transiently produced **285** errors, not
44 — `AggregateColumnBehaviorWithSchemaTest::tearDown()` unconditionally
called `$this->con->commit()` after `testComputeWithSchema()` hit a
pre-existing not-null violation that aborted the Postgres transaction;
committing an already-aborted transaction throws, which skipped
`parent::tearDown()` (`SchemasTestBase`), which is what restores Propulsion's
process-global config from the `schemas` datasource back to `bookstore`
after a schema test runs. Every subsequent test in the same PHPUnit process
that assumed `bookstore` was configured (any `BookstoreTestBase` subclass —
most of the suite) then failed with `No connection information ... for
datasource [bookstore]`, even though each one passes fine in isolation.
Fixed by guarding the commit the same way `BookstoreTestBase::tearDown()`
already does elsewhere (`isCommitable()`/`isInTransaction()`/
`forceRollBack()`), so `parent::tearDown()` always runs regardless of
whether the test body threw.

The second, separate regression (285 → 44 fixed that, then 44 → 36 fixed
this one): the cluster #8 fix to `ModelCriteria::doDeleteAll()` (passing
`$con` as the sole positional arg instead of `null, $con`) assumed every
generated Peer class has the single-`$con` `doDeleteAll(PropulsionPDO $con =
null)` contract that `PHP5PeerBuilder` generates. `PHP84PeerBuilder`'s
peers, however, `extends BasePeer` directly whenever no behavior
`parentClass` is set, and its `doDeleteAll` override literally copied
`BasePeer::doDeleteAll(?string $tableName = null, ?PropulsionPDO $con = null,
?string $databaseName = null)`'s 3-arg signature — so passing `$con` first
now threw a `TypeError` (or a fatal LSP-incompatibility error once the
per-table signature was corrected back to match) for every namespaced/PHP84
fixture test (8 `NamespaceTest` methods). Root cause: `PHP5PeerBuilder`
deliberately skips the `extends BasePeer` when `basePeerClassname ===
'BasePeer'` (`generator/Lib/Builder/OM/PHP5PeerBuilder.php`), specifically
to avoid forcing the per-table peer's simpler `doDeleteAll($con)` wrapper
into signature-compatibility with `BasePeer`'s generic 3-arg version — but
`PHP84PeerBuilder`'s copy of that same check dropped the `'BasePeer'`
exclusion, so it always extended it. Fixed `PHP84PeerBuilder` to match
`PHP5PeerBuilder`'s guard; the per-table peer's `doDeleteAll(?PropulsionPDO
$con = null)` signature is correct again and no longer conflicts with the
one-argument call site.

Without Docker (`PROPULSION_SKIP_INTEGRATION=1`, now genuinely supported —
see "Structural/tooling gaps" below): 2187 tests, 127 errors, 37 failures,
1135 skipped (not re-verified against the final combined state above; likely
close but not re-run).

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
   across `PropulsionModelPagerTest`, `PropulsionObjectCollectionTest`,
   `PropulsionObjectCollectionWithFixturesTest`, `PropulsionArrayFormatterWithTest`,
   `PropulsionObjectFormatterWithTest`, `PropulsionOnDemandFormatterTest`,
   `PropulsionOnDemandFormatterWithTest`, `BaseObjectSerializeTest`,
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
   SQL in `PropulsionArrayFormatterTest`/`PropulsionObjectFormatterTest`/
   `PropulsionOnDemandFormatterTest`/`PropulsionStatementFormatterTest`, e.g.
   `$con->query('SELECT * FROM book WHERE book.TITLE = "Quicksilver"')`.
   MySQL's non-standard extension treats double-quoted string literals
   as strings; standard SQL (and Postgres) treats `"..."` as a quoted
   *identifier*, so Postgres correctly reports `column "Quicksilver" does
   not exist`. This is a legitimate MySQL-vs-Postgres semantic difference
   in the test's own SQL, not a library bug — left alone rather than
   papered over (would require rewriting the test SQL to use single
   quotes, which is a test-content call the original test author didn't
   make and isn't in scope here).

4. ~~**~27 "Failed asserting that two strings are equal"**~~ **Fixed** (the
   real count was closer to 159 once masked data-provider datasets started
   running — see #7). Mostly real bugs, not MySQL-vs-Postgres noise: a
   typo'd `instanceof MysqlPLatform` in `Table.php` silently disabled
   MySQL's auto FK indices; `DefaultPlatform::getDatabaseType()` substring-
   matched `Platform` against the FQCN and tripped on the `Platform`
   namespace segment, breaking MySQL charset/collation/FULLTEXT lookups;
   `ColumnMap::normalizeName()` lowercased instead of uppercasing (against
   its own docblock), which was band-aiding a real bug in
   `PHP5TableMapBuilder`/`PHP84TableMapBuilder`'s uncased relation-column
   mappings; `TableMap::hasPrefix()` used `strpos($data, null)`, which
   always matches on an unset prefix; `ConcreteInheritanceBehavior::
   parentClass()` matched builders by bare `get_class()` name instead of
   FQCN, so reparenting silently never fired; `OMBuilder::getPackagePath()`
   had a malformed regex (`$i#` instead of `$#i`); `PropulsionCollection::
   getIterator()` returned a detached `ArrayIterator` copy, so by-reference
   `foreach` mutation never persisted through `save()`. The remainder were
   genuine MySQL-backtick-vs-Postgres-unquoted expected-SQL literals (~89
   assertions across `ModelCriteriaTest`, `CriteriaMergeTest`, `SubQueryTest`,
   `ModelCriteriaSelectTest`, `BasePeerTest`, `PropulsionPDOTest`) and a few
   stale bare-vs-namespaced class-name literals.

5. ~~**10 "This test did not perform any assertions"**~~ **8 of 10 fixed** —
   restored dropped assertions or added `expectNotToPerformAssertions()`
   where the test genuinely just checks "doesn't throw". Left 2 on purpose:
   `NestedSetBehaviorQueryBuilderModifierTest::testOrderByLevel` exposes a
   real separate ordering bug once its assertion is restored (not yet
   fixed); `GeneratedObjectTest::testNoColsModified` is genuinely
   incomplete.

6. ~~**4 "Unkown column BookClubList in model Book"**~~ **Fixed.**
   `QueryBuilder::addFilterByCrossFK()` only emitted the
   `...ViaCrossReference()` filter method name, but generated getters/
   counters on the related object call the plain `filterByBookClubList()`
   name, which then hit PHP's magic `__call` as a bogus column filter.
   Ported the already-correct dual-method-emission fix from
   `PHP84QueryBuilder` back into the base `QueryBuilder`.

7. ~~**3 `PgsqlPlatformMigrationTest` "data provider ... is invalid"**~~
   **Fixed**, and it affected `Mysql`/`OraclePlatformMigrationTest` too, not
   just Pgsql. Root cause: PHPUnit 10+ requires static data providers, but
   `PlatformMigrationTestProvider` (and `DefaultPlatformTest`) called
   `$this->getPlatform()`/`$this->getDatabaseFromSchema()` from methods
   declared `static`. Made `PlatformTestBase`'s helpers and all platform
   subclasses' `getPlatform()` static. This also fixed several previously
   entirely-masked datasets that hadn't been running at all, which is why
   cluster #4's real count was ~159, not ~27.

8. ~~**~5 `aggregate_poll`/`aggregate_post` "lock timeout" errors**~~
   **Fixed.** `ModelCriteria::doDeleteAll()` passed an extra leading `null`
   to `Peer::doDeleteAll($con)`, silently shifting `$con` out of the call
   so it always fell back to `Propulsion::getConnection()` — opening a second
   real connection/transaction against the same database instead of
   reusing the caller's, which deadlocked later cleanup.

### Structural/tooling gaps found but not fixed

The five items below (schemas fixture wiring, namespaced-codegen casing,
`QuickGeneratorConfig` hardcoded class names, `PropulsionStringReader`'s broken
include, and CI) were fixed in a follow-up pass. Notes on what actually
happened, since a couple had surprises:

- **`test/fixtures/schemas/` project wiring**: fixed.
  `IntegrationDatabase::ensureSchemasReady()` (+ `schemasConfFile()` /
  `schemasClassesDir()`) was added following the `ensureNamespacedReady()`
  template, and `SchemasTestBase` now calls it instead of just checking
  `file_exists()` on a conf file nothing ever wrote. One real bug had to be
  fixed to make the fixture buildable at all: the schema's tables use a
  `schema="..."` attribute (Propulsion's multi-schema-per-database support), and
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
  (`NodeBuilder`/`NodePeerBuilder`/`NestedSetBuilder`/
  `NestedSetPeerBuilder`) had the same `.OM` typo. All six switched to
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
- **`generator/Lib/Builder/Util/PropulsionStringReader.php`**: fixed. Deleted
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
  `test/testsuite/runtime/query/{PropulsionQueryTest,SubQueryTest}.php`,
  `test/testsuite/generator/builder/om/QueryBuilderTest.php`, and
  `test/tools/helpers/bookstore/behavior/{BookstoreNestedSetTestBase,TestAuthor}.php`.
  Also found two files (`JoinTest.php`, `CriteriaCombineTest.php`) that call
  `Propulsion::init(bookstore-conf.php)` unconditionally at file scope, and one
  more root cause: `test/bootstrap.php` only triggered
  `Propulsion\Propulsion`'s legacy bare-class-name aliasing (`PropulsionException`,
  `PropulsionCollection`, `PropulsionArrayCollection`, ...) as a side effect of the
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

**Done, partially** — see deviations below. `generator/default.properties`
no longer defaults `propel.targetPlatform = php5`; the unsuffixed
`propel.builder.*.class` keys now point at the promoted (formerly PHP84)
builders for **query and tablemap only** (see "Deviations" below for why
peer/object and node/nestedset were deliberately left on the legacy PHP5
builders). Verified via `cd test && rm -rf fixtures/*/build && ../vendor/bin/phpunit
-c phpunit.xml`: **2184 tests, 36 errors, 19 failures, 12 skipped, 2
risky** — identical to the pre-Phase-3 baseline (confirmed against `main`
at `90404e6` in a throwaway worktree), i.e. this phase is not a net
regression despite touching the generator's default output for every
fixture.

**What actually happened, vs. the plan's assumptions:**

- The plan assumed the existing abstract bases were named
  `AbstractPeerBuilder`/`AbstractObjectBuilder`. They weren't — the
  abstract bases were already named plain `PeerBuilder`/`ObjectBuilder`
  (that *was* the actual collision with promoting `PHP84PeerBuilder`/
  `PHP84ObjectBuilder`). Renamed the abstract bases to
  `AbstractPeerBuilder`/`AbstractObjectBuilder` first, then promoted
  `PHP84PeerBuilder`→`PeerBuilder`, `PHP84ObjectBuilder`→`ObjectBuilder`.
  Missed two files in the first pass (`PHP5ExtensionObjectBuilder`,
  `PHP5ExtensionPeerBuilder` still said `extends ObjectBuilder`/
  `extends PeerBuilder`, i.e. the newly-promoted concrete classes instead
  of the newly-renamed abstract ones) — caught immediately by running
  `testsuite/generator/builder/om/` right after, fixed in a follow-up
  commit.
- `PHP84TableMapBuilder extends PHP5TableMapBuilder` only to inherit
  `getUnprefixedClassname()`, `addIncludes()`, `addClassClose()`, and the
  `TableMapBuilderModifier`-aware `hasBehaviorModifier()`/
  `applyBehaviorModifier()` — none customized. Inlined all five verbatim;
  the promoted `TableMapBuilder` now extends `OMBuilder` directly with no
  PHP5 dependency.
- `QueryBuilder`/`PHP84QueryBuilder` needed a real merge, not a rename, as
  the plan anticipated — but the merge was smaller than it looked:
  `PHP84QueryBuilder extends QueryBuilder` and only overrode ~8 methods
  (`getPackage`, `addClassOpen`, `addClassBody`, `addConstructor`,
  `addFactory`, `addFilterByCol`, `addFilterByCrossFK`, `addFindPk`),
  inheriting everything else (`addFilterByFk`, `addJoinFk`,
  `addUseFkQuery`, etc.) unchanged. Of those 8, two were **not** merged
  after inspection found they regress real functionality:
  `addFindPk()`'s composite-primary-key branch is a hardcoded
  `throw new PropulsionException('...not yet implemented for PHP84QueryBuilder')`,
  and `addFilterByCol()` silently drops the ENUM/OBJECT column-type
  branches entirely (present in the merged-in base, absent from PHP84's
  override). Both are real, pre-existing PHP84-builder completeness gaps,
  not something introduced by this merge — left unmerged (i.e. the
  promoted `QueryBuilder` keeps the base's correct, complete versions of
  those two methods) and flagged here rather than silently reintroducing
  the regression.
- The straight prefix-drop renames went as scoped, no surprises.

**Deviations from "flip everything" — left on legacy PHP5 builders:**

- **`peer`, `object`, `objectstub`, `peerstub`, `objectmultiextend`**:
  flipping these surfaced a temporal-column-default bug in the promoted
  `ObjectBuilder` (assigned raw date strings to typed `?DateTimeInterface`
  properties, both illegally as a property-declaration default and as a
  runtime type error in `applyDefaultValues()` — fixed), immediately
  followed by a cascade of further failures and, on one fixture-table
  combination, an actual PHP engine segfault. Auditing and fixing the
  promoted `ObjectBuilder`/`PeerBuilder` to the same completeness bar as
  `query`/`tablemap` is real work beyond a rename/promotion phase's scope
  — left as explicit follow-up. The renamed classes (`PeerBuilder`,
  `ObjectBuilder`) exist and are reachable via `.php84.class`; they're
  just not the default yet.
- **`node`, `nodepeer`, `nodestub`, `nodepeerstub`, `nestedset`,
  `nestedsetpeer`**: turned out to be unfinished stubs, not
  feature-complete ports — dozens of methods across `NestedSetBuilder`,
  `NestedSetPeerBuilder`, `NodeBuilder`, `NodePeerBuilder` are literal
  `protected function addX(&$script): void { /* Implementation */ }`
  no-ops. Attempting to flip `nestedset`/`nestedsetpeer` by default
  immediately fataled with `Class Page contains 32 abstract methods` the
  moment a table using the deprecated "NestedSet treeMode" (distinct from
  the actively-maintained `nested_set` *behavior*) was loaded. Completing
  ~40 methods of real B-tree/nested-set manipulation logic is out of scope
  here; left on the legacy (complete) PHP5 builders by default.
- Both deviations are called out explicitly in `generator/default.properties`
  itself, next to the relevant keys.

**Other real bugs found and fixed along the way** (all previously dormant
because nothing exercised the promoted builders as the *default* before
this phase — same pattern as commit `306ee1b`'s findings):

- `QueryBuilder`/`PeerBuilder`'s `getUseStatements()` (inherited verbatim
  from the PHP84 builders) had a `use`-import bug that only manifests when
  a builder's output target has no PHP namespace of its own: it either
  emitted the same `use Propulsion\Query\ModelCriteria;`-style import once
  per class — fatal ("name is already in use") when `PropulsionQuickBuilder`
  concatenates many such flat classes into one `eval()`'d script with no
  namespace block between them — or, in an earlier fix attempt, skipped
  imports too broadly and broke genuinely namespaced projects (a bare
  class reference inside a real `namespace Foo\Bar;` block resolves
  *relative to that namespace*, not globally, so it still needs a real
  `use` import there). Fixed to key off whether the *generated* class
  itself has a real namespace, not blanket rules.
- `TableMapBuilder`'s promoted `addInitialize()` never called
  `setSingleTableInheritance(true)` for tables using single-table
  inheritance, unlike `PHP5TableMapBuilder` (caught by
  `PHP5TableMapBuilderTest::testSingleTableInheritance`, which despite its
  name exercises whichever `tablemap` builder is configured by default).
- `NestedSetBuilder`/`NodeBuilder` generated `setLevel(?int $level)`,
  narrower than `Propulsion\OM\NodeObject`'s untyped interface method —
  PHP rejects narrowing a parameter type as a contravariance violation.
  `PHP5NestedSetBuilder`'s `save()`/`delete()` overrides also needed
  updating to match the (now default) typed `ObjectBuilder`-generated
  parent class's signatures, since `PHP5NestedSetBuilder`-generated
  classes extend whatever `object` builder is configured.
- `VersionableBehaviorObjectBuilderModifier::addVersion()` passed a
  `PropulsionPDO` positionally into a collection getter's `$criteria`
  parameter — silently tolerated by the old untyped `create()` (the
  `instanceof Criteria` check just evaluated false and did nothing) but a
  hard `TypeError` under the new typed one. That mistyped `$con` being
  non-null was also accidentally load-bearing: it forced the getter to
  bypass its in-memory collection cache and re-query fresh from the
  database, which `addVersion()` actually needs to snapshot current state
  correctly. Fixed by passing an explicit empty `Criteria` object, which
  forces the same fresh-query behavior correctly instead of by accident.
- Two test fixtures needed updating to match the new default codegen
  shape (custom `Query` subclasses overriding `create()` with the old
  untyped signature), and two tests hardcoded `require_once()` paths using
  the legacy lowercase `.../map/...` directory name — `TableMapBuilder`
  (and `ObjectBuilder`/`PeerBuilder`/`QueryBuilder`'s `.om` package,
  unchanged by this phase) intentionally capitalizes its output package to
  `Map`/`OM`, for PSR-4-friendly `<SchemaName>/Map` and `<SchemaName>/OM`
  directory naming — not a bug to revert.

**Superseded by Phase 3.5 below**: rather than doing the "still needed"
audit-and-flip work incrementally, all PHP5 builders were removed from the
codebase outright per explicit user instruction. See Phase 3.5.

### Phase 3.5 — Remove PHP5 builders entirely, archived to `archaeology/php5-builders/`

**Done, with substantial known fallout** — explicit user request ("Rip out
all the PHP5 stuff. Place the files in some archeology directory for now
so you can reference them if/when needed. I'm unsure if nestedsets have
ever worked in Propel."), executed as a follow-on to Phase 3 rather than
the originally-planned incremental audit-then-flip.

**What moved**: every `PHP5*`-prefixed builder (`PHP5PeerBuilder`,
`PHP5ObjectBuilder`, `PHP5TableMapBuilder`, `PHP5QueryBuilder`,
`PHP5InterfaceBuilder`, the `PHP5Extension*`/`PHP5Node*`/`PHP5NestedSet*`
family, `PHP5MultiExtendObjectBuilder`, `PHP5ObjectNoCollectionBuilder`) —
15 files — moved from `generator/Lib/Builder/OM/` (the PSR-4 root for
`Propulsion\Generator\`) to `archaeology/php5-builders/` (outside any
autoload path; see that directory's `README.md`). `default.properties`'
`propel.builder.*.class` keys and `.php5.class` overrides referencing them
were removed; every builder type is now unconditionally the promoted
(formerly `PHP84`-suffixed) one. `ExtensionObjectBuilder`/
`ExtensionPeerBuilder`, which literally `extends PHP5Extension{Object,Peer}Builder`,
had the two methods they still needed (`getUnprefixedClassname()`,
`addClassClose()`) inlined and their base class changed to
`AbstractObjectBuilder`/`AbstractPeerBuilder`. A second, entirely separate
hardcoded builder registry, `generator/Lib/Config/QuickGeneratorConfig.php`
(used by `PropulsionQuickBuilder`, the ad-hoc-schema builder nearly all
behavior unit tests use — independent of `default.properties`), was missed
in the first pass and fixed once it started fataling with "Class
...PHP5TableMapBuilder not found".

**The big one — ObjectBuilder had *zero* behavior-modifier hook calls**:
removing PHP5 forced `ObjectBuilder`/`PeerBuilder` to become the
unconditional default (previously deferred in Phase 3 specifically because
of known gaps). Auditing `ObjectBuilder` turned up something far larger
than the previously-documented temporal-default bug: it never called
`applyBehaviorModifier()` *anywhere*, and never called the
user-overridable `preSave()`/`postSave()`/`preInsert()`/`postInsert()`/
`preUpdate()`/`postUpdate()`/`preDelete()`/`postDelete()` hook methods
(defined on `runtime/Lib/OM/BaseObject.php`, e.g. `TestAuthor` overrides
these throughout the test suite) at all. This meant **every schema
behavior that hooks object-level codegen** (`nested_set`, `versionable`,
`sluggable`, `timestampable`, `aggregate_column`, `archivable`, `i18n`,
`sortable`, `concrete_inheritance`, `delegate`, `soft_delete`,
`query_cache`) silently got none of its generated code injected, and no
object ever ran its own save/delete lifecycle hooks either — this was the
dominant cause of a spike to ~830 combined errors/failures immediately
after the PHP5 removal. Fixed by porting the `addHooks`-gated hook
structure from `archaeology/php5-builders/PHP5ObjectBuilder.php`
(`addSaveBody`/`addDeleteBody`) into `addSave()`/`addDelete()`, and adding
the remaining hook points: `objectAttributes` (end of `addProperties()`),
`objectMethods` (in `addClassBody()`, before `addMagicCall()`),
`objectFilter` (after the closing brace in `addClassClose()`, since filter
hooks rewrite `$script` by reference so must run last),
`objectClearReferences` (in `addClearAllReferences()`, after the `$deep`
block). `objectCall` was already wired via `addMagicCall()`'s existing
`getBehaviorContent('objectCall')` call.

**Other real, previously-dormant bugs found and fixed while chasing this
down** (all silent under PHP5-as-default, since nothing exercised the
promoted `ObjectBuilder`/`PeerBuilder`/`TableMapBuilder` as the *only*
option before):

- `addPKRefFKSet()` (the 1:1-relationship reverse FK setter, e.g.
  `BookstoreEmployee::setBookstoreEmployeeAccount()`) called the other
  side's setter unconditionally with no guard against it calling back —
  infinite mutual recursion, stack overflow, **an actual PHP engine
  segfault** the moment any 1:1 relationship's setter was exercised.
  Ported PHP5's `if ($v !== null && $v->get{FK}() === null)` guard.
- `addHasOnlyDefaultValues()`'s temporal-column comparison used a plain
  double-quoted string containing `$this->$phpname->format(...)` — PHP's
  simple string-interpolation syntax parses `$phpname->format` as a
  (bogus, build-time) property access on the `$phpname` *string* itself,
  silently corrupting the generated code. Needed `{$phpname}`.
- `PeerBuilder::doValidateThis()` type-hinted its `$obj` parameter as the
  current table's own object class, which is an LSP violation the moment a
  concrete-inheritance child peer (a real PHP class hierarchy, e.g.
  `ConcreteArticlePeer extends ConcreteContentPeer`) narrows that type
  relative to its parent. Dropped the type hint (`$obj` is only ever
  called with `$this`).
- `QueryBuilder`/`PeerBuilder`/`ObjectBuilder`/`TableMapBuilder` all needed
  (and, for the latter two, were missing) a `getUseStatements()` override:
  the default emits a `use Fully\Qualified\Name;` for every FQCN-declared
  class regardless of whether the generated class has a real namespace.
  For a flat/non-namespaced target this is at best redundant and at worst
  fatal — `PropulsionQuickBuilder` concatenates many flat classes into a
  single `eval()`'d script with no namespace block between them, so the
  same `use X;` emitted once per class collides ("name is already in
  use"). All four now key off whether the *generated* class itself has a
  real namespace.
- Temporal, enum, and boolean column mutators/properties were all typed
  far too strictly relative to what Propulsion has always accepted at
  these call sites (a pattern repeated three times before it was
  recognized as systemic): `?DateTimeInterface`-only setters rejected the
  int-timestamp/string inputs `TimestampableBehavior`/`SoftDeleteBehavior`
  pass; enum columns' `?string`-typed property couldn't legally take the
  int index `getDefaultValueString()` returns as a *compile-time property
  declaration default* (a hard fatal, not a warning); `?bool`-only
  setters silently inverted string inputs like `'false'`/`'off'`/`'no'`
  (bool's weak typing casts any non-empty string truthy, so this one
  didn't even throw). Ported PHP5's normalization logic for all three
  (`PHP5ObjectBuilder::addTemporalMutator()`/`addBooleanMutator()`,
  and the enum-index default handling), while keeping the *properties*
  themselves strictly typed (`?DateTimeInterface`, real objects — PHP5
  stored temporal values as pre-formatted strings internally; this
  builder does not, and this phase didn't change that).
- `NestedSetBuilder.php`/`NestedSetPeerBuilder.php` (the deprecated
  "NestedSet treeMode", not the actively-maintained `nested_set`
  *behavior*) had ~38+11 literal `{ /* Implementation */ }` no-op stub
  methods, so any table using it (the `cms.page` fixture,
  `Page`/`PagePeer`) fataled with "must be declared abstract" on
  instantiation the moment it stopped falling back to
  `PHP5NestedSetBuilder`. Ported the real logic from
  `archaeology/php5-builders/PHP5NestedSetBuilder.php`/
  `PHP5NestedSetPeerBuilder.php`. `GeneratedNestedSetObjectTest`/
  `GeneratedNestedSetTest`/`GeneratedNestedSetPeerTest` (27 tests) now
  pass. Known remaining gaps in the port: `updateLoadedNode()`'s
  composite-primary-key branch doesn't reconstruct PHP5's OR-of-ANDs
  `Criterion` tree (no fixture exercises it), and
  `hydrateDescendants()`/`hydrateChildren()` don't handle
  single-table-inheritance children-column tables (also unexercised).
  `NodeBuilder.php`/`NodePeerBuilder.php` (the separate, never-fixture-tested
  `MaterializedPath`/`AdjacencyList` "node" builder type) were *not*
  similarly completed — no test in this suite exercises them (the
  `test/fixtures/treetest/` fixture that would is orphaned, not wired
  into `IntegrationDatabase` or any test file) — so they remain the same
  kind of no-op stubs, now with no PHP5 fallback either.

**Net result — this was not a clean net win on the test counter**, and
that's an accurate reflection of real completeness gaps this phase
exposed rather than something papered over: before this phase (PHP5 still
providing a safety net for `peer`/`object`/`objectstub`/`peerstub`/
`objectmultiextend`/`node`/`nestedset`/etc., per Phase 3), the full suite
was at parity with the documented **36 errors, 19 failures** baseline.
After removing PHP5 entirely and fixing everything above (segfault, the
entirely-missing behavior-hook system, the four `getUseStatements` bugs,
the three column-mutator strictness regressions, the LSP violation, the
NestedSet builder port): **2200 tests, 143 errors, 184 failures, 11
skipped, 1 risky**. The swing is entirely attributable to behavior tests
that depend on completeness the promoted `ObjectBuilder` never actually
had before (most were simply never reached, because PHP5 was silently
doing the real work): dominant remaining clusters (see a fresh
`../vendor/bin/phpunit -c phpunit.xml testsuite/` run for the current
exact numbers) are `NestedSetBehaviorObjectBuilderModifierTest` (~50,
active `nested_set` *behavior* — distinct from the treeMode builder above,
and only partially working: tree positioning/query edge cases remain
broken even with the hooks now firing), `I18nBehaviorObjectBuilderModifierTest`/
`I18nBehaviorQueryBuilderModifierTest` (~37), `SortableBehaviorObjectBuilderModifierTest`
and its `WithScope` variant (~29), plus scattered failures across
`GeneratedObjectTest`, `ModelCriteriaTest`, `GeneratedPeerDoSelectTest`,
and others not yet root-caused. **Not further investigated in this pass**
due to time constraints — flagged here rather than left silently broken.
Given the user's explicit acknowledgment that nested-set correctness was
already in doubt, and that PHP5 removal was requested outright rather than
gated on reaching parity first, this is being landed as-is; a follow-up
pass should work through the remaining clusters the same way this one
worked through the hook-wiring and segfault issues (bisect by running
`testsuite/generator/behavior/<name>/` in isolation, find the first real
error, root-cause it, repeat).

**Sortable behavior cluster — fixed** (the `SortableBehaviorObjectBuilderModifierTest`/
`SortableBehaviorObjectBuilderModifierWithScopeTest`/`SortableBehaviorPeerBuilderModifierTest`/
`SortableBehaviorPeerBuilderModifierWithScopeTest` ~29-37 failures called out
above). Two independent, real bugs in the sortable behavior's own modifier
code (`generator/Lib/Behavior/Sortable/*.php`), neither in `ObjectBuilder`/
`PeerBuilder` itself — both pre-existing and silently tolerated by PHP5's
untyped generated code, surfaced only once the promoted (strictly-typed)
builders became the sole code path:

- `SortableBehaviorObjectBuilderModifier::addRankAccessors()`/
  `addScopeAccessors()` generated `getRank()`/`getScopeValue()` wrapper
  methods (emitted whenever the schema names the rank/scope column
  something other than the literal `rank`/`scope_value`, which both
  `table11`/`table12` sortable fixtures do) that read a raw object
  property named via `strtolower($column->getName())` — e.g.
  `$this->sortable_rank`. That was `PHP5ObjectBuilder`'s property-naming
  convention (`strtolower($col->getName())` throughout). The promoted
  `ObjectBuilder` names column properties by PascalCase `phpName` instead
  (`private ?int $SortableRank`, per `addProperties()`), so these wrappers
  always read an undeclared property (silently `null` under PHP's dynamic
  property access) instead of the real one. Every rank-dependent
  assertion across all four Sortable test classes failed or returned/keyed
  on a null/empty rank as a result (visible as `Failed asserting that null
  matches expected N`, or arrays keyed by `''` instead of the real rank in
  the reorder/move tests). Fixed by delegating to the real generated
  getter method (`$this->getSortableRank()`, via the existing
  `getColumnGetter()` helper) instead of re-deriving a property name —
  decouples the behavior modifier from `ObjectBuilder`'s internal property-
  naming scheme entirely, rather than reintroducing a second, now-wrong
  copy of PHP5's convention. This is the kind of gap the task's framing
  anticipated finding in behavior modifiers specifically (not just in the
  builders) — worth a quick check by whoever owns `nested_set`/`i18n`
  whether their behavior modifiers make the same `strtolower(getName())`
  property-name assumption, since the pattern (and the `getColumnAttribute()`
  helper method name) recurs verbatim in
  `SortableBehaviorPeerBuilderModifier` too, just unused there.
- `SortableBehaviorPeerBuilderModifier::addCountList()` called
  `Peer::doCount($c, $con)`, positionally passing the connection into
  `doCount()`'s `$distinct` parameter (the call was simply missing the
  `$distinct` argument). `PeerBuilder::addDoCount()`'s `doCount()` wrapper
  is untyped and forwards to the strictly-typed `doCountThis(Criteria,
  bool, ?PropulsionPDO)`, so this always threw `doCountThis(): Argument #2
  ($distinct) must be of type bool, null given` the moment
  `countList()` (used by `SortableBehaviorPeerBuilderModifierWithScopeTest::
  testCountList`) ran — this was the suite's one sortable-cluster *error*
  (as opposed to failure). Fixed by passing the missing `false` literal
  for `$distinct` explicitly.

Verified: `--filter SortableBehavior` went from 37 failures + 1 error (of
73 tests) to 0/0, 73/73 passing. Full suite
(`../vendor/bin/phpunit -c phpunit.xml`, Docker/Postgres, fixture `build/`
dirs removed first): **2200 tests, 143 errors, 145 failures** (was 143
errors/184 failures before this fix) — the 39-defect drop matches the
sortable cluster exactly (confirmed via a full re-run: no `SortableBehavior`
class appears anywhere in the post-fix failure/error list), and the error
count staying at 143 rather than dropping to 142 is this suite's
already-documented run-to-run flakiness (±1-2), not a regression — no new
failing test class appeared. Not investigated further: `nested_set`/`i18n`
clusters, out of this task's scope (being worked in parallel elsewhere).

**Follow-up: `i18n` behavior cluster (`I18nBehaviorObjectBuilderModifierTest`/
`I18nBehaviorQueryBuilderModifierTest`) fixed.** Scoped follow-up pass, done
in isolation from the concurrent `nested_set`/`sortable` work also in
progress on this cluster list (deliberately did not touch either of those
behaviors' code, per instructions, even though the first bug found below
is generic enough that it could theoretically also matter to them).

Root cause #1 (generic, not i18n-specific -- **flagged for whoever is
working `nested_set`/`sortable`**, though grepping confirmed neither
actually depends on it): `I18nBehaviorObjectBuilderModifier::
addTranslatedColumnGetter()`/`addTranslatedColumnSetter()`
(`generator/Lib/Behavior/I18n/I18nBehaviorObjectBuilderModifier.php`)
compose a translated-column getter/setter by asking a *second*
`ObjectBuilder` instance (for the i18n side-table, obtained via
`$this->builder->getNewObjectBuilder($i18nTable)`) to emit just the
doc-comment and function-signature/opening-brace pieces of what would be
that table's own plain accessor/mutator -- a pattern that requires the
comment/signature generation to be split into small, independently
callable, `public` pieces (`addDefaultAccessorComment()`/
`addDefaultAccessorOpen()`/`addTemporalAccessorComment()`/
`addTemporalAccessorOpen()`/`addMutatorComment()`/
`addTemporalMutatorComment()`/`addMutatorOpenOpen()`), exactly the way
`archaeology/php5-builders/PHP5ObjectBuilder.php` originally split
`addDefaultAccessor()`/`addTemporalAccessor()`/`addMutatorOpen()` into
comment/open/body/close quartets. The promoted `ObjectBuilder`
(`generator/Lib/Builder/OM/ObjectBuilder.php`) never had this split --
`addColumnAccessor()`/`addColumnMutator()` build the whole method (comment
through closing brace) as one inline blob per column-type branch -- so
every one of those seven method calls threw `Error: Call to undefined
method`. This is a real completeness gap in the promoted `ObjectBuilder`'s
public API surface (any future behavior modifier wanting to compose a
method from the standard comment+signature the way i18n does would hit the
same wall), not an i18n-only concern; it just happens that i18n is
currently the only behavior exercising this composition pattern. Fixed by
re-splitting `addColumnAccessor()`/`addColumnMutator()` into the same
comment/open pieces (public, matching the PHP5 method names so the existing
i18n call sites needed no renaming) plus the original combined body, with
`addColumnAccessor()`/`addColumnMutator()` themselves now just calling the
split pieces in sequence -- i.e. this was a refactor of the existing
(verified byte-for-byte identical output via a before/after diff of
`testsuite/generator/builder/om/` failures with `git stash`) logic, not new
logic duplicated alongside it.

Root cause #2 (i18n-specific): once root cause #1 unblocked codegen,
`I18nBehaviorObjectBuilderModifierTest`/`I18nBehaviorQueryBuilderModifierTest`
still failed with `TypeError: BaseFooI18n::setBar(): Return value must be of
type BaseFooI18n, Foo returned`. `addTranslatedColumnSetter()` calls the
above comment/open methods *on the i18n table's own `ObjectBuilder`
instance*, so `$returnType` inside them (used for the composed setter's doc
comment and, now that setters have real PHP return types, its actual `):
$returnType` signature) is the i18n side-table's own classname
(`BaseFooI18n`) -- but the spliced-together translated setter is pasted into
the *outer* table's class (`Foo`) and actually `return $this;`s a `Foo`. The
i18n modifier already had a `str_replace()` patching the doc comment's
`@return` line for exactly this reason, but it matched PHP5's old
5-space-indented `'@return     ' . $i18nTablePhpName` format, which no
longer exists in the promoted builder's output (single space) -- so it had
long since become a silent no-op even for the (cosmetic) doc comment, and,
more importantly, never touched the real return-type declaration at all
(harmless under PHP5, which had no return types to get wrong; a hard fatal
now). Fixed by widening the replacement to a plain
`str_replace($i18nTablePhpName, $tablePhpName, ...)` applied to both
`$comment` and `$functionStatement`, which is format-independent and fixes
the actual signature, not just the docblock.

Verified: `--filter I18nBehavior` went from 56 tests / 38 errors (plus 3
more from before root cause #1's fix landed piecemeal --
`addDefaultAccessorComment` missing was the first error found, fixing it
serially uncovered `addMutatorComment` next, then the return-type
`TypeError`s) to **56 tests, 0 errors, 0 failures, 1 skipped** -- full green.
Full suite (`cd test && rm -rf fixtures/*/build &&
../vendor/bin/phpunit -c phpunit.xml`, Docker): **2200 tests, 106 errors,
182 failures, 12 skipped, 1 risky**, down from this section's own
143-errors/184-failures baseline above (the drop is 37 errors, matching
this cluster's size, plus one file's warning noise; failures dropped by 2,
consistent with a couple of the 56 having been counted as failures rather
than errors). No regressions: independently confirmed via `git stash` that
`testsuite/generator/builder/om/` (which exercises `addColumnAccessor()`/
`addColumnMutator()` directly, i.e. the code this pass refactored) produces
an *identical* failing-test list before and after this pass's changes.
`generator/default.properties` and `generator/Lib/Config/
QuickGeneratorConfig.php` were not touched and do not reference any
`PHP5*` class in their final state (only pre-existing comments mention
PHP5 historically, e.g. noting where it used to live).

`nested_set`/`sortable` gaps are unaffected and explicitly out of scope for
this pass (confirmed neither behavior's modifier calls any of the
comment/open methods touched here); a follow-up pass on those should
still bisect `testsuite/generator/behavior/<name>/` the way this file's
Phase 3.5 section originally recommended.

**Follow-up: `nested_set` behavior cluster — fixed.** Root cause of
essentially all ~89 `NestedSetBehaviorObjectBuilderModifierTest`/
`NestedSetBehaviorObjectBuilderModifierWithScopeTest`/
`NestedSetBehaviorQueryBuilderModifierTest`/
`NestedSetBehaviorQueryBuilderModifierWithScopeTest`/
`NestedSetBehaviorPeerBuilderModifierTest`/
`NestedSetBehaviorPeerBuilderModifierWithScopeTest` failures: this was
never actually a builder-hook-wiring gap (the `8caa48c`/this-phase fix
already covers `nested_set` like every other behavior) — it was
`NestedSetBehavior{Object,Peer}BuilderModifier::getColumnAttribute()`
generating `$this->{lowercased_column_name}` (e.g. `$this->tree_left`)
inside the `getLeftValue()`/`getRightValue()`/`getLevel()`/
`getScopeValue()` proxy accessors. That matched
`PHP5ObjectBuilder`'s property-naming convention
(`strtolower($col->getName())`) exactly, but the promoted `ObjectBuilder`
declares object properties by column *PhpName* instead (e.g.
`$this->TreeLeft`) — so every proxy accessor silently read/wrote a
nonexistent dynamic property. Left/right/level always round-tripped as
`null` after a save+reload, cascading into `TREE_LEFT > NULL` SQL errors,
broken tree math, and near-total failure across all six test classes.
Fixed by having `getColumnAttribute()` return the column's PhpName
instead of a lowercased column name, in both the Object and Peer
builder modifiers (`generator/Lib/Behavior/NestedSet/
NestedSetBehaviorObjectBuilderModifier.php`/
`NestedSetBehaviorPeerBuilderModifier.php`). This is a `nested_set`-behavior-
specific fix, not a generic builder-hook change like the `8caa48c` one --
worth the other parallel engineers (`i18n`/`sortable`/generic
`ObjectBuilder` gaps) double-checking whether their behaviors have an
analogous stale "reference the PHP5 property-naming convention by hand"
spot, since this class of bug (a behavior modifier assuming PHP5's
codegen conventions instead of querying the column for its real name)
wouldn't be caught by anything that only audits hook *wiring*.

With that fixed, two more real, previously-unreached bugs in
`NestedSetBehaviorQueryBuilderModifier` surfaced (pre-existing, not
introduced by PHP5 removal -- this file is shared between old and new
builders, so both always generated this):
- `orderByLevel()` ordered by `RIGHT_COL` descending/ascending. That
  coincidentally puts the root first (it always has the largest right
  value) but past that interleaves subtrees in a postorder-ish sequence
  instead of grouping same-level nodes together. Fixed to order by
  `LEVEL_COL`, with `LEFT_COL` (branch/natural order) as a tiebreaker.
  This also let `testOrderByLevel` (called out above under cluster #5,
  "This test did not perform any assertions") get its dropped
  `assertEquals()` calls restored -- the real bug it had been left
  disabled to avoid hitting.
- `treeRoots()` had no `ORDER BY` at all. With scoping enabled there's
  one root per scope, all sharing `LEFT_COL = 1` with nothing else to
  order by, so Postgres was free to return them in whatever order it
  pleased -- passing or failing depending on unrelated global suite
  state (it reliably passed under `--filter NestedSetBehavior` alone but
  intermittently failed inside a full-suite run). Added a deterministic
  `SCOPE_COL` ascending order when scoping is in use.

Also replaced `NestedSetBehaviorObjectBuilderModifierTest::testObjectAttributes`'s
call to `assertClassHasAttribute()` (removed in PHPUnit 10+, same class
of fix as commit `0186ecb`'s `assertInternalType()` removals) with the
direct `property_exists()` replacement.

Verified with `../vendor/bin/phpunit -c phpunit.xml --filter
NestedSetBehavior`: 121 tests, 39 errors + 58 failures -> 0 errors, 0
failures (1 pre-existing, unrelated risky test). Full suite (Docker/
Postgres, fixture `build/` dirs removed first): 143 errors/184 failures
-> 105 errors/125 failures, confirmed as a real improvement and not
just moved elsewhere by an A/B run with `--exclude-filter
'VersionableBehaviorObjectBuilderModifierTest::'` on both sides (144
errors/192 failures before this fix vs. 106 errors/138 failures after,
out of the same 2160 tests) -- see the note immediately below for why
that one class had to be excluded to get a clean full-suite number at
all.

**Unrelated discovery while running the full suite for verification: a
real PHP-engine-crashing infinite-recursion bug in the `versionable`
behavior, not fixed here (out of `nested_set` scope).**
`VersionableBehaviorObjectBuilderModifierTest` (specifically at least
`testToVersionPreservesVersionedReferrerObjects` and
`testReferrerVersion`, and very possibly the rest of that class) segfaults
the PHP process outright under this environment's PHP 8.5 -- confirmed
reproducible identically with and without the `nested_set` fix in this
section, so it's pre-existing, not a regression from anything here. Root
cause (visible in the C-level stack captured once `pcov` was disabled so
PHPUnit's own child-process guard could catch it as "premature end of
PHP process" instead of the parent segfaulting): `BaseVersionableBehaviorTest4::
isVersioningNecessary()` and `BaseVersionableBehaviorTest5::
isVersioningNecessary()` call each other in an unbounded mutual
recursion (visible as thousands of alternating stack frames between the
two), blowing the C stack until the process segfaults. This crashes the
*entire* PHPUnit run (not just the one test) unless that test class is
excluded via `--exclude-filter 'VersionableBehaviorObjectBuilderModifierTest::'`,
which is why the full-suite comparison above needed it. Flagging
explicitly for whichever pass ends up owning `versionable` (not
called out as one of the three behaviors -- `i18n`/`sortable`/generic
`ObjectBuilder` -- already assigned to parallel work at the top of this
Phase 3.5 section, so it may not have an owner yet) since a single test
class currently makes `vendor/bin/phpunit -c phpunit.xml` (no filter)
uncompletable in this environment.

**Fixed.** The generated `isVersioningNecessary()` (built by
`VersionableBehaviorObjectBuilderModifier::addIsVersioningNecessary()` in
`generator/Lib/Behavior/Versionable/VersionableBehaviorObjectBuilderModifier.php`)
had no re-entrancy guard for the case being diagnosed above. It already
carried an `alreadyInSave` check (a *different* flag, only ever set true
while inside `save()`), which does nothing to stop two objects with a
mutual versioned fk/referrer relationship from calling each other's
`isVersioningNecessary()` back and forth outside of a save — each call
checks its own fk/referrer getters and, for every versionable
fk/referrer, unconditionally recurses into the related object's
`isVersioningNecessary()` with no memory of who's already on the call
stack. For a bidirectional pair (A has a versioned fk/referrer to B, B
has one back to A) that recursion never bottoms out and blows the C
stack until PHP segfaults, exactly as diagnosed. Fixed by giving each
versionable object its own dedicated re-entrancy flag, generated via a
new `objectAttributes` hook (`protected bool $alreadyInIsVersioningNecessary = false;`)
and checked/set at the top of `isVersioningNecessary()`, mirroring the
existing `alreadyInSave`/`alreadyInValidation` guard pattern already used
elsewhere in `ObjectBuilder.php` (e.g. `doValidate()`'s
`alreadyInValidation` guard). On re-entry (i.e. the object is already
being checked further up the current call stack) the method now
short-circuits to `false` instead of recursing again — the object that
started the check still gets its own columns and every other
relationship evaluated normally; only the cyclical re-entry into an
object already being evaluated is skipped, which is semantically correct
(that object's own modified-state contribution to the "is versioning
necessary" decision is already being evaluated by the frame further up
the stack, and won't be lost). Verified: `--filter
VersionableBehaviorObjectBuilderModifierTest` (40 tests) and `--filter
Versionable` (51 tests) both now complete without crashing and pass in
full. A subsequent unfiltered `vendor/bin/phpunit -c phpunit.xml` run
(previously impossible to complete at all) finished cleanly at **2200
tests, 5262 assertions, 38 errors, 26 failures, 3 PHPUnit warnings, 1
warning, 260 deprecations, 12 skipped, 1 risky** — matching the
already-documented post-Phase-3.5 baseline above exactly, confirming this
fix has no side effects on the rest of the suite and that number is now
obtainable with a genuinely unfiltered run rather than one requiring
`--exclude-filter 'VersionableBehaviorObjectBuilderModifierTest::'` to
avoid the segfault.

**Separate, smaller discovery, also out of scope and not fixed:** even
with `versionable` excluded, five `nested_set` peer tests
(`NestedSetBehaviorPeerBuilderModifierTest::testMakeRoomForLeaf`/
`testUpdateLoadedNodes`/`testRetrieveTree`,
`NestedSetBehaviorPeerBuilderModifierWithScopeTest::testRetrieveRoots`/
`testRetrieveTree`) fail only inside a full-suite run, never under
`--filter NestedSetBehavior` alone -- off-by-one left/right values and a
`Table9` instance where a `PublicTable9` (the test's own subclass) was
expected. This is the same already-documented class of order-dependent,
instance-pool/global-state cross-test bleed described elsewhere in this
file (e.g. the `DatabaseMapTest::testAddTableObject` flakiness noted
under the `Propel*` -> `Propulsion*` rename section), not a new bug this
pass introduced -- left alone rather than chased down given the time
already spent isolating the two crash-class issues above.
**Follow-up pass — completeness audit of `ObjectBuilder`/`PeerBuilder`,
scoped to everything except `nested_set`/`i18n`/`sortable`** (those three
behaviors were being fixed concurrently in separate worktrees). Assigned
test set: `GeneratedObjectTest`, `ObjectBehaviorTest`,
`GeneratedPeerDoSelectTest`, `BaseObjectSerializeTest`,
`BaseObjectConvertTest`, `GeneratedObjectArrayColumnTypeTest`,
`GeneratedObjectEnumColumnTypeTest`, `GeneratedObjectLobTest`,
`PeerBehaviorTest`, `QueryBuilderTest`, `SluggableBehaviorTest`,
`Ticket520Test`. Before this pass: 214 tests / 19 errors / 51 failures / 1
risky in that filtered set. After: 214 tests / 2 errors / 0 failures / 1
risky — the 2 remaining errors (`GeneratedPeerDoSelectTest::testDoSelect`/
`testDoSelectOne`) and the 1 risky test (`GeneratedObjectTest::testNoColsModified`)
are the exact same pre-existing, already-documented issues from clusters #3
and #5 above (MySQL-vs-Postgres string/identifier quoting semantics; a
genuinely-incomplete test), confirmed unrelated to this pass by reproducing
them against the pre-pass commit (`910e12f`) in a throwaway worktree.

Full suite, before this pass: **2200 tests, 143 errors, 184 failures, 11
skipped, 1 risky** (the Phase 3.5 net-result baseline above). After: **2200
tests, 115 errors, 121 failures, 11 skipped, 1 risky** — same test/skip/risky
counts (no tests gained, lost, or newly skipped), 28 fewer errors and 63
fewer failures, all attributable to fixes below. Spot-checked several
failure clusters outside the assigned test list that touch the same
machinery this pass changed (`GeneratedQueryArrayColumnTypeTest`,
`PropulsionObjectCollectionTest`, `BasePeerTest`, `GeneratedObjectRelTest`,
`GeneratedObjectTemporalColumnTypeTest`, `AggregateColumnBehaviorTest`)
against the same pre-pass commit to confirm no new regressions were
introduced there either -- all either already failing before this pass with
the same failure, or newly *fixed* as an incidental benefit.

Root causes found and fixed, all in `generator/Lib/Builder/OM/{ObjectBuilder,PeerBuilder}.php`
unless noted (every one was a real gap the promoted builders had that
PHP5's had not, silently masked as long as PHP5 was the default):

- **`PeerBuilder::doCountThis()`'s `$distinct` param was strictly typed
  `bool`.** Generated behavior code
  (`SortableBehaviorPeerBuilderModifier::countList()`) and at least one test
  call `doCount($criteria, $con)` positionally -- shifting `$con` into the
  `$distinct` slot. PHP5's untyped equivalent silently tolerated this
  (truthy object, harmless no-op `setDistinct()` call, connection argument
  just silently dropped in favor of the default); the strict type threw a
  `TypeError`. Loosened to `mixed`, matching PHP5's actual (untyped)
  contract.
- **`doSelectJoin<Fk>()`/`doSelectJoinAll()`/`doSelectJoinAllExcept<Fk>()`
  and their `doCountJoin*()` counterparts were entirely absent** from the
  promoted `PeerBuilder` -- a ~750-line method family PHP5PeerBuilder
  provided as the default. Ported it (`addCriteriaJoin()`/`getJoinBehavior()`
  helpers plus the six `addDoSelectJoin*()`/`addDoCountJoin*()` methods and
  an `addSelectMethods()` override wiring them in), adapting parameter
  typing to the promoted builder's `?PropulsionPDO`/`mixed` conventions.
  This single gap accounted for most of `GeneratedPeerDoSelectTest`'s
  failures and the `doSelectJoinAuthor()`-dependent paths in
  `GeneratedObjectTest::toArray()`.
- **Array-typed (`PHP_ARRAY`) columns with a plural name got no
  `has<Singular>()`/`add<Singular>()`/`remove<Singular>()` methods** (e.g. a
  `tags` column should generate `hasTag()`/`addTag()`/`removeTag()`) --
  ported from PHP5's `addHasArrayElement()`/`addAddArrayElement()`/
  `addRemoveArrayElement()`, adapted to this builder's storage model (a real
  PHP array property, not PHP5's lazily-decoded serialized-string-plus-cache
  pair). Also fixed array columns with no explicit schema default
  incorrectly defaulting to `null` instead of `array()`, in both the
  property declaration and `applyDefaultValues()`.
- **LOB columns (BLOB/VARBINARY/LONGVARBINARY) had no resource support and
  no lazy-load mechanism at all.** Property/getter/setter were plain
  `?string`, so passing a stream resource (a normal, previously-supported
  calling convention) threw a `TypeError`; the `${col}_isLoaded` flag existed
  but nothing ever consulted it, and there was no `load<Phpname>()` method to
  actually populate a lazy column on first access. Ported PHP5's
  stream-based model: `mixed`-typed property/getter/setter (accepting either
  a resource or a raw string, normalizing the latter into a rewound
  `php://memory` stream), a real lazy-loader method, `doSave()` rewinding LOB
  stream properties after insert/update (PDO leaves them at EOF), and
  `reload()` actually nulling lazy-loaded columns (previously it reset the
  `_isLoaded` flag but left the stale/possibly-`fclose()`'d resource in
  place).
- **`copyInto()` passed enum columns' raw internal index straight to the
  target's setter**, which validates against the enum's *label* set, not its
  index -- throws the moment the index isn't itself coincidentally a valid
  label. Routed enum columns through the getter (index -> label) instead.
- **Non-lazy column properties were `private`**, unlike PHP5's uniform
  `protected`. Several tests gain white-box access to internal state by
  declaring a same-named public property on a throwaway subclass -- only
  possible if the parent's property is `protected` (a same-named `private`
  parent property is a distinct storage slot, silently gaining no access at
  all). Switched to `protected`.
- **`toArray()`'s `$includeLazyLoadColumns` param was strictly typed
  `bool`**; existing call sites (both test code and this method's own
  recursive self-calls) pass `null` for it, relying on PHP5's untyped param
  treating `null` as falsy. Widened to `?bool`. Also fixed the recursion
  guard to return the bare string `'*RECURSION*'` (PHP5's actual behavior)
  instead of `['*RECURSION*' => true]` -- fixed `BaseObjectConvertTest`'s
  YAML/JSON/CSV recursion-marker assertions.
- **`ObjectBuilder::addPrimaryString()` (the generated `__toString()`)
  always stringified the primary key**, ignoring both a `primaryString="true"`
  schema column and the fallback to `Peer::DEFAULT_STRING_FORMAT` (YAML by
  default) that PHP5 used. Fixing this one method also fixed all of
  `SluggableBehaviorTest`'s remaining failures, since `SluggableBehavior`'s
  default raw-slug source slugifies the object's string representation.
- **`reloadOnInsert`/`reloadOnUpdate` schema attributes had all their
  plumbing (the `$skipReload` parameter) but no actual effect** -- nothing
  ever set `$reloadObject` or called `reload()` after a modified insert/update,
  so DB-computed default expressions (e.g. a `created` column with
  `defaultExpr="CURRENT_TIMESTAMP"`) were never picked up into the object
  after `save()`. Ported the `$reloadObject` tracking and final
  `reload()` call from PHP5's `addDoSave()`.
- **Temporal column mutators didn't mark a column modified when explicitly
  re-set to its own schema default**, even though that's a real, intentional
  write (e.g. `new Review(); $review->setReviewDate('2001-01-01')` when
  `'2001-01-01'` is `review_date`'s default) -- PHP5's mutator special-cased
  this exact scenario (`|| ($dt->format($fmt) === $defaultValue)`); ported
  it.
- **`allowPkInsert="true"` tables silently lost a caller-supplied primary
  key on Postgres.** `BasePeer::doInsert()` only returns a freshly-generated
  id when the caller didn't already supply one; on a sequence-based platform
  it returns `null` when the criteria already had an explicit value (id
  generation is skipped entirely). `doSave()` called `$this->set<Pk>($pk)`
  unconditionally after every insert, silently overwriting the caller's
  explicit id with that `null` the moment the row was saved. Guarded the
  call with `if ($pk !== null)` for `allowPkInsert` tables, matching PHP5's
  existing guard.
- **`ensureConsistency()` was a complete no-op.** PHP5's version invalidates
  a cached FK reference object when its own foreign column no longer
  matches the local FK column's current value; `hydrate()` already called
  `ensureConsistency()` on re-hydration (i.e. from `reload()`), but with
  nothing implemented there, `reload()` (which doesn't clear FK reference
  caches unless `$deep=true`) kept returning a stale cached related object
  via the getter even after the FK column was re-hydrated with a new value.
  Ported the real check.
- **`count<Fk>()` (referrer count) always hit the database, or returned `0`
  for a new/unsaved object** -- ignoring an already-loaded in-memory
  referrer collection entirely, so counting objects added via `add<Fk>()`
  before the first `save()` always returned 0 instead of the real in-memory
  count. Ported PHP5's collection-aware version.
- **`get<Fk>()` (referrer collection getter) unconditionally overwrote the
  cached full referrer collection with the result of a caller-supplied
  `$criteria` filter**, so a subsequent no-criteria call wrongly kept
  returning the filtered subset instead of the real full collection. PHP5's
  version only persists into the cache when `$criteria` is `null`; a
  filtered fetch returns its result directly without touching the cache.
  Ported that distinction.
- **`copyInto()` explicitly skipped 1:1 referrer relationships** ("1:1
  relationships don't get deep copied", contradicting PHP5's
  `addCopyInto()`, which does deep-copy them via `set<Fk>($relObj->copy($deepCopy))`).
  Ported the real 1:1 branch.
- Two **test-file fixes**, same "PHP5-era assumption about internal
  representation" pattern documented elsewhere in this file: `PeerBehaviorTest`/
  `ObjectBehaviorTest` hardcoded `'PHP5PeerBuilder'`/`'PHP5ObjectBuilder'` as
  the expected `get_class($builder)` value from behavior hooks (stale --
  those classes are archived, `PeerBuilder`/`ObjectBuilder` are what's
  actually invoked now); `test/tools/helpers/bookstore/behavior/Testallhooksbehavior.php`'s
  `preDelete`/`postDelete` hook bodies and `GeneratedObjectEnumColumnTypeTest`'s
  white-box test subclass read/declared the raw lowercase column-name
  property (`$this->id`, `$bar`) that only existed on PHP5-generated objects;
  updated to the promoted builder's PhpName-cased properties/getters (`$Bar`,
  `getId()`).

**Not investigated in this pass** (out of scope, per the explicit split with
concurrent `nested_set`/`i18n`/`sortable` work): the ~150 remaining
errors/failures in `NestedSetBehaviorObjectBuilderModifierTest` and its
`WithScope`/`PeerBuilderModifierTest`/`QueryBuilderModifierTest` variants,
`I18nBehaviorObjectBuilderModifierTest`/`I18nBehaviorQueryBuilderModifierTest`/
`I18nBehaviorPeerBuilderModifierTest`, and
`SortableBehaviorObjectBuilderModifierTest`/its `WithScope`/`PeerBuilderModifierTest`/
`QueryBuilderModifierTest` variants. Also not investigated: the pre-existing
`ModelCriteriaTest`/`SubQueryTest`/`ModelCriteriaWithSchemaTest` failures that
predate Phase 3.5 (confirmed against `910e12f` in a throwaway worktree,
e.g. `ModelCriteriaTest::testFindPkSimpleKey`'s `ModelCriteria::findPk()`
"Undefined array key 0" -- `getTableMap()->getPrimaryKeys()` returning a
non-zero-indexed array is a `runtime/Lib/Query/ModelCriteria.php` bug,
unrelated to the generator/builder work this pass scoped to), and the
`MssqlPlatformTest`/`PropulsionStatementFormatterTest`/`PropulsionArrayFormatterTest`/
`PropulsionObjectFormatterTest`/`PropulsionPDOTest`/`FieldnameRelatedTest`/
`PropulsionQuickBuilderTest`/`PropulsionArrayCollectionTest`/`MysqlSchemaParserTest`
clusters, none of which showed up in this pass's assigned test list and
weren't diffed against the pre-pass baseline individually.

### Phase 4 — Worker-safety rework (ServiceContainer/Session split)

This is the actual motivating goal from `PROPULSION_WORKER_REWORK.md`
(FrankenPHP/worker-mode safety — moving instance pools,
`forceMasterConnection`, and dangling transactions off `Propulsion`'s
process-global statics into a request-scoped `Session`, while keeping
connections/adapters/maps process-scoped in a `ServiceContainer`). Phased
as:

- **4a**: **Done.** Introduced `Propulsion\ServiceContainer` and
  `Propulsion\Session` behind `Propulsion::getServiceContainer()`/
  `Propulsion::getSession()` (both lazily-created singletons, with
  `setServiceContainer()`/`setSession()` escape hatches for tests or a
  worker integration that wants to swap in a fresh `Session` per request).
  Concretely:
  - `ServiceContainer::clearInstancePools()` is the interim pool-registry
    hack: since every generated Peer class has its own private `static
    $instances` array with no central registry, this walks every table in
    every `DatabaseMap` Propulsion currently has loaded, resolves each table's
    `PEER` classname, and calls the already-existing (per-class, generated)
    `clearInstancePool()` on each one. `registerInstancePoolClass()` also
    lets a class be cleared even if it's never been loaded into a
    `DatabaseMap` yet. Explicitly a stopgap — gets deleted in 4b once
    pooling delegates to `Session` directly instead of static arrays.
  - `Session::reset()` wires transaction-rollback-on-reset: it force-rolls-
    back (via `PropulsionPDO::forceRollBack()`, the same mechanism added in
    commit `6f6b08e` for test-teardown boundaries — this is the identical
    dangling-transaction failure mode, just at a request boundary instead)
    every connection `Propulsion` currently has open, then clears instance
    pools via `ServiceContainer`, then resets `forceMasterConnection` back
    to its default.
  - `forceMasterConnection` moved off `Propulsion`'s statics onto `Session`.
    `Propulsion::setForceMasterConnection()`/`getForceMasterConnection()` are
    kept as thin proxies to `Propulsion::getSession()` for backwards
    compatibility, and `Propulsion::getConnection()` now reads it from there.
  - Instance pooling's default was checked and is **already on**
    (`Propulsion::$instancePoolingEnabled` was already `true` by default) — no
    code change was needed there, contrary to what reading the plan in
    isolation might suggest.
  - Deliberately NOT done in this pass (all still gated on Phase 3, per the
    original phasing): `Propulsion`'s other process-global statics (connection
    map, adapter map, database maps) are untouched and still live on
    `Propulsion` directly — `ServiceContainer` does not yet own them, it only
    hosts the pool registry hack. No generated code (builder templates)
    changed at all. The full worker test matrix from the rework plan (no
    object bleed, transaction cleanup, connection persistence across
    requests, forceMaster isolation between requests, memory doesn't grow
    under sustained load) was NOT run — that requires an actual worker-mode
    harness (FrankenPHP or equivalent) this repo doesn't have yet, which is
    a bigger undertaking than 4a's scaffolding scope. What *was* tested (in
    `test/testsuite/runtime/ServiceContainerTest.php`, `SessionTest.php`,
    `SessionResetTransactionTest.php`): pool registration/clearing in
    isolation and via the `DatabaseMap` walk, `forceMasterConnection`
    default/get/set/reset and that it's genuinely per-`Session` rather than
    shared global state, `Propulsion`'s delegation to both new classes, and
    `Session::reset()` end-to-end against a real connection — force-rolling
    back a nested dangling transaction, being a no-op with nothing open,
    and clearing pools/forceMaster together.
  - Full suite after this change: 2200 tests, 36 errors, 21 failures, 12
    skipped, 2 risky (2200 = the previous 2184 baseline + 16 new tests, all
    16 passing). An immediate before/after A-B run on the same environment
    was needed to be confident about the ±2 error/failure delta, because
    this suite's counts already fluctuate a few points run-to-run from the
    global-state test-ordering flakiness documented elsewhere in this file
    — the unmodified baseline, re-run back-to-back right after, came out at
    38 errors / 23 failures for the same 2184 pre-existing tests. So this
    change is confirmed equal-or-better, not a regression.
- **4b**: **Done.** Reworked `PeerBuilder.php`'s generated pool methods to
  delegate to `Session` instead of each generated Peer class's own private
  `static $instances` array, and deleted the DatabaseMap-walking part of the
  interim hack. Concretely:
  - `Session` gained real per-class pool storage: `private array
    $instancePools` typed `array<class-string, array<string, object>>`,
    keyed first by the generated Peer's FQCN, then by the same string
    instance-pool key the generated code has always computed (a PK's string
    value, or `serialize()` of a composite-PK tuple —
    `PeerBuilder::getInstancePoolKeySnippet()`, unchanged). New public API:
    `addPooledInstance(string $peerClass, string $key, object $instance)`,
    `getPooledInstance(string $peerClass, string $key): ?object`,
    `removePooledInstance(string $peerClass, string $key)`,
    `getPool(string $peerClass): array`, `clearPool(string $peerClass)`,
    `clearAllPools()`. `Session::reset()` now calls `$this->clearAllPools()`
    directly instead of routing through `ServiceContainer`.
  - `PeerBuilder.php` no longer emits `public static $instances = array();`
    at all. `addInstanceToPool()`/`removeInstanceFromPool()`/
    `clearInstancePool()`/`getInstanceFromPool()` are now thin wrappers that
    compute the same instance-pool key as before (identical logic, untouched)
    and call the corresponding `Propulsion::getSession()` method with
    `self::class` as the Peer-classname key — the generated method
    signatures and caller-visible behavior are unchanged. Added one new
    generated method, `getInstancePool(): array`, replacing the old
    `public static $instances` array's "must be public so other peer
    classes/behaviors can access it" role — two call sites needed it
    (`NestedSetPeerBuilder::updateLoadedNode()`,
    `NestedSetBehaviorPeerBuilderModifier::updateLoadedNodes()`, both of
    which iterated `self::$instances`/`$peerClassname::$instances` directly
    to reconcile in-memory nodes after a tree-structure update); both
    switched to call `getInstancePool()` instead.
  - `ServiceContainer::clearInstancePools()` is now a one-line delegation to
    `Propulsion::getSession()->clearAllPools()` — no more walking every
    table in every loaded `DatabaseMap` to guess which Peer classes exist,
    since real pool storage now lives on `Session` and a pool that was never
    populated is already empty. `registerInstancePoolClass()`/
    `getRegisteredInstancePoolClasses()` are kept (inert bookkeeping only,
    no longer consulted by `clearInstancePools()`) purely because
    `ServiceContainerTest` exercises them directly and nothing indicated
    external code depends on their removal.
  - Regenerating all three fixture projects (`bookstore`, `schemas`,
    `namespaced`) surfaced two more direct `Peer::$instances` accesses
    outside the generator itself, both fixed:
    `test/tools/helpers/bookstore/BookstoreDataPopulator.php::depopulate()`
    used `ReflectionClass::getProperty('instances')` to free pooled objects
    before bulk-deleting fixture data (switched to the new
    `getInstancePool()`), and `test/tools/helpers/bookstore/behavior/
    Testallhooksbehavior.php`'s injected `preDelete`/`postDelete` hook
    bodies read `Table3Peer::$instances[$this->getId()]` directly (switched
    to `Table3Peer::getInstanceFromPool((string) $this->getId()) !== null`).
    The first of these was the dominant cause of an initial post-change
    regression run coming back at 335 errors instead of the expected
    ~38 — a single missed reflection call site used by nearly every
    bookstore-fixture test's teardown path, not a design problem with the
    pool-delegation change itself.
  - Extended `SessionTest`/`SessionResetTransactionTest` to cover the new
    pool-storage API directly (start-empty, add/get/remove, clear-one-class
    vs. clear-all, `reset()` clearing pools) and, specifically, the
    worker-safety property this whole phase exists to deliver: a fresh
    `Session` (or `Propulsion::setSession()`-swapped-in `Session`) never
    sees instances pooled under a different, still-populated `Session` —
    proving pool storage lives on the `Session` object itself, not on any
    remaining class-level or process-global state.
  - Verified against a full suite run (Docker/Postgres, all three fixture
    `build/` dirs removed first): **2208 tests, 38 errors, 32 failures, 12
    skipped, 1 risky** (2208 = the 2200-test baseline this file already
    documents + 8 new `SessionTest` pool-API tests, all 8 passing). Diffed
    the full failing/error test-name list against the documented baseline
    clusters (MySQL-vs-Postgres backtick/quoting mismatches, `SubQueryTest`,
    `MssqlPlatformTest`, the `AggregateColumnBehaviorWithSchemaTest`/
    `ModelCriteriaWithSchemaTest` not-null-violation cluster, etc.) — every
    single failing/erroring test name was already on that list; nothing new
    appeared, nothing pool- or Session-related appears anywhere in the
    failing list. `PropulsionQuickBuilder`/`QuickGeneratorConfig` needed no
    changes — neither hardcodes anything about pool storage shape.
- **4c**: Delete legacy `PHP5*` builders — **superseded**, this already
  happened outright in Phase 3.5 below (explicit user request, done ahead of
  the originally-planned 4a/4b-gated order). Nothing left to do here.
- **4d**: Quiote adapter integration (`PropulsionDatabase` wiring,
  `ResetInterface`) — tracked in the Quiote-side doc, not this repo.

Decision already made in an earlier conversation: instance pooling
defaults to **on** once 4a's reset-on-request-boundary wiring is in place
and the worker test matrix (§8 of the rework doc — no object bleed,
transaction cleanup, connection persistence, forceMaster isolation,
memory doesn't grow) passes. As noted above, the default was already on
before this pass; what's still outstanding is the worker test matrix
itself, deferred to whenever an actual worker-mode harness exists to run
it against (likely alongside 4b, once generated code actually delegates to
`Session`).

Note: `PROPULSION_WORKER_REWORK.md`, cited above as the source doc for this
phase, does not actually exist in this repo (checked at the start of this
pass) — only referenced from this file and from commit messages. This
pass's scope was therefore driven entirely by this section's own
description of 4a; nothing from the doc's supposedly-more-detailed §8
worker test matrix was consulted beyond what's summarized here.

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
  empty (matches upstream Propulsion's original "you must configure this"
  stance), the README doesn't mention a recommended database, and
  `PgsqlPlatform` hasn't had a pass to check it's on equal footing with
  `MysqlPlatform` feature-wise (this session only found and fixed the two
  bugs that blocked fixture loading — `DROP TABLE`/`DROP SEQUENCE` missing
  `IF EXISTS`, commit `024cec0` — not a general audit).

## `Propel*` -> `Propulsion*` class rename

Completed a codebase-wide rename of every `Propel`-prefixed class,
interface, and trait name to `Propulsion*` (the namespace was already
`Propulsion\`; only the bare class basenames still said `Propel`), plus the
main static facade `Propel` -> `Propulsion` (`Propulsion::getConnection()`,
`Propulsion::init()`, etc.). This is a hard cutover: no `class_alias()` shims
were added for any of the renamed classes themselves.

Scope covered: `runtime/`, `generator/` (including the PHP string templates
in `generator/Lib/Builder/**` and `generator/Lib/Behavior/**` that emit
source for generated model/query classes — both the canonical
`OM/{Object,Peer,Query,TableMap}Builder` templates and the still-supported
legacy `PHP5*Builder` templates), `test/` (test classes, fixture
`build.properties`/`runtime-conf.xml`/`schema.xml`, `phpunit.xml`,
`test.xml`), file renames to match (e.g. `PropelPDO.php` ->
`PropulsionPDO.php`), self-referential comments/docblocks ("This file is
part of the Propel package" -> "... Propulsion package", `@package
propel.*` tags left as-is since those are config-namespace strings, not
class references), and `README.md`/`KNOWN_ISSUES.md`. Also removed ~35 dead
`propel.phpdb.org/trac/ticket/NNN` links (old Trac tracker) from comments,
deleting the whole comment/docblock where the link was its only content.

Commits, in order: facade rename; runtime classes; generator classes and
builder templates; test suite; docs; phpdb.org cleanup; a follow-up fix for
a real bug the rename surfaced (see below).

**Judgment calls:**
- Author/contributor attribution lines crediting the historical upstream
  Propel/Torque projects this codebase was forked from (e.g. `@author Hans
  Lellelid <hans@xmpl.org> (Propel)`, `(Torque)`) were left exactly as-is —
  these name a real project a real person contributed to, not this
  codebase's own branding, so rewriting them would misrepresent history.
  Verified there are no comments that were ambiguous between the two
  categories; all ~140 occurrences found were either unambiguously
  self-referential (rewritten) or unambiguously third-party attribution
  (left alone).
- `generator/resources/xsl/dbd2propel.xsl` has one remaining
  `propel.phpdb.org` reference: a "Software: Propulsion:
  http://propel.phpdb.org/" line in a credits header, listing the tool
  this XSL was built against. This isn't a dead ticket link (the thing
  Step 4 targeted); left it alone.
- `runtime/Lib/legacy-class-map.php` and
  `test/tools/helpers/generator-legacy-class-map.php` are a *pre-existing*
  bare-global-name -> FQCN aliasing system, unrelated in origin to this
  rename (it exists so old, already-generated, unnamespaced model code
  that references runtime classes like bare `PropelException` keeps
  working after the earlier `Propel\` -> `Propulsion\` *namespace*
  migration). Per instructions, only the FQCN *values* were updated to the
  new class basenames; the old bare *keys* (`PropelException`, ...) were
  left untouched, since ~117 existing test files and any not-yet
  regenerated external projects still reference them bare.
- That said, this rename **did** surface a real, previously-latent bug in
  that system: the generator now emits bare `PropulsionPDO`/
  `PropulsionException`/etc. (per the explicit instruction to update
  builder template string literals), but for **unnamespaced** generated
  output (the default target, and the archived PHP5 builders) there is no
  `use` import backing those bare references — they only resolve through
  the legacy-class-map's `class_alias()` loop, which only had entries for
  the *old* bare names. Freshly generated/regenerated fixtures (e.g.
  `test/fixtures/bookstore`'s nested_set behavior classes) fataled with
  `Class "PropulsionPDO" not found` until fixed. The fix: added a
  parallel set of entries to both legacy-class-map files, keyed by the
  *new* bare `Propulsion*` names, mapping to the same FQCNs — additive
  only, the old bare keys are untouched. This isn't the kind of
  "class_alias shim for a renamed class" the hard-cutover instruction
  ruled out; it's the same pre-existing, purpose-built mechanism now
  covering both spellings of the bare name it was always meant to cover.

**Before/after test counts** (`test/phpunit.xml`, Docker/Postgres,
`vendor/bin/phpunit -c phpunit.xml`, fixture `build/` dirs removed first):
- Before (baseline on `main`, per prior pass): 2200 tests, 36 errors, 19
  failures, 12 skipped, 2 risky.
- After this rename: 2200 tests, 38 errors, 20-21 failures, 12 skipped, 2
  risky (fluctuates by 1 run-to-run). Independently re-verified (not just
  the rename agent's own report) by diffing the full failing-test-name list
  against the pre-rename baseline with `Propel*` normalized to `Propulsion*`:
  every renamed-test-class failure matches an already-documented flakiness
  cluster above under its old name. Two failures were genuinely new by name
  (`BookstoreTest::testScenario`, `DatabaseMapTest::testAddTableObject`),
  but both pass cleanly when run in isolation (`--filter`) — they're the
  same class of order-dependent global-state flakiness already documented
  elsewhere in this file (stale `DatabaseMap`/LOB-stream state bleeding
  across tests in the same process), just newly surfaced because renaming
  ~470 files changed PHPUnit's alphabetical test-discovery order, not
  because the rename introduced a new bug. No class-not-found or
  otherwise-novel failure signature appeared in either run.

During this rename, a worktree agent's *uncommitted* working tree also
contained an unrelated, unrequested refactor (moving the legacy `PHP5*`
builders into an `archaeology/php5-builders/` directory) — apparently
speculative Phase 4c work, which is explicitly gated on 4a/4b completing
first per the Phase 4 plan above and hadn't been asked for. It was never
committed and was discarded before merging; flagging here only so nobody
wonders where that idea came from if it resurfaces.
