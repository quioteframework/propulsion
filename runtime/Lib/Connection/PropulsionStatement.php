<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

use Propulsion\Observability\QueryExecution;
use Propulsion\Propulsion;

/**
 * The statement class every Propulsion connection uses by default.
 *
 * It exists for one reason: to notice that the connection underneath a
 * statement has gone away. Until this class was introduced, dropped-connection
 * detection lived on {@see PropulsionPDOTrait::exec()},
 * {@see PropulsionPDOTrait::query()} and {@see DebugPDOStatement::execute()},
 * and *almost no ORM traffic goes through any of those*: the generated Peer /
 * ModelCriteria paths all prepare a statement and call `execute()` on it, which
 * on a non-debug connection was a plain \PDOStatement nothing wrapped. A
 * dropped connection therefore surfaced as an ordinary PDOException, the
 * connection stayed in the pool with its transaction-depth counter and
 * prepared-statement cache intact, and the *next* caller to be handed that same
 * pooled connection failed too -- for the rest of the process's life under a
 * worker runtime, where connections outlive the request that opened them.
 *
 * Handling is delegated wholesale to
 * {@see PropulsionPDOTrait::handleDroppedConnection()}, so this class holds no
 * recovery policy of its own; see that method for why the statement is *not*
 * retried here (losing the connection loses any open transaction with it, so a
 * retry below the transaction boundary would silently run outside the
 * transaction the caller believes it is in). Retry that is safe because it
 * restarts the whole transaction lives in {@see Propulsion::transaction()}.
 *
 * **Not installed on persistent connections.** PDO refuses a custom statement
 * class when PDO::ATTR_PERSISTENT is set, so a deployment using persistent
 * connections keeps plain \PDOStatement and keeps the gap this class closes --
 * see {@see PropulsionPDOTrait::configureStatementClass()}, which suppresses
 * that failure rather than refusing to connect.
 *
 * @see DebugPDOStatement which extends this to add query counting and logging,
 *      and so inherits the detection rather than repeating it.
 */
class PropulsionStatement extends \PDOStatement
{
	/**
	 * The connection this statement was prepared on.
	 *
	 * Populated by PDO itself from the constructor arguments registered with
	 * PDO::ATTR_STATEMENT_CLASS (see
	 * {@see PropulsionPDOTrait::configureStatementClass()}).
	 *
	 * @var       PropulsionPDO
	 */
	protected $pdo;

	/**
	 * Values bound via {@see bindValue()} since the last `execute()`, keyed
	 * the same way PDO does (1-based position, or `:name`) -- handed to the
	 * next {@see QueryExecution} this statement opens. Not reset between
	 * executions of the same reused prepared statement: a value that was not
	 * rebound for a later iteration genuinely is still the value that will be
	 * used, so keeping it is correct, not stale.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $boundValues = array();

	/**
	 * The execution `execute()` most recently opened, kept around so
	 * {@see fetch()}/{@see closeCursor()}/{@see __destruct()} -- all called
	 * well after `execute()` has returned -- know what to attach captured
	 * rows to and what to notify {@see notifyRowsCapturedIfNeeded()} about.
	 */
	private ?QueryExecution $currentExecution = null;

	/**
	 * Whether {@see notifyRowsCapturedIfNeeded()} has already fired for
	 * {@see $currentExecution}. `fetch()` exhaustion, an explicit
	 * `closeCursor()`, a re-`execute()` of this same statement, and
	 * `__destruct()` can all reach that call for the same execution; this
	 * keeps it to exactly one notification each.
	 */
	private bool $rowsNotified = false;

	/**
	 * PDO instantiates this itself, passing the arguments registered alongside
	 * the class name in PDO::ATTR_STATEMENT_CLASS; the constructor is protected
	 * because PDO requires it to be non-public.
	 *
	 * @param     PropulsionPDO  $pdo  The connection this statement belongs to.
	 */
	protected function __construct(PropulsionPDO $pdo)
	{
		$this->pdo = $pdo;
	}

	/**
	 * Records a bound value, then binds it as normal. This is the only place
	 * that ever sees real parameter values: every runtime call site
	 * (`DBAdapter::bindValues()`/`bindValue()`) binds individually and then
	 * calls `execute()` with no arguments, so a value never otherwise reaches
	 * `execute()`'s own `$params` argument to be captured there.
	 *
	 * `bindParam()` (by-reference binding) is deliberately not overridden the
	 * same way: nothing in this codebase's own generated/runtime SQL uses it,
	 * and capturing it correctly would mean reading the referenced variable
	 * at `execute()` time rather than bind time, which is real additional
	 * machinery for a path that does not occur here. See docs/OBSERVABILITY.md.
	 */
	public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
	{
		$this->boundValues[$param] = $value;

		return parent::bindValue($param, $value, $type);
	}

