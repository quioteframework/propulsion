# Worker-mode safety

Read this before touching anything in `runtime/`, and before adding any static
property, cache, registry, or global toggle anywhere in the codebase.

Propulsion is expected to run under persistent-worker SAPIs — FrankenPHP worker
mode, RoadRunner, Swoole, `frankenphp run`, long-lived CLI consumers — where a
single PHP process serves many requests. Classic PHP-FPM gives you a free
correctness guarantee: the process dies at the end of the request, so anything
left behind is discarded. A worker has no such boundary. Every static property,
every open connection, every memoised value survives into the *next* request,
serving a different user.

That single difference is the source of every rule below.

---

## The one hard requirement on the host

A worker host **must** call `Session::reset()` at every request boundary:

```php
// once, at worker start
Propulsion::init('/path/to/runtime-conf.php');

// then, per request
try {
    handle($request);
} finally {
    Propulsion::getSession()->reset();
}
```

`reset()` in a `finally`, not on the success path. A request that threw is the
one most likely to have left an open transaction behind, and on PostgreSQL an
uncommitted transaction poisons the connection for everything that reuses it
(`current transaction is aborted` until an explicit `ROLLBACK`).

`Session::reset()` does five things, in a deliberate order (see its docblock):

1. force-rollback any dangling transaction on every connection Propulsion has open
2. clear every generated Peer's instance pool
3. reset `forceMasterConnection` to `false`
4. clear the L1 query result cache and the shared tier's per-request bookkeeping
5. clear the compiled-query (SQL string) cache

**Do not substitute `Propulsion::setSession(new Session())` for `reset()`.**
Swapping in a fresh `Session` relocates the request-scoped storage, but it
skips step 1 entirely — the previous request's open transaction stays open on a
connection that `ServiceContainer` still owns, and nothing will ever roll it
back. If a host wants a fresh `Session` per request, it must still call
`reset()` on the outgoing one first.

---

## The two buckets

All state in Propulsion belongs to exactly one of two buckets. Deciding which
one, *before* writing the code, is the whole discipline.

| Bucket | Home | Lifetime | Rule |
|--------|------|----------|------|
| **Process-scoped** | `ServiceContainer`, `Propulsion`'s own statics | the worker process | must be request-*independent*: identical for every request the process will ever serve |
| **Request-scoped** | `Session` | one request | must be cleared by `Session::reset()` |

Current inventory — keep this table honest when you add state:

**Request-scoped (`runtime/Lib/Session.php`)**

| State | Why it cannot be shared |
|-------|------------------------|
| `$instancePools` | holds hydrated model objects belonging to one request's data |
| `$queryCache` (L1 `QueryResultCache`) | holds *formatted* results — live object graphs |
| `$compiledQueryCache` | keyed by a caller-chosen string whose scope is per-request |
| `$tieredCache` → `TableVersionRegistry` `$memo`/`$pending` | per-request token memo, and version bumps buffered for an in-flight transaction |
| `$forceMasterConnection` | a replication choice one request opted into |

**Process-scoped (`runtime/Lib/ServiceContainer.php`, `runtime/Lib/Propulsion.php`)**

| State | Why sharing is correct (and desirable) |
|-------|---------------------------------------|
| `$connectionMap` | connections are expensive; reusing them is the point of worker mode |
| `$adapterMap`, `$dbMaps` | schema metadata, immutable once built |
| `$configuration`, `$logger`, `$eventDispatcher` | deployment wiring, set once at boot |
| `$queryCachePool` (PSR-16 L2 backend) | shared across requests, and often across processes |
| `$queryCacheConfig` | parsed deployment configuration |
| `BasePeer::$validatorMap` | stateless validator instances |
| `DBAdapter::$adapters`, `CacheDriverFactory::$drivers` | class-name registries |

**Neither, and the easiest thing to get wrong: pooled connections.** A
`PropulsionPDO` is process-scoped, but carries per-request state on itself —
`$nestedTransactionCount`, `$isUncommitable`, `$preparedStatements`,
`$queryCount`, `$lastExecutedQuery`. Anything you add there inherits that
hazard. See R5.

