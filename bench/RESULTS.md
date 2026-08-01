# Generated-code performance: baseline & results

## What this measures

`hydration_bench.php` builds a two-table model (`bench_book` → `bench_author`) as
**file-based generated classes** (the way a real project runs — not QuickBuilder's
`eval()` path), seeds in-memory SQLite, and times the hot paths the code generators
emit. `correctness_check.php` verifies behaviour is unchanged (35 assertions).

Reproduce (production-representative: JIT on, profiler off):

```
php -dpcov.enabled=0 -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M bench/hydration_bench.php 5000 25
php -dpcov.enabled=0 bench/correctness_check.php
```

`pcov` is installed in this repo and blocks JIT; disabling it (`-dpcov.enabled=0`)
plus the JIT flags is required for representative numbers.

## Scenarios

| ID | Path | Generated code exercised |
|----|------|--------------------------|
| A | `doSelect()`, pooling on, cold pool | `populateObjects()` + `hydrate()` + pool insert |
| B | `doSelect()`, pooling off | `populateObjects()` + `hydrate()` |
| C | `doSelect()`, pooling on, warm pool | `populateObjects()` pool-hit path |
| D | `doSelectJoinBenchAuthor()`, cold | joined hydration (obj1 + obj2 + relationship) |
| E | `save()` insert | write path / `doSave()` / `buildCriteria()` |
| F | setter churn + `buildCriteria()` | modified-column tracking |

## Results (ns/op, median of runs; lower is better; PHP 8.5.8, JIT on)

| Scenario | Before | After | Change |
|----------|-------:|------:|-------:|
| A read+hydrate, pool on, cold | 2460 | 2282 | **−7%** |
| B read+hydrate, pool off | 2290 | 2148 | **−6%** |
| C read, pool on, warm (hits) | 763 | 661 | **−13%** |
| D doSelectJoin, cold | 4330 | 1117 | **−74% (≈3.9× faster)** |
| E save() insert | 16300 | 16401 | ~flat |
| F setter churn + buildCriteria | 1855 | 1769 | **−5%** |

Verified reproducible across eval- and file-based classes and with JIT on/off;
correctness_check.php passes identically before and after.

## Changes that produced this

1. **Instance-pool state hoisted out of the per-row loop** in `populateObjects()`
   and all three `doSelectJoin*()` methods (`PeerBuilder`). The old generated code
   called `Peer::getInstanceFromPool()` / `Peer::addInstanceToPool()` per row, and
   each of those makes 3–4 *nested* static calls (`Propulsion::isInstancePoolingEnabled()`
   + `Propulsion::getSession()` + the pool op). The loop now resolves the pooling flag
   and session once and calls the session directly. The join path invokes that chain
   for two peer classes per row, so it benefited most (scenario D).

2. **Modified-column tracking as a set** (`col => true`) instead of a plain list
   (`BaseObject` + generated setters). `isColumnModified()` is now O(1) `isset()`
   instead of `in_array()`, `getModifiedColumns()` is `array_keys()` instead of
   `array_unique()`, and repeated setter calls no longer accumulate duplicates.

3. **`hydrate()` reads each result cell once** into a local instead of indexing
   `$row` (with `$startcol + N` offset arithmetic) two or three times per column.

4. **`addSelectColumns()` emits one bulk `Criteria::addSelectColumns([...])` call**
   instead of one `addSelectColumn()` method call per column, per query.

## Query result cache (`query_cache_bench.php`)

