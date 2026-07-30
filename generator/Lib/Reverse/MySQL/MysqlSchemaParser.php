<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Reverse\MySQL;

/**
 * Mysql database schema parser.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
use Propulsion\Generator\Reverse\BaseSchemaParser;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\ColumnDefaultValue;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Exception\EngineException;
use \PDO;
class MysqlSchemaParser extends BaseSchemaParser
{
	/**
	 * Verbose logging level for optional $task->log() calls (matches the historical build-tool's verbose-log level).
	 */
	private const MSG_VERBOSE = 4;

	/**
	 * @var        boolean
	 */
	private $addVendorInfo = false;

	/**
	 * Map MySQL native types to Propulsion types.
	 * @var        array<string, string>
	 */
	private static $mysqlTypeMap = array(
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

	/** @var array<string, int> */
	protected static $defaultTypeSizes = array(
		'char'     => 1,
		'tinyint'  => 4,
		'smallint' => 6,
		'int'      => 11,
		'bigint'   => 20,
		'decimal'  => 10,
	);

	/**
	 * Gets a type mapping from native types to Propulsion types
	 *
	 * @return     array<string, string>
	 */
	protected function getTypeMapping(): array
	{
		return self::$mysqlTypeMap;
	}

	public function parse(Database $database, mixed $task = null)
	{
		$generatorConfig = $this->getGeneratorConfig();
		$this->addVendorInfo = $generatorConfig !== null && (bool) $generatorConfig->getBuildProperty('addVendorInfo');

		$stmt = $this->queryOrFail("SHOW FULL TABLES");

		// First load the tables (important that this happen before filling out details of tables)
		$tables = array();

		$this->logTask($task, "Reverse Engineering Tables", self::MSG_VERBOSE);

		while (($row = $this->fetchNum($stmt)) !== null) {
			$name = $row[0] ?? null;
			$type = $row[1] ?? null;
			if (!is_string($name) || !is_string($type)) {
				continue;
			}

			if ($name == $this->getMigrationTable() || $type != "BASE TABLE") {
				continue;
			}

			$this->logTask($task, "  Adding table '" . $name . "'", self::MSG_VERBOSE);

			$table = new Table($name);
			$table->setIdMethod($database->getDefaultIdMethod());
			$database->addTable($table);
			$tables[] = $table;
		}

		// Now populate only columns.
		$this->logTask($task, "Reverse Engineering Columns", self::MSG_VERBOSE);

		foreach ($tables as $table) {
			$this->logTask($task, "  Adding columns for table '" . $table->getName() . "'", self::MSG_VERBOSE);
			$this->addColumns($table);
		}

		// Now add indices and constraints.
		$this->logTask($task, "Reverse Engineering Indices And Constraints", self::MSG_VERBOSE);

		foreach ($tables as $table) {
			$this->logTask($task, "  Adding indices and constraints for table '" . $table->getName() . "'", self::MSG_VERBOSE);

			$this->addForeignKeys($table);
			$this->addIndexes($table);
			$this->addPrimaryKey($table);

			if ($this->addVendorInfo) {
				$this->addTableVendorInfo($table);
			}
		}

		return count($tables);
	}

	/**
	 * Adds Columns to the specified table.
	 *
	 * @param      Table $table The Table model class to add columns to.
	 */
	protected function addColumns(Table $table): void
	{
		$stmt = $this->queryOrFail("SHOW COLUMNS FROM `" . $table->getName() . "`");

		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$column = $this->getColumnFromRow($row, $table);
			$table->addColumn($column);
		}

	} // addColumn()

	/**
	 * Factory method creating a Column object
	 * based on a row from the 'show columns from ' MySQL query result.
	 *
	 * @param     array<string, mixed> $row An associative array with the following keys:
	 *                       Field, Type, Null, Key, Default, Extra.
	 * @return    Column
	 */
	public function getColumnFromRow(array $row, Table $table): Column
	{
		$name = self::rowValueToString($row['Field'] ?? '');
		$rowType = self::rowValueToString($row['Type'] ?? '');
		$is_nullable = (($row['Null'] ?? null) == 'YES');
		$autoincrement = (strpos(self::rowValueToString($row['Extra'] ?? ''), 'auto_increment') !== false);
		$size = null;
		$precision = null;
		$scale = null;
		$sqlType = false;

		$regexp = '/^
			(\w+)        # column type [1]
			[\(]         # (
				?([\d,]*)  # size or size, precision [2]
			[\)]         # )
			?\s*         # whitespace
			(\w*)        # extra description (UNSIGNED, CHARACTER SET, ...) [3]
		$/x';
		if (preg_match($regexp, $rowType, $matches)) {
			$nativeType = $matches[1];
			if ($matches[2]) {
				if (($cpos = strpos($matches[2], ',')) !== false) {
					$size = (int) substr($matches[2], 0, $cpos);
					$precision = $size;
					$scale = (int) substr($matches[2], $cpos + 1);
				} else {
					$size = (int) $matches[2];
				}
			}
			if ($matches[3]) {
				$sqlType = $rowType;
			}
			foreach (self::$defaultTypeSizes as $type => $defaultSize) {
				if ($nativeType == $type && $size == $defaultSize) {
					$size = null;
					continue;
				}
			}
		} elseif (preg_match('/^(\w+)\(/', $rowType, $matches)) {
			$nativeType = $matches[1];
			if ($nativeType == 'enum') {
				$sqlType = $rowType;
			}
		} else {
			$nativeType = $rowType;
		}

		//BLOBs can't have any default values in MySQL
		$rawDefault = preg_match('~blob|text~', $nativeType) ? null : ($row['Default'] ?? null);
		$default = $rawDefault !== null ? self::rowValueToString($rawDefault) : null;

		$propelType = $this->getMappedPropulsionType($nativeType);
		if (!$propelType) {
			$propelType = Column::DEFAULT_TYPE;
			$sqlType = $rowType;
			$this->warn("Column [" . $table->getName() . "." . $name. "] has a column type (".$nativeType.") that Propulsion does not support.");
		}

		// Special case for TINYINT(1) which is a BOOLEAN
		if (PropulsionTypes::TINYINT === $propelType && 1 === $size) {
			$propelType = PropulsionTypes::BOOLEAN;
		}

		$column = new Column($name);
		$column->setTable($table);
		$column->setDomainForType($propelType);
		if ($sqlType) {
			$column->getDomain()->replaceSqlType($sqlType);
		}
		$column->getDomain()->replaceSize($size);
		$column->getDomain()->replaceScale($scale);
		if ($default !== null) {
			if ($propelType == PropulsionTypes::BOOLEAN) {
				if ($default == '1') $default = 'true';
				if ($default == '0') $default = 'false';
			}
			if (in_array($default, array('CURRENT_TIMESTAMP'))) {
				$type = ColumnDefaultValue::TYPE_EXPR;
			} else {
				$type = ColumnDefaultValue::TYPE_VALUE;
			}
			$column->getDomain()->setDefaultValue(new ColumnDefaultValue($default, $type));
		}
		$column->setAutoIncrement($autoincrement);
		$column->setNotNull(!$is_nullable);

		if ($this->addVendorInfo) {
			$vi = $this->getNewVendorInfoObject($row);
			$column->addVendorInfo($vi);
		}

		return $column;
	}

	/**
	 * Load foreign keys for this table.
	 */
	protected function addForeignKeys(Table $table): void
	{
		$database = $table->getDatabase();
		if ($database === null) {
			throw new EngineException("Table '" . $table->getName() . "' is not attached to a database; cannot resolve its foreign keys.");
		}

		$stmt = $this->queryOrFail("SHOW CREATE TABLE `" . $table->getName(). "`");
		$row = $stmt->fetch(PDO::FETCH_NUM);
		$createTableSql = is_array($row) && isset($row[1]) && is_string($row[1]) ? $row[1] : '';

		$foreignKeys = array(); // local store to avoid duplicates

		// Get the information on all the foreign keys
		$regEx = '/CONSTRAINT `([^`]+)` FOREIGN KEY \((.+)\) REFERENCES `([^`]*)` \((.+)\)(.*)/';
		if (preg_match_all($regEx, $createTableSql, $matches)) {
			$tmpArray = array_keys($matches[0]);
			foreach ($tmpArray as $curKey) {
				$name = $matches[1][$curKey];
				$rawlcol = $matches[2][$curKey];
				$ftbl = $matches[3][$curKey];
				$rawfcol = $matches[4][$curKey];
				$fkey = $matches[5][$curKey];

				$lcols = array();
				foreach (preg_split('/`, `/', $rawlcol) ?: [] as $piece) {
					$lcols[] = trim($piece, '` ');
				}

				$fcols = array();
				foreach (preg_split('/`, `/', $rawfcol) ?: [] as $piece) {
					$fcols[] = trim($piece, '` ');
				}

				//typical for mysql is RESTRICT
				$fkactions = array(
					'ON DELETE'	=> ForeignKey::RESTRICT,
					'ON UPDATE'	=> ForeignKey::RESTRICT,
				);

				if ($fkey) {
					//split foreign key information -> search for ON DELETE and afterwords for ON UPDATE action
					foreach (array_keys($fkactions) as $fkaction) {
						$result = NULL;
						preg_match('/' . $fkaction . ' (' . ForeignKey::CASCADE . '|' . ForeignKey::SETNULL . ')/', $fkey, $result);
						if ($result) {
							$fkactions[$fkaction] = $result[1];
						}
					}
				}

				// restrict is the default
				foreach ($fkactions as $key => $action) {
					if ($action == ForeignKey::RESTRICT) {
						$fkactions[$key] = null;
					}
				}

				$localColumns = array();
				$foreignColumns = array();
				$foreignTable = $database->getTable($ftbl, true);
				if ($foreignTable === null) {
					throw new EngineException("Table '" . $table->getName() . "' has a foreign key referencing unknown table '$ftbl'.");
				}

				foreach($fcols as $fcol) {
					$foreignColumns[] = $foreignTable->getColumn($fcol);
				}
				foreach($lcols as $lcol) {
					$localColumns[] = $table->getColumn($lcol);
				}

				if (!isset($foreignKeys[$name])) {
					$fk = new ForeignKey($name);
					$fk->setForeignTableCommonName($foreignTable->getCommonName());
					$fk->setForeignSchemaName($foreignTable->getSchema());
					$fk->setOnDelete($fkactions['ON DELETE']);
					$fk->setOnUpdate($fkactions['ON UPDATE']);
					$table->addForeignKey($fk);
					$foreignKeys[$name] = $fk;
				}

				for($i=0; $i < count($localColumns); $i++) {
					$foreignKeys[$name]->addReference($localColumns[$i], $foreignColumns[$i]);
				}

			}

		}

	}

	/**
	 * Load indexes for this table
	 */
	protected function addIndexes(Table $table): void
	{
		$stmt = $this->queryOrFail("SHOW INDEX FROM `" . $table->getName() . "`");

		// Loop through the returned results, grouping the same key_name together
		// adding each column for that key.

		$indexes = array();
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$colName = self::rowValueToString($row["Column_name"] ?? '');
			$name = self::rowValueToString($row["Key_name"] ?? '');

			if ($name === "PRIMARY") {
				continue;
			}

			if (!isset($indexes[$name])) {
				$isUnique = (($row["Non_unique"] ?? null) == 0);
				if ($isUnique) {
					$indexes[$name] = new Unique($name);
				} else {
					$indexes[$name] = new Index($name);
				}
				if ($this->addVendorInfo) {
					$vi = $this->getNewVendorInfoObject($row);
					$indexes[$name]->addVendorInfo($vi);
				}
				$table->addIndex($indexes[$name]);
			}

			$column = $table->getColumn($colName);
			if ($column === null) {
				throw new EngineException("Index '$name' on table '" . $table->getName() . "' references unknown column '$colName'.");
			}
			$indexes[$name]->addColumn($column);
		}
	}

	/**
	 * Loads the primary key for this table.
	 */
	protected function addPrimaryKey(Table $table): void
	{
		$stmt = $this->queryOrFail("SHOW KEYS FROM `" . $table->getName() . "`");

		// Loop through the returned results, grouping the same key_name together
		// adding each column for that key.
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			// Skip any non-primary keys.
			if (($row['Key_name'] ?? null) !== 'PRIMARY') {
				continue;
			}
			$name = self::rowValueToString($row["Column_name"] ?? '');
			$column = $table->getColumn($name);
			if ($column === null) {
				throw new EngineException("Primary key on table '" . $table->getName() . "' references unknown column '$name'.");
			}
			$column->setPrimaryKey(true);
		}
	}

	/**
	 * Adds vendor-specific info for table.
	 *
	 * @param      Table $table
	 */
	protected function addTableVendorInfo(Table $table): void
	{
		$stmt = $this->queryOrFail("SHOW TABLE STATUS LIKE '" . $table->getName() . "'");
		$row = $this->fetchAssoc($stmt);
		if ($row === null) {
			throw new EngineException("No table status information returned for table '" . $table->getName() . "'.");
		}
		$vi = $this->getNewVendorInfoObject($row);
		$table->addVendorInfo($vi);
	}
}
