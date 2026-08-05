# Query result caching

Propulsion caches query results in two tiers.

| Tier | Lives in | Survives a request? | Shared with other processes? | Stores |
|------|----------|--------------------|------------------------------|--------|
| **L1** request-scoped | the `Session` | no | no | the formatted result |
| **L2** global | a PSR-16 pool | yes | depends on the driver | raw pre-hydration rows |

L1 catches a query repeated *within* one request — a lookup called from a loop,
or from several unrelated call sites. It has always existed and is unchanged.

L2 catches a query repeated *across* requests and, with the right driver, across
processes and hosts. It is what makes caching worthwhile in a worker-mode
deployment, where the process outlives the request.

Both are **off by default** and both are opt-in per query. An uncached read is
always correct; a cached one is a trade you have to make deliberately.

---

## Turning it on

Two independent gates, both required.

**1. Configure a backend** in the runtime configuration:

```php
return array(
  'datasources' => array( /* ... */ ),
  'cache' => array(
    'query' => array(
      'enabled'   => true,
      'driver'    => 'apcu',        // '' | null | array | apcu | file | psr16
      'ttl'       => 300,           // seconds; null = never expire (discouraged)
      'namespace' => 'myapp',       // key prefix, if several apps share a backend
    ),
  ),
);
```

**2. Opt the query in:**

```php
$books = BookQuery::create()->filterByPublished(true)->setQueryCache(true)->find();
```

Configuring a driver is not, by itself, consent to serve stale data — which is
why `enabled` defaults to false and why turning it on is a config-file change:
a deliberate, reviewable, deployment-level act.

Unknown keys in the `cache` section are rejected outright rather than ignored, so
a `'tll' => 300` typo fails at boot instead of silently running on defaults.

### Bringing your own pool

Propulsion ships **no Redis or Memcached client**. Both protocols already have
several mature PSR-16 implementations, and owning reconnection, cluster and
sentinel topologies, TLS and protocol versions to duplicate them would be pure
maintenance cost. Register any third-party pool instead:

```php
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

Propulsion::setQueryCachePool(new Psr16Cache(new RedisAdapter($redis)));
```

A pool registered this way always wins over `cache.query.driver`. Set
`driver: 'psr16'` to make that explicit in the config file.

---

## Choosing a driver

The three real drivers are within ~20% of each other on the hit path (see
`bench/RESULTS.md`), so **choose on sharing semantics, not speed.**

| Driver | Shared across | Survives restart | Notes |
|--------|---------------|------------------|-------|
| `array` | nothing | no | In-process only. Under a *threaded* worker it is per-thread. |
| `apcu` | all processes/threads on one host | no | Invisible to CLI. |
| `file` | everything on one host, including CLI | yes | Slow writes; needs pruning. |
| `psr16` | whatever your pool does | — | The answer for multi-node. |
| `null` / `''` | — | — | The "off" null object. |

### `array`

Fastest, and in a single-threaded long-lived worker it is a genuine
cross-request cache. Two limits, both silent:

- **Not cross-process.** Two php-fpm workers never see each other's writes — or,
  more dangerously, each other's *invalidations*.
- **Not cross-thread.** PHP memory belongs to the thread, so with FrankenPHP's
  `worker <n>` above 1 each thread accumulates its own entries. Measured
  directly by the `l2:array-cross-thread` profile in `test/worker/driver.php`:
  of 16 concurrently-written keys, only 7 were visible to the reading thread.

Always bounded by `max_entries` (default 1000, LRU). An unbounded array in a
process that never exits is an out-of-memory condition waiting for enough
distinct keys.

### `apcu`

The realistic zero-infrastructure choice for a single host: shared by every
php-fpm worker and every worker thread, with lookups in already-mapped shared
memory. Two things to know:

- A php-fpm restart discards the whole segment, so a deploy cold-starts the cache.
- **CLI processes do not share it.** `apc.enable_cli=1` gives each CLI invocation
  its *own* segment. A cron job therefore cannot see, warm, or — the dangerous
  one — *invalidate* the web tier's entries. **If anything writes to your
  database from CLI, do not use this driver.**

Capacity is `apc.shm_size`; APCu evicts under pressure on its own.

### `file`

**It is not "about as fast as APCu".** A warm-page-cache read is still
open/read/close — three or four syscalls, each paying a mode switch and a VFS
walk, under PHP's stream layer — against APCu's hash probe in mapped memory.
Expect roughly 3–10× slower on reads, and considerably worse on writes (769µs to
store an entry, 98µs to bump a table version, versus ~80µs and ~1.5µs for the
in-memory drivers), because every write is an atomic temp-file-plus-rename.

Ship it for what it uniquely does, not for speed:

1. **No infrastructure.** Works on shared hosting, in a bare container.
2. **Survives restarts**, unlike APCu.
3. **Shared across SAPIs** — the one thing APCu genuinely cannot do. A cron job
   writing to the database *can* invalidate the web tier's cached queries here.
   For applications with CLI writers this driver is more *correct*, not just
   slower.

