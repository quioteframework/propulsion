<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\Pgsql;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\PropulsionPDOTrait;

/**
 * PropulsionPDO implementation for PostgreSQL, extending PHP 8.4+'s
 * driver-specific \Pdo\Pgsql (rather than plain \PDO) so that Postgres-only
 * PDO methods -- e.g. \Pdo\Pgsql::copyFromArray(), the non-deprecated
 * replacement for the now-deprecated PDO::pgsqlCopyFromArray() -- stay
 * reachable through this connection object. See PropulsionPDO's own
 * docblock for why this can't just be the base PropulsionPDO class itself.
 */
class PgsqlPropulsionPDO extends \Pdo\Pgsql implements PropulsionPDO
{
	use PropulsionPDOTrait;
}
