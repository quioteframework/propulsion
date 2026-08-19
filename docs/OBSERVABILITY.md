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
| `->boundParams` | values bound via `bindValue()` or `execute($params)`, keyed the way PDO does (position or `:name`); always empty for `exec()`/`query()` |
| `->correlationId` | whatever `Propulsion::getCorrelationId()` returned when this execution began, or `null` |
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

Deliberately a collector and not a metrics client. StatsD and Prometheus both
have mature PHP clients; reimplementing one here would be maintenance with no
upside — the same reasoning that keeps a Redis driver out of the query cache.
**Reset it per request** under a persistent worker: observers are
process-scoped, so an un-reset collector reports totals since the worker
booted.

## OpenTelemetry

`Propulsion\Observability\OpenTelemetryQueryObserver` is a span-per-statement
observer. Unlike the previous two observers, this one can be turned on
entirely from configuration — no application code required:

```php
return [
    'datasources' => [ /* ... */ ],
    'telemetry' => [
        'enabled'      => true,
        'service_name' => 'my-app',                       // resource attribute
        'exporter' => [
            'endpoint' => 'http://otel-collector:4318/v1/traces',
            'protocol' => 'http/protobuf',                 // or 'http/json'
            'headers'  => ['authorization' => 'Bearer ...'],
            'timeout'  => 10,                               // seconds
        ],
        'sampler' => ['ratio' => 1.0],                     // TraceIdRatioBased
        'record_statement_text' => true,                   // see the PII note below
    ],
];
```

That's the whole setup. `Propulsion::setConfiguration()` registers the
observer immediately; the OTLP/HTTP exporter, batch processor and PSR-18 HTTP
client behind it are only actually built the first time a statement runs, so
a process that enables telemetry but never queries anything never pays for
any of it.

**Install the optional packages first** — `open-telemetry/api`,
`open-telemetry/sdk`, `open-telemetry/exporter-otlp` and
`open-telemetry/sem-conv` are not hard dependencies of Propulsion (see
"Why this is not a hard dependency" below):

```
composer require open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp open-telemetry/sem-conv
```

You also need *some* PSR-18 HTTP client installed — Propulsion resolves one
via `php-http/discovery` rather than pinning a concrete implementation for
every consumer:

```
composer require guzzlehttp/guzzle
```

(any PSR-18 provider works — `symfony/http-client`, `php-http/curl-client`,
etc. — if your application already has one installed via some other
dependency, nothing further is needed). If `telemetry.enabled` is true and
either the OpenTelemetry packages or a PSR-18 client are missing, the first
query throws a `PropulsionException` naming exactly what to `composer
require` — a config flag that silently ships nothing would be a worse failure
than an exception pointing at the fix.

### Spans

One CLIENT-kind span per statement, named after its leading SQL verb
(`SELECT`, `INSERT`, ...). Attributes come from `open-telemetry/sem-conv`'s
own constants rather than hand-typed strings, so a semantic-conventions
version bump — not a silent drift — is what changes them:

| Attribute | |
|---|---|
| `db.system.name` | mapped from the connection's `PDO::ATTR_DRIVER_NAME` (`mysql`, `postgresql`, `sqlite`, `oracle.db`, `microsoft.sql_server`) |
| `db.query.text` | the statement text, as sent — gated by `record_statement_text` |
| `db.response.returned_rows` | when `QueryExecution::getRowCount()` is not null (never set for a SELECT — see "What an execution carries" above) |

A failed statement gets `recordException()`, an `error.type` attribute, and
an `Error` span status; the span still ends either way, and the exception
still reaches the caller unchanged, exactly like every other observer here.

**PII note on `record_statement_text`.** Most Propulsion traffic is a
prepared statement with bound placeholders, so the text rarely carries a
literal value. `exec()`/`query()` traffic (the ORM's own bookkeeping, plus
any raw SQL your application runs that way) can carry literals, though — turn
this off if that is a compliance concern for those paths.

**Uses the *incubating* `db.system.name` value set, not the stable one.** The
stable subset only names four database systems (MySQL, MariaDB, PostgreSQL,
SQL Server), which would silently drop SQLite and Oracle — two of the five
platforms this project's own test matrix covers. The incubating set is the
actual, current spec surface for the rest.

