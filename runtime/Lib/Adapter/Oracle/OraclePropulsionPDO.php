<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\Oracle;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\PropulsionPDOTrait;

/**
 * PropulsionPDO implementation for Oracle. Unlike the other driver-specific
 * PropulsionPDO subclasses, this extends plain \PDO, not a driver-specific
 * subclass: confirmed no \Pdo\Oci class exists in PHP core (pdo_oci is a
 * PECL extension, not one of the bundled drivers PHP ships a dedicated
 * subclass for) -- class_exists('Pdo\\Oci') is false even with pdo_oci
 * loaded. Named and kept separate from GenericPropulsionPDO anyway, for the
 * same discoverability/symmetry every other platform here gets its own
 * class for.
 */
class OraclePropulsionPDO extends \PDO implements PropulsionPDO
{
	use PropulsionPDOTrait;

	/**
	 * Oracle requires a FROM clause on every SELECT, so the bare `SELECT 1`
	 * every other platform pings with is a syntax error here.
	 */
	protected function getPingSql(): string
	{
		return 'SELECT 1 FROM dual';
	}
}
