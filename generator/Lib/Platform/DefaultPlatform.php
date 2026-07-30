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
 * Default implementation for the Platform interface.
 *
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @version    $Revision$
 */
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseDiff;
use Propulsion\Generator\Model\Diff\PropulsionTableDiff;
use Propulsion\Generator\Model\Diff\PropulsionColumnDiff;
use \PDO;
class DefaultPlatform implements PropulsionPlatformInterface
{

	protected ?GeneratorConfig $generatorConfig = null;

	/**
	 * Mapping from Propulsion types to Domain objects.
	 *
	 * @var        array<string, Domain>
	 */
	protected $schemaDomainMap;

	/**
	 * @var        PDO|null Database connection.
	 */
	protected $con;

	/**
	 * @var        boolean whether the identifier quoting is enabled
	 */
	protected $isIdentifierQuotingEnabled = true;

	/**
	 * Default constructor.
	 * @param      PDO $con Optional database connection to use in this platform.
	 */
	public function __construct(?PDO $con = null)
	{
		if ($con) $this->setConnection($con);
		$this->initialize();
	}

	/**
	 * Set the database connection to use for this Platform class.
	 * @param      PDO $con Database connection to use in this platform.
	 */
	public function setConnection(?PDO $con = null): void
	{
		$this->con = $con;
	}

	/**
	 * Returns the database connection to use for this Platform class.
	 * @return     PDO|null The database connection or NULL if none has been set.
	 */
	public function getConnection()
	{
		return $this->con;
	}

	/**
	 * Sets the GeneratorConfig to use in the parsing.
	 *
	 * @param      GeneratorConfig $config
	 */
	public function setGeneratorConfig(GeneratorConfig $config): void
	{
		// do nothing by default
	}

	/**
	 * Table/Column/Index/ForeignKey/Domain's own getName()/getTable()/getType()
	 * getters are typed nullable throughout this codebase's model classes
	 * (schema-XML parsing leaves them unset until later in the load process),
	 * but by the time DDL generation actually runs on a real, fully-loaded
	 * schema they're always populated -- these helpers turn that implicit
	 * assumption into an explicit, real failure instead of silently widening
	 * this class's own types to tolerate null everywhere DDL string-building
	 * actually requires a real value. (Mirrors the same convention already
	 * used in OraclePlatform/MssqlPlatform.)
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

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
	 */
	protected function getBuildProperty($name)
	{
		if ($this->generatorConfig !== null) {
			return $this->generatorConfig->getBuildProperty($name);
		}
		return null;
	}

	/**
	 * Initialize the type -> Domain mapping.
	 */
	protected function initialize(): void
	{
		$this->schemaDomainMap = array();
		foreach (PropulsionTypes::getPropulsionTypes() as $type) {
			$this->schemaDomainMap[$type] = new Domain($type);
		}
		// BU_* no longer needed, so map these to the DATE/TIMESTAMP domains
		$this->schemaDomainMap[PropulsionTypes::BU_DATE] = new Domain(PropulsionTypes::DATE);
		$this->schemaDomainMap[PropulsionTypes::BU_TIMESTAMP] = new Domain(PropulsionTypes::TIMESTAMP);

		// Boolean is a bit special, since typically it must be mapped to INT type.
		$this->schemaDomainMap[PropulsionTypes::BOOLEAN] = new Domain(PropulsionTypes::BOOLEAN, "INTEGER");
	}

	/**
	 * Adds a mapping entry for specified Domain.
	 * @param      Domain $domain
	 */
	protected function setSchemaDomainMapping(Domain $domain): void
	{
		$this->schemaDomainMap[$this->requireString($domain->getType(), 'Domain type')] = $domain;
	}

	/**
	 * Returns the short name of the database type that this platform represents.
	 * For example MysqlPlatform->getDatabaseType() returns 'mysql'.
	 * @return     string
	 */
	public function getDatabaseType()
	{
		$clazz = (new \ReflectionClass($this))->getShortName();
		$pos = strpos($clazz, 'Platform');
		if ($pos === false) {
			throw new EngineException(sprintf('Platform class "%s" does not end in "Platform" as expected.', $clazz));
		}
		return strtolower(substr($clazz, 0, $pos));
	}

