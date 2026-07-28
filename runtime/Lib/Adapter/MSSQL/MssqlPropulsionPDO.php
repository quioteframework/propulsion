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
	 * A nested call is a no-op beyond incrementing the depth counter -- an earlier
	 * version of this method issued a real T-SQL "SAVE TRANSACTION" savepoint here
	 * (mirroring the base PropulsionPDO's standard-SQL SAVEPOINT for
	 * Postgres/MySQL/SQLite), which is architecturally the *more correct* behavior,
	 * but had to be reverted: something elsewhere in this codebase leaves
	 * getNestedTransactionCount() unbalanced (nonzero) across a huge number of
	 * otherwise-unrelated tests sharing one process-lifetime connection, which the
	 * old no-op emulation tolerated silently -- making nested begin/rollback
	 * actually execute real SQL turned every one of those latent imbalances into a
	 * live MARS ("results pending") failure, regressing ~500 previously-passing
	 * tests. See KNOWN_ISSUES.md.
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
	 * A nested call is a no-op beyond decrementing the depth counter -- see
	 * beginTransaction()'s own comment on why this doesn't use a real SAVE
	 * TRANSACTION/nested commit here.
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
	 * A nested call doesn't undo anything by itself -- see beginTransaction()'s
	 * own comment on why the real-SAVE-TRANSACTION version of this was reverted.
	 * Instead, the whole (outer) transaction is marked uncommittable, so that its
	 * eventual commit() throws instead of silently discarding the rolled-back
	 * nested work.
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
				$this->isUncommitable = true;
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
