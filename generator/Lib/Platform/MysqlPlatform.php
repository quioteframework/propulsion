<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Platform;

/**
 * MySql PropulsionPlatformInterface implementation.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @version    $Revision$
 */
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseDiff;
use Propulsion\Generator\Model\Diff\PropulsionColumnDiff;
class MysqlPlatform extends DefaultPlatform
{
	/**
	 * Table/Column/Index/Unique/ForeignKey's own getName()/getTable()/
	 * getDatabase() getters are typed nullable throughout this codebase's
	 * model classes (schema-XML parsing leaves them unset until later in the
	 * load process), but by the time DDL generation actually runs on a real,
	 * fully-loaded schema they're always populated -- these helpers turn that
	 * implicit assumption into an explicit, real failure instead of silently
	 * widening this class's own types to tolerate null everywhere DDL
	 * string-building actually requires a real value. (Mirrors the same
	 * convention already used in DefaultPlatform/OraclePlatform/
	 * MssqlPlatform/PgsqlPlatform.)
	 */
	private function requireString(?string $value, string $description): string
	{
		if ($value === null) {
			throw new EngineException("$description is required but was null.");
		}
		return $value;
	}

	private function requireTable(?Table $table): Table
	{
		if ($table === null) {
			throw new EngineException('Expected a Table but got null.');
		}
		return $table;
	}

	private function requireColumn(?Column $column): Column
	{
		if ($column === null) {
			throw new EngineException('Expected a Column but got null.');
		}
		return $column;
	}

	private function requireDatabase(?Database $database): Database
	{
		if ($database === null) {
			throw new EngineException('Expected a Database but got null.');
		}
		return $database;
	}

	/**
	 * VendorInfo::getParameter()/GeneratorConfig::getBuildProperty() are both
	 * untyped (generic bags for arbitrary `<vendor><parameter>` XML
	 * attributes/build properties), but every one this class reads back out
	 * is, by construction, a plain string value -- this makes that
	 * assumption an explicit, checked one.
	 */
	private function requireStringParam(mixed $value, string $description): string
	{
		if (!is_string($value)) {
			throw new EngineException("$description is required but was not a string.");
		}
		return $value;
	}

