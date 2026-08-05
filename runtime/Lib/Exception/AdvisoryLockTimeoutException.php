<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Exception;

/**
 * Thrown by {@see \Propulsion\Propulsion::withAdvisoryLock()} when the named
 * lock could not be taken out within the requested wait.
 *
 * Its own class rather than a plain PropulsionException because "somebody else
 * is holding it" is an *expected* outcome of asking for a mutex, not a
 * malfunction -- the whole point of passing a timeout is to be told this. A
 * cron entry that finds the previous run still going wants to log and exit;
 * that decision needs to be distinguishable from a genuine database failure,
 * which a caller must not swallow.
 */
class AdvisoryLockTimeoutException extends PropulsionException
{
	/**
	 * @param     string $lockName The name that could not be acquired.
	 * @param     ?float $timeout  The wait that elapsed, in seconds; null means
	 *                             the acquisition failed without one, which the
	 *                             platform reports for a lock attempt that
	 *                             errored rather than merely lost the race.
	 */
	public function __construct(private readonly string $lockName, private readonly ?float $timeout = null)
	{
		parent::__construct(sprintf(
			'Could not acquire advisory lock "%s"%s: it is held elsewhere.',
			$lockName,
			$timeout === null ? '' : sprintf(' within %ss', rtrim(rtrim(number_format($timeout, 3, '.', ''), '0'), '.'))
		));
	}

	public function getLockName(): string
	{
		return $this->lockName;
	}

	/**
	 * The wait that elapsed, in seconds, or null if the attempt was made
	 * without one.
	 */
	public function getTimeout(): ?float
	{
		return $this->timeout;
	}
}
