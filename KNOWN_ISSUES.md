# Known issues and remaining work

Status as of the test-fixing pass on `main` (commits `f8675fc`..`0186ecb`). This
tracks two separate things: **remaining test failures** (from the PHPUnit
pass) and **modernization plan phases not yet done** (from the original
scoping conversation, before it detoured into fixing tests).

## Current test suite state

With Docker (full suite, confirmed by an actual combined run after all of the
below landed together on `main`): **2184 tests, 36 errors, 19 failures, 12
skipped, 2 risky**. Started from a suite that couldn't run a single test at
the start of this work (bootstrap was completely broken) and had 1137+
errors once it could run at all. See git log on `main` for the detailed fix
history — each commit message documents the specific root cause found and
fixed.

Getting from ~118 errors (clusters #1-#3 fixed) down to 36 required fixing
two more things not called out as their own numbered clusters: merging four
independent fix branches together transiently produced **285** errors, not
44 — `AggregateColumnBehaviorWithSchemaTest::tearDown()` unconditionally
called `$this->con->commit()` after `testComputeWithSchema()` hit a
pre-existing not-null violation that aborted the Postgres transaction;
committing an already-aborted transaction throws, which skipped
`parent::tearDown()` (`SchemasTestBase`), which is what restores Propel's
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
generated Peer class has the single-`$con` `doDeleteAll(PropelPDO $con =
null)` contract that `PHP5PeerBuilder` generates. `PHP84PeerBuilder`'s
peers, however, `extends BasePeer` directly whenever no behavior
`parentClass` is set, and its `doDeleteAll` override literally copied
`BasePeer::doDeleteAll(?string $tableName = null, ?PropelPDO $con = null,
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
`PHP5PeerBuilder`'s guard; the per-table peer's `doDeleteAll(?PropelPDO
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
   had a malformed regex (`$i#` instead of `$#i`); `PropelCollection::
   getIterator()` returned a detached `ArrayIterator` copy, so by-reference
   `foreach` mutation never persisted through `save()`. The remainder were
   genuine MySQL-backtick-vs-Postgres-unquoted expected-SQL literals (~89
   assertions across `ModelCriteriaTest`, `CriteriaMergeTest`, `SubQueryTest`,
   `ModelCriteriaSelectTest`, `BasePeerTest`, `PropelPDOTest`) and a few
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
   so it always fell back to `Propel::getConnection()` — opening a second
   real connection/transaction against the same database instead of
   reusing the caller's, which deadlocked later cleanup.

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
  `throw new PropelException('...not yet implemented for PHP84QueryBuilder')`,
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
  per class — fatal ("name is already in use") when `PropelQuickBuilder`
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
  `PropelPDO` positionally into a collection getter's `$criteria`
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

**Still needed for a fully-flipped Phase 3** (tracked here rather than a
separate phase, since it's the same rename/promotion effort, just
incomplete): audit and fix the promoted `ObjectBuilder`/`PeerBuilder` to
the same completeness bar demonstrated above for `QueryBuilder`/
`TableMapBuilder`, then flip `propel.builder.peer.class`/
`propel.builder.object.class`/etc.; separately, either finish porting
`NestedSetBuilder`/`NestedSetPeerBuilder`/`NodeBuilder`/`NodePeerBuilder`'s
~40 stubbed methods or formally deprecate/remove the "NestedSet treeMode"
and "node" (`MaterializedPath`) builder types instead (their PHP5
equivalents are the only complete implementations and the treeMode itself
is already marked `@deprecated` in its own generated docblock).

### Phase 4 — Worker-safety rework (ServiceContainer/Session split)

This is the actual motivating goal from `PROPULSION_WORKER_REWORK.md`
(FrankenPHP/worker-mode safety — moving instance pools,
`forceMasterConnection`, and dangling transactions off `Propel`'s
process-global statics into a request-scoped `Session`, while keeping
connections/adapters/maps process-scoped in a `ServiceContainer`). Phased
as:

- **4a**: **Done.** Introduced `Propulsion\ServiceContainer` and
  `Propulsion\Session` behind `Propel::getServiceContainer()`/
  `Propel::getSession()` (both lazily-created singletons, with
  `setServiceContainer()`/`setSession()` escape hatches for tests or a
  worker integration that wants to swap in a fresh `Session` per request).
  Concretely:
  - `ServiceContainer::clearInstancePools()` is the interim pool-registry
    hack: since every generated Peer class has its own private `static
    $instances` array with no central registry, this walks every table in
    every `DatabaseMap` Propel currently has loaded, resolves each table's
    `PEER` classname, and calls the already-existing (per-class, generated)
    `clearInstancePool()` on each one. `registerInstancePoolClass()` also
    lets a class be cleared even if it's never been loaded into a
    `DatabaseMap` yet. Explicitly a stopgap — gets deleted in 4b once
    pooling delegates to `Session` directly instead of static arrays.
  - `Session::reset()` wires transaction-rollback-on-reset: it force-rolls-
    back (via `PropelPDO::forceRollBack()`, the same mechanism added in
    commit `6f6b08e` for test-teardown boundaries — this is the identical
    dangling-transaction failure mode, just at a request boundary instead)
    every connection `Propel` currently has open, then clears instance
    pools via `ServiceContainer`, then resets `forceMasterConnection` back
    to its default.
  - `forceMasterConnection` moved off `Propel`'s statics onto `Session`.
    `Propel::setForceMasterConnection()`/`getForceMasterConnection()` are
    kept as thin proxies to `Propel::getSession()` for backwards
    compatibility, and `Propel::getConnection()` now reads it from there.
  - Instance pooling's default was checked and is **already on**
    (`Propel::$instancePoolingEnabled` was already `true` by default) — no
    code change was needed there, contrary to what reading the plan in
    isolation might suggest.
  - Deliberately NOT done in this pass (all still gated on Phase 3, per the
    original phasing): `Propel`'s other process-global statics (connection
    map, adapter map, database maps) are untouched and still live on
    `Propel` directly — `ServiceContainer` does not yet own them, it only
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
    shared global state, `Propel`'s delegation to both new classes, and
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
  empty (matches upstream Propel's original "you must configure this"
  stance), the README doesn't mention a recommended database, and
  `PgsqlPlatform` hasn't had a pass to check it's on equal footing with
  `MysqlPlatform` feature-wise (this session only found and fixed the two
  bugs that blocked fixture loading — `DROP TABLE`/`DROP SEQUENCE` missing
  `IF EXISTS`, commit `024cec0` — not a general audit).