	protected string $tableEngineKeyword = 'ENGINE';  // overwritten in build.properties
	// InnoDB has been MySQL's own default storage engine since 5.5 (2010) and is
	// required for supportsNativeDeleteTrigger() (foreign-key-driven ON DELETE
	// triggers need real FK support, which MyISAM never had) -- MyISAM is kept
	// available via mysqlTableType for anyone who still needs it, but a modern
	// install shouldn't have to opt into InnoDB by hand.
	protected string $defaultTableEngine = 'InnoDB';  // overwritten in build.properties

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize(): void
	{
		parent::initialize();
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BOOLEAN, "TINYINT", 1));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMERIC, "DECIMAL"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARCHAR, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BINARY, "BLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARBINARY, "MEDIUMBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARBINARY, "LONGBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BLOB, "LONGBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CLOB, "LONGTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TIMESTAMP, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::OBJECT, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::PHP_ARRAY, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::ENUM, "TINYINT"));
		// MySQL (5.7.8+) has a native JSON column type, but no separate binary/JSONB
		// variant like PostgreSQL -- map both Propulsion types onto it.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSON, "JSON"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSONB, "JSON"));
		// MySQL has no native UUID column type; fall back to the canonical
		// 36-character hyphenated textual representation (see
		// PropulsionTypes::UUID / Column::isUuidType()).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::UUID, "CHAR", 36));
		// No native interval/duration type -- emulated as a VARCHAR storing an
		// ISO-8601 duration string (e.g. "P1DT2H"), same convention as UUID above.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTERVAL, "VARCHAR", 32));
		// No native network address types or case-insensitive text -- emulated
		// as VARCHAR, sized to fit an IPv6/CIDR address, a MAC address, and
		// (for CITEXT) this platform's own plain-text fallback respectively.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INET, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CIDR, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::MACADDR, "VARCHAR", 17));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CITEXT, "TEXT"));
		// No native range types -- emulated as a VARCHAR storing the Postgres
		// range literal text (e.g. "[1,10)"), which Propulsion\Type\Range parses
		// the same way regardless of platform.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT4RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT8RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATERANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSTZRANGE, "VARCHAR", 64));
		// The *default* mapping only: emulated as plain text (the same
		// bracketed JSON VectorHandler already produces), because MariaDB
		// 11.7+/MySQL 9.0+'s real native `VECTOR` type rejects a plain
		// bound/literal bracket-JSON string outright ("Incorrect vector
		// value", confirmed live against a real MariaDB 11.8 server) and
		// needs VEC_FromText()/VEC_ToText() wrapped around the value at the
		// SQL level instead.
		//
		// That wrapping now exists -- DBAdapter::getColumnBindExpression()/
		// getColumnSelectExpression(), see DBMySQL's overrides -- so a column
		// can opt into the real type with `nativeVector="true"`, handled in
		// getColumnDDL() below. It stays opt-in rather than becoming the
		// default for the same reason nativeUuid does: MysqlPlatform serves
		// both MySQL and MariaDB, and generator time has no live connection
		// to tell which server a schema targets. MySQL 9.0+'s own native
		// VECTOR spells its conversion functions STRING_TO_VECTOR()/
		// VECTOR_TO_STRING() and is not covered by that flag.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VECTOR, "TEXT"));
		// Emulated as plain text (WKT), not MySQL's own real `GEOMETRY` type --
		// see PropulsionTypes::GEOMETRY_NATIVE_TYPE for why.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::GEOMETRY, "TEXT"));
		// No native full-text-search vector type -- emulated as plain text;
		// see PgsqlPlatform for the real `tsvector` mapping.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSVECTOR, "TEXT"));
	}

	public function setGeneratorConfig(GeneratorConfig $generatorConfig): void
	{
		if ($defaultTableEngine = $generatorConfig->getBuildProperty('mysqlTableType')) {
			$this->defaultTableEngine = $this->requireStringParam($defaultTableEngine, 'mysqlTableType build property');
		}
		if ($tableEngineKeyword = $generatorConfig->getBuildProperty('mysqlTableEngineKeyword')) {
			$this->tableEngineKeyword = $this->requireStringParam($tableEngineKeyword, 'mysqlTableEngineKeyword build property');
		}
	}

	/**
	 * Setter for the tableEngineKeyword property
	 *
	 * @param string $tableEngineKeyword
	 */
	function setTableEngineKeyword($tableEngineKeyword): void
	{
		$this->tableEngineKeyword = $tableEngineKeyword;
	}

	/**
	 * Getter for the tableEngineKeyword property
	 *
	 * @return string
	 */
	function getTableEngineKeyword()
	{
		return $this->tableEngineKeyword;
	}

	/**
	 * Setter for the defaultTableEngine property
	 *
	 * @param string $defaultTableEngine
	 */
	function setDefaultTableEngine($defaultTableEngine): void
	{
		$this->defaultTableEngine = $defaultTableEngine;
	}

	/**
	 * Getter for the defaultTableEngine property
	 *
	 * @return string
	 */
	function getDefaultTableEngine()
	{
		return $this->defaultTableEngine;
	}

	public function getAutoIncrement()
	{
		return "AUTO_INCREMENT";
	}

	public function getMaxColumnNameLength()
	{
		return 64;
	}

	public function supportsNativeDeleteTrigger()
	{
		return strtolower($this->getDefaultTableEngine()) == 'innodb';
	}

	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getCommentBlockDDL($this->requireString($table->getName(), 'Table name'));
			$ret .= $this->getDropTableDDL($table);
			$ret .= $this->getAddSequenceDDL($table);
			$ret .= $this->getAddTableDDL($table);
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	/**
	 * Overridden only to look past `Table::finalize()`'s "idMethod=native but
	 * no autoIncrement column on the table -> silently downgrade to
	 * NO_ID_METHOD" rule (see Table.php) -- the same override
	 * MssqlPlatform::getSequenceName() already needs and explains in more
	 * detail: a table using an explicit `nativeSequence`-backed sequence has
	 * no reason to also declare an autoIncrement column (its column instead
	 * uses `defaultExpr="NEXTVAL(<sequence>)"`), so it never stays NATIVE
	 * post-finalize() the way Postgres's own usage of this same
	 * `<id-method-parameter>` mechanism does. Falls through to the inherited
	 * NATIVE-gated behavior whenever no id-method-parameter is declared.
	 */
	public function getSequenceName(Table $table): ?string
	{
		$idMethodParams = $table->getIdMethodParameters();
		if (empty($idMethodParams)) {
			return parent::getSequenceName($table);
		}
		return $idMethodParams[0]->getValue();
	}

	/**
	 * A real MariaDB 10.3+ `CREATE SEQUENCE` object for a table declared both
	 * `nativeSequence="true"` and a named `<id-method-parameter value="...">`
	 * -- see Table::isNativeSequence()'s own docblock for why both are
	 * required (plain MySQL supports neither concept). Mirrors
	 * MssqlPlatform::getAddSequenceDDL() exactly except for the opt-in gate
	 * (MSSQL needs none: every supported SQL Server version has sequences) and
	 * the value a column pulls its next id from -- MariaDB's own `NEXTVAL(seq)`
	 * function-call syntax, unlike Postgres's `nextval('seq')` or MSSQL's
	 * `NEXT VALUE FOR seq` -- which a column declares via the existing
	 * `defaultExpr="NEXTVAL(<sequence>)"` raw-expression column default, the
	 * same mechanism MSSQL's identical feature already uses.
	 */
	protected function getAddSequenceDDL(Table $table): ?string
	{
		if (!$table->isNativeSequence() || empty($table->getIdMethodParameters())) {
			return null;
		}
		$sequenceName = $this->requireString($this->getSequenceName($table), 'Sequence name');
		$pattern = "
CREATE SEQUENCE %s START WITH 1 INCREMENT BY 1;
";
		return sprintf($pattern, $this->quoteIdentifier($sequenceName));
	}

	protected function getDropSequenceDDL(Table $table): ?string
	{
		if (!$table->isNativeSequence() || empty($table->getIdMethodParameters())) {
			return null;
		}
		$sequenceName = $this->requireString($this->getSequenceName($table), 'Sequence name');
		$pattern = "
DROP SEQUENCE IF EXISTS %s;
";
		return sprintf($pattern, $this->quoteIdentifier($sequenceName));
	}

	public function getBeginDDL()
	{
		return "
# This is a fix for InnoDB in MySQL >= 4.1.x
# It \"suspends judgement\" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;
";
	}

	public function getEndDDL()
	{
		return "
# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
";
	}

	public function getAddTableDDL(Table $table)
	{
		$lines = array();

		foreach ($table->getColumns() as $column) {
			$lines[] = $this->getColumnDDL($column);
		}

		if ($table->hasPrimaryKey()) {
			$lines[] = $this->getPrimaryKeyDDL($table);
		}

		foreach ($table->getUnices() as $unique) {
			$lines[] = $this->getUniqueDDL($unique);
		}

		foreach ($table->getIndices() as $index ) {
			$lines[] = $this->getIndexDDL($index);
		}

		foreach ($table->getForeignKeys() as $foreignKey) {
			if ($foreignKey->isSkipSql()) {
				continue;
			}
			$lines[] = str_replace("
	", "
		", $this->getForeignKeyDDL($foreignKey));
		}

		$vendorSpecific = $table->getVendorInfoForType('mysql');
		if ($vendorSpecific->hasParameter('Type')) {
			$mysqlTableType = $this->requireStringParam($vendorSpecific->getParameter('Type'), 'mysql vendor "Type" parameter');
		} elseif ($vendorSpecific->hasParameter('Engine')) {
			$mysqlTableType = $this->requireStringParam($vendorSpecific->getParameter('Engine'), 'mysql vendor "Engine" parameter');
		} else {
			$mysqlTableType = $this->getDefaultTableEngine();
		}

		$tableOptions = $this->getTableOptions($table);

		if ($table->getDescription()) {
			$tableOptions []= 'COMMENT=' . $this->quote($this->requireString($table->getDescription(), 'Table description'));
		}

		$tableOptions = $tableOptions ? ' ' . implode(' ', $tableOptions) : '';
		$sep = ",
	";

		$pattern = "
CREATE TABLE %s
(
	%s
) %s=%s%s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			implode($sep, $lines),
			$this->getTableEngineKeyword(),
			$mysqlTableType,
			$tableOptions
		);
	}

	/**
	 * @return     string[]
	 */
	protected function getTableOptions(Table $table): array
	{
		$dbVI = $this->requireDatabase($table->getDatabase())->getVendorInfoForType('mysql');
		$tableVI = $table->getVendorInfoForType('mysql');
		$vi = $dbVI->getMergedVendorInfo($tableVI);
		$tableOptions = array();
		// List of supported table options
		// see http://dev.mysql.com/doc/refman/5.5/en/create-table.html
		$supportedOptions = array(
			'AutoIncrement'   => 'AUTO_INCREMENT',
			'AvgRowLength'    => 'AVG_ROW_LENGTH',
			'Charset'         => 'CHARACTER SET',
			'Checksum'        => 'CHECKSUM',
			'Collate'         => 'COLLATE',
			'Connection'      => 'CONNECTION',
			'DataDirectory'   => 'DATA DIRECTORY',
			'Delay_key_write' => 'DELAY_KEY_WRITE',
			'DelayKeyWrite'   => 'DELAY_KEY_WRITE',
			'IndexDirectory'  => 'INDEX DIRECTORY',
			'InsertMethod'    => 'INSERT_METHOD',
			'KeyBlockSize'    => 'KEY_BLOCK_SIZE',
			'MaxRows'         => 'MAX_ROWS',
			'MinRows'         => 'MIN_ROWS',
			'Pack_Keys'       => 'PACK_KEYS',
			'PackKeys'        => 'PACK_KEYS',
			'RowFormat'       => 'ROW_FORMAT',
			'Union'           => 'UNION',
		);
		foreach ($supportedOptions as $name => $sqlName) {
			if ($vi->hasParameter($name)) {
				$tableOptions []= sprintf('%s=%s',
					$sqlName,
					$this->quote($this->requireStringParam($vi->getParameter($name), sprintf('mysql vendor "%s" parameter', $name)))
				);
			} elseif ($vi->hasParameter($sqlName)) {
				$tableOptions []= sprintf('%s=%s',
					$sqlName,
					$this->quote($this->requireStringParam($vi->getParameter($sqlName), sprintf('mysql vendor "%s" parameter', $sqlName)))
				);
			}
		}
		return $tableOptions;
	}

	public function getDropTableDDL(Table $table)
	{
		$ret = "
DROP TABLE IF EXISTS " . $this->quoteIdentifier($this->requireString($table->getName(), 'Table name')) . ";
";
		$ret .= $this->getDropSequenceDDL($table);
		return $ret;
	}

	/**
	 * Generated/virtual columns (`generatedAs="expr"`/`generatedType="virtual"|
	 * "stored"` -- the same platform-generic `Column` attributes
	 * SqlitePlatform's own generated-column support introduced, and
	 * MssqlPlatform's computed columns reuse). MySQL's grammar is closer to
	 * SQLite's than MSSQL's here: `GENERATED ALWAYS AS (expr) VIRTUAL|STORED`
	 * is spelled out in full either way (unlike MSSQL's bare `AS (expr)` with
	 * `PERSISTED` only for the stored case), and `NOT NULL` is legal on both
	 * VIRTUAL and STORED generated columns (unlike MSSQL, which only allows it
	 * alongside `PERSISTED`) -- confirmed against a live MariaDB 11.8 server.
	 * A generated column can't also carry a `DEFAULT` or `AUTO_INCREMENT`, the
	 * same mutual exclusivity SqlitePlatform's own version already has.
	 */
	private function getGeneratedColumnDDL(Column $col): string
	{
		$domain = $col->getDomain();
		$sqlType = $this->requireString($domain->getSqlType(), 'Column SQL type');

		$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')));
		if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl []= $sqlType . $domain->printSize();
		} else {
			$ddl []= $sqlType;
		}
		$ddl []= sprintf('GENERATED ALWAYS AS (%s) %s', $col->getGeneratedExpr(), $col->getGeneratedType());
		if ($notNull = $this->getNullString($col->isNotNull())) {
			$ddl []= $notNull;
		}
		if ($col->getDescription()) {
			$ddl []= 'COMMENT ' . $this->quote($col->getDescription());
		}

		return implode(' ', $ddl);
	}

	public function getColumnDDL(Column $col)
	{
		if ($col->isGenerated()) {
			return $this->getGeneratedColumnDDL($col);
		}

		$domain = $col->getDomain();
		$sqlType = $this->requireString($domain->getSqlType(), 'Column SQL type');
		$notNullString = $this->getNullString($col->isNotNull());
		$defaultSetting = $this->getColumnDefaultValueDDL($col);

		// Special handling of TIMESTAMP/DATETIME types ...
		if ($sqlType == 'DATETIME') {
			$def = $domain->getDefaultValue();
			if ($def && $def->isExpression()) { // DATETIME values can only have constant expressions
				$sqlType = 'TIMESTAMP';
			}
		} elseif ($sqlType == 'DATE') {
			$def = $domain->getDefaultValue();
			if ($def && $def->isExpression()) {
				throw new EngineException("DATE columns cannot have default *expressions* in MySQL.");
			}
		} elseif ($sqlType == 'TEXT' || $sqlType == 'BLOB') {
			if ($domain->getDefaultValue()) {
				throw new EngineException("BLOB and TEXT columns cannot have DEFAULT values. in MySQL.");
			}
		}

		$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')));
		if ($col->isEnumType() && $col->isNativeEnum()) {
			// MySQL's own native ENUM(...) column type stores the label text
			// directly -- no separate CHECK constraint or custom type needed,
			// unlike the SQLite/Oracle/Postgres native-enum mechanisms.
			$ddl []= $this->getEnumSqlType($col);
		} elseif ($col->isSetType()) {
			// Unlike ENUM (opt-in via nativeEnum -- an emulated integer index
			// is a real, meaningful alternative there), SET's only sane
			// emulated form elsewhere is the same comma-joined text its native
			// wire format already is, so this is unconditional: no opt-in flag
			// needed, MySQL always gets the real SET(...) column type.
			$ddl []= $this->getSetSqlType($col);
		} elseif ($col->isUuidType() && $col->isNativeUuid()) {
			// MariaDB 10.7+'s real native UUID column type -- opt-in (see
			// Column::isNativeUuid()'s own docblock for why: plain MySQL has
			// no equivalent at any version, and there's no live connection at
			// generator time to auto-detect which server this schema targets).
			// No size parameter, unlike the CHAR(36) emulation it replaces.
			$ddl []= 'UUID';
		} elseif ($col->isVectorType() && $col->isNativeVector()) {
			// MariaDB 11.7+'s real native VECTOR(n) column type in place of the
			// bracketed-JSON-in-TEXT emulation the domain mapping above sets up.
			// Opt-in for the same "no build-time way to tell MariaDB from MySQL"
			// reason as nativeUuid above, plus its own: reading and writing this
			// column needs VEC_ToText()/VEC_FromText() wrapped around it at the
			// SQL level, which only happens because ColumnMap::isNativeVector()
			// (emitted by TableMapBuilder from this same flag) switches
			// DBMySQL's column-SQL-rewriting hooks on for it.
			//
			// The dimension is required and comes from the ordinary `size`
			// attribute, the same one printSize() renders for VARCHAR(n) -- but
			// hasSize() deliberately excludes the emulated "TEXT" mapping, so
			// it is spelled out here rather than reached through that path.
			$rawSize = $col->getSize();
			$size = is_numeric($rawSize) ? (int) $rawSize : 0;
			if ($size < 1) {
				throw new EngineException(sprintf(
					'Column "%s" is nativeVector="true" but has no size: MariaDB\'s VECTOR type requires an '
					. 'explicit dimension (e.g. size="1536").',
					$this->requireString($col->getName(), 'Column name')
				));
			}
			$ddl []= 'VECTOR(' . $size . ')';
		} elseif ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl []= $sqlType . $domain->printSize();
		} else {
			$ddl []= $sqlType;
		}
		if ($col->isNumericType()) {
			if ($col->isZerofill()) {
				// ZEROFILL implies UNSIGNED in MySQL/MariaDB even if `unsigned`
				// wasn't also set.
				$ddl []= 'UNSIGNED ZEROFILL';
			} elseif ($col->isUnsigned()) {
				$ddl []= 'UNSIGNED';
			}
		}
		$colinfo = $col->getVendorInfoForType($this->getDatabaseType());
		if ($colinfo->hasParameter('Charset')) {
			$ddl []= 'CHARACTER SET '. $this->quote($this->requireStringParam($colinfo->getParameter('Charset'), 'mysql vendor "Charset" parameter'));
		}
		if ($colinfo->hasParameter('Collation')) {
			$ddl []= 'COLLATE '. $this->quote($this->requireStringParam($colinfo->getParameter('Collation'), 'mysql vendor "Collation" parameter'));
		} elseif ($colinfo->hasParameter('Collate')) {
			$ddl []= 'COLLATE '. $this->quote($this->requireStringParam($colinfo->getParameter('Collate'), 'mysql vendor "Collate" parameter'));
		}
		if ($sqlType == 'TIMESTAMP') {
			if ($notNullString == '') {
				$notNullString = 'NULL';
			}
			if ($defaultSetting == '' && $notNullString == 'NOT NULL') {
				$defaultSetting = 'DEFAULT CURRENT_TIMESTAMP';
			}
			if ($notNullString) {
				$ddl []= $notNullString;
			}
			if ($defaultSetting) {
				$ddl []= $defaultSetting;
			}
		} else {
			if ($defaultSetting) {
				$ddl []= $defaultSetting;
			}
			if ($notNullString) {
				$ddl []= $notNullString;
			}
		}
		if ($autoIncrement = $col->getAutoIncrementString()) {
			$ddl []= $autoIncrement;
		}
		if ($col->getDescription()) {
			$ddl []= 'COMMENT ' . $this->quote($col->getDescription());
		}

		return implode(' ', $ddl);
	}

	/**
	 * Builds MySQL's inline native `ENUM('a', 'b', ...)` column type from a
	 * column's declared valueSet.
	 *
	 * @return     string
	 */
	protected function getEnumSqlType(Column $col)
	{
		$values = implode(', ', array_map(fn ($v) => $this->quote($v), $col->getValueSet()));
		return "ENUM({$values})";
	}

	/**
	 * Builds MySQL's inline native `SET('a', 'b', ...)` column type from a
	 * column's declared valueSet.
	 *
	 * @return     string
	 */
	protected function getSetSqlType(Column $col)
	{
		$values = implode(', ', array_map(fn ($v) => $this->quote($v), $col->getValueSet()));
		return "SET({$values})";
	}

	/**
	 * Creates a comma-separated list of column names for the index.
	 * For MySQL unique indexes there is the option of specifying size, so we cannot simply use
	 * the getColumnsList() method.
	 * @param      Index $index
	 * @return     string
	 */
	protected function getIndexColumnListDDL(Index $index): string
	{
		$list = array();
		foreach ($index->getColumns() as $col) {
			$list[] = $this->quoteIdentifier($col) . ($index->hasColumnSize($col) ? '(' . $index->getColumnSize($col) . ')' : '');
		}
		return implode(', ', $list);
	}

	/**
	 * Builds the DDL SQL to drop the primary key of a table.
	 *
	 * @param      Table $table
	 * @return     string
	 */
	public function getDropPrimaryKeyDDL(Table $table)
	{
		$pattern = "
ALTER TABLE %s DROP PRIMARY KEY;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name'))
		);
	}

	/**
	 * Builds the DDL SQL to add an Index.
	 *
	 * @param      Index $index
	 * @return     string
	 */
	public function getAddIndexDDL(Index $index)
	{
		$pattern = "
CREATE %sINDEX %s ON %s (%s);
";
		return sprintf($pattern,
			$this->getIndexType($index),
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->quoteIdentifier($this->requireString($this->requireTable($index->getTable())->getName(), 'Table name')),
			$this->getColumnListDDL($index->getColumns())
		);
	}

	/**
	 * Builds the DDL SQL to drop an Index.
	 *
	 * @param      Index $index
	 * @return     string
	 */
	public function getDropIndexDDL(Index $index)
	{
		$pattern = "
DROP INDEX %s ON %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->quoteIdentifier($this->requireString($this->requireTable($index->getTable())->getName(), 'Table name'))
		);
	}

	/**
	 * Builds the DDL SQL for an Index object.
	 * @return     string
	 */
	public function getIndexDDL(Index $index)
	{
		return sprintf('%sINDEX %s (%s)',
			$this->getIndexType($index),
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->getIndexColumnListDDL($index)
		);
	}

	/**
	 * Resolves the `CREATE {type}INDEX`/inline `{type}INDEX` prefix for
	 * $index. `Index::getIndexType()` (`indexType="fulltext"`/`"spatial"`,
	 * the same shared model-level flag PgsqlPlatform's `USING <method>`
	 * consults) takes priority as the modern, cross-platform-consistent way
	 * to ask for one; the legacy `<vendor type="mysql"><parameter
	 * name="Index_type" .../>` convention is still honored as a fallback for
	 * any schema still using it. A FULLTEXT/SPATIAL index can't also be
	 * UNIQUE, so `indexType`/`Index_type` both take priority over
	 * `isUnique()` when both are set.
	 */
	protected function getIndexType(Index $index): string
	{
		if ($index->getIndexType()) {
			return strtoupper($index->getIndexType()) . ' ';
		}
		$type = '';
		$vendorInfo = $index->getVendorInfoForType($this->getDatabaseType());
		if ($vendorInfo->getParameter('Index_type')) {
			$type = $this->requireStringParam($vendorInfo->getParameter('Index_type'), 'mysql vendor "Index_type" parameter') . ' ';
		} elseif ($index->isUnique()) {
			$type = 'UNIQUE ';
		}
		return $type;
	}

	public function getUniqueDDL(Unique $unique)
	{
		return sprintf('UNIQUE INDEX %s (%s)',
			$this->quoteIdentifier($this->requireString($unique->getName(), 'Unique constraint name')),
			$this->getIndexColumnListDDL($unique)
		);
	}

	public function getDropForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "
