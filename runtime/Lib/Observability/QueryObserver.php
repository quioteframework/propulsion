<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

/**
 * Notified around every statement Propulsion executes -- the interception seam
 * SQLAlchemy spells as events, Doctrine as middleware and EF Core as
 * interceptors.
 *
 * Two methods rather than one after-the-fact callback, because the shape a
 * tracer needs is a *span*: OpenTelemetry wants the span opened before the
 * statement runs, so that anything the driver itself does (and anything the
 * server logs) falls inside it, and closed after. A single
 * "query finished, here is the duration" callback cannot express that, while
 * an observer that only cares about the outcome can leave
 * {@see queryStarted()} empty.
 *
 * **An observer must not throw.** One that does is caught, logged and skipped
 * by {@see QueryObservers}: telemetry breaking the query it is measuring is a
 * strictly worse outcome than losing the telemetry, and an exception thrown
 * from `queryFinished()` would additionally replace whatever the statement
 * itself was reporting.
 *
 * **Observers see every statement on a non-persistent connection**, including
 * the ORM's own bookkeeping (`SELECT 1` liveness pings, savepoint statements).
 * Filter in the observer if that is noise for your backend. Persistent
 * connections are the exception and see nothing from prepared-statement
 * execution, for the same reason dropped-connection detection does not work
 * there -- PDO refuses a custom statement class under `ATTR_PERSISTENT`; see
 * {@see \Propulsion\Connection\PropulsionStatement}.
 */
interface QueryObserver
{
	/**
	 * Called immediately before the statement is sent to the server.
	 *
	 * The same {@see QueryExecution} instance is passed to
	 * {@see queryFinished()}, so state that has to span the call (an open
	 * tracing span) belongs on it via `setAttribute()`.
	 */
	public function queryStarted(QueryExecution $execution): void;

	/**
	 * Called after the statement returns, whether it succeeded or threw --
	 * `$execution->isFailed()` says which, and the exception is rethrown to the
	 * caller either way.
	 */
	public function queryFinished(QueryExecution $execution): void;
}