	/**
	 * Returns the max column length supported by the db.
	 *
	 * @return     int The max column length
	 */
	public function getMaxColumnNameLength()
	{
		return 64;
	}

	/**
	 * Returns the native IdMethod (sequence|identity)
	 *
	 * @return     string The native IdMethod (PropulsionPlatformInterface:IDENTITY, PropulsionPlatformInterface::SEQUENCE).
	 */
	public function getNativeIdMethod()
	{
		return PropulsionPlatformInterface::IDENTITY;
	}

	/**
	 * Returns the db specific domain for a propelType.
	 *
	 * @param      string $propelType the Propulsion type name.
	 * @return     Domain The db specific domain.
	 */
	public function getDomainForType($propelType)
	{
		if (!isset($this->schemaDomainMap[$propelType])) {
			throw new EngineException("Cannot map unknown Propulsion type " . var_export($propelType, true) . " to native database type.");
		}
		return $this->schemaDomainMap[$propelType];
	}

	/**
	 * @return     string The RDBMS-specific SQL fragment for <code>NULL</code>
	 * or <code>NOT NULL</code>.
	 */
	public function getNullString(bool $notNull)
	{
		return ($notNull ? "NOT NULL" : "");
	}

	/**
	 * @return     string The RDBMS-specific SQL fragment for autoincrement.
	 */
	public function getAutoIncrement()
	{
		return "IDENTITY";
	}

	/**
	 * Gets the name to use for creating a sequence for a table.
	 *
	 * This will create a new name or use one specified in an id-method-parameter
	 * tag, if specified.
	 *
	 * @param      Table $table
	 *
	 * @return     string Sequence name for this table.
	 */
	public function getSequenceName(Table $table): ?string
	{
		static $longNamesMap = array();
		$result = null;
		if ($table->getIdMethod() == IDMethod::NATIVE) {
			$tableName = $this->requireString($table->getName(), 'Table name');
			$idMethodParams = $table->getIdMethodParameters();
			$maxIdentifierLength = $this->getMaxColumnNameLength();
			if (empty($idMethodParams)) {
				if (strlen($tableName . "_SEQ") > $maxIdentifierLength) {
					if (!isset($longNamesMap[$tableName])) {
						$longNamesMap[$tableName] = strval(count($longNamesMap) + 1);
					}
					$result = substr($tableName, 0, $maxIdentifierLength - strlen("_SEQ_" . $longNamesMap[$tableName])) . "_SEQ_" . $longNamesMap[$tableName];
				}
				else {
					$result = substr($tableName, 0, $maxIdentifierLength -4) . "_SEQ";
				}
			} else {
				$result = substr($this->requireString($idMethodParams[0]->getValue(), 'Id method parameter value'), 0, $maxIdentifierLength);
			}
		}
		return $result;
	}

	/**
	 * Builds the DDL SQL to add the tables of a database
	 * together with index and foreign keys
	 *
	 * @return     string
	 */
	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getCommentBlockDDL($this->requireString($table->getName(), 'Table name'));
			$ret .= $this->getDropTableDDL($table);
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
			$ret .= $this->getAddForeignKeysDDL($table);
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	/**
	 * Gets the requests to execute at the beginning of a DDL file
	 *
	 * @return     string
	 */
	public function getBeginDDL()
	{
		return '';
	}

	/**
	 * Gets the requests to execute at the end of a DDL file
	 *
	 * @return     string
	 */
	public function getEndDDL()
	{
		return '';
	}

	/**
	 * Builds the DDL SQL to drop a table
	 * @return     string
	 */
	public function getDropTableDDL(Table $table)
	{
		return "
DROP TABLE " . $this->quoteIdentifier($this->requireString($table->getName(), 'Table name')) . ";
";
	}

	/**
	 * Builds the DDL SQL to add a table
	 * without index and foreign keys
	 *
	 * @return     string
	 */
	public function getAddTableDDL(Table $table)
	{
		$tableDescription = $table->hasDescription() ? $this->getCommentLineDDL($this->requireString($table->getDescription(), 'Table description')) : '';

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

		$sep = ",
	";

		$pattern = "
%sCREATE TABLE %s
(
	%s
);
";
		return sprintf($pattern,
			$tableDescription,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			implode($sep, $lines)
		);
	}

