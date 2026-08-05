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

/**
 * One statement execution, from just before it is sent to just after it comes
 * back -- the payload {@see QueryObserver} is handed twice, once at each end.
 *
 * Mutable rather than two immutable events on purpose. A tracing observer
 * opens a span in `queryStarted()` and has to close *that* span in
 * `queryFinished()`; giving it the same object both times means it can hang
 * the span off {@see setAttribute()} instead of maintaining its own
 * correlation map keyed by something that would have to be invented here.
 */
final class QueryExecution
{
	/** Prepared statement executed via PDOStatement::execute(). */
	public const SOURCE_STATEMENT = 'statement';

	/** PDO::exec() -- a statement with no result set. */
	public const SOURCE_EXEC = 'exec';

	/** PDO::query() -- immediate statement returning a result set. */
	public const SOURCE_QUERY = 'query';

	/**
	 * Monotonic start time in nanoseconds (`hrtime(true)`), not a wall-clock
	 * timestamp: this is used to measure a duration, and a wall clock can step
	 * backwards under NTP.
	 */
	private readonly int $startedAt;

	private ?int $finishedAt = null;

	private ?int $rowCount = null;

	private ?\Throwable $error = null;

	/** @var array<string, mixed> */
	private array $attributes = array();

	/**
	 * @param     string        $sql        The statement text, as sent.
	 * @param     string        $source     One of the SOURCE_* constants.
	 * @param     PropulsionPDO $connection The connection it ran on. Deliberately
	 *                                      the connection rather than a datasource
	 *                                      name: a connection genuinely does not
	 *                                      know the name it was registered under
	 *                                      (see PropulsionPDO::ping()'s docblock for
	 *                                      the same constraint), and inventing one
	 *                                      here would mean inventing a wrong one.
	 */
	public function __construct(
		public readonly string $sql,
		public readonly string $source,
		public readonly PropulsionPDO $connection,
	) {
		$this->startedAt = hrtime(true);
	}

	/**
	 * Records the outcome. Called once, by the instrumentation, before
	 * {@see QueryObserver::queryFinished()}.
	 */
	public function finish(?int $rowCount = null, ?\Throwable $error = null): void
	{
		$this->finishedAt = hrtime(true);
		$this->rowCount = $rowCount;
		$this->error = $error;
	}

	/**
	 * How long the statement took, in seconds, or null if it has not finished.
	 * Seconds-as-float because that is what every metrics and tracing API in
	 * this ecosystem takes.
	 */
	public function getDurationSeconds(): ?float
	{
		return $this->finishedAt === null ? null : ($this->finishedAt - $this->startedAt) / 1e9;
	}

	/** Milliseconds, for the common case of logging a threshold breach. */
	public function getDurationMilliseconds(): ?float
	{
		$seconds = $this->getDurationSeconds();

		return $seconds === null ? null : $seconds * 1000.0;
	}

	/**
	 * Rows affected or returned, where the driver reports it, and null where it
	 * does not -- notably for a SELECT on most platforms, where `rowCount()` is
	 * documented as unreliable and is therefore not consulted.
	 */
	public function getRowCount(): ?int
	{
		return $this->rowCount;
	}

	/** The exception the statement threw, if it threw. */
	public function getError(): ?\Throwable
	{
		return $this->error;
	}

	public function isFailed(): bool
	{
		return $this->error !== null;
	}

	/**
	 * Scratch space for an observer to carry state between `queryStarted()`
	 * and `queryFinished()` -- an open tracing span being the motivating case.
	 * Key it by something specific to your observer; nothing here namespaces
	 * it for you.
	 */
	public function setAttribute(string $key, mixed $value): void
	{
		$this->attributes[$key] = $value;
	}

	public function getAttribute(string $key): mixed
	{
		return $this->attributes[$key] ?? null;
	}
}
