<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Event;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\UnitOfWork;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Dispatched from {@see \Propulsion\UnitOfWork::flush()} before anything else
 * happens -- before entities are partitioned/ordered, before the transaction
 * opens. Stoppable: a listener calling stopPropagation() aborts the whole
 * flush (flush() returns 0, nothing is persisted), the same veto convention
 * {@see StoppableModelLifecycleEvent} already gives per-object save()/delete().
 */
class PreFlushEvent implements StoppableEventInterface
{
	private bool $propagationStopped = false;

	public function __construct(
		private readonly UnitOfWork $unitOfWork,
		private readonly PropulsionPDO $connection,
	) {
	}

	/**
	 * The UnitOfWork instance whose flush() is running.
	 */
	public function getUnitOfWork(): UnitOfWork
	{
		return $this->unitOfWork;
	}

	/**
	 * The connection flush() will use for the whole batch.
	 */
	public function getConnection(): PropulsionPDO
	{
		return $this->connection;
	}

	public function stopPropagation(): void
	{
		$this->propagationStopped = true;
	}

	public function isPropagationStopped(): bool
	{
		return $this->propagationStopped;
	}
}
