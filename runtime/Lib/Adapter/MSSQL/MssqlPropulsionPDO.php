<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\MSSQL;

/**
 * dblib doesn't support transactions so we need to add a workaround for transactions, last insert ID, and quoting
 *
 */
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;

class MssqlPropulsionPDO extends PropulsionPDO
{
	/**
	 * Begin a transaction.
	 *
	 * It is necessary to override the abstract PDO transaction functions here, as
	 * the PDO driver for MSSQL does not support transactions.
	 *
	 * A nested call issues a real T-SQL "SAVE TRANSACTION" savepoint (SQL Server's
	 * own syntax for the same thing the base PropulsionPDO does with standard
	 * "SAVEPOINT" for Postgres/MySQL/SQLite -- see getSavepointName()), so a nested
	 * rollBack() can undo just that work without poisoning the outer transaction.
	 *
	 * @return    boolean
	 */
	public function beginTransaction(): bool
	{
		$return = true;
		$opcount = $this->getNestedTransactionCount();
		if ( $opcount === 0 ) {
			$return = self::exec('BEGIN TRANSACTION');
			if ($this->useDebug) {
				// Logged under the base class's own method-name identifier
				// (not __METHOD__, which would report this subclass's own
				// namespace/class) so debugpdo.logging.methods configuration
				// keyed on "PropulsionPDO::beginTransaction" (the identifier
				// every other platform's un-overridden beginTransaction()
				// naturally reports) matches here too, regardless of which
				// class actually implements it.
				$this->log('Begin transaction', null, PropulsionPDO::class . '::beginTransaction');
			}
			$this->isUncommitable = false;
		} else {
			// Matches the base PropulsionPDO::beginTransaction()'s own nested
			// (savepoint) branch: a nested SAVE TRANSACTION isn't itself
			// logged or counted as a tracked query, same as SAVEPOINT on
			// Postgres/MySQL/SQLite. Calls the truly-raw \PDO::exec() (a
			// fully-qualified instance-method call binds $this the same way
			// parent:: does), *not* parent::exec() -- since this class's
			// immediate parent is PropulsionPDO itself (not \PDO directly the
			// way the base class's own parent::exec() call is), parent::exec()
			// here would actually reach PropulsionPDO::exec()'s own
			// logging/counting wrapper, not bypass it.
			$return = \PDO::exec('SAVE TRANSACTION ' . $this->getSavepointName($opcount + 1)) !== false;
		}
		$this->nestedTransactionCount++;
		return $return;
	}

	/**
	 * Commit a transaction.
	 *
	 * It is necessary to override the abstract PDO transaction functions here, as
	 * the PDO driver for MSSQL does not support transactions.
	 *
	 * A nested call is a no-op beyond decrementing the depth counter: unlike
	 * standard SQL's SAVEPOINT, T-SQL has no explicit "release" statement for a
	 * "SAVE TRANSACTION" savepoint at all -- it's simply discarded once the outer
	 * transaction commits (or a shallower savepoint of the same name is set again).
	 *
	 * @return    boolean
	 */
	public function commit(): bool
	{
		$return = true;
		$opcount = $this->getNestedTransactionCount();
		if ($opcount > 0) {
			if ($opcount === 1) {
				if ($this->isUncommitable) {
					throw new PropulsionException('Cannot commit because a nested transaction was rolled back');
				} else {
					$return = self::exec('COMMIT TRANSACTION');
					if ($this->useDebug) {
						// See beginTransaction()'s own comment on why this
						// isn't __METHOD__.
						$this->log('Commit transaction', null, PropulsionPDO::class . '::commit');
					}

				}
			}
			$this->nestedTransactionCount--;
		}
		return $return;
	}

	/**
	 * Roll-back a transaction.
	 *
	 * It is necessary to override the abstract PDO transaction functions here, as
	 * the PDO driver for MSSQL does not support transactions.
	 *
	 * A nested call issues a real T-SQL "ROLLBACK TRANSACTION savepoint_name",
	 * undoing only the work done since the matching beginTransaction() call --
	 * the outer transaction is left open and can go on to commit() normally
	 * afterwards, matching Postgres/MySQL/SQLite's real-savepoint semantics (see
	 * PropulsionPDO::rollBack()) rather than poisoning it the way the old
	 * counter-only emulation here used to.
	 *
	 * @return    boolean
	 */
	public function rollBack() : bool
	{
		$return = true;
		$opcount = $this->getNestedTransactionCount();
		if ($opcount > 0) {
			if ($opcount === 1) {
				$return = self::exec('ROLLBACK TRANSACTION');
				if ($this->useDebug) {
					// See beginTransaction()'s own comment on why this isn't
					// __METHOD__.
					$this->log('Rollback transaction', null, PropulsionPDO::class . '::rollBack');
				}
			} else {
				// See beginTransaction()'s own comment on \PDO::exec() vs
				// self::exec()/parent::exec() here.
				$return = \PDO::exec('ROLLBACK TRANSACTION ' . $this->getSavepointName($opcount)) !== false;
			}
			$this->nestedTransactionCount--;
		}
		return $return;
	}

	/**
	 * Rollback the whole transaction, even if this is a nested rollback
	 * and reset the nested transaction count to 0.
	 *
	 * It is necessary to override the abstract PDO transaction functions here, as
	 * the PDO driver for MSSQL does not support transactions.
	 *
	 * @return    boolean
	 */
	public function forceRollBack(): bool
	{
		$return = true;
		$opcount = $this->getNestedTransactionCount();
		if ($opcount > 0) {
			// If we're in a transaction, always roll it back
			// regardless of nesting level.
			$return = self::exec('ROLLBACK TRANSACTION');

			// reset nested transaction count to 0 so that we don't
			// try to commit (or rollback) the transaction outside this scope.
			$this->nestedTransactionCount = 0;

			if ($this->useDebug) {
				// See beginTransaction()'s own comment on why this isn't
				// __METHOD__.
				$this->log('Rollback transaction', null, PropulsionPDO::class . '::forceRollBack');
			}
		}
		return $return;
	}

	/**
	 * @param      string  $seqname
	 * @return     string|false
	 */
	public function lastInsertId($seqname = null) : string|false
	{
		$result = self::query('SELECT SCOPE_IDENTITY()');
		return (string) $result->fetchColumn();
	}

	/**
	 * @param      string  $text
	 * @return     string
	 */
	public function quoteIdentifier($text)
	{
		return '[' . $text . ']';
	}

	/**
	 * @return    boolean
	 */
	public function useQuoteIdentifier()
	{
		return true;
	}
}