```php
'file' => array(
    'directory' => '/var/cache/propulsion',
    'levels'    => 2,                       // shard depth; 65,536 leaf dirs
    'max_bytes' => 512 * 1024 * 1024,
),
```

Expired entries are unlinked lazily, when something asks for them. Entries that
expire and are never requested again occupy disk until you prune, so **schedule
`prune()`**:

```php
// bin/propulsion-cache-prune, from cron
$cache = Propulsion::queryCachePool();
if ($cache instanceof \Propulsion\Cache\Driver\FileCache) {
    $cache->prune();   // drops expired entries, then enforces max_bytes
}
```

Propulsion deliberately does *not* prune probabilistically inside requests: that
turns one unlucky user's request into a full-tree stat walk.

`clear()` refuses to run unless it finds the `.propulsion-cache` marker file it
wrote at construction — a misconfigured `directory` pointing at a document root
would otherwise turn a routine flush into data loss.

### Third-party pools (multi-node)

Propulsion cannot bound a pool it does not own. **Configure `maxmemory` and an
LRU policy on Redis yourself** (`maxmemory-policy allkeys-lru`); without it, a
diverse query workload will grow the keyspace until Redis refuses writes.

One capability is lost with a third-party pool: PSR-16 has no atomic
create-if-absent, so strict single-flight is impossible. Propulsion detects this
(`Propulsion\Cache\AtomicCache`) and falls back to probabilistic protection —
see below.

---

## Invalidation

Every table has a **version token** in the shared backend, and the cache key of a
query embeds the tokens of every table it reads. A write replaces that table's
token with a fresh random value, which makes every key derived from the old one
unreachable in a single write — no index to maintain, no scan, no cross-process
coordination.

Tokens are random rather than incrementing counters, deliberately. PSR-16 has no
atomic increment, so read-add-write would race: two writers both reading v=7 both
write v=8, and a result cached in between stays live and stale for its whole TTL.
A blind random write has no read step, so the race cannot occur. It also means
losing a token always fails toward a *miss* rather than toward staleness — an
evicted token is reseeded to a never-used value, whereas a counter reseeded to 1
would resurrect orphaned entries.

Writes through the ORM (`save()`, `delete()`, `ModelCriteria::update()`,
`BasePeer::doInsert()` and friends) bump the right tokens for you.

### What does *not* invalidate

- **Raw SQL writes**, another application, a migration, a DBA at a console.
- **APCu specifically**: writes from a CLI process, which has its own segment.

For the first, tell Propulsion:

```php
$con->exec('UPDATE book SET ... /* hand-written */');
Propulsion::invalidateQueryCacheForTables(['book']);
```

Otherwise those entries stay served until their TTL lapses. **TTL is the only
backstop against writes Propulsion cannot see** — which is why it defaults to a
finite 300 seconds rather than "never expire".

### Transactions

A write inside an open transaction buffers its token bump and publishes it on
commit; a rollback discards it. And **no query issued inside a transaction is
ever published to the shared tier**, because such a SELECT can see your own
uncommitted rows and publishing those to a cache other processes read would leak
them. In-transaction reads still use L1.

---

## Overload protection

Two different failure modes, two different mechanisms. They are often conflated;
they are not the same problem.

### Cache pollution (unbounded growth)

A query whose parameters never repeat — `WHERE id = <random>`, whether from a
diverse workload or a deliberate flood — produces a distinct key every time.
Every request misses, and if every miss also *wrote*, the cache would grow
without bound while never serving a hit. Stampede protection does nothing here:
there is no contention on any single key.

So an entry is admitted only once its key has been **seen twice** within a short
window, tracked by a tiny marker key. A never-repeating key never reaches a
second sighting and never stores anything; a genuinely repeated query is cached
from its second execution.

```php
'admission' => array(
    'min_sightings' => 2,   // 1 restores cache-on-first-miss for trusted workloads
    'window'        => 300, // marker TTL; defaults to ttl
),
```

The marker lookup is batched into the round trip that already fetches version
tokens, so it costs no extra latency.

### Stampede (concurrent misses on one key)

When a popular entry expires, every concurrent reader can pile onto the database
at once. Two defences:

- **Probabilistic early recomputation**, always on. As an entry nears expiry each
  reader independently may elect to refresh it slightly early, weighted by how
  expensive the query was. One reader refreshes while everyone else still gets a
  hit. Lock-free, no extra round trip, nothing to clean up if a process dies.
- **Single-flight**, where the backend supports atomic create-if-absent — all
  three first-party drivers do. The winner recomputes; losers serve the existing
  entry rather than blocking, because a cache outage must not become a latency
  outage.

```php
'stampede' => array(
    'beta'     => 1.0,  // 0.0 disables early recomputation
    'lock_ttl' => 5,
),
```

On a third-party PSR-16 pool only the probabilistic defence applies, so N truly
simultaneous cold misses on one key can still produce N queries.

---

