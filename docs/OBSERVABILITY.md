# Observability

What Propulsion will tell you about the queries it runs, and how to get it
somewhere useful.

There are two mechanisms and they are for different jobs. **Query logging**
(`useDebug`) logs every statement and is a development tool — you cannot leave
it on. **Query observers** are notified around every statement and decide for
themselves what is worth reporting, which is what you leave on in production.

## Observers

An observer is notified twice per statement: before it is sent, and after it
comes back.

```php
use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\QueryObserver;

final class TracingObserver implements QueryObserver
{
    public function queryStarted(QueryExecution $execution): void
    {
        $execution->setAttribute('my.span', $this->tracer->startSpan('db.query'));
    }

    public function queryFinished(QueryExecution $execution): void
    {
        $span = $execution->getAttribute('my.span');
        $span->setAttribute('db.statement', $execution->sql);
        $span->setAttribute('db.duration_ms', $execution->getDurationMilliseconds());
        if ($execution->isFailed()) {
            $span->recordException($execution->getError());
        }
        $span->end();
    }
}

Propulsion::addQueryObserver(new TracingObserver($tracer));
```

Two methods rather than one after-the-fact callback because the shape a tracer
needs is a *span*: it has to be open while the statement runs, so that whatever
the driver and the server do falls inside it. An observer that only cares about
the outcome leaves `queryStarted()` empty.

The same `QueryExecution` object is passed to both calls, so state that has to
span them — the open span above — goes on it via `setAttribute()`, rather than
into a correlation map keyed by something that would have to be invented.

### What an execution carries

| | |
|---|---|
| `->sql` | the statement text, as sent |
| `->source` | `statement` (a prepared statement — nearly all ORM traffic), `exec`, or `query` |
| `->connection` | the `PropulsionPDO` it ran on |
| `getDurationSeconds()` / `getDurationMilliseconds()` | monotonic (`hrtime`), so an NTP step cannot produce a negative duration |
| `getRowCount()` | rows affected, for statements that change rows; `null` for a SELECT |
| `isFailed()` / `getError()` | the exception, which is rethrown to the caller regardless |

`getRowCount()` is deliberately null for a SELECT: PDO documents `rowCount()`
as unreliable there, and on several drivers answering it means buffering the
whole result set — the measurement would change what it measures.

## Shipped observers

**`SlowQueryObserver`** — the slow-query threshold. Logs nothing until
something is actually slow:

```php
Propulsion::addQueryObserver(new SlowQueryObserver(0.1));               // PSR-3 warning at 100ms
Propulsion::addQueryObserver(new SlowQueryObserver(0.1, $handler));     // or handle it yourself
```

**`QueryStatsObserver`** — counts and totals for a request:

```php
$stats = new QueryStatsObserver();
Propulsion::addQueryObserver($stats);
// ... end of request ...
$metrics->timing('db.query.total_ms', $stats->getTotalMilliseconds());
$metrics->count('db.query.count', $stats->getCount());
$metrics->count('db.query.failed', $stats->getFailedCount());
```

Deliberately a collector and not a metrics client. StatsD, Prometheus and
OpenTelemetry all have mature PHP clients; reimplementing one here would be
maintenance with no upside — the same reasoning that keeps a Redis driver out
of the query cache. **Reset it per request** under a persistent worker:
observers are process-scoped, so an un-reset collector reports totals since the
worker booted.

## Things to know

- **An observer must not throw.** One that does is caught, logged at error
  level and skipped. Telemetry breaking the query it measures is strictly
  worse than losing the telemetry — and worse than it first looks: an
  exception out of `queryFinished()` would *replace* the exception the
  statement itself was reporting, turning a database error into a mystifying
  observer stack trace.
- **Observers are process-scoped** and survive `Session::reset()`. They are
  bootstrap wiring; a tracer that silently stopped tracing at the request
  boundary would be worse than one that was never installed, because nothing
  would say so.
- **Everything is observed, including bookkeeping.** Liveness pings
  (`SELECT 1`), savepoints and the ORM's own metadata queries all go through
  the same seam. Filter on `->sql` or `->source` in the observer if that is
  noise for your backend.
- **Registering the same instance twice is a no-op**, so a double-registered
  collector cannot double-count.
- **Persistent connections see nothing from prepared statements.** PDO refuses
  a custom statement class under `PDO::ATTR_PERSISTENT`, which is the same
  reason dropped-connection detection does not work there; see
  `docs/CONNECTIONS.md`. `exec()` and `query()` are still observed.
- **Cost when unused** is one array check per statement. No `QueryExecution` is
  constructed for an application with no observers registered.

## Where the hook sits, and why that matters

Instrumentation lives on `PropulsionStatement::execute()`, plus
`PropulsionPDO::exec()`/`query()`.

That first one is the load-bearing choice. Essentially all ORM traffic prepares
a statement and executes it — the generated Peer and `ModelCriteria` paths all
do — so an observability hook on `exec()`/`query()` alone would measure almost
nothing while looking like it worked. This codebase has made exactly that
mistake before: dropped-connection detection lived there for years and saw
almost no real traffic, which is why `PropulsionStatement` exists at all.
