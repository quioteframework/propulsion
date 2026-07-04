<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Reverse\SQLite;

/**
 * SQLite database schema parser.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 * @package    propel.generator.reverse.sqlite
 */
use Propulsion\Generator\Reverse\BaseSchemaParser;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\ColumnDefaultValue;
use Propulsion\Generator\Model\Index;
use \PDO;
class SqliteSchemaParser extends BaseSchemaParser
{
	/**
	 * Verbose logging level for optional $task->log() calls (matches the historical build-tool's verbose-log level).
	 */
	private const MSG_VERBOSE = 4;

	/**
	 * Map Sqlite native types to Propulsion types.
	 *
	 * There really aren't any SQLite native types, so we're just
	 * using the MySQL ones here.
	 *
	 * @var        array
	 */
	private static $sqliteTypeMap = array(
		'tinyint' => PropulsionTypes::TINYINT,
		'smallint' => PropulsionTypes::SMALLINT,
		'mediumint' => PropulsionTypes::SMALLINT,
		'int' => PropulsionTypes::INTEGER,
		'integer' => PropulsionTypes::INTEGER,
		'bigint' => PropulsionTypes::BIGINT,
		'int24' => PropulsionTypes::BIGINT,
		'real' => PropulsionTypes::REAL,
		'float' => PropulsionTypes::FLOAT,
		'decimal' => PropulsionTypes::DECIMAL,
		'numeric' => PropulsionTypes::NUMERIC,
		'double' => PropulsionTypes::DOUBLE,
		'char' => PropulsionTypes::CHAR,
		'varchar' => PropulsionTypes::VARCHAR,
		'date' => PropulsionTypes::DATE,
		'time' => PropulsionTypes::TIME,
		'year' => PropulsionTypes::INTEGER,
		'datetime' => PropulsionTypes::TIMESTAMP,
		'timestamp' => PropulsionTypes::TIMESTAMP,
		'tinyblob' => PropulsionTypes::BINARY,
		'blob' => PropulsionTypes::BLOB,
		'mediumblob' => PropulsionTypes::BLOB,
		'longblob' => PropulsionTypes::BLOB,
		'longtext' => PropulsionTypes::CLOB,
		'tinytext' => PropulsionTypes::VARCHAR,
		'mediumtext' => PropulsionTypes::LONGVARCHAR,
		'text' => PropulsionTypes::LONGVARCHAR,
		'enum' => PropulsionTypes::CHAR,
		'set' => PropulsionTypes::CHAR,
	);

	/**
	 * Gets a type mapping from native types to Propulsion types
	 *
	 * @return     array
	 */
	protected function getTypeMapping()
	{
		return self::$sqliteTypeMap;
	}

	/**
	 *
	 */
	public function parse(Database $database, mixed $task = null)
	{
		$stmt = $this->dbh->query("SELECT name FROM sqlite_master WHERE type='table' UNION ALL SELECT name FROM sqlite_temp_master WHERE type='table' ORDER BY name;");

		// First load the tables (important that this happen before filling out details of tables)
		$tables = array();
		while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
			$name = $row[0];
			if ($name == $this->getMigrationTable()) {
				continue;
			}
			$table = new Table($name);
			$table->setIdMethod($database->getDefaultIdMethod());
			$database->addTable($table);
			$tables[] = $table;
		}

		// Now populate only columns.
		foreach ($tables as $table) {
			$this->addColumns($table);
		}

		// Now add indexes and constraints.
		foreach ($tables as $table) {
			$this->addIndexes($table);
		}

		return count($tables);

	}

	/**
	 * Adds Columns to the specified table.
	 *
	 * @param      Table $table The Table model class to add columns to.
	 */
	protected function addColumns(Table $table)
	{
		$stmt = $this->dbh->query("PRAGMA table_info('" . $table->getName() . "')");

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

			$name = $row['name'];

			$fulltype = $row['type'];
			$size = null;
			$precision = null;
			$scale = null;

			if (preg_match('/^([^\(]+)\(\s*(\d+)\s*,\s*(\d+)\s*\)$/', $fulltype, $matches)) {
				$type = $matches[1];
				$precision = $matches[2];
				$scale = $matches[3]; // aka precision
			} elseif (preg_match('/^([^\(]+)\(\s*(\d+)\s*\)$/', $fulltype, $matches)) {
				$type = $matches[1];
				$size = $matches[2];
			} else {
				$type = $fulltype;
			}
			// If column is primary key and of type INTEGER, it is auto increment
			// See: http://sqlite.org/faq.html#q1
			$autoincrement = ($row['pk'] == 1 && strtolower($type) == 'integer');
			$not_null = $row['notnull'];
			$default = $row['dflt_value'];

			$propelType = $this->getMappedPropulsionType(strtolower($type));
			if (!$propelType) {
				$propelType = Column::DEFAULT_TYPE;
				$this->warn("Column [" . $table->getName() . "." . $name. "] has a column type (".$type.") that Propulsion does not support.");
			}

			$column = new Column($name);
			$column->setTable($table);
			$column->setDomainForType($propelType);
			// We may want to provide an option to include this:
			// $column->getDomain()->replaceSqlType($type);
			$column->getDomain()->replaceSize($size);
			$column->getDomain()->replaceScale($scale);
			if ($default !== null) {
				$column->getDomain()->setDefaultValue(new ColumnDefaultValue($default, ColumnDefaultValue::TYPE_VALUE));
			}
			$column->setAutoIncrement($autoincrement);
			$column->setNotNull($not_null);

			if (($row['pk'] == 1) || (strtolower($type) == 'integer')) {
				$column->setPrimaryKey(true);
			}

			$table->addColumn($column);

		}

	} // addColumn()

	/**
	 * Load indexes for this table
	 */
	protected function addIndexes(Table $table)
	{
		$stmt = $this->dbh->query("PRAGMA index_list('" . $table->getName() . "')");

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

			$name = $row['name'];
			$index = new Index($name);

			$stmt2 = $this->dbh->query("PRAGMA index_info('".$name."')");
			while ($row2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
				$colname = $row2['name'];
				$index->addColumn($table->getColumn($colname));
			}

			$table->addIndex($index);

		}
	}

}
