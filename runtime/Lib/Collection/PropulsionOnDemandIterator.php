<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Collection;

use Propulsion\Formatter\PropulsionObjectFormatter;
use \Iterator;
use \PDOStatement;
use Propulsion\OM\BaseObject;
use Propulsion\Propulsion;
use Propulsion\Exception\PropulsionException;
use PDO;
/**
 * Class for iterating over a statement and returning one Propulsion object at a time
 *
 * @author     Francois Zaninotto
 *
 * @implements Iterator<int,BaseObject>
 */
class PropulsionOnDemandIterator implements Iterator
{
	/**
	 * @var       PropulsionObjectFormatter
	 */
	protected $formatter;

	/**
	 * @var       PDOStatement
	 */
	protected $stmt;

	/** @var array<int,mixed>|false */
	protected $currentRow = false;

	protected int $currentKey = -1;
	protected ?bool $isValid = null;

	/**
	 * Whether this iterator still owes the session a resumeInstancePooling()
	 * call. Tracks *our own* outstanding suspension, not the global pooling
	 * state -- see the constructor.
	 */
	protected bool $hasSuspendedInstancePooling = false;

	/**
	 * @param     PropulsionObjectFormatter  $formatter
	 * @param     PDOStatement     $stmt
	 */
	public function __construct(PropulsionObjectFormatter $formatter, PDOStatement $stmt)
	{
		$this->formatter = $formatter;
		$this->stmt = $stmt;
		// Suspend pooling for as long as this iterator streams: pooling every
		// row of a result set that is being read one row at a time defeats the
		// entire point of on-demand mode, since the pool would retain what the
		// iterator is careful not to.
		//
		// This suspends unconditionally and always resumes, rather than the old
		// `enableInstancePoolingOnFinish = Propulsion::disableInstancePooling()`
		// pattern, which asked the global switch "did I change you?" and
		// restored only if so. That could not nest: with two on-demand
		// iterations alive at once, the second saw "already disabled" and
		// recorded that it must not restore, so the first one to finish
		// re-enabled pooling underneath the other one, which then started
		// pooling every remaining row. Suspensions count instead, so pooling
		// resumes only when the last one does. See
		// Session::$instancePoolingSuspendCount.
		Propulsion::getSession()->suspendInstancePooling();
		$this->hasSuspendedInstancePooling = true;
	}

	public function closeCursor(): void
	{
		$this->stmt->closeCursor();
	}

	/**
	 * Returns the number of rows in the resultset
	 * Warning: this number is inaccurate for most databases. Do not rely on it for a portable application.
	 *
	 * @return    integer  Number of results
	 */
	public function count()
	{
		return $this->stmt->rowCount();
	}

	// Iterator Interface

	/**
	 * Gets the current Model object in the collection
	 * This is where the hydration takes place.
	 *
	 * @see       PropulsionObjectFormatter::getAllObjectsFromRow()
	 *
	 * @return    BaseObject
	 */
	public function current(): mixed
	{
		if (!is_array($this->currentRow)) {
			throw new PropulsionException('current() called on an invalid iterator position');
		}
		return $this->formatter->getAllObjectsFromRow($this->currentRow);
	}

	/**
	 * Gets the current key in the iterator
	 *
	 * @return    int
	 */
	public function key(): int
	{
		return $this->currentKey;
	}

	/**
	 * Advances the curesor in the statement
	 * Closes the cursor if the end of the statement is reached
	 */
	public function next(): void
	{
		$row = $this->stmt->fetch(PDO::FETCH_NUM);
		$this->currentRow = (is_array($row) && array_is_list($row)) ? $row : false;
		$this->currentKey++;
		$this->isValid = (bool) $this->currentRow;
		if (!$this->isValid) {
			$this->closeCursor();
			$this->restoreInstancePooling();
		}
	}

	/**
	 * Ends this iterator's own suspension of instance pooling, if it still has
	 * one outstanding. Idempotent via the flag, so it's safe to call from both
	 * next() (on normal end-of-stream) and __destruct() (for callers like
	 * ModelCriteria::findOne() that only ever read one row and never exhaust the
	 * statement) without resuming twice and cancelling some *other* iterator's
	 * suspension.
	 */
	private function restoreInstancePooling(): void
	{
		if ($this->hasSuspendedInstancePooling) {
			$this->hasSuspendedInstancePooling = false;
			Propulsion::getSession()->resumeInstancePooling();
		}
	}

	/**
	 * A caller that never fully iterates this (e.g. only checks count()/
	 * offsetExists(), or reads a handful of rows and stops) leaves $stmt's
	 * result set open otherwise -- next() only closes it on natural end-of-
	 * stream. FreeTDS/pdo_dblib (MSSQL) has no MARS support, so relying on the
	 * statement's own destructor to release it eventually isn't good enough:
	 * the next statement on this connection can fail with "Attempt to
	 * initiate a new Adaptive Server operation with results pending" before
	 * PHP gets around to it. closeCursor() is a safe no-op if next() already
	 * closed it.
	 */
	public function __destruct()
	{
		$this->closeCursor();
		$this->restoreInstancePooling();
	}

	/**
	 * Initializes the iterator by advancing to the first position
	 * This method can only be called once (this is a NoRewindIterator)
	 */
	public function rewind(): void
	{
		// check that the hydration can begin
		if (null === $this->formatter) {
			throw new PropulsionException('The On Demand collection requires a formatter. Add it by calling setFormatter()');
		}
		if (null === $this->stmt) {
			throw new PropulsionException('The On Demand collection requires a statement. Add it by calling setStatement()');
		}
		if (null !== $this->isValid) {
			throw new PropulsionException('The On Demand collection can only be iterated once');
		}

		// initialize the current row and key
		$this->next();
	}

	/**
	 * @return    boolean
	 */
	public function valid(): bool
	{
		return (bool) $this->isValid;
	}
}
