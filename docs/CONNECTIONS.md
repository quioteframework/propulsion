# Connection resilience

How Propulsion behaves when the database goes away or refuses to cooperate:
what it detects, what it recovers from on its own, and what it deliberately
leaves to you.

Everything here is **off by default** except the detection itself. The two
recovery features spend something real — a round trip, or running your closure
more than once — and neither can be switched on safely without a deployment
having thought about it.

All of it matters most in a **persistent-worker SAPI** (FrankenPHP worker mode
and friends), where a connection outlives the request that opened it and can be
reaped between requests by an idle timeout, a load balancer, a failover or a
restart. Under PHP-FPM the connection died with the request, so most of this has
nothing to catch. See `docs/WORKER_MODE.md`.

## 1. Dropped-connection detection

A connection whose server-side session has gone away is detected wherever a
statement runs, and evicted from the pool so the next `Propulsion::getConnection()`
opens a fresh one instead of handing out the corpse.

Detection lives in `PropulsionStatement::execute()` (plus `PropulsionPDO::exec()`
and `::query()` for the statement-less paths). `PropulsionStatement` is installed
as the statement class on every non-persistent connection Propulsion opens, which
is the point: essentially all ORM traffic prepares a statement and calls
`execute()` on it, and until this existed nothing wrapped that path — only
`DebugPDOStatement` did, and only when debugging was on. A dropped connection
therefore surfaced as an ordinary `PDOException`, the connection stayed pooled
with its transaction depth and prepared-statement cache intact, and every
subsequent caller handed that connection failed too.

What happens on detection (`PropulsionPDOTrait::handleDroppedConnection()`):

- the transaction depth counter is zeroed — whatever the server had open is gone
- buffered shared-query-cache version bumps are discarded, as on a rollback
- the prepared-statement cache is dropped
- the connection is evicted from the pool
- a PSR-3 warning is logged, if a logger is registered
- **the original exception is rethrown unchanged**

That last point is deliberate: this is a notification seam, not an error-handling
one. Callers already catching `PDOException` see exactly what they saw before.

**The statement is not retried, and cannot be.** PDO has no reconnect — a dropped
connection's object stays dropped for its whole lifetime — and retrying one level
up is not safe either, because losing the connection loses any open transaction
with it, so re-running a single statement on a fresh connection would execute it
outside the transaction the caller believes it is in. Recovery has to happen
where the transaction boundary is known; that is what `Propulsion::transaction()`
below is for.

**Persistent connections are the exception.** PDO refuses a custom statement class
when `PDO::ATTR_PERSISTENT` is set, so a deployment using persistent connections
keeps plain `PDOStatement` and keeps the original gap. Propulsion suppresses that
failure rather than refusing to connect.

## 2. Pre-checkout liveness check

```php
'connection' => [
    'liveness' => [
        'enabled'        => true,
        'idle_threshold' => 5.0,   // seconds; 0.0 pings every checkout
    ],
],
```

When enabled, `Propulsion::getConnection()` pings a pooled connection before
handing it out, and replaces it if the ping fails. The ping is the cheapest
statement that proves the server is still there — `SELECT 1`, or `SELECT 1 FROM
dual` on Oracle.

**Only genuinely idle connections are pinged.** A connection that ran a statement
within `idle_threshold` seconds is evidence of its own liveness, so under
sustained traffic this collapses to approximately zero extra round trips while
still covering the quiet period after which connections actually get reaped.

Choosing the threshold is a trade between the two ways it goes wrong: too low and
a busy process pays a round trip on checkouts where the connection demonstrably
worked moments ago; too high and the window in which a reaped connection still
looks fresh grows to match. The 5s default sits far below any idle timeout worth
worrying about (MySQL's `wait_timeout` is 8 hours; pgbouncer's
`server_idle_timeout` 10 minutes).

A connection is never pinged inside a transaction — the pool does not hand out
connections mid-transaction, and a stray statement inside somebody's transaction
is worse than a stale connection.

This does not make checkout infallible. It narrows the window between "we checked"
and "you used it"; it cannot close it. A connection can still die in that gap, in
which case detection above takes over.

## 3. Transaction retry

```php
'connection' => [
    'retry' => [
        'enabled'      => true,
        'max_attempts' => 3,      // total attempts, not retries
        'base_delay'   => 50,     // ms before the first retry
        'max_delay'    => 1000,   // ms ceiling
        'multiplier'   => 2.0,
        'jitter'       => 1.0,    // 1.0 = full jitter
    ],
],
```

```php
$book = Propulsion::transaction(function ($con) {
    $book = BookQuery::create()->filterByISBN($isbn)->findOne($con);
    $book->setStock($book->getStock() - 1);
    $book->save($con);
    return $book;
});
```

The transaction is begun, the closure is called with the connection, and the
transaction is committed if it returns or rolled back if it throws. The closure's
return value is the method's return value.

### What gets retried

Only failures the adapter classifies as transient (`DBAdapter::isRetryableError()`)
and connection drops:

| Platform | Retryable |
|---|---|
| PostgreSQL | `40001` serialization failure, `40P01` deadlock detected |
| MySQL / MariaDB | 1213 deadlock (`40001`), 1205 lock-wait timeout (`HY000`) |
| SQLite | 5 `SQLITE_BUSY`, 6 `SQLITE_LOCKED` |
| MSSQL | 1205 deadlock victim |
| Oracle | ORA-00060 deadlock, ORA-08177 cannot serialize |

These are the database working as designed — it aborts one transaction so another
can proceed — and the loser is supposed to respond by trying again.

Backoff is exponential with **full jitter**: the delay before retry *n* is a
uniform draw from `[0, min(base × multiplier^(n-1), max)]`, not that bound itself.
Un-jittered backoff is actively harmful here — a deadlock has at least two
transactions in it, and if both back off by the same computed delay they collide
again on the retry.

### What does not get retried

- **Anything the closure itself throws.** A business failure is deterministic;
  re-running it burns transactions to reach the same answer.
- **Non-transient database errors** — constraint violations, syntax errors.
- **A connection lost while the COMMIT was in flight.** This is the important
  one. The transaction's outcome is genuinely unknown: the server may have
  committed it and died before saying so, so re-running the closure could apply
  the work twice. Propulsion rethrows and leaves it to you, because you are the
  only one with the information to resolve it. A drop *before* the commit is
  issued is retried, because nothing was committed.
- **Nested calls.** If the connection is already in a transaction, the closure
  runs inside a nested one (a real `SAVEPOINT` where the platform has them) and is
  never retried — the failures being retried abort the *entire* transaction on
  most platforms, so the outer transaction is already dead and re-running the inner
  scope inside it would only fail again. Retrying has to happen at the outermost
  boundary.

### Your closure must be safe to run more than once

This is the price of retrying and it cannot be paid on your behalf. Database work
is undone by the rollback; anything the closure does *outside* the transaction is
not — sending mail, charging a card, incrementing a Redis counter, mutating
objects the caller kept a reference to. Keep side effects out of the closure, or
pass `RetryPolicy::none()` to opt one call out:

```php
Propulsion::transaction($work, dbName: null, policy: RetryPolicy::none());
```

A `RetryPolicy` passed explicitly always overrides the configuration.

## What is still not handled

- **Persistent connections** get no statement-level detection (see above).
- **The check-then-use window** in the liveness check cannot be closed, only
  narrowed.
- **`Propulsion::initialize()` only resets `$connectionMap`** — `$adapterMap`,
  `$dbMaps` and the memoised `$defaultDBName` survive a `setConfiguration()`.
  See `KNOWN_ISSUES.md`.
