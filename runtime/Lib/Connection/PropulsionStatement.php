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
		$observers = Propulsion::getServiceContainer()->getQueryObservers();
		$execution = $observers->start($this->queryString, QueryExecution::SOURCE_STATEMENT, $this->pdo);

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
}
