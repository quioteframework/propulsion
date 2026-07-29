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

/**
 * Dispatched from {@see \Propulsion\UnitOfWork::flush()} after the batch has
 * committed successfully. Not stoppable -- the operation has already
 * happened, same contract as {@see PostSaveEvent}.
 */
class PostFlushEvent
{
	public function __construct(
		private readonly UnitOfWork $unitOfWork,
		private readonly PropulsionPDO $connection,
		private readonly int $affectedRows,
	) {
	}

	/**
	 * The UnitOfWork instance whose flush() just committed.
	 */
	public function getUnitOfWork(): UnitOfWork
	{
		return $this->unitOfWork;
	}

	/**
	 * The connection the whole batch ran on.
	 */
	public function getConnection(): PropulsionPDO
	{
		return $this->connection;
	}

	/**
	 * The total affected-row count across every entity flush() saved/deleted.
	 */
	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}
}