Measures `Criteria::setQueryCache()` (`runtime/Lib/Cache/QueryResultCache.php`):
re-issuing the *same* query repeatedly (a loop, or several call sites in one
request) with the cache off vs on, through both call styles it supports
(`ModelCriteria::find()`/`count()`, and the generated Peer's `doSelect()`).
Reproduce:

```
php -dpcov.enabled=0 -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M bench/query_cache_bench.php 3000 200
```

Results (rows=3000, 200 repeats of the identical query per scenario, PHP 8.5.8, JIT on):

| Scenario | Cache off | Cache on | Speedup |
|----------|----------:|---------:|--------:|
| `ModelCriteria::find()` | 618 ops/s | 86,027 ops/s | **≈139×** |
| `ModelCriteria::count()` | 9,961 ops/s | 306,686 ops/s | **≈31×** |
| `FooPeer::doSelect()` | 277 ops/s | 50,057 ops/s | **≈181×** |

Expected shape: every scenario's "cache off" cost is dominated by the real SQLite
round trip (prepare/bind/execute/fetch), paid on *every* call; "cache on" pays
that cost exactly once (the warmup call the `bench()` helper already does before
timing) and then serves every subsequent call from an in-memory array keyed by
SQL+params -- see `Criteria::getQueryCacheKey()`. The win scales with how much
work the real query does (`count()`'s single-row fetch benefits far less than
`find()`'s N-row hydration or raw `doSelect()`'s full object population), and
disappears entirely for a workload that never repeats a query -- this cache
does not help a request that issues N distinct queries once each, only
in-request or in-process repetition of the same one.

## Global (L2) query result cache

`global_query_cache_bench.php` measures the *process-shared* tier (the
`cache.query` config section, `Propulsion\Cache\Driver\*`) against the
request-scoped tier and against no cache at all. Every L2 row calls
`Session::reset()` before each iteration -- what a worker host does at a request
boundary -- so these are genuine cross-request hits, not L1 hits in disguise. A
`reset()`-only control column is reported so L2 can be read net of it.

Reproduce:

```
php -dpcov.enabled=0 -dopcache.enable_cli=1 -dopcache.jit=tracing \
    -dopcache.jit_buffer_size=64M -dapc.enable_cli=1 \
    bench/global_query_cache_bench.php 2000 100
```

Results (2000 rows, 50-row result set, 100 repeats, PHP 8.5.8, JIT on;
median ns/op, lower is better):

| Scenario | ns/op | net of reset() | vs uncached |
|----------|------:|---------------:|------------:|
| U  uncached `find()` | 88,883 | — | 1.0× |
| L1 request-scoped hit | 6,185 | — | **14.4×** |
| C  `Session::reset()` control | 798 | — | — |

| Per driver | ns/op | net of C | vs uncached |
|------------|------:|---------:|------------:|
| L2 hit, `array` | 45,489 | 44,691 | **2.0×** |
| L2 hit, `apcu` | 48,375 | 47,577 | **1.9×** |
| L2 hit, `file` | 54,359 | 53,561 | **1.7×** |
| L2 hit, joined (3 version tokens), `array` | 56,154 | 55,356 | — |
| L2 hit, joined, `apcu` | 54,658 | 53,860 | — |
| L2 hit, joined, `file` | 69,520 | 68,722 | — |
| L2 miss + store, `array` | 80,890 | 80,092 | — |
| L2 miss + store, `apcu` | 82,685 | 81,887 | — |
| L2 miss + store, `file` | 769,028 | 768,230 | — |
| INV table version bump, `array` | 1,895 | — | — |
| INV table version bump, `apcu` | 1,496 | — | — |
| INV table version bump, `file` | 97,599 | — | — |

### Reading these numbers honestly

**The 1.7–2.0× figures badly understate the real-world win, and the reason
matters.** The baseline here is in-memory SQLite, where the whole "database
round trip" is ~89µs. An L2 hit skips that round trip but still pays full
hydration of all 50 rows, which at roughly 0.8-1µs/row is most of the ~45µs it
costs. So this benchmark is essentially measuring *hydration against a
free database*.

Against a real networked database the arithmetic changes completely: a Postgres
round trip for the same query is typically 0.5-2ms, none of which an L2 hit
pays, while the hydration cost stays at ~45µs. That is a 10-40× win, not 2×.
Conversely, if your database is genuinely as fast as in-memory SQLite, the
shared tier is not worth configuring.

The three drivers land within ~20% of each other on the hit path, so **driver
choice should be made on sharing semantics, not speed** (see `docs/CACHING.md`):
`array` is per-process and, under a threaded worker, per-*thread*; `apcu` is
per-host and invisible to CLI; `file` is the only one a cron job shares with the
web tier. The file driver's disadvantage shows up not on reads but on **writes**
-- 769µs to store an entry and 98µs to bump a table version, roughly 8× and 65×
the in-memory drivers, because each is an atomic temp-file-plus-rename. A
write-heavy workload feels that; a read-heavy one does not.

**L1 remains ~7× faster than L2** (6.2µs vs 45µs) because it returns the
already-formatted collection and skips hydration entirely. The tiers are
complementary, not alternatives: L1 catches repetition within a request, L2
catches it across requests and processes.

The joined-query rows exist to show version-token read amplification: an N-table
query costs 1 + N backend reads per hit. It is visible on `file` (+15µs for two
extra tables) and in the noise on the in-memory drivers.

As with the request-scoped tier, all of this disappears for a workload that
never repeats a query -- and with admission control on by default, a query seen
only once is never even stored.
