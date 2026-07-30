<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Reverse\Oracle;

/**
 * Oracle database schema parser.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @author     Guillermo Gutierrez <ggutierrez@dailycosas.net> (Adaptation)
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
use Propulsion\Generator\Model\IdMethodParameter;
use Propulsion\Generator\Exception\EngineException;
use \PDO;
class OracleSchemaParser extends BaseSchemaParser
{
	/**
	 * Verbose logging level for optional $task->log() calls (matches the historical build-tool's verbose-log level).
	 */
	private const MSG_VERBOSE = 4;

	/**
	 * Map Oracle native types to Propulsion types.
	 *
	 * There really aren't any Oracle native types, so we're just
	 * using the MySQL ones here.
	 *
	 * Left as unsupported:
	 *   BFILE,
	 *   RAW,
	 *   ROWID
	 *
	 * Supported but non existant as a specific type in Oracle:
	 *   DECIMAL (NUMBER with scale),
	 *   DOUBLE (FLOAT with precision = 126)
	 *
	 * @var        array<string, string>
	 */
	private static $oracleTypeMap = array(
		'BLOB'		=> PropulsionTypes::BLOB,
		'CHAR'		=> PropulsionTypes::CHAR,
		'CLOB'		=> PropulsionTypes::CLOB,
		'DATE'		=> PropulsionTypes::TIMESTAMP,
		'BIGINT'	=> PropulsionTypes::BIGINT,
		'DECIMAL'	=> PropulsionTypes::DECIMAL,
		'DOUBLE'	=> PropulsionTypes::DOUBLE,
		'FLOAT'		=> PropulsionTypes::FLOAT,
		'LONG'		=> PropulsionTypes::LONGVARCHAR,
		'NCHAR'		=> PropulsionTypes::CHAR,
		'NCLOB'		=> PropulsionTypes::CLOB,
		'NUMBER'	=> PropulsionTypes::INTEGER,
		'NVARCHAR2'	=> PropulsionTypes::VARCHAR,
		'TIMESTAMP'	=> PropulsionTypes::TIMESTAMP,
		'VARCHAR2'	=> PropulsionTypes::VARCHAR,
	);

	/**
	 * Gets a type mapping from native types to Propulsion types
	 *
	 * @return     array<string, string>
	 */
	protected function getTypeMapping(): array
	{
		return self::$oracleTypeMap;
	}

	/**
	 * Searches for tables in the database. Maybe we want to search also the views.
	 * @param	Database $database The Database model class to add tables to.
	 */
	public function parse(Database $database, mixed $task = null)
	{
		$tables = array();
		$stmt = $this->queryOrFail("SELECT OBJECT_NAME FROM USER_OBJECTS WHERE OBJECT_TYPE = 'TABLE'");

		$generatorConfig = $this->getGeneratorConfig();
		$seqPattern = $generatorConfig !== null ? $generatorConfig->getBuildProperty('oracleAutoincrementSequencePattern') : null;
		$seqPattern = is_string($seqPattern) ? $seqPattern : null;

		$this->logTask($task, "Reverse Engineering Table Structures", self::MSG_VERBOSE);
		// First load the tables (important that this happen before filling out details of tables)
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$objectName = self::rowValueToString($row['OBJECT_NAME'] ?? '');
			if (strpos($objectName, '$') !== false) {
				// this is an Oracle internal table or materialized view - prune
				continue;
			}
			if (strtoupper($objectName) == strtoupper($this->getMigrationTable())) {
				continue;
			}
			$table = new Table($objectName);
			$table->setIdMethod($database->getDefaultIdMethod());
			$this->logTask($task, "Adding table '" . $table->getName() . "'", self::MSG_VERBOSE);
			$database->addTable($table);
			// Add columns, primary keys and indexes.
			$this->addColumns($table);
			$this->addPrimaryKey($table);
			$this->addIndexes($table);

			$pkColumns = $table->getPrimaryKey();
			if (count($pkColumns) == 1 && $seqPattern) {
				$seqName = str_replace('${table}', (string) $table->getName(), $seqPattern);
				$seqName = strtoupper($seqName);

				$stmt2 = $this->queryOrFail("SELECT * FROM USER_SEQUENCES WHERE SEQUENCE_NAME = '" . $seqName . "'");
				$hasSeq = $this->fetchAssoc($stmt2);

				if ($hasSeq !== null) {
					$pkColumns[0]->setAutoIncrement(true);
					$idMethodParameter = new IdMethodParameter();
					$idMethodParameter->setValue($seqName);
					$table->addIdMethodParameter($idMethodParameter);
				}
			}

			$tables[] = $table;
		}

		$this->logTask($task, "Reverse Engineering Foreign Keys", self::MSG_VERBOSE);

		foreach ($tables as $table) {
			$this->logTask($task, "Adding foreign keys for table '" . $table->getName() . "'", self::MSG_VERBOSE);
			$this->addForeignKeys($table);
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
		$stmt = $this->queryOrFail("SELECT COLUMN_NAME, DATA_TYPE, NULLABLE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, DATA_DEFAULT FROM USER_TAB_COLS WHERE TABLE_NAME = '" . $table->getName() . "'");
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$columnName = self::rowValueToString($row['COLUMN_NAME'] ?? '');
			if (strpos($columnName, '$') !== false) {
				// this is an Oracle internal column - prune
				continue;
			}
			$dataPrecision = self::rowValueToIntStringOrNull($row["DATA_PRECISION"] ?? null);
			$dataScale = self::rowValueToIntStringOrNull($row["DATA_SCALE"] ?? null);
			$size = $dataPrecision ? $dataPrecision : self::rowValueToIntStringOrNull($row["DATA_LENGTH"] ?? null);
			$scale = $dataScale;
			$default = $row['DATA_DEFAULT'] ?? null;
			if ($default !== null) {
				$default = self::rowValueToString($default);
			}
			$type = self::rowValueToString($row["DATA_TYPE"] ?? '');
			$isNullable = (($row['NULLABLE'] ?? null) == 'Y');
			if ($type == "NUMBER" && is_numeric($dataScale) && (int) $dataScale > 0) {
				$type = "DECIMAL";
			}
			if ($type == "NUMBER" && is_numeric($size) && (int) $size > 9) {
				$type = "BIGINT";
			}
			if ($type == "FLOAT" && is_numeric($dataPrecision) && (int) $dataPrecision == 126) {
				$type = "DOUBLE";
			}
			if (strpos($type, 'TIMESTAMP(') !== false) {
				$parenPos = strpos($type, '(');
				$type = $parenPos !== false ? substr($type, 0, $parenPos) : $type;
				$default = "0000-00-00 00:00:00";
				$size = null;
				$scale = null;
			}
			if ($type == "DATE") {
				$default = "0000-00-00";
				$size = null;
				$scale = null;
			}

			$propelType = $this->getMappedPropulsionType($type);
			if (!$propelType) {
				$propelType = Column::DEFAULT_TYPE;
				$this->warn("Column [" . $table->getName() . "." . $columnName. "] has a column type (".$type.") that Propulsion does not support.");
			}

			$column = new Column($columnName);
			$column->setPhpName(); // Prevent problems with strange col names
			$column->setTable($table);
			$column->setDomainForType($propelType);
			$column->getDomain()->replaceSize($size);
			$column->getDomain()->replaceScale($scale);
			if ($default !== null) {
				$column->getDomain()->setDefaultValue(new ColumnDefaultValue($default, ColumnDefaultValue::TYPE_VALUE));
			}
			$column->setAutoIncrement(false); // This flag sets in self::parse()
			$column->setNotNull(!$isNullable);
			$table->addColumn($column);
		}

	} // addColumn()

	/**
	 * Adds Indexes to the specified table.
	 *
	 * @param      Table $table The Table model class to add columns to.
	 */
	protected function addIndexes(Table $table): void
	{
		$stmt = $this->queryOrFail("SELECT COLUMN_NAME, INDEX_NAME FROM USER_IND_COLUMNS WHERE TABLE_NAME = '" . $table->getName() . "' ORDER BY COLUMN_NAME");

		/** @var array<string, list<string>> $indices */
		$indices = array();
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$indexName = self::rowValueToString($row['INDEX_NAME'] ?? '');
			$columnName = self::rowValueToString($row['COLUMN_NAME'] ?? '');
			$indices[$indexName][] = $columnName;
		}

		foreach ($indices as $indexName => $columnNames) {
			$index = new Index($indexName);
			foreach($columnNames AS $columnName) {
				// Oracle deals with complex indices using an internal reference, so...
				// let's ignore this kind of index
				$column = $table->getColumn($columnName);
				if ($column !== null) {
					$index->addColumn($column);
				}
			}
			// since some of the columns are pruned above, we must only add an index if it has columns
			if ($index->hasColumns()) {
				$table->addIndex($index);
			}
		}
	}

	/**
	 * Load foreign keys for this table.
	 *
	 * @param      Table $table The Table model class to add FKs to
	 */
	protected function addForeignKeys(Table $table): void
	{
		// local store to avoid duplicates
		$foreignKeys = array();

		$stmt = $this->queryOrFail("SELECT CONSTRAINT_NAME, DELETE_RULE, R_CONSTRAINT_NAME FROM USER_CONSTRAINTS WHERE CONSTRAINT_TYPE = 'R' AND TABLE_NAME = '" . $table->getName(). "'");
		while (($row = $this->fetchAssoc($stmt)) !== null) {
			$constraintName = self::rowValueToString($row['CONSTRAINT_NAME'] ?? '');
			$rConstraintName = self::rowValueToString($row['R_CONSTRAINT_NAME'] ?? '');

			// Local reference
			$stmt2 = $this->queryOrFail("SELECT COLUMN_NAME FROM USER_CONS_COLUMNS WHERE CONSTRAINT_NAME = '".$constraintName."' AND TABLE_NAME = '" . $table->getName(). "'");
			$localReferenceInfo = $this->fetchAssoc($stmt2);

			// Foreign reference
			$stmt2 = $this->queryOrFail("SELECT TABLE_NAME, COLUMN_NAME FROM USER_CONS_COLUMNS WHERE CONSTRAINT_NAME = '".$rConstraintName."'");
			$foreignReferenceInfo = $this->fetchAssoc($stmt2);

			if ($localReferenceInfo === null || $foreignReferenceInfo === null) {
				throw new EngineException("Foreign key constraint '$constraintName' on table '" . $table->getName() . "' is missing its local or foreign column reference.");
			}

			if (!isset($foreignKeys[$constraintName])) {
				$fk = new ForeignKey($constraintName);
				$fk->setForeignTableCommonName(self::rowValueToString($foreignReferenceInfo['TABLE_NAME'] ?? ''));
				$deleteRule = self::rowValueToString($row["DELETE_RULE"] ?? '');
				$onDelete = ($deleteRule == 'NO ACTION') ? 'NONE' : $deleteRule;
				$fk->setOnDelete($onDelete);
				$fk->setOnUpdate($onDelete);
				$fk->addReference(array(
					"local" => self::rowValueToString($localReferenceInfo['COLUMN_NAME'] ?? ''),
					"foreign" => self::rowValueToString($foreignReferenceInfo['COLUMN_NAME'] ?? ''),
				));
				$table->addForeignKey($fk);
				$foreignKeys[$constraintName] = $fk;
			}
		}
	}

	/**
	 * Loads the primary key for this table.
	 *
	 * @param      Table $table The Table model class to add PK to.
	 */
	protected function addPrimaryKey(Table $table): void
	{
		$stmt = $this->queryOrFail("SELECT COLS.COLUMN_NAME FROM USER_CONSTRAINTS CONS, USER_CONS_COLUMNS COLS WHERE CONS.CONSTRAINT_NAME = COLS.CONSTRAINT_NAME AND CONS.TABLE_NAME = '".$table->getName()."' AND CONS.CONSTRAINT_TYPE = 'P'");
		while (is_array($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
			// This fixes a strange behavior by PDO. Sometimes the
			// row values are inside an index 0 of an array
			if (isset($row[0]) && is_array($row[0])) {
				$row = $row[0];
			}
			$columnName = self::rowValueToString($row['COLUMN_NAME'] ?? '');
			$column = $table->getColumn($columnName);
			if ($column === null) {
				throw new EngineException("Primary key on table '" . $table->getName() . "' references unknown column '$columnName'.");
			}
			$column->setPrimaryKey(true);
		}
	}

}

