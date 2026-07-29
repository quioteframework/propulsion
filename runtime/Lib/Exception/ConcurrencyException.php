<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Exception;

use Propulsion\OM\BaseObject;

/**
 * Thrown when an optimistic-lock UPDATE (see OptimisticLockBehavior) affects
 * zero rows -- the row's version column no longer matches the value this
 * object was loaded with, meaning another writer already changed (or
 * deleted) it since. Mirrors EF Core's `DbUpdateConcurrencyException`.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
class ConcurrencyException extends PropulsionException
{
	/**
	 * @param     string|\Exception|null $message A message string, or (if called with a
	 *            single argument) the wrapped exception itself.
	 * @param     ?BaseObject $entity The object whose UPDATE affected zero rows.
	 * @param     \Exception|null $previous
	 */
	public function __construct($message = null, private readonly ?BaseObject $entity = null, ?\Exception $previous = null)
	{
		parent::__construct($message, $previous);
	}

	/**
	 * The object whose UPDATE affected zero rows, if known.
	 *
	 * @return    ?BaseObject
	 */
	public function getEntity(): ?BaseObject
	{
		return $this->entity;
	}
}
