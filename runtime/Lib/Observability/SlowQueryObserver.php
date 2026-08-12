<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

use Propulsion\Propulsion;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Reports any statement that takes longer than a threshold -- the
 * slow-query-threshold callback PLATFORM_FEATURES.md listed as missing,
 * shipped as an observer rather than as a special case in the connection.
 *
 *     Propulsion::addQueryObserver(new SlowQueryObserver(0.1));            // log at 100ms
 *     Propulsion::addQueryObserver(new SlowQueryObserver(0.1, $callback)); // or handle it yourself
 *
 * Why this is not simply "turn on query logging". The PSR-3 logging that
 * already exists (`useDebug`) logs *every* statement and is a development
 * tool; you cannot leave it on in production. This logs nothing until
 * something is actually slow, which is the thing you want on all the time.
 */
final class SlowQueryObserver implements QueryObserver
{
	/**
	 * @param     float    $thresholdSeconds Statements at or above this duration are
	 *                                       reported. Seconds-as-float, matching
	 *                                       QueryExecution::getDurationSeconds().
	 * @param     ?callable(QueryExecution): mixed $onSlowQuery What to do with one. Its return
	 *                                       value is ignored -- `mixed`, not `void`, so a
	 *                                       one-expression arrow function is accepted whatever
	 *                                       the call it wraps returns.
	 *                                       Defaults to logging through Propulsion's
	 *                                       PSR-3 channel; pass your own to push a
	 *                                       metric, sample a stack trace, or route it
	 *                                       somewhere else entirely.
	 * @param     string   $level            PSR-3 level for the default logging path.
	 *                                       Warning, not error: a slow query is a
	 *                                       symptom to look at, not a failure -- the
	 *                                       statement succeeded.
	 * @param     ?LoggerInterface $logger   Overrides Propulsion's configured logger.
	 */
	public function __construct(
		private readonly float $thresholdSeconds,
		private $onSlowQuery = null,
		private readonly string $level = LogLevel::WARNING,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function queryStarted(QueryExecution $execution): void
	{
		// Nothing to do: the duration is measured by QueryExecution itself, so
		// this observer only cares about the far end.
	}

	public function queryFinished(QueryExecution $execution): void
	{
		$duration = $execution->getDurationSeconds();
		if ($duration === null || $duration < $this->thresholdSeconds) {
			return;
		}

		if ($this->onSlowQuery !== null) {
			($this->onSlowQuery)($execution);

			return;
		}

		$message = sprintf(
			'Slow query (%.1fms >= %.1fms): %s',
			$execution->getDurationMilliseconds() ?? 0.0,
			$this->thresholdSeconds * 1000.0,
			$execution->sql
		);

		if ($this->logger !== null) {
			$this->logger->log($this->level, $message);

			return;
		}

		Propulsion::log($message, $this->level);
	}
}