---

## Rules

### R1. No new mutable statics. Pick a bucket first.

If you catch yourself writing `private static array $somethingCache = []`, stop.
The question is not "is a static convenient here" but "is this value identical
for every request this process will serve". If yes, it belongs on
`ServiceContainer`. If no, it belongs on `Session`. A static on some other class
is neither, and will be forgotten by `reset()`.

The one legitimate exception is a genuinely immutable registry — a map of
driver names to class names, a list of savepoint-capable PDO drivers. Those are
constants that happen to be spelled as arrays.

### R2. If it can be reset, it must be reset.

Adding request-scoped state to `Session` is half the job; the other half is a
line in `Session::reset()` and a line in its docblock explaining *why* that
state must not survive. State that is on `Session` but missing from `reset()` is
worse than state that was never moved, because it looks safe.

### R3. Process-scoped state must not capture request identity.

A memoised value is only safe to share if it cannot encode anything about the
request that produced it. Watch for:

- **Request data**: hydrated objects, user ids, tenant names, `$_SERVER` values.
- **Object identity**: `spl_object_id()` keys — ids are recycled after the object
  is freed, so a stale entry keyed by one will be silently attributed to an
  unrelated later object. `TableVersionRegistry::$pending` is keyed this way and
  is on `Session` precisely so it cannot outlive the connection it refers to.
- **Transaction state**: nothing memoised process-wide may depend on whether a
  transaction was open when it was computed.
- **Time**: a memoised "now", or a TTL computed against boot time.

### R4. Anything that holds a model object is request-scoped. No exceptions.

Instance pools, L1 cache entries, collections, formatters, `UnitOfWork`. A
hydrated `BaseObject` holds FK-related objects, referrer collections, and
(through the formatter) a `Criteria`. One escaped reference retains a subgraph
for the life of the process.

