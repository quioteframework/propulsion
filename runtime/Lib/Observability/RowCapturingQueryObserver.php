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
 * An optional capability a {@see QueryObserver} can add: seeing the rows a
 * statement actually returned, not just that it ran.
 *
 * Deliberately a separate interface extending {@see QueryObserver}, rather
 * than a new required method on it -- adding it here does not disturb
 * `SlowQueryObserver`, `QueryStatsObserver`, or `OpenTelemetryQueryObserver`,
 * none of which want rows and none of which now need a no-op method to stay
 * compilable.
 *
 * `rowsCaptured()` fires *after* {@see QueryObserver::queryFinished()} --
 * often long after, for a caller that fetches lazily -- once the statement's
 * cursor is exhausted or closed. This is unavoidable: for the ORM's own
 * `find()`/`findOne()`, rows are fetched by the caller once
 * `PropulsionStatement::execute()` has already returned, so there is nothing
 * to report yet at `queryFinished()` time. Observers that care about
 * duration or outcome (a tracer, a slow-query log) must keep using
 * `queryFinished()` for that -- `rowsCaptured()` says nothing about timing
 * or success/failure, only about what came back.
 *
 * An observer must ask first: {@see QueryExecution::requestRowCapture()},
 * called from `queryStarted()`. One that never asks never sees this method
 * called, and costs nothing extra on `PropulsionStatement::fetch()`'s hot
 * path either.
 */
interface RowCapturingQueryObserver extends QueryObserver
{
	/**
	 * Called once per statement that requested row capture, after its
	 * result set is exhausted or closed. Get the rows themselves from
	 * `$execution` -- {@see QueryExecution::getCapturedRows()},
	 * {@see QueryExecution::isRowsTruncated()},
	 * {@see QueryExecution::getColumnNames()} -- rather than as separate
	 * parameters here, the same shape `queryStarted()`/`queryFinished()`
	 * already use.
	 *
	 * **Must not throw**, for the same reason {@see QueryObserver} itself
	 * documents: {@see QueryObservers} catches and logs a throw here too,
	 * rather than letting it propagate.
	 */
	public function rowsCaptured(QueryExecution $execution): void;
}
