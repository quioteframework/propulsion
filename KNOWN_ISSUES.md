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
- `PROPULSION_TEST_DB=mysql|mariadb|mssql|oracle` — run the bookstore fixture
  against that platform's testcontainer instead of the default Postgres one.
  MSSQL needs `pdo_dblib`; Oracle needs `pdo_oci` built against an Instant
  Client (see `IntegrationDatabase`'s docblock for setup).
- `composer test:worker` — FrankenPHP worker-mode harness (`test/worker/`).
  Runs the same request-boundary-safety matrix against SQLite and Postgres
  (single worker thread each), plus a cross-thread matrix
  (`WORKER_THREAD_COUNT=4`). Empirically, `Propulsion::$session` and every
  connection it holds are one instance shared by *every* FrankenPHP worker
  thread in a process, not one per thread -- so request-boundary isolation
  (`Session::reset()`) is what makes concurrent requests safe, not any
  per-thread separation the runtime provides for you.
- `composer test:cleanup-containers` — remove any testcontainers leaked by a killed run.
- **Code coverage:** pass `-d pcov.directory=<repo root>` explicitly:

  ```
  cd test
  php -d pcov.directory=.. ../vendor/bin/phpunit -c phpunit.xml --coverage-text
  ```

  Without it pcov reports **0.00%** with no error. `pcov.directory` defaults to
  the working directory, the suite runs from `test/`, and the code being
  measured lives in `../runtime/Lib` and `../generator/Lib` — so pcov
  faithfully reports 0% of the nothing it was pointed at. This was previously
  misdiagnosed as "pcov silently instruments nothing on PHP 8.5" and worked
  around by using xdebug in CI; it is neither a PHP 8.5 nor a pcov bug.

  Note that a single run only measures the platform it ran against. The
  per-platform adapters look far worse than they are from a Postgres-only run
  — `DBMySQL` measures 53.6% there and 88.5% under `PROPULSION_TEST_DB=mariadb`
  — which is why CI collects coverage from every database job and uploads each
  under its own Codecov flag so they merge.

## Open issues

- **Query result cache: coherence with non-ORM writes, and driver-specific
  sharing limits.** The cache now has a global (process-/host-shared) tier on
  top of the request-scoped one — any PSR-16 pool, selected by the `cache.query`
  config section; see `docs/CACHING.md` — invalidated by table version tokens.
  What remains:
  - A write that bypasses the ORM (raw SQL, another application, a migration, a
    DBA) bumps no version token, so entries covering that table stay served
    until their TTL lapses. TTL is the only backstop, which is why it defaults
    to a finite 300s rather than "never".
    `Propulsion::invalidateQueryCacheForTables()` is the escape hatch.
  - Strict single-flight needs an atomic create-if-absent, which PSR-16 cannot
    express. Propulsion's own drivers implement it; a third-party pool gets
    probabilistic early recomputation only, so N truly simultaneous cold misses
    on one key can still issue N queries there.
  - The `file` driver has no automatic expiry GC beyond lazy unlink-on-read: an
    entry that expires and is never requested again occupies disk until
    `FileCache::prune()` runs (schedule it; see `docs/CACHING.md`).
- **The `array` and `apcu` cache drivers share less than they appear to.**
  `array` is per-process and, under a *threaded* worker, per-thread — the
  `l2:array-cross-thread` profile in `test/worker/` measures this directly (7 of
  16 concurrently-written keys visible to the reading thread). `apcu` is
  per-host and dies with the php-fpm master, and because `apc.enable_cli` gives
  each CLI process its own segment, a cron job can neither read nor *invalidate*
  the web tier's entries. If anything writes to the database from CLI, use
  `file` or a shared network pool.
- **MariaDB**: served by `DBMySQL`/`MysqlPlatform` as though it were MySQL
  (`DBMySQL::isMariaDb()` detects it at the runtime-connection level for the
  few things that already need to differ, e.g. `RETURNING`), no dedicated
  `MariadbPlatform`. Covered by its own full-suite CI job
  (`integration-mariadb`, `mariadb:11`) since 2026-08-05, so the MariaDB-only
  paths -- `RETURNING`, and `MysqlPlatform`'s `CREATE SEQUENCE`/native-UUID
  opt-ins -- are no longer verified only by hand. A dedicated platform/adapter
  pair is still the open item here, not the coverage.

- **Tests that reconfigure Propulsion leak its adapter map.**
  `Propulsion::setConfiguration()` drops every registered adapter, and one
  registered with `setDB()` (which is how `PropulsionQuickBuilder` hands each
  built schema to the runtime) cannot be rebuilt from the configuration — so a
  test that reconfigures and restores only the configuration array silently
  unregisters ~50 datasources belonging to other tests. The failure lands on
  an unrelated test later in the run (`Unable to find adapter for datasource
  [...]`) and depends on execution order, so it reproduces only with a cleared
  `test/.phpunit.cache`. It has reddened CI twice.

  `GlobalStateLeakGuard` (a PHPUnit extension, sibling of
  `TransactionLeakGuard`) names the culprit after every test *and re-registers
  what was lost*, so a leak can no longer travel.
  `PropulsionStateSnapshot::capture()/restore()` is the fix for a test that
  means to reconfigure.

  **The backlog is empty** — zero leaks on all five platforms — so a report
  now means a newly introduced leak rather than a known one. Like
  `TransactionLeakGuard`, the guard reports without failing the run; the
  repair is what protects the suite, and a named test is enough to get it
  fixed. Note that the leaking set differs per platform, because which tests
  skip differs: the seven classes originally listed here were the Postgres
  set, and Oracle reported seven *different* ones. A single-platform run does
  not clear this.

- **One order-dependent failure remains, in the integration tier only.**
  Random orderings of the *no-Docker* tier are now clean (12 seeds, identical
  assertion and skip counts), but a Postgres run under
  `--order-by=random --random-order-seed=606` fails
  `GeneratedPeerDoDeleteTest::testDoDeleteCompositePK` with a foreign-key
  violation (`book_opinion.reader_id=2` has no `book_reader` row). That is a
  *fixture-data* dependency -- the test assumes rows another test created --
  and so is a different problem from the two bootstrap-state ones fixed in
  `<this commit>`. Reproduce with:

  ```
  cd test
  rm -rf .phpunit.cache fixtures/bookstore/build fixtures/schemas/build fixtures/namespaced/build
  PROPULSION_REQUIRE_INTEGRATION=1 ../vendor/bin/phpunit -c phpunit.xml \
      --order-by=random --random-order-seed=606
  ```

  Note the cleanup: clearing `.phpunit.cache` alone is not enough to compare
  two runs, because the generated fixture trees change which tests skip.

## Open: generated relation parameters name the stub class, not the base

253 of the ~550 remaining PHPStan level 9 findings in *generated* code are one
shape:

```
Parameter #1 $v of method BaseReview::setBook() expects Book, $this(BaseBook) given.
```

Every relation call the generator emits lives in the `Base<Model>` class, and
passes `$this`:

```php
// emitted into BaseBook
$review->setBook($this);                 // setBook(?Book $v) is on BaseReview
$query->filterByBook($this)->find($con); // filterByBook(Book|PropulsionObjectCollection<Book>)
```

`$this` there is a `BaseBook`. The parameter says `Book`. That holds at runtime
only because the generator's stub (`class Book extends BaseBook {}`) is the sole
subclass -- an invariant nothing states or enforces. Anyone who hand-writes
`class Rogue extends BaseBook` gets a real `TypeError` out of generated code, not
a degraded experience.

Affected emitters, by finding count: `filterBy<Relation>()` (100),
`set<Relation>()` (62), `add<Relation>()` (51), and the nested-set behavior's
`isDescendantOf()`/`isAncestorOf()`/`childrenOf()`/`descendantsOf()`/
`ancestorsOf()`/`prune()`/`branchOf()` (~40).

### What does not work

Checked against PHPStan 2.2.5, all with a scratch reproduction:

- **`final class Book`.** Constrains the wrong class. It forbids extending
  `Book`; what would have to be forbidden is extending `BaseBook`. `$this`
  inside `BaseBook` stays `static of BaseBook`.
- **`@phpstan-sealed Book` / `@psalm-inheritors Book` on `BaseBook`.** Neither
  narrows `$this`; PHPStan does not read either as a closed-world statement.
- **A self-referential template** (`@template T of BaseBook` on the base,
  `@extends BaseBook<Book>` on the stub, parameters typed `T`). This *does*
  work for a call written inside the stub, where `T` resolves to `Book`. It
  fails for calls inside the base, which is where all of ours are:

  ```
  expects T of BaseNote, $this(BaseNote<T of BaseNote>) given.
  💡 Type $this(BaseNote<T of BaseNote>) is not always the same as T.
  ```

  It also cannot reach the common case at all: `$review->setBook($this)` needs a
  type on a parameter belonging to a *different* model's class, where no `T` of
  `BaseBook`'s is in scope.

The constraint, however it is spelled: the parameter must name a type `$this`
provably already is -- `BaseBook` itself, or an interface `BaseBook` implements.
Anything below it in the hierarchy cannot work from inside the base, because
subclassing the base is always legal.

### The fix

**Type the relation parameters on the base object class** -- `setBook(?BaseBook $v)`.
Per emitter, use `getNewObjectBuilder($table)->getClassname()` where
`getNewStubObjectBuilder($table)->getClassname()` is used today. Three places
need it, not one:

- the `@param` docblock,
- the `instanceof` narrowing inside the method body
  (`if ($book instanceof Book)` in `filterBy<Relation>()`),
- the FK cache property the mutator assigns to -- `protected ?Book $aBook = null;`
  becomes `?BaseBook`, or `$this->aBook = $v` is an `assign.propertyType` error.

It admits exactly the same runtime objects (nothing but `BaseBook` subclasses
ever reaches these), and a hand-extended base starts working rather than
throwing. Cost: `BaseBook` appears in public signatures.

Deferred rather than implemented: it is a wide, mechanical diff across the
relation emitters in `QueryBuilder`, `ObjectBuilder` and the nested-set behavior
modifiers, and it changes public generated signatures, so it wants to land on
its own rather than inside a level 9 sweep.

### Rejected: an interface per model

`BaseBook implements BookInterface`, parameters typed `BookInterface`. Looks
like the tidier signature until you ask what the interface has to contain.
`setBook()` calls `$v->getId()` *and* `$v->addReview($this)`, so `BookInterface`
needs the relation methods too -- and `addReview()`'s own parameter has this
same problem, so it needs `ReviewInterface`. The interfaces come out mutually
referential and mirroring most of the generated surface, one extra file per
model, for a name.

The extension-point argument for it does not survive either: a class
implementing `BookInterface` without extending `BaseBook` cannot be hydrated,
cannot be pooled (`addInstanceToPool()` takes `Poolable`) and cannot go in a
`PropulsionObjectCollection<Book>` (`@template T of BaseObject`). Nothing can
usefully implement it, which makes it a second name for the base class rather
than a contract.

Making it user-editable like the stubs would be worse again: codegen depends on
the interface matching the methods it emits, so a hand-maintained one would
drift and break generation. The stubs are editable precisely because nothing
generated depends on their contents.

## Known-open, deliberately not fixed

Findings from the code-review pass that landed in `e06386a`/`cfe545a`/`547af90`,
left open with a reason rather than silently:

- **Persistent connections get no statement-level dropped-connection detection,
  and no query observation either.** `PropulsionStatement` closed the first
  gap for ordinary connections (see `docs/CONNECTIONS.md`) and is also where
  query observers are notified (`docs/OBSERVABILITY.md`), but PDO refuses a
  custom statement class when `PDO::ATTR_PERSISTENT` is set, so a deployment
  using persistent connections still surfaces most drops as a plain
  `PDOException` with no pool eviction, and its observers see only
  `exec()`/`query()` rather than the prepared-statement executions that carry
  essentially all ORM traffic. `configureStatementClass()` suppresses that
  failure rather than refusing to connect. Nothing can be done about it below
  "don't use persistent connections with this ORM", which is also what
  `getQueryCount()` already tells you.

## Missing modernization work

- **PSR-18**: not started, nothing to wire it into yet.
- **Phase 4d (Quiote adapter integration)**: tracked in the Quiote-side repo,
  not here.
