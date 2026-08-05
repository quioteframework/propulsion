# Unit-of-Work roadmap

Design notes and an implementation checklist for adding an optional
Unit-of-Work / `DbContext`-style layer on top of Propulsion's existing
ActiveRecord API, plus a list of Entity Framework Core ideas worth stealing.
Written 2026-07-26 after a codebase survey; updated 2026-07-29 once the core
class, cascade suppression, and optimistic concurrency landed (verified live
against Postgres/MySQL/MariaDB/SQLite/MSSQL/Oracle). See git log for anything
newer.

## Current foundation (already built, confirmed on `main`)

Propulsion has more of the plumbing a Unit-of-Work needs than a typical
ActiveRecord ORM does — this is additive work, not a rewrite:

- **Dirty tracking**: `Propulsion\OM\BaseObject` (`runtime/Lib/OM/BaseObject.php`)
  already tracks `$modifiedColumns`, `isModified()`, `isColumnModified()`,
  `isNew()`/`setNew()`, `isDeleted()`/`setDeleted()`, `resetModified()`.
- **Identity map**: request-scoped instance pooling via `Propulsion\Session`
  (`runtime/Lib/Session.php`, `$instancePools`), fed by generated
  `{Model}Peer::addInstanceToPool()`/`getInstanceFromPool()`. Querying the
  same PK twice in one request already returns the same PHP instance.
  Toggle: `Propulsion::$instancePoolingEnabled`. **Gotcha confirmed while
  building/testing optimistic concurrency below**: this makes it easy to
  accidentally write a "two concurrent writers" test that's actually
  exercising the *same* PHP object twice — `{Model}Peer::clearInstancePool()`
  between two `findPk()` calls is required to get a genuinely independent
  second copy (see `OptimisticLockBehaviorTest`'s own comments).
- **Nested transactions / savepoints**: `Propulsion\Connection\PropulsionPDO`
  (`runtime/Lib/Connection/PropulsionPDO.php`) already supports real
  `SAVEPOINT`/`RELEASE SAVEPOINT`/`ROLLBACK TO SAVEPOINT` on pgsql/mysql/
  sqlite/oci (Oracle), with depth-counter + poison-flag emulation on MSSQL
  (the one platform among the five this project supports with no savepoint
  support at all). Every generated `save()`/`delete()` accepts an optional
  `?PropulsionPDO $con` and joins the caller's transaction if one is passed
  in — this is exactly the mechanism `UnitOfWork::flush()` (below) relies on
  to make a whole batch atomic via ordinary nested `beginTransaction()`/
  `commit()`/`rollBack()` calls, no new transaction API needed.
- **Lifecycle hooks**: PSR-14 events (`Pre`/`PostSaveEvent`,
  `Pre`/`PostInsertEvent`, etc., `runtime/Lib/Event/`) already dispatch from
  `BaseObject`; a `preXxx` listener can veto via `stopPropagation()`.
  Behaviors (`generator/Lib/Behavior/`) separately inject code at the same
  hook points at generation time — both mechanisms coexist today, and both
  are exactly what `UnitOfWork::flush()`'s own `PreFlushEvent`/
  `PostFlushEvent` and `OptimisticLockBehavior` (below) build on.
- **Cascade**: generated `doSave()` already eagerly saves modified/new FK
  parents first, then the row itself, then referrer collections/one-to-one
  referrers, guarded by an `alreadyInSave` reentrancy flag. This is
  unconditional and schema-driven, not runtime-configurable -- **except now
  it is**, via `BaseObject::$suppressAutoCascade` (see below).
- **Batch save/delete (flat)**: `PropulsionObjectCollection`/
  `PropulsionArrayCollection` (`runtime/Lib/Collection/`) already expose
  `save()`/`delete()` that loop a collection in one transaction — but with
  no dedup, no cross-type ordering, and each element still runs its own
  per-object cascade.
- **Query cache invalidation**: `BasePeer::doInsert/doUpdate/doDelete/
  doDeleteAll` already own invalidation of the opt-in query result cache
  (`Criteria::setQueryCache()`) — `UnitOfWork::flush()` goes through these
  same `BasePeer`-level paths (via each entity's own `save()`/`delete()`),
  so this is inherited for free, not something the UoW had to reimplement.

## What's built

- [x] **`BaseObject::$suppressAutoCascade`** (`runtime/Lib/OM/BaseObject.php`)
  — a new `protected bool` flag + `setSuppressAutoCascade()`/
  `isSuppressAutoCascade()`, false by default (zero behavior change for
  plain `->save()`/`->delete()` callers). Wired into the *generated*
  `doSave()` template (`generator/Lib/Builder/OM/ObjectBuilder.php::addDoSave()`):
  when true, the recursive `->save($con)` call into each FK-parent and each
  referrer collection is skipped. Two things this is deliberately **not**
  wholesale:
  - The FK-parent **re-sync** (`$this->setAuthor($this->aAuthor)`, which
    re-reads the parent's current primary key into this row's own FK
    column) runs unconditionally, cascade-suppressed or not. Skipping it too
    would have silently broken `UnitOfWork::flush()`'s whole reason for
    existing: a child row's FK column needs to pick up a parent's
    just-generated primary key even when the *parent's own* recursive
    `save()` call was suppressed (because the UoW is saving it separately,
    in the right order). This is exactly the kind of subtle interaction
    that only showed up once this was tested against a real
    parent/child insert, not something a design read-through would have
    caught.
  - Referrer-collection cascade suppression means an entity reachable
    *only* via another tracked entity's FK setter or collection, and not
    itself explicitly tracked, is **never persisted** by `flush()` — this
    replaces the automatic cascade, it doesn't optimize on top of it. See
    `UnitOfWork::track()`'s own doc comment and
    `UnitOfWorkTest::testFlushWithoutTrackingFkParentLeavesForeignKeyUnset()`,
    which asserts this exact limitation on purpose.
- [x] **Core `UnitOfWork` class** (`runtime/Lib/UnitOfWork.php`, `Propulsion\UnitOfWork`)
  and **`EntityState` enum** (`runtime/Lib/EntityState.php`):
  ```php
  $uow = new UnitOfWork($con);
  $uow->track($book);
  $uow->markDeleted($author);
  $uow->attach($detachedEntity, EntityState::Modified);
  $affected = $uow->flush();
  ```
  `flush()`:
  1. Dispatches `PreFlushEvent` (stoppable — a listener calling
     `stopPropagation()` aborts the whole flush, returning 0 with nothing
     persisted).
  2. Partitions tracked entities into insert/update/delete using their own
     `isNew()`/`isModified()`/`isDeleted()` (or an explicit `attach()`
     override) — no new dirty-tracking mechanism needed, exactly as this
     doc originally proposed.
  3. Topologically sorts the *distinct tables* the tracked entities span by
     FK dependency (`TableMap`/`ColumnMap::getRelatedTableName()` — plain
     name-string comparisons, deliberately not the eager
     `ColumnMap::getRelatedTable()`, which throws for any table whose Peer
     class isn't autoloaded yet, unrelated to whether that table is even
     part of this flush). A DFS-based topological sort, tolerant of cycles
     (a self-referencing FK, e.g. a tree's `parent_id`, is common and
     harmless; a genuine cross-table cycle just has its back-edge ignored
     rather than aborting the whole sort — best-effort, same as the
     per-object cascade it replaces never handled that case correctly
     either). This is table-level, not per-instance — inserts/updates
     happen parent-table-first exactly once across the whole batch, not via
     each root's own depth-first recursion.
  4. Opens **one** transaction (`$con->beginTransaction()`), sets
     `$suppressAutoCascade = true` on every entity about to be
     saved/deleted, then calls each entity's own `save($con)`/`delete($con)`
     in dependency order — inserts and updates together (parent-table
     order), then deletes (reverse order). Each entity's flag is reset to
     `false` in a `finally` block regardless of outcome.
  5. On success: commits, dispatches `PostFlushEvent` (carrying the total
     affected-row count), clears the tracked-entity list, returns the count.
  6. On any `PropulsionException` (including a `ConcurrencyException`, see
     below): rolls back and rethrows, **tracked entities are left tracked**
     so a caller can inspect what failed and retry — same contract as a
     single object's `save()`.
  - **Confirmed caveat, MSSQL only** (the one supported platform with no
    savepoint support): when `flush()` runs *nested* inside a caller's
    already-open outer transaction (e.g. a test harness that wraps every
    test in one, or an application that opens its own transaction before
    calling `flush()`), a failure's `rollBack()` can't issue a real
    mid-transaction `ROLLBACK TO SAVEPOINT` — there's no savepoint
    mechanism to use. It can only mark the *outer* transaction uncommitable
    (`PropulsionPDO::isCommitable()` becomes `false`), so partial writes
    stay visible on that connection until whoever owns the outer
    transaction eventually rolls it back. A `flush()` call with no
    pre-existing outer transaction (the normal top-level case) doesn't hit
    this at all — its own `rollBack()` is the outermost one, which always
    issues a real `ROLLBACK TRANSACTION`, MSSQL included. Confirmed live;
    see `OptimisticLockBehaviorTest::testUnitOfWorkFlushRollsBackOnStaleRow()`
    and `UnitOfWorkTest::testFlushRollsBackWholeBatchOnFailure()`'s own
    comments for the exact mechanism.
  - **Confirmed, unrelated to `UnitOfWork` itself, discovered while testing
    it against live MSSQL**: `DBMSSQL`'s `OUTPUT`-clause id-fold (every
    `INSERT` now uses this, since `DBAdapter::supportsInsertReturning()`
    went unconditional) silently swallows a failed `INSERT`'s constraint
    violation on pdo_dblib instead of throwing — `execute()` returns `true`
    and the `OUTPUT` clause's result set is simply empty, so
    `extractInsertedId()` gets back `false`/`null` with no exception raised
    at all. A real, independently-worth-fixing platform gap — see
    `KNOWN_ISSUES.md`. `UnitOfWorkTest` avoids it (triggers its
    rollback-on-failure test via a failing `UPDATE` instead of a failing
    `INSERT`) rather than being gated around it, since it's orthogonal to
    what that test is actually about.
  - **Scoped to a single connection/database.** Entities spanning more than
    one configured database in one `flush()` call aren't supported — every
    tracked entity is saved/deleted via the one connection passed to the
    constructor, regardless of which database its own table actually lives
    in.
  - **No automatic graph discovery.** `track()` only ever tracks the exact
    entity passed to it — it does not walk FK setters/collections to find
    and include related new/modified objects the way EF Core's change
    tracker does. Combined with cascade suppression during `flush()`, this
    means the caller is responsible for tracking every entity that needs to
    be persisted, not just the "roots".
  - **`attach()`** (the EF Core `Entry(entity).State` steal, see below) is
    done as part of this, not a separate follow-up: `EntityState::Added`/
    `Modified` additionally call `setNew(true)`/`setNew(false)` so `flush()`
    takes the right insert-vs-update path for a detached entity (e.g.
    hydrated from a deserialized API request body) whose `isNew()` would
    otherwise just be the `BaseObject` default (always `true`) regardless of
    whether it represents a genuinely new row.
- [x] **Optimistic concurrency tokens**: `OptimisticLockBehavior`
  (`generator/Lib/Behavior/OptimisticLock/`) — confirmed
  `VersionableBehavior` (checked first, per this doc's own earlier note) is
  an unrelated, same-named-in-spirit-only audit/history-log feature, not
  conflict detection, so this is new. Adds an integer `version` column
  (configurable via `version_column`, default `0`) and:
  - `preUpdate()` (object-builder hook, spliced into `save()`'s update
    branch, before `doSave()` runs): if `isModified()`, stashes the
    pre-bump version into a new `$optimisticLockPreviousVersion` property
    and bumps the real column via its own setter. Gated on `isModified()`
    specifically so a no-op `save()` call doesn't bump the version merely
    by virtue of this behavior touching the column itself (the setter call
    would otherwise mark it modified and turn every no-op `save()` into a
    real `UPDATE`).
  - `doUpdateSelectCriteria()` (peer-builder hook, spliced into
    `doUpdateThis()`'s WHERE-clause construction): adds
    `version = <the stashed pre-bump value>` alongside the existing
    primary-key condition — a stale writer's `UPDATE` now affects zero rows
    instead of silently overwriting a change it never saw.
  - `postUpdateAffectedRows()` (object-builder hook, spliced right after
    `doSave()` calls `doUpdateThis()`): throws `ConcurrencyException`
    (`runtime/Lib/Exception/ConcurrencyException.php`, carrying the entity)
    when the affected-row count is zero — mirrors EF Core's
    `DbUpdateConcurrencyException`.
  - Verified live against all five platforms (`table15`/`table16` in
    `behavior-optimistic-lock-schema.xml`, the default and a custom
    `version_column` name respectively) — including in combination with
    `UnitOfWork::flush()` (a stale row's `ConcurrencyException` correctly
    rolls back an otherwise-valid insert earlier in the same batch).

## What's still missing

- [ ] **Session-lifecycle integration**: if a UoW instance is meant to be
  request-scoped (so it survives across a request in worker mode the same
  way `Session::$instancePools` does), hook its own reset into
  `Session::reset()` at request boundaries. Not started — today's
  `UnitOfWork` is a plain object a caller constructs and discards itself;
  no `Propulsion::getUnitOfWork()` request-scoped default exists.
- [ ] **Statement batching**: multi-row `INSERT` for same-table new entities
  instead of one INSERT per row. Confirmed on re-survey: `BasePeer::doBulkInsert()`
  (the COPY/LOAD DATA fast path) is **not** a usable starting point for
  this — it's Postgres/MySQL-only and, more fundamentally, has no mechanism
  to return generated per-row primary keys at all (COPY/LOAD DATA protocols
  don't support it), which a UoW flush needs whenever a batch of new
  entities' rows are referenced by other rows in the same batch. A real
  multi-row `INSERT ... VALUES (...),(...),(...)` with id retrieval would
  need to be built from scratch.
- [ ] **No-tracking read mode**: a per-query or per-scope way to skip
  identity-map registration for read-heavy paths, on top of the existing
  global `Propulsion::disableInstancePooling()` toggle (which is all-or-
  nothing today).

## Things worth stealing from EF Core

EF Core's `DbContext` is the most mature mainstream Unit-of-Work/
`ChangeTracker` implementation to compare against. What's actually worth
porting vs. what Propulsion already does better or doesn't need:

- [ ] **Batched `SaveChanges()` round-trips** — EF Core consolidates
  multiple INSERT/UPDATE statements into fewer round trips per
  `SaveChanges()` call. Directly applicable; see "Statement batching" above.
- [x] **Optimistic concurrency tokens** (`[ConcurrencyCheck]`/
  `RowVersion`, `DbUpdateConcurrencyException`) — done, see
  `OptimisticLockBehavior` above.
- [x] **`Entry(entity).State` explicit override** — done, see
  `UnitOfWork::attach()`/`EntityState` above.
- [ ] **`AsNoTracking()` query mode** — directly applicable, see "No-
  tracking read mode" above.
- [x] **Global query filters** (auto-applied `WHERE` clauses for soft-delete
  or multi-tenancy, suppressible per-query) — shipped as
  `Propulsion::addGlobalQueryFilter()` plus
  `ModelCriteria::withoutGlobalFilter()`/`withoutGlobalFilters()`. See
  `PLATFORM_FEATURES.md`'s own entry for the design notes (why a callable
  rather than a stored predicate, why UPDATE/DELETE are filtered too, and the
  joined-model limitation).
- [x] **Topological insert/update ordering across the whole tracked graph**
  — done, see `UnitOfWork::flush()`'s table-level topological sort above
  (table-level, not per-instance, but covers the whole tracked batch, not
  per-aggregate-root).
- **Not worth porting**: EF Core's `DetectChanges()` snapshot-diffing —
  Propulsion's setter-driven real-time dirty tracking (`modifiedColumns`) is
  already better (no O(n) walk needed at flush time). Shadow properties,
  owned/value-object types, compiled query caching, and split-query
  `Include()` behavior don't map cleanly onto Propulsion's generated-Peer
  architecture and aren't clear wins here — skip unless a concrete need
  comes up.
- **Second-level (cross-request) cache**: EF Core doesn't ship one either
  (needs a third-party package) — not an EF steal, just worth noting this
  is the same gap already tracked in `KNOWN_ISSUES.md` for Propulsion's
  query result cache.

## Notes / caveats

- Confirmed on `main` as of this writing: PSR-14 events, savepoint-capable
  nested transactions, and instance pooling are all already merged (not
  just present in feature worktrees) — the design above builds on the
  `main` versions of these, not a branch.
- Separate, unrelated in-flight branches exist for JSON/JSONB and UUID
  column types (`feature/json-jsonb-column`, `feature/uuid-column`) — no
  overlap with this work, just flagging so they aren't confused for UoW
  prerequisites.
- ~~Double-cascade avoidance (`$suppressAutoCascade` flag) is the trickiest
  part of this design — prototype it against a schema with bidirectional
  FK relationships (the existing `alreadyInSave` reentrancy guard is the
  precedent to study) before committing to the approach.~~ Done — the real
  trickiness turned out not to be double-cascade avoidance itself (that
  part was a straightforward wholesale skip) but making sure the FK
  re-sync half of the cascade survives suppression (see `$suppressAutoCascade`
  above); double-saving was never actually a risk since `isModified()`
  already no-ops a second `save()` on an unchanged object regardless of
  cascade suppression.