	/**
	 * Executes the statement, reporting a dropped connection to the connection
	 * itself before letting the exception continue on to the caller, and
	 * notifying any registered {@see \Propulsion\Observability\QueryObserver}
	 * around the call.
	 *
	 * The exception is rethrown unchanged: this is a notification seam, not an
	 * error-handling one. Callers that were already catching PDOException see
	 * exactly what they saw before; what changes is that the dead connection no
	 * longer stays in the pool behind them.
	 *
	 * This is where observability is hooked in for the same reason
	 * dropped-connection detection is: essentially all ORM traffic prepares a
	 * statement and executes it here, so instrumenting anywhere else would
	 * measure almost nothing. An application with no observers registered pays
	 * one array check ({@see \Propulsion\Observability\QueryObservers::isEmpty()},
	 * via `start()` returning null) and never builds an execution record.
	 *
	 * @param     array<int|string, mixed>|null  $params
	 */
	public function execute(?array $params = null): bool
	{
		// A prepared statement re-executed in a loop (e.g. a batch insert)
		// without the caller ever exhausting or closing the previous result
		// set would otherwise leave that execution's row capture -- if any
		// was requested -- silently unreported once this overwrites it below.
		$this->notifyRowsCapturedIfNeeded();

		$observers = Propulsion::getServiceContainer()->getQueryObservers();
		$execution = $observers->start($this->queryString, QueryExecution::SOURCE_STATEMENT, $this->pdo, $this->boundValues);
		$this->currentExecution = $execution;
		$this->rowsNotified = false;

		try {
			$return = parent::execute($params);
		} catch (\PDOException $e) {
			// Finished before handleDroppedConnection() and before the rethrow,
			// so an observer sees the failure of the statement it opened rather
			// than a span left dangling.
			$observers->finish($execution, null, $e);
			if (Propulsion::isConnectionDropped($e)) {
				$this->pdo->handleDroppedConnection($e, static::class . '::' . __FUNCTION__);
			}
			throw $e;
		}

		// rowCount() is only consulted for a statement that changed rows.
		// PDO documents it as unreliable for SELECT (several drivers return 0,
		// others buffer the whole result set to answer), and calling it there
		// would make the measurement change the thing measured.
		$observers->finish($execution, $this->columnCount() === 0 ? $this->rowCount() : null);

		$this->pdo->touchActivity();

		return $return;
	}

	/**
	 * Captures a fetched row onto {@see $currentExecution} when a registered
	 * {@see \Propulsion\Observability\RowCapturingQueryObserver} asked for
	 * one during `queryStarted()`, and notifies once the result set is
	 * exhausted. A statement nothing asked to capture rows for costs exactly
	 * one extra property read (`$this->currentExecution?->wantsRowCapture()`)
	 * on top of the ordinary `fetch()` call.
	 */
	public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
	{
		$row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

		$execution = $this->currentExecution;
		if ($execution === null || !$execution->wantsRowCapture()) {
			return $row;
		}

		if ($row === false) {
			$this->notifyRowsCapturedIfNeeded();

			return $row;
		}

		// Only a list-shaped row (PDO::FETCH_NUM, what the ORM's own default
		// formatter uses -- see StatementRows::iterate()) needs column names
		// attached: an associative row or an object already carries its own.
		if ($execution->getColumnNames() === null && is_array($row) && array_is_list($row)) {
			$execution->setColumnNames($this->columnNames());
		}
		$execution->captureRow($row);

		return $row;
	}

	/**
	 * Belt-and-braces: a caller that reads a few rows and stops without
	 * exhausting the cursor still gets notified, via whichever of
	 * `closeCursor()` or `__destruct()` runs first -- the same
	 * primary-signal-plus-destructor-backstop shape
	 * {@see \Propulsion\Collection\PropulsionOnDemandIterator} already uses
	 * for the same reason (see docs/WORKER_MODE.md, R11: do not rely on
	 * destructor *timing*, but a destructor as a backstop under an explicit
	 * primary signal is fine).
	 */
	public function closeCursor(): bool
	{
		$this->notifyRowsCapturedIfNeeded();

		return parent::closeCursor();
	}

	public function __destruct()
	{
		$this->notifyRowsCapturedIfNeeded();
	}

	/** @return array<int, string> */
	private function columnNames(): array
	{
		$names = array();
		$count = $this->columnCount();
		for ($i = 0; $i < $count; $i++) {
			$meta = $this->getColumnMeta($i);
			$names[] = is_array($meta) ? $meta['name'] : (string) $i;
		}

		return $names;
	}

	private function notifyRowsCapturedIfNeeded(): void
	{
		if ($this->rowsNotified || $this->currentExecution === null || !$this->currentExecution->wantsRowCapture()) {
			return;
		}
		$this->rowsNotified = true;
		Propulsion::getServiceContainer()->getQueryObservers()->notifyRowsCaptured($this->currentExecution);
	}
}