	/**
	 * Builds the DDL SQL for a Column object.
	 * @return     string
	 */
	public function getColumnDDL(Column $col)
	{
		$domain = $col->getDomain();

		$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')));
		$sqlType = $this->requireString($domain->getSqlType(), 'Column SQL type');
		if ($this->usesNativeEnumStorage($col)) {
			// A native-storage ENUM column holds the label text directly (see
			// getColumnDefaultValueDDL()/getEnumCheckConstraintDDL() below),
			// not the emulated integer index -- so it needs a text column
			// sized to the longest declared label, not the domain's
			// TINYINT/NUMBER emulated-int mapping.
			$valueSet = $col->getValueSet();
			if (empty($valueSet)) {
				throw new EngineException(sprintf('nativeEnum column "%s" has no valueSet', $col->getName()));
			}
			$maxLen = max(array_map('strlen', $valueSet));
			$ddl []= $this->getDomainForType(PropulsionTypes::VARCHAR)->getSqlType() . '(' . $maxLen . ')';
		} elseif ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl []= $sqlType . $domain->printSize();
		} else {
			$ddl []= $sqlType;
		}
		if ($default = $this->getColumnDefaultValueDDL($col)) {
			$ddl []= $default;
		}
		if ($notNull = $this->getNullString($col->isNotNull())) {
			$ddl []= $notNull;
		}
		if ($autoIncrement = $col->getAutoIncrementString()) {
			$ddl []= $autoIncrement;
		}
		if ($this->usesNativeEnumStorage($col)) {
			$ddl []= $this->getEnumCheckConstraintDDL($col);
		}

