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

	private bool $rowCaptureRequested = false;

	private int $maxRowCapture = 0;

	/** @var array<int, mixed> */
	private array $capturedRows = array();

	private bool $rowsTruncated = false;

	/** @var array<int, string>|null */
	private ?array $columnNames = null;

	/**
	 * @param     string        $sql          The statement text, as sent.
	 * @param     string        $source       One of the SOURCE_* constants.
	 * @param     PropulsionPDO $connection   The connection it ran on. Deliberately
	 *                                        the connection rather than a datasource
	 *                                        name: a connection genuinely does not
	 *                                        know the name it was registered under
	 *                                        (see PropulsionPDO::ping()'s docblock for
	 *                                        the same constraint), and inventing one
	 *                                        here would mean inventing a wrong one.
	 * @param     array<int|string, mixed> $boundParams Values bound via
	 *                                        `PDOStatement::bindValue()` before this
	 *                                        statement ran, keyed the same way PDO
	 *                                        does (1-based position, or `:name`).
	 *                                        Always empty for `exec()`/`query()`
	 *                                        traffic, which has no bind step.
	 *                                        Values bound via `bindParam()` (by
	 *                                        reference) are not captured -- see
	 *                                        docs/OBSERVABILITY.md.
	 * @param     ?string       $correlationId Whatever {@see \Propulsion\Propulsion::getCorrelationId()}
	 *                                        returned when this execution began.
	 */
	public function __construct(
		public readonly string $sql,
		public readonly string $source,
		public readonly PropulsionPDO $connection,
		public readonly array $boundParams = array(),
		public readonly ?string $correlationId = null,
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

	/**
	 * Ask that fetched rows be captured, up to `$maxRows` -- call this from
	 * {@see QueryObserver::queryStarted()} only; a request made any later has
	 * nothing to attach to, since {@see \Propulsion\Connection\PropulsionStatement}
	 * decides once, right after `queryStarted()` runs, whether it is worth
	 * wrapping `fetch()` at all for this statement.
	 *
	 * Safe to call from more than one observer: the largest `$maxRows` any of
	 * them asked for wins, rather than the last one silently overriding an
	 * earlier, larger request.
	 */
	public function requestRowCapture(int $maxRows = 100): void
	{
		$this->rowCaptureRequested = true;
		$this->maxRowCapture = max($this->maxRowCapture, $maxRows);
	}

	/** Whether any observer called {@see requestRowCapture()} during `queryStarted()`. */
	public function wantsRowCapture(): bool
	{
		return $this->rowCaptureRequested;
	}

	public function getMaxRowCapture(): int
	{
		return $this->maxRowCapture;
	}

	/**
	 * Called by {@see \Propulsion\Connection\PropulsionStatement} only, once
	 * per fetched row, while {@see wantsRowCapture()} is true. Silently stops
	 * appending -- and sets {@see isRowsTruncated()} -- once
	 * {@see getMaxRowCapture()} is reached, rather than growing without bound
	 * for a large result set.
	 *
	 * @internal
	 */
	public function captureRow(mixed $row): void
	{
		if (count($this->capturedRows) >= $this->maxRowCapture) {
			$this->rowsTruncated = true;

			return;
		}
		$this->capturedRows[] = $row;
	}

	/**
	 * Rows captured so far, in whatever shape the caller actually fetched
	 * them in -- a numeric-indexed array for the ORM's own default formatter,
	 * but an object, an associative array, or a scalar for a caller using a
	 * different `PDO::FETCH_*` mode. Faithful to what actually happened,
	 * rather than normalised to one shape.
	 *
	 * @return array<int, mixed>
	 */
	public function getCapturedRows(): array
	{
		return $this->capturedRows;
	}

	/**
	 * True once more rows were fetched than {@see getMaxRowCapture()} allows.
	 * The rows themselves are simply not there past the cap -- there is no
	 * separate "how many were dropped" count, since a cassette recorder
	 * generally only needs to know its capture is incomplete, not by how much.
	 */
	public function isRowsTruncated(): bool
	{
		return $this->rowsTruncated;
	}

	/**
	 * Called by {@see \Propulsion\Connection\PropulsionStatement} only, at
	 * most once per execution, and only when the fetched rows are
	 * list-shaped (`PDO::FETCH_NUM`) -- an associative or object row already
	 * carries its own column names, so capturing them again would be pure
	 * overhead for no benefit.
	 *
	 * @param array<int, string> $names
	 * @internal
	 */
	public function setColumnNames(array $names): void
	{
		$this->columnNames ??= $names;
	}

	/**
	 * Column names for {@see getCapturedRows()}'s rows, positionally
	 * matching a list-shaped row -- null if nothing was captured, or if what
	 * was captured already carried its own names.
	 *
	 * @return array<int, string>|null
	 */
	public function getColumnNames(): ?array
	{
		return $this->columnNames;
	}
}