This is also why the L2 shared tier stores **raw rows, not formatted results**
(`SharedQueryCache`'s class docblock): rows are inert data that can safely cross
a request boundary; a serialized `PropulsionObjectCollection` cannot.

### R5. Never leave per-request state on a pooled connection.

Connections outlive requests, so anything you set on one must either be reset
before the request ends or be genuinely connection-lifetime. In practice:

- An open transaction must be closed or rolled back. `Session::reset()`'s sweep
  is the safety net, not the design — `UnitOfWork::flush()` and generated
  `save()`/`delete()` both roll back in a `catch (\Throwable)` for this reason.
- Transaction depth counters must stay truthful even on the failure path. This
  is why `PropulsionPDOTrait::commit()` decrements in a `finally`: a
  `PDOException` out of `parent::commit()` used to leave a pooled connection
  claiming a depth it no longer had, which silently disabled the shared cache
  (`TieredQueryCache::sharedTierFor()` declines inside a transaction) for the
  rest of the request.
- A dropped connection must be evicted from the pool by identity, not by
  datasource name — see `PropulsionPDOTrait::handleDroppedConnection()` and
  `Propulsion::discardConnection()`. It must also never be transparently
  retried: losing the connection loses the transaction with it.
- Statement caches, prepared-statement handles, and the like belong to that
  connection and die with it (`clearStatementCache()`).

### R6. A global toggle that can nest must not be a boolean.

`Propulsion::disableInstancePooling()` / `enableInstancePooling()` is the
cautionary example. It returns "did I change it", and callers restore based on
that — which breaks the moment two callers overlap: the inner one records "not
mine to restore", the outer one re-enables while the inner is still running, and
the inner silently starts pooling. Use a depth counter, and put the counter on
`Session` so an abandoned scope cannot leak the toggle into the next request.

### R7. Unbounded growth is a bug, not a tuning issue.

In PHP-FPM, an unbounded array is bounded by the request. In a worker it is
bounded by nothing. Anything process-lived needs an explicit cap:

- Bound by **bytes or entry size**, not just entry count, where entries vary in
  size. `ArrayCache::DEFAULT_MAX_ENTRIES` bounds count; 1000 wide row sets is
  still a lot of memory.
- Assume cache keys can be **attacker-influenced**. The shared query cache is
  keyed partly by bound parameter values, which is exactly why
  `SharedQueryCache::admit()` exists: a query whose parameters never repeat must
  cost the cache nothing.
- Prefer digests to raw strings as keys. `SharedQueryCache::buildKey()` hashes
  its payload with `xxh128`; multi-kilobyte SQL held as a live array key is
  memory *and* a slower lookup.

### R8. Reference cycles are leaks here.

Refcounting frees a cycle only when the cycle collector runs. With no process
exit to fall back on, a cycle created per query accumulates. `ArrayObject`
subclasses that memoise their own iterator, objects that hold a formatter that
holds them, closures capturing `$this` and stored on `$this` — all suspect. If a
cycle is unavoidable, break it explicitly (`WeakReference`, or an explicit
teardown call that something actually invokes; a teardown method nothing calls
is not a fix).

### R9. Never call `clear()` on a shared cache backend at a request boundary.

The L2 pool is process- or host-shared. Flushing it at the end of a request
flushes every other process's cache too. `TieredQueryCache::reset()` carries
this warning in its docblock and deliberately touches only the request-scoped
tier and the version memo.

The corollary: shared-cache *invalidation* must go through table version tokens
(`TableVersionRegistry`), never through enumerate-and-delete, and must be
published only after the outermost commit — publishing at statement time lets
another process cache your uncommitted rows under an already-current token.

### R10. A threaded worker shares the process, not just the request.

Under FrankenPHP with `worker <n>` above 1, `Propulsion::$session` and every
connection it reaches are **one instance shared by every thread in the
process** — measured, not assumed (see `KNOWN_ISSUES.md`). There is no
per-thread isolation to lean on. What makes concurrent requests safe is the
request-boundary reset, so anything that would only be safe "because each
thread has its own copy" is not safe.

This also means per-process caches share less than they appear to across a
deployment: `ArrayCache` is per-process *and* per-thread; `apcu` is per-host but
gives CLI its own segment. Both are documented in `KNOWN_ISSUES.md`.

### R11. Do not rely on destructor or shutdown timing.

`__destruct()` runs at refcount zero, which in a worker may be much later than
the end of the request — and never, for cyclic garbage. `register_shutdown_function()`
runs at process exit, which may be days away. Neither is a request boundary.
Cleanup that must happen per request goes in `Session::reset()`.

`PropulsionOnDemandIterator::__destruct()` closing its cursor is a *belt-and-braces*
addition on top of `next()`'s explicit close, not the primary mechanism — treat
it that way in new code too.

### R12. Debug and diagnostic counters are request-scoped too.

`getQueryCount()` and `getLastExecutedQuery()` live on the connection, so in a
worker they report process-lifetime values unless something resets them. If you
add a counter or a "last X" field, decide whether callers expect per-request or
per-process semantics, and say so in the docblock.

---

## Review checklist

For any change under `runtime/`, answer all of these:

- [ ] Did I add a static property? If so, which bucket, and why is that right?
- [ ] Did I add state to `Session`? Is it cleared in `reset()`, and documented there?
- [ ] Did I add state to `ServiceContainer` or a `Propulsion` static? Is it truly
      identical for every request this process will serve?
- [ ] Does anything I added hold a `BaseObject`, a `Criteria`, a `PDOStatement`,
      or a `Closure` capturing one?
- [ ] Can anything I added grow without bound across requests? What caps it?
- [ ] Did I create a reference cycle? What breaks it?
- [ ] Did I add per-request state to a pooled connection? What resets it, including
      on the exception path?
- [ ] If I touched transaction handling: is the depth counter still truthful when
      the underlying statement *fails*?
- [ ] If I touched the shared cache: does invalidation still go through version
      tokens, published only after the outermost commit?
- [ ] If I added a global toggle: what happens when two callers overlap?
- [ ] Does anything depend on `__destruct()` or shutdown-function timing?

---

## Testing

```
composer test:worker          # FrankenPHP worker-mode matrix (needs Docker)
```

`test/worker/` boots Propulsion once and serves many requests from one process,
calling `Session::reset()` between them — the same wiring a real deployment
uses. It runs the request-boundary-safety matrix against SQLite and Postgres
single-threaded, plus a cross-thread matrix. Environment knobs:

| Variable | Effect |
|----------|--------|
| `WORKER_DB_ADAPTER` | `sqlite` (default) or `pgsql` |
| `WORKER_THREAD_COUNT` | worker threads; `4` exercises cross-thread sharing |
| `WORKER_CACHE_DRIVER` | register an L2 pool at boot (`array`, `apcu`, `file`) |
| `WORKER_CACHE_MAX_ENTRIES` | `ArrayCache` bound, for the bounded-growth assertions |
| `PROPULSION_SKIP_INTEGRATION=1` | skip entirely (no Docker) |
| `WORKER_READY_TIMEOUT` | seconds to wait for a worker container to serve (default 60) |

Two operational notes, both learned the hard way:

- **Worker containers are published on host ports 18080+, chosen by the harness**,
  not by Docker. Letting Docker choose puts the mapping inside the kernel's
  ephemeral range, where it can collide with a port already in use; the mapping is
  then reported as created while nothing listens on it, and the profile fails with
  a message that looks like a worker-safety regression but is a host networking
  artefact. If a readiness failure ever recurs, the message now prints the probed
  URL, the last curl error and the container state — check those before suspecting
  the runtime.
- **Run `composer test:cleanup-containers` between repeated matrix runs.** Each run
  starts eight-plus containers; a killed run leaks them, and a leaked Postgres
  container will block the *main* test suite's testcontainer afterwards, which
  surfaces as a large number of unrelated errors and skips.

The harness deliberately drives `Session`/`ServiceContainer`/`PropulsionPDO`
directly rather than generated model classes: the worker-safety contract lives
in those three, and standing up the code generator inside the image would add
moving parts without adding certainty.

**If you add request-scoped state, add a profile to the matrix that proves it
does not survive the boundary.** A `reset()` line with no test asserting it is
one refactor away from being dropped.

---

## Currently known-open worker-mode gaps

Recorded here so new code does not build on them. Cross-referenced with
`KNOWN_ISSUES.md`.

| Gap | Consequence |
|-----|-------------|
| `Propulsion::initialize()` resets only `$connectionMap` | `$adapterMap`, `$dbMaps` and the memoised `$defaultDBName` survive a `setConfiguration()`, so reconfiguring a live process keeps the old default datasource and adapters |
| L1 `QueryResultCache` is bounded by entry count, not bytes | 500 wide result sets is still a lot of memory; the same caveat `ArrayCache` carries (R7) |
| `Propulsion.php` eagerly `class_alias()`es 102 legacy names at load | ~3.2 MB and 176 classes in every worker process (R7). Skippable with `PROPULSION_SKIP_LEGACY_CLASS_ALIASES` — see below — but still on by default |
| Dropped-connection detection only covers `exec()`/`query()`/`DebugPDOStatement::execute()` | most dropped connections surface as a plain `PDOException` with no pool eviction |

Closed since this document was written, kept here as worked examples of the
rules above:

| Was | Fixed by |
|-----|----------|
| `Propulsion::$instancePoolingEnabled` doubled as both the explicit switch and a transient suspension, so an abandoned on-demand iteration left pooling disabled for later requests, and overlapping iterations re-enabled it underneath each other (R1, R6) | the transient case became `Session::$instancePoolingSuspendCount` — a counter, request-scoped, cleared by `reset()`; the explicit switch stayed process-scoped as configuration |
| `PropulsionCollection::getIterator()` memoised its iterator strongly, forming a cycle per iterated collection; `clearIterator()` was called by nothing (R8) | `getIterator()` now remembers it via `WeakReference` (a running `foreach` holds it strongly for the loop, so the cursor helpers still work); the strong cursor is only built by `getInternalIterator()` |
| `PropulsionPDO::$preparedStatements` had no bound (R5, R7) | bounded by `MAX_CACHED_PREPARED_STATEMENTS`, oldest-first eviction |
| `TableVersionRegistry::discardPending()` unset `$memo` unconditionally, so one connection's rollback dropped another's live override (R3) | only drops an override the rolling-back connection still owns |
| `DatabaseMap::hasTable()` truncated at the first dot, disagreeing with `getTable()` for schema-qualified tables | exact name first, historic first-segment behaviour kept as a fallback |
| `TieredQueryCache` materialised the full row set on every shared-tier miss, even though `min_sightings` (default 2) rejects the first execution of every query and every never-repeating one (R7) | admission is decided *before* the query runs; a rejection streams straight to the formatter. Entries already stored are exempt, so early refresh still works |
| L1 `QueryResultCache` was unbounded and keyed on raw SQL + `serialize($params)` (R7) | capped at `MAX_ENTRIES` with oldest-first eviction, keyed by an `xxh128` digest like L2 already was |
| `QueryResultCache::invalidateTable()` scanned every other table's key list per write | a reverse key-to-tables index makes eviction proportional to what is evicted |
| Connection `$queryCount` / `$lastExecutedQuery` reported process-lifetime values (R12) | `PropulsionPDO::resetDebugCounters()`, called from `Session::reset()` |
| The compiled-query cache was request-scoped, so a worker recompiled identical SELECT text on every request — defeating the one deployment it exists for | moved to `ServiceContainer`, bounded by `MAX_ENTRIES`, and cleared by `Propulsion::setConfiguration()` (a new configuration can mean a new adapter, hence different SQL) |

### Reclaiming the 3.2 MB the legacy aliases cost

Set `PROPULSION_SKIP_LEGACY_CLASS_ALIASES` before Propulsion is loaded:

```php
define('PROPULSION_SKIP_LEGACY_CLASS_ALIASES', true);
```

Measured effect: **176 loaded classes/interfaces down to 1, 3,208 KB down to
115 KB**, per process.

Safe when nothing references the bare historic names (`Criteria`,
`PropulsionPDO`, `PropelException`, …):

- **Namespaced schemas** — generated code has always imported the runtime classes
  properly, so it never needed the aliases.
- **Flat schemas** — newly generated code imports them too, as of the
  `OMBuilder::getUseStatements()` rework. Regenerate first.
- **Your own code** is the part to check. Grep for the bare names, remembering
  that `catch`/`instanceof`/`is_a()`/type checks are where a missing alias hurts.

Note this is *not* a PSR-4/autoloading concern. Composer already autoloads
`Propulsion\…` via PSR-4; the alias table exists to create *global* symbols for
bare-name references, which no autoloader configuration can substitute for — see
the table below for why.

### Why the eager legacy-alias block cannot be made lazy

Worth recording, because "just defer those 102 `class_alias()` calls behind an
autoloader" is the obvious idea and it does not work.

PHP only consults the autoloader for *some* references to a missing class.
Measured on PHP 8.5:

| Context | Autoloads? | Failure mode without the alias |
|---|---|---|
| `new Foo` / `Foo::bar()` / `class_exists('Foo')` | yes | — |
| `catch (Foo $e)` | **no** | the catch silently does not match |
| `$x instanceof Foo` | **no** | silently `false` |
| `is_a($x, 'Foo')` | **no** | silently `false` |
| parameter/return type check against `Foo` | **no** | `TypeError` |

Generated model code is emitted unnamespaced and uses the bare names in exactly
those non-autoloading contexts — 17 such sites in a single generated Peer. So a
lazily-aliased name would be silently absent precisely where it matters, and the
failures would be wrong answers (`false`, an unmatched catch) rather than
errors. An opt-out flag has the same problem: it would be unsafe for anyone using
generated models, which is everyone.

Two things would actually address it, neither of them a local change:

- **opcache preloading.** Preloaded classes live in shared memory instead of
  being materialised per process, which is the standard way to stop this cost
  scaling with worker count. Worth measuring for your deployment.
- **Emitting namespaced references from the generator**, after which the legacy
  map becomes genuinely optional rather than load-bearing. That requires every
  consumer to regenerate, so it is a release-scale decision, not a cleanup.