ALTER TABLE %s DROP FOREIGN KEY %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fk->getTable())->getName(), 'Table name')),
			$this->quoteIdentifier($this->requireString($fk->getName(), 'Foreign key name'))
		);
	}

	public function getCommentBlockDDL(string $comment): string
	{
		$pattern = "
-- ---------------------------------------------------------------------
-- %s
-- ---------------------------------------------------------------------
";
		return sprintf($pattern, $comment);
	}

	/**
	 * Builds the DDL SQL to modify a database
	 * based on a PropulsionDatabaseDiff instance
	 *
	 * @return     string
	 */
	public function getModifyDatabaseDDL(PropulsionDatabaseDiff $databaseDiff)
	{
		$ret = $this->getBeginDDL();

		foreach ($databaseDiff->getRemovedTables() as $table) {
			$ret .= $this->getDropTableDDL($table);
		}

		foreach ($databaseDiff->getRenamedTables() as $fromTableName => $toTableName) {
			$ret .= $this->getRenameTableDDL($fromTableName, $toTableName);
		}

		foreach ($databaseDiff->getModifiedTables() as $tableDiff) {
			$ret .= $this->getModifyTableDDL($tableDiff);
		}

		foreach ($databaseDiff->getAddedTables() as $table) {
			$ret .= $this->getAddTableDDL($table);
		}

		$ret .= $this->getEndDDL();

		return $ret;
	}

	/**
	 * Builds the DDL SQL to rename a table
	 * @return     string
	 */
	public function getRenameTableDDL(string $fromTableName, string $toTableName)
	{
		$pattern = "
RENAME TABLE %s TO %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($fromTableName),
			$this->quoteIdentifier($toTableName)
		);
	}

	/**
	 * Builds the DDL SQL to remove a column
	 *
	 * @return     string
	 */
	public function getRemoveColumnDDL(Column $column)
	{
		$pattern = "
ALTER TABLE %s DROP %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($column->getTable())->getName(), 'Table name')),
			$this->quoteIdentifier($this->requireString($column->getName(), 'Column name'))
		);
	}

	/**
	 * Builds the DDL SQL to rename a column
	 * @return     string
	 */
	public function getRenameColumnDDL(Column $fromColumn, Column $toColumn)
	{
		return $this->getChangeColumnDDL($fromColumn, $toColumn);
	}

	/**
	 * Builds the DDL SQL to modify a column
	 *
	 * @return     string
	 */
	public function getModifyColumnDDL(PropulsionColumnDiff $columnDiff)
	{
		return $this->getChangeColumnDDL(
			$this->requireColumn($columnDiff->getFromColumn()),
			$this->requireColumn($columnDiff->getToColumn())
		);
	}

	/**
	 * Builds the DDL SQL to change a column
	 * @return     string
	 */
	public function getChangeColumnDDL(Column $fromColumn, Column $toColumn)
	{
		$pattern = "
ALTER TABLE %s CHANGE %s %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fromColumn->getTable())->getName(), 'Table name')),
			$this->quoteIdentifier($this->requireString($fromColumn->getName(), 'Column name')),
			$this->getColumnDDL($toColumn)
		);
	}
	/**
	 * Builds the DDL SQL to modify a list of columns
	 *
	 * @return     string
	 */
	/**
	 * @param      array<string, PropulsionColumnDiff> $columnDiffs
	 */
	public function getModifyColumnsDDL(array $columnDiffs)
	{
		$ret = '';
		foreach ($columnDiffs as $columnDiff) {
			$ret .= $this->getModifyColumnDDL($columnDiff);
		}

		return $ret;
	}

	/**
	 * @see        Platform::supportsSchemas()
	 */
	public function supportsSchemas()
	{
		return true;
	}

	public function hasSize($sqlType)
	{
		// "TEXT" is excluded alongside its MEDIUMTEXT/LONGTEXT siblings even
		// though MySQL/MariaDB happen to accept TEXT(n) syntactically (picks
		// the smallest TEXT variant that fits n characters, confirmed live
		// against MariaDB 11.8) -- this codebase's own emulated-as-unbounded-
		// text columns (GEOMETRY, TSVECTOR, and now VECTOR, all mapped to
		// plain "TEXT" above) still carry their schema `size` attribute
		// (e.g. VECTOR's dimension) on the Domain regardless of platform, and
		// printing that as a TEXT(n) size annotation would misleadingly imply
		// a real length constraint where none exists -- matching
		// SqlitePlatform/MssqlPlatform's own hasSize() already excluding their
		// equivalent unbounded-text mappings for the same reason.
		return !("TEXT" == $sqlType || "MEDIUMTEXT" == $sqlType || "LONGTEXT" == $sqlType
				|| "BLOB" == $sqlType || "MEDIUMBLOB" == $sqlType
				|| "LONGBLOB" == $sqlType);
	}

	/**
	 * Escape the string for RDBMS.
	 * @param      string $text
	 * @return     string
	 */
	/*
	public function disconnectedEscapeText($text)
	{
		if (function_exists('mysql_escape_string')) {
			return mysql_escape_string($text);
		} else {
			return addslashes($text);
		}
	}
	*/
	
	/**
	 * MySQL documentation says that identifiers cannot contain '.'. Thus it
	 * should be safe to split the string by '.' and quote each part individually
	 * to allow for a <schema>.<table> or <table>.<column> syntax.
	 *
	 * @param       string $text the identifier
	 * @return      string the quoted identifier
	 */
	public function quoteIdentifier($text)
	{
		return $this->isIdentifierQuotingEnabled ? '`' . strtr($text, array('.' => '`.`')) . '`' : $text;
	}

	public function getTimestampFormatter()
	{
		return 'Y-m-d H:i:s';
	}
}