## Caching hand-written SQL

Complex queries that are easier to write by hand than as a `Criteria` tend to be
the expensive ones — and so the best candidates for caching. They get the same
tiering, invalidation and overload protection:

```php
$books = Propulsion::rawQuery(
        'SELECT b.* FROM book b JOIN author a ON (...) WHERE ...',
        [$authorId]
    )
    ->dependsOn('book', 'author')
    ->cache(ttl: 300)
    ->hydrate(BookPeer::class);
```

Terminals: `rows()` (raw `FETCH_NUM` arrays), `one()`, `hydrate(PeerClass)`, and
`formatWith($formatter)`. Parameters may be positional (`?`) or named (`:name`).
`hydrate()` requires the SELECT to return that Peer's columns in its column
order — `SELECT b.*`, not a hand-picked list.

**You must declare the tables.** Propulsion will not parse the SQL to work them
out: a parser would be wrong about CTEs, views, aliases and subqueries, and a
cache that is *silently* wrong about invalidation is worse than one that insists
you say. `dependsOn()` validates each name against the DatabaseMap, so a typo
throws immediately rather than quietly never invalidating. Calling `cache()`
without `dependsOn()` throws for the same reason.

---

## Correctness caveats

- **Non-deterministic SQL.** A query built on `NOW()`, `CURRENT_DATE`, `RANDOM()`
  or a `LIMIT` over an unstable `ORDER BY` has stable SQL text but an unstable
  correct answer, so its first result freezes for the whole TTL — across every
  process, not just for the rest of one request. Use
  `setQueryCache(true, shared: false)` to keep such a query in the
  request-scoped tier. Propulsion does not try to detect these by scanning the
  SQL: that gives false positives on columns like `now_at` and false negatives on
  user-defined functions and views, and a confidently wrong detector is worse
  than none.
- **Authorization decisions.** Do not cache a query whose result gates access.
- **Object identity.** An L1 hit returns the *same* object instance, so mutating
  a cached `find()` result mutates the cache entry. An L2 hit returns a freshly
  hydrated graph and behaves exactly like a real database read, instance pool
  included.
- **The two tiers key differently, on purpose.** L1 folds the formatter into its
  key, because it stores the formatted result and an `ARRAY` and an `OBJECT`
  result are different values of different types. L2 does not, because it stores
  raw rows, which are the same whatever formats them — so an `ARRAY`-formatted
  and an `OBJECT`-formatted query with identical SQL share one L2 entry, and
  either can be served from the other's stored rows.
- **Subqueries and CTEs** *are* followed. `getQueryCacheTouchedTables()`
  descends into FROM-clause subqueries (`addSelectQuery()`), CTEs (`withCte()`),
  set-operation branches (`union()`/`intersect()`/`except()`) and
  `EXISTS`/`IN` filters (`addExistsQuery()`/`addInQuery()`), so a table read only
  from inside one is still a dependency. What is *not* followed is SQL
  Propulsion never parsed: a table named inside a raw string clause reaches the
  query as opaque text. `withColumn()` expressions are scanned for
  `table.column` references and so are covered; a bare `where('...')` predicate
  naming an otherwise-unmentioned table is not. Declare those with `rawQuery()`
  and `dependsOn()`, or leave them uncached.
- **Read-your-own-writes** is guaranteed within a process. Across processes it is
  bounded by commit-to-publish latency plus the per-request token memo: a bump
  published by another process midway through your request is not observed until
  the next one.
- **BLOB columns** fetched as stream resources cannot be serialized; such queries
  silently skip the shared tier and use L1 only. Every row of the result set is
  checked for one, not just the first — `serialize()` does not fail on a
  resource, it quietly writes `i:0`, so a partial check would publish an entry
  with blob columns turned into the integer `0`.
- **The `file` driver does not deserialize objects.** Its directory is writable
  by every process (and possibly every application) sharing it, which makes its
  contents untrusted input, so `unserialize()` runs with
  `allowed_classes: false` and a class payload comes back as
  `__PHP_Incomplete_Class` rather than being instantiated. Nothing Propulsion
  stores through it is an object; this only narrows using it as a
  general-purpose PSR-16 pool for your own objects.

---

## Operations

**Turning it off in an incident** is one config line — `enabled => false` — and
needs no code change or redeploy of application logic.

**Monitoring.** A cache that never hits is pure overhead; a cache that hits
constantly on stale data is worse. Instrument at the pool: wrap your PSR-16
implementation in a decorator that counts hits and misses.

**Worker mode.** The pool is process-scoped and deliberately survives
`Session::reset()`; the request-scoped tier does not. `Session::reset()` never
calls `clear()` on the backend — doing so would flush every other process's cache
at the end of every request. The FrankenPHP harness in `test/worker/` asserts
both halves of that contrast on every run.

**Sizing.** APCu: `apc.shm_size`. File: `max_bytes` plus a pruning cron. Array:
`max_entries`. Redis: `maxmemory` and `allkeys-lru`, which only you can set.
