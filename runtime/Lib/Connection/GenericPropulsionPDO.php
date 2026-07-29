<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

/**
 * Driver-agnostic PropulsionPDO implementation, extending plain \PDO directly
 * rather than one of PHP 8.4+'s driver-specific subclasses (\Pdo\Pgsql,
 * \Pdo\Mysql, \Pdo\Sqlite, \Pdo\Dblib).
 *
 * Used where no specific driver subclass is safe to assume: DBAdapter
 * implementations with no matching \Pdo\* class at all (DBOracle -- see
 * OraclePropulsionPDO, which is otherwise identical to this class but named
 * for discoverability), DBNone (no real database), and DBSQLSRV (the
 * alternate pdo_sqlsrv-based MSSQL adapter -- deliberately NOT given
 * MssqlPropulsionPDO's \Pdo\Dblib base, since a class extending one driver's
 * PDO subclass throws immediately if constructed against a different
 * driver's DSN; confirmed empirically that mismatched construction like this
 * fails fast rather than silently misbehaving).
 */
class GenericPropulsionPDO extends \PDO implements PropulsionPDO
{
	use PropulsionPDOTrait;
}
