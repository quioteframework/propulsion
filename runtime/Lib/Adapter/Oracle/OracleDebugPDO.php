<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\Oracle;

/**
 * OraclePropulsionPDO with debug logging/query-counting on by default -- see
 * Propulsion\Connection\DebugPDO's own docblock for what that entails.
 */
class OracleDebugPDO extends OraclePropulsionPDO
{
	public $useDebug = true;

	/**
	 * Free-form slot behaviors' preSelect() hooks can stash debug info on
	 * (e.g. which builder/class triggered the hook) for tests to inspect.
	 *
	 * @var       mixed
	 */
	public $preSelect;

	/**
	 * Free-form slot behaviors' postDelete()/postUpdate() hooks can stash the
	 * number of affected rows on for tests to inspect.
	 *
	 * @var       mixed
	 */
	public $lastAffectedRows;
}