		return implode(' ', $ddl);
	}

	/**
	 * Returns the SQL for the default value of a Column object
	 * @return     string
	 */
	public function getColumnDefaultValueDDL(Column $col)
	{
		$default = '';
		$defaultValue = $col->getDefaultValue();
		if ($defaultValue !== null) {
			$default .= 'DEFAULT ';
			if ($defaultValue->isExpression()) {
				$default .= $defaultValue->getValue();
			} else {
				if ($col->getType() == PropulsionTypes::ENUM) {
					// A native-storage enum column stores (and therefore
					// defaults to) the label text itself; the emulated form
					// still defaults to the label's index into valueSet.
					$default .= $this->usesNativeEnumStorage($col)
						? $this->quote($defaultValue->getValue())
						: array_search($defaultValue->getValue(), $col->getValueSet());
				} elseif ($col->isTextType()) {
					$default .= $this->quote($defaultValue->getValue());
				} elseif ($col->getType() == PropulsionTypes::BOOLEAN || $col->getType() == PropulsionTypes::BOOLEAN_EMU) {
					$default .= $this->getBooleanString($defaultValue->getValue());
				} else {
					$default .= $defaultValue->getValue();
				}
			}
		}

		return $default;
	}

	/**
	 * Whether `nativeEnum="true"` on an ENUM column emits this platform's own
	 * native-ish enforcement (here: a CHECK constraint on the emulated text
	 * column) and stores the label text directly, instead of staying purely
	 * emulated as an integer index. MSSQL has no native enum mechanism and,
	 * by design, doesn't get an emulated CHECK constraint either --
	 * MssqlPlatform overrides this to false. MySQL and Postgres
	 * override getColumnDDL()/getAddTablesDDL() with their own native
	 * mechanisms entirely and don't consult this for their own column type,
	 * but do still rely on it via getColumnDefaultValueDDL() (inherited,
	 * unmodified) to know whether a default value is a label or an index.
	 *
	 * @return     boolean
	 */
	public function supportsNativeEnumDDL()
	{
		return true;
	}

	/**
	 * Whether this platform has a real native array column type (`type[]`)
	 * for a `nativeArray="true"` PHP_ARRAY column to use, instead of the
	 * default emulated `" | "`-delimited text. False by default -- only
	 * `PgsqlPlatform` overrides this; every other platform stays on the
	 * emulated format regardless of the `nativeArray` attribute.
	 *
	 * @return     boolean
	 */
	public function supportsNativeArrayDDL()
	{
		return false;
	}

	/**
	 * Whether $col is an ENUM column whose value is stored as the label text
	 * itself, rather than the emulated integer index -- i.e. `nativeEnum`
	 * is set AND this platform doesn't reject it (see supportsNativeEnumDDL()).
	 *
	 * @return     boolean
	 */
	protected function usesNativeEnumStorage(Column $col)
	{
		return $col->isEnumType() && $col->isNativeEnum() && $this->supportsNativeEnumDDL();
	}

	/**
	 * Builds a `CHECK (col IN (...))` constraint DDL fragment restricting a
	 * native-storage ENUM column to its declared valueSet labels -- the
	 * SQLite/Oracle enforcement mechanism, since neither has a real enum type.
	 *
	 * @return     string
	 */
	protected function getEnumCheckConstraintDDL(Column $col)
	{
		$values = implode(', ', array_map(fn ($v) => $this->quote($v), $col->getValueSet()));
		return sprintf('CHECK (%s IN (%s))', $this->quoteIdentifier($this->requireString($col->getName(), 'Column name')), $values);
	}

	/**
	 * Creates a delimiter-delimited string list of column names, quoted using quoteIdentifier().
	 * @example
	 * <code>
	 * echo $platform->getColumnListDDL(array('foo', 'bar');
	 * // '"foo","bar"'
	 * </code>
	 * @param      Column[]|string[] $columns
	 * @param      string $delimiter The delimiter to use in separating the column names.
	 *
	 * @return     string
	 */
	public function getColumnListDDL($columns, $delimiter = ',')
	{
		$list = array();
		foreach ($columns as $column) {
			$columnName = $column instanceof Column
				? $this->requireString($column->getName(), 'Column name')
				: $column;
			$list[] = $this->quoteIdentifier($columnName);
		}
		return implode($delimiter, $list);
	}

	/**
	 * Returns the name of a table primary key
	 * @return     string
	 */
	public function getPrimaryKeyName(Table $table)
	{
		$tableName = $table->getCommonName();
		return $tableName . '_PK';
	}

	/**
	 * Returns the SQL for the primary key of a Table object
	 * @return     string
	 */
	public function getPrimaryKeyDDL(Table $table)
	{
		if ($table->hasPrimaryKey()) {
			return 'PRIMARY KEY (' . $this->getColumnListDDL($table->getPrimaryKey()) . ')';
		}

		return '';
	}

	/**
	 * Builds the DDL SQL to drop the primary key of a table.
	 *
	 * @param      \Propulsion\Generator\Model\Table $table
	 * @return     string
	 */
	public function getDropPrimaryKeyDDL(Table $table)
	{
		$pattern = "
ALTER TABLE %s DROP CONSTRAINT %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			$this->quoteIdentifier($this->getPrimaryKeyName($table))
		);
	}

	/**
	 * Builds the DDL SQL to add the primary key of a table.
	 *
	 * @param      Table $table
	 * @return     string
	 */
	public function getAddPrimaryKeyDDL(Table $table)
	{
		$pattern = "
ALTER TABLE %s ADD %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			$this->getPrimaryKeyDDL($table)
		);
	}

	/**
	 * Builds the DDL SQL to add the indices of a table.
	 *
	 * @param      Table $table
	 * @return     string
	 */
	public function getAddIndicesDDL(Table $table)
	{
		$ret = '';
		foreach ($table->getIndices() as $fk) {
			$ret .= $this->getAddIndexDDL($fk);
		}
		return $ret;
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
			$index->isUnique() ? 'UNIQUE ' : '',
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->quoteIdentifier($this->requireString($this->requireTable($index->getTable())->getName(), 'Table name')),
			$this->getColumnListDDL($index->getColumns())
		);
	}

	/**
	 * Builds the DDL SQL for an index's column list, honoring
	 * Index::isExpressionAtPosition() -- a plain column name is quoted as an
	 * identifier as usual, while an expression entry is emitted verbatim,
	 * wrapped in an extra pair of parentheses (always safe/valid even when
	 * the expression is itself already parenthesized, e.g. a function call,
	 * and required when it isn't, e.g. `a || b`). Shared by any platform
	 * whose `CREATE INDEX` syntax accepts expression index columns
	 * (currently PgsqlPlatform and SqlitePlatform).
	 */
	protected function getIndexColumnListDDL(Index $index): string
	{
		$parts = array();
		foreach ($index->getColumns() as $pos => $column) {
			$parts[] = $index->isExpressionAtPosition($pos)
				? '(' . $column . ')'
				: $this->quoteIdentifier($column);
		}
		return implode(',', $parts);
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
DROP INDEX %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name'))
		);
	}

	/**
	 * Builds the DDL SQL for an Index object.
	 *
	 * @param      Index $index
	 * @return     string
	 */
	public function getIndexDDL(Index $index)
	{
		return sprintf('%sINDEX %s (%s)',
			$index->isUnique() ? 'UNIQUE ' : '',
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->getColumnListDDL($index->getColumns())
		);
	}

	/**
	 * Builds the DDL SQL for a Unique constraint object.
	 *
	 * @param      Unique $unique
	 * @return     string
	 */
	public function getUniqueDDL(Unique $unique)
	{
		return sprintf('UNIQUE (%s)' , $this->getColumnListDDL($unique->getColumns()));
	}

	/**
	 * Builds the DDL SQL to add the foreign keys of a table.
	 *
	 * @param      Table $table
	 * @return     string
	 */
	public function getAddForeignKeysDDL(Table $table)
	{
		$ret = '';
		foreach ($table->getForeignKeys() as $fk) {
			$ret .= $this->getAddForeignKeyDDL($fk);
		}
		return $ret;
	}

	/**
	 * Builds the DDL SQL to add a foreign key.
	 *
	 * @param      ForeignKey $fk
	 * @return     string
	 */
	public function getAddForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "
