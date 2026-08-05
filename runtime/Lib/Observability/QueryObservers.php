<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;

/**
 * The registered {@see QueryObserver}s, and the dispatch around them.
 *
 * Process-scoped, on {@see \Propulsion\ServiceContainer}: observers are wiring
 * installed at bootstrap, and a tracer that silently stopped tracing at the
 * request boundary under a worker runtime would be worse than one that was
 * never installed, because nothing would say so.
 *
 * The cost of *not* using this is one array check per statement
 * ({@see isEmpty()}), which is why the instrumentation asks that first and
 * never constructs a {@see QueryExecution} for an application with no
 * observers.
 */
final class QueryObservers
{
	/** @var array<int, QueryObserver> */
	private array $observers = array();

	public function add(QueryObserver $observer): void
	{
		// Registering the same instance twice would double-count every metric
		// and open two spans per query, so it is idempotent by identity.
		if (!in_array($observer, $this->observers, true)) {
			$this->observers[] = $observer;
		}
	}

	public function remove(QueryObserver $observer): void
	{
		$index = array_search($observer, $this->observers, true);
		if ($index !== false) {
			unset($this->observers[$index]);
			$this->observers = array_values($this->observers);
		}
	}

	public function clear(): void
	{
		$this->observers = array();
	}

	/**
	 * The hot-path check: true when there is nothing to notify, so the caller
	 * can skip building a QueryExecution at all.
	 */
	public function isEmpty(): bool
	{
		return $this->observers === array();
	}

	/**
	 * @return array<int, QueryObserver>
	 */
	public function all(): array
	{
		return $this->observers;
	}

	/**
	 * Opens an execution record and notifies every observer, or returns null
	 * when none are registered -- which is also the signal to the caller that
	 * it need not call {@see finish()} either.
	 */
	public function start(string $sql, string $source, PropulsionPDO $connection): ?QueryExecution
	{
		if ($this->observers === array()) {
			return null;
		}

		$execution = new QueryExecution($sql, $source, $connection);
		foreach ($this->observers as $observer) {
			$this->safely($observer, static fn () => $observer->queryStarted($execution), 'queryStarted');
		}

		return $execution;
	}

	/**
	 * Records the outcome on $execution (null is accepted and ignored, so a
	 * caller can pass whatever `start()` returned without branching) and
	 * notifies every observer.
	 */
	public function finish(?QueryExecution $execution, ?int $rowCount = null, ?\Throwable $error = null): void
	{
		if ($execution === null) {
			return;
		}

		$execution->finish($rowCount, $error);
		foreach ($this->observers as $observer) {
			$this->safely($observer, static fn () => $observer->queryFinished($execution), 'queryFinished');
		}
	}

	/**
	 * Runs one notification, swallowing whatever it throws.
	 *
	 * Telemetry must not be able to break the query it is measuring, and the
	 * failure mode if it could is nastier than it first looks: an exception out
	 * of `queryFinished()` would *replace* the exception the statement itself
	 * was reporting, turning a database error into a mystifying observer stack
	 * trace. The failure is logged at error level through the ordinary PSR-3
	 * channel so it is not invisible.
	 *
	 * @param     callable(): void $notify
	 */
	private function safely(QueryObserver $observer, callable $notify, string $method): void
	{
		try {
			$notify();
		} catch (\Throwable $e) {
			Propulsion::log(
				sprintf(
					'Query observer %s::%s() threw and was ignored: %s',
					get_class($observer),
					$method,
					$e->getMessage()
				),
				Propulsion::LOG_ERR
			);
		}
	}
}
