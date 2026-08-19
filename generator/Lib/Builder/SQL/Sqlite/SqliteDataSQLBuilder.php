<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\SQL\Sqlite;

/**
 * SQLite class for building data dump SQL.
 *
 * An empty subclass, like MysqlDataSQLBuilder: SQLite has no serial-sequence
 * bookkeeping the way PgsqlDataSQLBuilder needs (no separate sequence object
 * to resync after a dump), and stores booleans as plain integers -- exactly
 * what the base class's own getBooleanSql() already produces.
 */
use Propulsion\Generator\Builder\SQL\DataSQLBuilder;

class SqliteDataSQLBuilder extends DataSQLBuilder {}
