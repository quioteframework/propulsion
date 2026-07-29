<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter\Mysql;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Connection\PropulsionPDOTrait;

/**
 * PropulsionPDO implementation for MySQL/MariaDB, extending PHP 8.4+'s
 * driver-specific \Pdo\Mysql (rather than plain \PDO) so that MySQL-only PDO
 * methods stay reachable through this connection object, and so this
 * connection keeps pace with any future PDO::mysql*()-style method
 * deprecation the way PDO::pgsqlCopyFromArray() already was in PHP 8.5 --
 * see PropulsionPDO's own docblock for the full reasoning.
 */
class MysqlPropulsionPDO extends \Pdo\Mysql implements PropulsionPDO
{
	use PropulsionPDOTrait;
}