ALTER TABLE %s ADD %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fk->getTable())->getName(), 'Table name')),
			$this->getForeignKeyDDL($fk)
		);
	}

	/**
	 * Builds the DDL SQL to drop a foreign key.
	 *
	 * @param      ForeignKey $fk
	 * @return     string
	 */
	public function getDropForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "
ALTER TABLE %s DROP CONSTRAINT %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fk->getTable())->getName(), 'Table name')),
			$this->quoteIdentifier($this->requireString($fk->getName(), 'ForeignKey name'))
		);
	}

	/**
	 * Builds the DDL SQL for a ForeignKey object.
	 * @return     string
	 */
	public function getForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "CONSTRAINT %s
	FOREIGN KEY (%s)
	REFERENCES %s (%s)";
		$script = sprintf($pattern,
			$this->quoteIdentifier($this->requireString($fk->getName(), 'ForeignKey name')),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($this->requireString($fk->getForeignTableName(), 'Foreign table name')),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
		if ($fk->hasOnUpdate()) {
			$script .= "
	ON UPDATE " . $fk->getOnUpdate();
		}
		if ($fk->hasOnDelete()) {
			$script .= "
	ON DELETE " . $fk->getOnDelete();
		}

		return $script;
	}

	public function getCommentLineDDL(string $comment): string
	{
		$pattern = "-- %s
";
		return sprintf($pattern, $comment);
	}

	public function getCommentBlockDDL(string $comment): string
	{
		$pattern = "
-----------------------------------------------------------------------
-- %s
-----------------------------------------------------------------------
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

		foreach ($databaseDiff->getAddedTables() as $table) {
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
		}

		foreach ($databaseDiff->getModifiedTables() as $tableDiff) {
			$ret .= $this->getModifyTableDDL($tableDiff);
		}

		foreach ($databaseDiff->getAddedTables() as $table) {
			$ret .= $this->getAddForeignKeysDDL($table);
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
ALTER TABLE %s RENAME TO %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($fromTableName),
			$this->quoteIdentifier($toTableName)
		);
	}

	/**
	 * Builds the DDL SQL to alter a table
	 * based on a PropulsionTableDiff instance
	 *
	 * @return     string
	 */
	public function getModifyTableDDL(PropulsionTableDiff $tableDiff)
	{
		$ret = '';

		// drop indices, foreign keys
		if ($tableDiff->hasModifiedPk()) {
			$ret .= $this->getDropPrimaryKeyDDL($this->requireTable($tableDiff->getFromTable()));
		}
		foreach ($tableDiff->getRemovedFks() as $fk) {
			$ret .= $this->getDropForeignKeyDDL($fk);
		}
		foreach ($tableDiff->getModifiedFks() as $fkName => $fkModification) {
			list($fromFk, $toFk) = $fkModification;
			$ret .= $this->getDropForeignKeyDDL($fromFk);
		}
		foreach ($tableDiff->getRemovedIndices() as $index) {
			$ret .= $this->getDropIndexDDL($index);
		}
		foreach ($tableDiff->getModifiedIndices() as $indexName => $indexModification) {
			list($fromIndex, $toIndex) = $indexModification;
			$ret .= $this->getDropIndexDDL($fromIndex);
		}

		// alter table structure
		foreach ($tableDiff->getRenamedColumns() as $columnRenaming) {
			$ret .= $this->getRenameColumnDDL($columnRenaming[0], $columnRenaming[1]);
		}
		if ($modifiedColumns = $tableDiff->getModifiedColumns()) {
			$ret .= $this->getModifyColumnsDDL($modifiedColumns);
		}
		if ($addedColumns = $tableDiff->getAddedColumns()) {
			$ret .= $this->getAddColumnsDDL($addedColumns);
		}
		foreach ($tableDiff->getRemovedColumns() as $column) {
			$ret .= $this->getRemoveColumnDDL($column);
		}

		// add new indices and foreign keys
		if ($tableDiff->hasModifiedPk()) {
			$ret .= $this->getAddPrimaryKeyDDL($this->requireTable($tableDiff->getToTable()));
		}
		foreach ($tableDiff->getModifiedIndices() as $indexName => $indexModification) {
			list($fromIndex, $toIndex) = $indexModification;
			$ret .= $this->getAddIndexDDL($toIndex);
		}
		foreach ($tableDiff->getAddedIndices() as $index) {
			$ret .= $this->getAddIndexDDL($index);
		}
		foreach ($tableDiff->getModifiedFks() as $fkName => $fkModification) {
			list($fromFk, $toFk) = $fkModification;
			$ret .= $this->getAddForeignKeyDDL($toFk);
		}
		foreach ($tableDiff->getAddedFks() as $fk) {
			$ret .= $this->getAddForeignKeyDDL($fk);
		}

		return $ret;
	}

	/**
	 * Builds the DDL SQL to alter a table
	 * based on a PropulsionTableDiff instance
	 *
	 * @return     string
	 */
	public function getModifyTableColumnsDDL(PropulsionTableDiff $tableDiff)
	{
		$ret = '';

		foreach ($tableDiff->getRemovedColumns() as $column) {
			$ret .= $this->getRemoveColumnDDL($column);
		}

		foreach ($tableDiff->getRenamedColumns() as $columnRenaming) {
			$ret .= $this->getRenameColumnDDL($columnRenaming[0], $columnRenaming[1]);
		}

		if ($modifiedColumns = $tableDiff->getModifiedColumns()) {
			$ret .= $this->getModifyColumnsDDL($modifiedColumns);
		}

		if ($addedColumns = $tableDiff->getAddedColumns()) {
			$ret .= $this->getAddColumnsDDL($addedColumns);
		}

		return $ret;
	}

	/**
	 * Builds the DDL SQL to alter a table's primary key
	 * based on a PropulsionTableDiff instance
	 *
	 * @return     string
	 */
	public function getModifyTablePrimaryKeyDDL(PropulsionTableDiff $tableDiff)
	{
		$ret = '';

		if ($tableDiff->hasModifiedPk()) {
			$ret .= $this->getDropPrimaryKeyDDL($this->requireTable($tableDiff->getFromTable()));
			$ret .= $this->getAddPrimaryKeyDDL($this->requireTable($tableDiff->getToTable()));
		}

		return $ret;
	}

	/**
	 * Builds the DDL SQL to alter a table's indices
	 * based on a PropulsionTableDiff instance
	 *
	 * @return     string
	 */
	public function getModifyTableIndicesDDL(PropulsionTableDiff $tableDiff)
	{
		$ret = '';

		foreach ($tableDiff->getRemovedIndices() as $index) {
			$ret .= $this->getDropIndexDDL($index);
		}

		foreach ($tableDiff->getAddedIndices() as $index) {
			$ret .= $this->getAddIndexDDL($index);
		}

		foreach ($tableDiff->getModifiedIndices() as $indexName => $indexModification) {
			list($fromIndex, $toIndex) = $indexModification;
			$ret .= $this->getDropIndexDDL($fromIndex);
			$ret .= $this->getAddIndexDDL($toIndex);
		}

		return $ret;
	}

	/**
	 * Builds the DDL SQL to alter a table's foreign keys
	 * based on a PropulsionTableDiff instance
	 *
	 * @return     string
	 */
	public function getModifyTableForeignKeysDDL(PropulsionTableDiff $tableDiff)
	{
		$ret = '';

		foreach ($tableDiff->getRemovedFks() as $fk) {
			$ret .= $this->getDropForeignKeyDDL($fk);
		}

		foreach ($tableDiff->getAddedFks() as $fk) {
			$ret .= $this->getAddForeignKeyDDL($fk);
		}

		foreach ($tableDiff->getModifiedFks() as $fkName => $fkModification) {
			list($fromFk, $toFk) = $fkModification;
			$ret .= $this->getDropForeignKeyDDL($fromFk);
			$ret .= $this->getAddForeignKeyDDL($toFk);
		}

		return $ret;
	}

	/**
	 * Builds the DDL SQL to remove a column
	 *
	 * @return     string
	 */
	public function getRemoveColumnDDL(Column $column)
	{
		$pattern = "
ALTER TABLE %s DROP COLUMN %s;
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
		$pattern = "
ALTER TABLE %s RENAME COLUMN %s TO %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fromColumn->getTable())->getName(), 'Table name')),
			$this->quoteIdentifier($this->requireString($fromColumn->getName(), 'Column name')),
			$this->quoteIdentifier($this->requireString($toColumn->getName(), 'Column name'))
		);
	}

	/**
	 * Builds the DDL SQL to modify a column
	 *
	 * @return     string
	 */
	public function getModifyColumnDDL(PropulsionColumnDiff $columnDiff)
	{
		$toColumn = $this->requireColumn($columnDiff->getToColumn());
		$pattern = "
ALTER TABLE %s MODIFY %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($toColumn->getTable())->getName(), 'Table name')),
			$this->getColumnDDL($toColumn)
		);
	}

	/**
	 * Builds the DDL SQL to modify a list of columns
	 *
	 * @param      array<string, PropulsionColumnDiff> $columnDiffs
	 * @return     string
	 */
	public function getModifyColumnsDDL(array $columnDiffs)
	{
		$lines = array();
		$tableName = null;
		foreach ($columnDiffs as $columnDiff) {
			$toColumn = $this->requireColumn($columnDiff->getToColumn());
			if (null === $tableName) {
				$tableName = $this->requireString($this->requireTable($toColumn->getTable())->getName(), 'Table name');
			}
			$lines []= $this->getColumnDDL($toColumn);
		}

		$sep = ",
	";

		$pattern = "
ALTER TABLE %s MODIFY
(
	%s
);
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($tableName, 'Table name')),
			implode($sep, $lines)
		);
	}

	/**
	 * Builds the DDL SQL to remove a column
	 *
	 * @return     string
	 */
	public function getAddColumnDDL(Column $column)
	{
		$pattern = "
ALTER TABLE %s ADD %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($column->getTable())->getName(), 'Table name')),
			$this->getColumnDDL($column)
		);
	}

	/**
	 * Builds the DDL SQL to remove a list of columns
	 *
	 * @param      array<string, Column> $columns
	 * @return     string
	 */
	public function getAddColumnsDDL(array $columns)
	{
		$lines = array();
		$tableName = null;
		foreach ($columns as $column) {
			if (null === $tableName) {
				$tableName = $this->requireString($this->requireTable($column->getTable())->getName(), 'Table name');
			}
			$lines []= $this->getColumnDDL($column);
		}

		$sep = ",
	";

		$pattern = "
ALTER TABLE %s ADD
(
	%s
);
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($tableName, 'Table name')),
			implode($sep, $lines)
		);
	}

	/**
	 * Returns if the RDBMS-specific SQL type has a size attribute.
	 *
	 * @param      string $sqlType the SQL type
	 * @return     boolean True if the type has a size attribute
	 */
	public function hasSize($sqlType)
	{
		return true;
	}

	/**
	 * Returns if the RDBMS-specific SQL type has a scale attribute.
	 *
	 * @param      string $sqlType the SQL type
	 * @return     boolean True if the type has a scale attribute
	 */
	public function hasScale($sqlType)
	{
		return true;
	}

	/**
	 * Quote and escape needed characters in the string for unerlying RDBMS.
	 * @param      string $text
	 * @return     string
	 */
	public function quote($text)
	{
		if ($con = $this->getConnection()) {
			return $con->quote($text);
		} else {
			return "'" . $this->disconnectedEscapeText($text) . "'";
		}
	}

	/**
	 * Method to escape text when no connection has been set.
	 *
	 * The subclasses can implement this using string replacement functions
	 * or native DB methods.
	 *
	 * @param      string $text Text that needs to be escaped.
	 * @return     string
	 */
	protected function disconnectedEscapeText($text)
	{
		return str_replace("'", "''", $text);
	}

	/**
	 * Quotes identifiers used in database SQL.
	 * @param      string $text
	 * @return     string Quoted identifier.
	 */
	public function quoteIdentifier($text)
	{
		return $this->isIdentifierQuotingEnabled ? '"' . strtr($text, array('.' => '"."')) . '"' : $text;
	}

	public function setIdentifierQuoting(bool $enabled = true): void
	{
		$this->isIdentifierQuotingEnabled = $enabled;
	}

	public function getIdentifierQuoting(): bool
	{
		return $this->isIdentifierQuotingEnabled;
	}

	/**
	 * Whether RDBMS supports native ON DELETE triggers (e.g. ON DELETE CASCADE).
	 * @return     boolean
	 */
	public function supportsNativeDeleteTrigger()
	{
		return false;
	}

	/**
	 * Whether RDBMS supports INSERT null values in autoincremented primary keys
	 * @return     boolean
	 */
	public function supportsInsertNullPk()
	{
		return true;
	}

	/**
	 * Whether the underlying PDO driver for this platform returns BLOB columns as streams (instead of strings).
	 * @return     boolean
	 */
	public function hasStreamBlobImpl()
	{
		return false;
	}

	/**
	 * @see        Platform::supportsSchemas()
	 */
	public function supportsSchemas()
	{
		return false;
	}

	/**
	 * @see        Platform::supportsMigrations()
	 */
	public function supportsMigrations()
	{
		return true;
	}

	/**
	 * @see        PropulsionPlatformInterface::supportsTransactionalDDL()
	 */
	public function supportsTransactionalDDL()
	{
		return false;
	}

	public function supportsVarcharWithoutSize()
	{
		return false;
	}
	/**
	 * Returns the boolean value for the RDBMS.
	 *
	 * This value should match the boolean value that is set
	 * when using Propulsion's PreparedStatement::setBoolean().
	 *
	 * This function is used to set default column values when building
	 * SQL.
	 *
	 * @param      mixed $b A boolean or string representation of boolean ('y', 'true').
	 * @return     string
	 */
	public function getBooleanString($b): string
	{
		$isTrue = $b === true || $b === 1 || $b === '1'
			|| (is_string($b) && in_array(strtolower($b), array('true', 'y', 'yes'), true));
		return ($isTrue ? '1' : '0');
	}

	/**
	 * Gets the preferred timestamp formatter for setting date/time values.
	 * @return     string
	 */
	public function getTimestampFormatter()
	{
		return 'Y-m-d H:i:s';
	}

	/**
	 * Gets the preferred time formatter for setting date/time values.
	 * @return     string
	 */
	public function getTimeFormatter()
	{
		return 'H:i:s';
	}

	/**
	 * Gets the preferred date formatter for setting date/time values.
	 * @return     string
	 */
	public function getDateFormatter()
	{
		return 'Y-m-d';
	}

}
