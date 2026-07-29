<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\Sqlite;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\PropulsionPDOTrait;

/**
 * PropulsionPDO implementation for SQLite, extending PHP 8.4+'s
 * driver-specific \Pdo\Sqlite (rather than plain \PDO) so that SQLite-only
 * PDO methods (createFunction(), createAggregate(), loadExtension(), etc.)
 * stay reachable through this connection object. See PropulsionPDO's own
 * docblock for why this can't just be the base PropulsionPDO class itself.
 */
class SqlitePropulsionPDO extends \Pdo\Sqlite implements PropulsionPDO
{
	use PropulsionPDOTrait;
}