### Bringing your own tracer provider

Register an already-built `TracerProviderInterface` — your own, or one
shared with other instrumentation — bypassing `telemetry` configuration
entirely:

```php
Propulsion::setTelemetryTracerProvider($tracerProvider);
```

Takes effect immediately, independent of `telemetry.enabled`, and always wins
over a configuration-built provider. This is also the escape hatch for a
custom PSR-18 client (`Propulsion::setTelemetryHttpClient()`, consulted only
by the configuration-driven path) or for wiring the observer up entirely by
hand the way earlier versions of this doc showed:

```php
use Propulsion\Observability\OpenTelemetryQueryObserver;

Propulsion::addQueryObserver(new OpenTelemetryQueryObserver(
    fn () => $tracerProvider->getTracer('my-app'),
));
```

**Neither survives `Propulsion::setConfiguration()`** — same contract
`Propulsion::setQueryCachePool()` already has. Call
`setTelemetryTracerProvider()`/`setTelemetryHttpClient()` again afterward if
you still want them.

### Flushing under a worker runtime

`Propulsion::flushTelemetry()` force-flushes whatever spans have buffered,
without waiting for the exporter's batch timer. The factory registers a
`register_shutdown_function()` that does this automatically, which is the
right granularity under ordinary PHP-FPM/CLI (a shutdown function runs at the
end of every request there) — but under a true async worker runtime
(FrankenPHP worker mode), the whole worker script is one long-lived process,
so that shutdown function only fires once, at worker exit, not per served
request. Call `flushTelemetry()` yourself at the request boundary there — the
same "reset per request yourself under a worker" contract
`QueryStatsObserver::reset()` already documents above.

### Why this is not a hard dependency

`open-telemetry/api` — what the observer itself binds to — is interfaces and
no-op fallbacks, negligible weight. The heavier packages
(`open-telemetry/sdk`, `open-telemetry/exporter-otlp`) stay optional
(`require-dev` plus `composer.json`'s `suggest` block) so that an application
that never sets `telemetry.enabled` never needs them installed — nothing in
this codebase even references those classes until the first query actually
runs with telemetry active.

### Not a replacement for `opentelemetry-auto-pdo`

`open-telemetry/opentelemetry-auto-pdo`, built on the `opentelemetry` native
PHP extension's engine-level method hooking (the same mechanism Datadog's
`ddtrace` and New Relic's agent use), can already wrap raw `PDO`/`PDOStatement`
calls with zero code changes — including on **persistent connections**, which
this observer structurally cannot reach (PDO refuses a custom statement class
under `PDO::ATTR_PERSISTENT`; see "Things to know" below). If you already run
that (or a similar APM agent), you likely have DB spans today.

What it cannot give you: it only sees raw SQL text and PDO-level facts, with
no notion of `QueryExecution::source` (so no way to separate the ORM's own
bookkeeping from real application queries) and no ORM-level context. It also
requires installing a native extension, which some hosts do not allow —
`OpenTelemetryQueryObserver` is pure PHP/Composer. The two are complementary,
not competing: run both if you want both persistent-connection coverage and
ORM-aware spans.

## Correlation id

`Propulsion::setCorrelationId(?string $id)` sets a request-scoped identifier
-- something a record/replay recorder, or a log aggregator, can use to group
every query one request issued. It lives on `Session`, the same
request-scoped bucket `forceMasterConnection` already lives in, and is
cleared by `Session::reset()`, so it never leaks onto a later request sharing
this worker process:

```php
Propulsion::setCorrelationId($request->getHeaderLine('X-Request-Id'));
// ... handle the request ...
Propulsion::getSession()->reset();   // clears it, along with everything else request-scoped
```

Every `QueryExecution` built while it is set carries it on `->correlationId`,
so an observer sees which request a query belongs to without needing its own
request-scoped storage. That matters specifically because
**`QueryObservers` itself is process-scoped, not request-scoped**: adding and
removing an observer per request would be reading and writing process-wide
state from what should be request-scoped code, which is unsafe under a
threaded worker runtime -- FrankenPHP with `worker <n>` above 1 shares
process statics across every thread with no per-thread isolation (see
`docs/WORKER_MODE.md`, R10). Register one observer at boot and let it read
`->correlationId` off each execution instead.

## Bound parameters and captured rows

Two more things a record/replay recorder needs that a tracer or a stats
collector does not: what values a statement actually ran with, and what came
back.

**Bound parameters** are on `->boundParams`, an array of `BoundParameter`
(`->value`, `->table`, `->column`) keyed the way PDO keys placeholders
(1-based position, or `:name`). Captured both from `bindValue()` calls --
every runtime call site (`DBAdapter::bindValues()`/`bindValue()`) binds
individually and then calls `execute()` with no arguments -- and from a
value passed through `execute($params)`'s own argument instead, which no
runtime call site actually uses but is captured all the same, with `->table`/
`->column` left `null` (that path carries no column identity to attach).
`->table`/`->column` are otherwise populated whenever `DBAdapter::bindValues()` itself
knew them (which is essentially always for ORM traffic — `BasePeer::buildParams()`
for `INSERT`/`UPDATE` and `Criterion` for a `SELECT`'s `WHERE` clause both
carry table/column all the way through) and `null` when it didn't (a value
bound directly via `bindValue()` from outside that path, e.g. `Propulsion::rawQuery()`
or hand-written PDO code). **`bindParam()` (by-reference binding) is not
captured at all** -- nothing in this codebase's own generated/runtime SQL
uses it, and capturing it correctly would mean reading the referenced
variable at `execute()` time rather than bind time. Raw/manual PDO code that
binds by reference will not have its values reported.

