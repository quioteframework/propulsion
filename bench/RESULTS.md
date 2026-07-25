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
