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
 * Counts statements and adds up how long they took -- the query-timing metric
 * PLATFORM_FEATURES.md listed as missing, in the smallest form that is
 * actually useful: totals you can read at the end of a request and hand to
 * whatever metrics client you already have.
 *
 *     $stats = new QueryStatsObserver();
 *     Propulsion::addQueryObserver($stats);
 *     // ... end of request ...
 *     $metrics->timing('db.query.total_ms', $stats->getTotalMilliseconds());
 *     $metrics->count('db.query.count', $stats->getCount());
 *
 * Deliberately not a metrics *client*. Propulsion ships no Redis or Memcached
 * driver for the query cache for the same reason: StatsD, Prometheus and
 * OpenTelemetry all have mature PHP clients, and reimplementing one here would
 * be maintenance with no upside. This collects; you export.
 *
 * **Reset it per request** under a persistent worker ({@see reset()}) --
 * observers are process-scoped, so an un-reset collector reports the totals
 * since the worker booted rather than for the request you are measuring.
 */
final class QueryStatsObserver implements QueryObserver
{
	private int $count = 0;

	private int $failedCount = 0;

	private float $totalSeconds = 0.0;

	private float $slowestSeconds = 0.0;

	private ?string $slowestSql = null;

	/** @var array<string, int> */
	private array $countBySource = array();

	public function queryStarted(QueryExecution $execution): void
	{
	}

	public function queryFinished(QueryExecution $execution): void
	{
		$this->count++;
		$this->countBySource[$execution->source] = ($this->countBySource[$execution->source] ?? 0) + 1;
		if ($execution->isFailed()) {
			$this->failedCount++;
		}

		$duration = $execution->getDurationSeconds();
		if ($duration === null) {
			return;
		}

		$this->totalSeconds += $duration;
		if ($duration > $this->slowestSeconds) {
			$this->slowestSeconds = $duration;
			$this->slowestSql = $execution->sql;
		}
	}

	public function getCount(): int
	{
		return $this->count;
	}

	/** How many of those threw. A rate worth alerting on that a plain count hides. */
	public function getFailedCount(): int
	{
		return $this->failedCount;
	}

	/**
	 * Statement counts keyed by {@see QueryExecution}'s SOURCE_* constants --
	 * prepared-statement executions separated from exec()/query(), since the
	 * latter two are mostly the ORM's own bookkeeping.
	 *
	 * @return array<string, int>
	 */
	public function getCountBySource(): array
	{
		return $this->countBySource;
	}

	public function getTotalSeconds(): float
	{
		return $this->totalSeconds;
	}

	public function getTotalMilliseconds(): float
	{
		return $this->totalSeconds * 1000.0;
	}

	/** Zero when nothing has been recorded, rather than a division by zero. */
	public function getAverageSeconds(): float
	{
		return $this->count === 0 ? 0.0 : $this->totalSeconds / $this->count;
	}

	public function getSlowestSeconds(): float
	{
		return $this->slowestSeconds;
	}

	public function getSlowestSql(): ?string
	{
		return $this->slowestSql;
	}

	/**
	 * Zero everything. Call at the request boundary under a worker runtime;
	 * see the class docblock.
	 */
	public function reset(): void
	{
		$this->count = 0;
		$this->failedCount = 0;
		$this->totalSeconds = 0.0;
		$this->slowestSeconds = 0.0;
		$this->slowestSql = null;
		$this->countBySource = array();
	}
}