**Returned rows** need an observer to opt in, because of a timing problem
querying alone can't solve: for `find()`/`findOne()`, rows are fetched by the
*caller*, after `PropulsionStatement::execute()` has already returned --
`queryFinished()` fires strictly before any row exists to report. So this is
a separate, later signal:

```php
use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\RowCapturingQueryObserver;

final class CassetteObserver implements RowCapturingQueryObserver
{
    public function queryStarted(QueryExecution $execution): void
    {
        $execution->requestRowCapture(100);   // ask, or rowsCaptured() never fires
    }

    public function queryFinished(QueryExecution $execution): void
    {
    }

    public function rowsCaptured(QueryExecution $execution): void
    {
        // Each BoundParameter carries ->value plus, when known, ->table/->column
        // -- e.g. {":p1": {value: "a@example.com", table: "customer", column: "EMAIL"}}.
        $cassette->record(
            $execution->sql,
            $execution->boundParams,
            $execution->getCapturedRows(),
            $execution->getColumnNames(),
            $execution->isRowsTruncated(),
        );
    }
}
```

- **Ask first, in `queryStarted()`.** An observer that never calls
  `requestRowCapture()` never gets `rowsCaptured()` called, and costs nothing
  extra on the `fetch()` hot path either — the same "free when unused" shape
  every observer here already has.
- **Capped, by default at 100 rows.** More than one observer can ask; the
  largest request wins. Past the cap, `isRowsTruncated()` is true and the
  rows past it are simply not there — there is no partial/best-effort count,
  since a recorder generally only needs to know its capture is incomplete,
  not by how much.
- **Fires once**, after the result set is exhausted or closed — whichever
  happens first among the cursor naturally running out, an explicit
  `closeCursor()`, the statement being re-`execute()`'d without having been
  exhausted, or (belt-and-braces, same shape `PropulsionOnDemandIterator`
  already uses) the statement's own destructor.
- **Column names** are attached once, only for a list-shaped row
  (`PDO::FETCH_NUM`, what the ORM's own default formatter uses) -- an
  associative or object row already carries its own names, so nothing extra
  is captured there.
- **Rows are handed over exactly as fetched** — a numeric array for the
  default formatter, but an object, an associative array, or a scalar for a
  caller using a different fetch mode. **No redaction happens here.** A
  cassette that needs to scrub PII/secrets out of bound values or returned
  rows (a `shipping_address`, a token) does that on the consuming side, with
  whatever discipline it already applies elsewhere — Propulsion's job is
  only to expose what actually happened.
- `rowsCaptured()` says nothing about duration or success/failure. Keep using
  `queryFinished()` for that.

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
