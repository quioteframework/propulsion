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
 * Postgresql PropulsionPlatformInterface implementation.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @author     Niklas Närhinen <niklas@narhinen.net>
 * @version    $Revision$
 */
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Model\Diff\PropulsionColumnDiff;
use Propulsion\Generator\Model\Diff\PropulsionDatabaseDiff;
use Propulsion\Generator\Model\Index;

class PgsqlPlatform extends DefaultPlatform
{

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize()
	{
		parent::initialize();
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BOOLEAN, "BOOLEAN"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TINYINT, "INT2"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::SMALLINT, "INT2"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BIGINT, "INT8"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::REAL, "FLOAT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DOUBLE, "DOUBLE PRECISION"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::FLOAT, "DOUBLE PRECISION"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARCHAR, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BINARY, "BYTEA"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARBINARY, "BYTEA"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARBINARY, "BYTEA"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BLOB, "BYTEA"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CLOB, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::OBJECT, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::PHP_ARRAY, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::ENUM, "INT2"));
	}

	public function getNativeIdMethod()
	{
		return PropulsionPlatformInterface::SERIAL;
	}

	public function getAutoIncrement()
	{
		return '';
	}

	/**
	 * PostgreSQL identifiers are stored in a `NAMEDATALEN`-sized column
	 * (64 bytes, one of which is reserved for the trailing null terminator),
	 * so 63 characters is the real usable limit on any currently-supported
	 * server (this only changes if PostgreSQL is compiled with a
	 * non-default `NAMEDATALEN`, vanishingly rare in practice). This used to
	 * return 32 -- the limit on PostgreSQL server versions older than 7.3
	 * (2002) -- which is long past this codebase's PostgreSQL 15+ floor (see
	 * KNOWN_ISSUES.md) and needlessly truncated auto-generated constraint/
	 * index names (see ConstraintNameGenerator, Index::getName()) well
	 * before the real server-enforced limit.
	 */
	public function getMaxColumnNameLength()
	{
		return 63;
	}

	public function getBooleanString($b)
	{
		// parent method does the checking for allowes tring
		// representations & returns integer
		$b = parent::getBooleanString($b);
		return ($b ? "'t'" : "'f'");
	}

	public function supportsNativeDeleteTrigger()
	{
		return true;
	}

	/**
	 * Override to provide sequence names that conform to postgres' standard when
	 * no id-method-parameter specified.
	 *
	 * @param      Table $table
	 *
	 * @return     string
	 */
	public function getSequenceName(Table $table)
	{
		static $longNamesMap = array();
		$result = null;
		if ($table->getIdMethod() == IDMethod::NATIVE) {
			$idMethodParams = $table->getIdMethodParameters();
			if (empty($idMethodParams)) {
				$result = null;
				// We're going to ignore a check for max length (mainly
				// because I'm not sure how Postgres would handle this w/ SERIAL anyway)
				foreach ($table->getColumns() as $col) {
					if ($col->isAutoIncrement()) {
						$result = $table->getName() . '_' . $col->getName() . '_seq';
						break; // there's only one auto-increment column allowed
					}
				}
			} else {
				$result = $idMethodParams[0]->getValue();
			}
		}
		return $result;
	}

	protected function getAddSequenceDDL(Table $table)
	{
		if (
			$table->getIdMethod() == IDMethod::NATIVE
			&& $table->getIdMethodParameters() != null
		) {
			$pattern = "
CREATE SEQUENCE %s;
";
			return sprintf(
				$pattern,
				$this->quoteIdentifier(strtolower($this->getSequenceName($table)))
			);
		}
	}

	protected function getDropSequenceDDL(Table $table)
	{
		if (
			$table->getIdMethod() == IDMethod::NATIVE
			&& $table->getIdMethodParameters() != null
		) {
			$pattern = "
DROP SEQUENCE IF EXISTS %s;
";
			return sprintf(
				$pattern,
				$this->quoteIdentifier(strtolower($this->getSequenceName($table)))
			);
		}
	}

	/**
	 * Emits a `CREATE SCHEMA` statement for every distinct schema referenced by
	 * the database's tables.
	 *
	 * Two independent ways exist to put a table in a non-default schema, and
	 * both are honored here:
	 *  - the `schema="..."` attribute on `<database>`/`<table>` (the primary,
	 *    cross-platform mechanism -- see {@link Table::getName()}/
	 *    {@link \Propulsion\Generator\Model\ForeignKey::getForeignTableName()},
	 *    which already qualify every identifier as `schema.table` for any
	 *    platform where {@link supportsSchemas()} is true). Until this was
	 *    fixed, a table using only this attribute got fully schema-qualified
	 *    DDL (`CREATE TABLE "x"."book" ...`) but the schema itself was never
	 *    created, so the generated SQL failed against a fresh database unless
	 *    something else created the schema out of band.
	 *  - the legacy `<vendor type="pgsql"><parameter name="schema" .../>`
	 *    vendor-info convention, which additionally wraps the table's DDL in
	 *    `SET search_path` (see {@link getUseSchemaDDL()}) since -- unlike the
	 *    `schema` attribute -- it does not change the table's qualified name.
	 */
	public function getAddSchemasDDL(Database $database)
	{
		$ret = '';
		$schemas = array();
		foreach ($database->getTables() as $table) {
			$schemaName = $table->getSchema();
			if ($schemaName !== null && $schemaName !== '' && !isset($schemas[$schemaName])) {
				$schemas[$schemaName] = true;
				$ret .= $this->getCreateSchemaDDL($schemaName);
			}
			$vi = $table->getVendorInfoForType('pgsql');
			if ($vi->hasParameter('schema') && !isset($schemas[$vi->getParameter('schema')])) {
				$schemas[$vi->getParameter('schema')] = true;
				$ret .= $this->getCreateSchemaDDL($vi->getParameter('schema'));
			}
		}
		return $ret;
	}

	public function getAddSchemaDDL(Table $table)
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			return $this->getCreateSchemaDDL($vi->getParameter('schema'));
		};
	}

	protected function getCreateSchemaDDL($schemaName, $ifNotExists = false)
	{
		$pattern = "
CREATE SCHEMA %s%s;
";
		return sprintf($pattern, $ifNotExists ? 'IF NOT EXISTS ' : '', $this->quoteIdentifier($schemaName));
	}

	/**
	 * Emits `CREATE SCHEMA IF NOT EXISTS` for every distinct schema referenced
	 * by the given (newly-added, in a diff) tables' `schema="..."` attribute.
	 *
	 * Used by {@link getModifyDatabaseDDL()} so that a migration/diff adding a
	 * brand-new schema-qualified table creates its schema first, the same way
	 * {@link getAddSchemasDDL()} does for a full rebuild. `IF NOT EXISTS` is
	 * used here (unlike getAddSchemasDDL()'s full-rebuild `CREATE SCHEMA`,
	 * which intentionally errors on a name collision) since a diff only ever
	 * runs against a database that may already have other tables -- possibly
	 * in that same schema -- so re-declaring an already-existing schema must
	 * not be a hard failure.
	 *
	 * @param      Table[] $tables
	 * @return     string
	 */
	protected function getAddSchemasForTablesDDL(array $tables)
	{
		$ret = '';
		$schemas = array();
		foreach ($tables as $table) {
			$schemaName = $table->getSchema();
			if ($schemaName !== null && $schemaName !== '' && !isset($schemas[$schemaName])) {
				$schemas[$schemaName] = true;
				$ret .= $this->getCreateSchemaDDL($schemaName, true);
			}
			$vi = $table->getVendorInfoForType('pgsql');
			if ($vi->hasParameter('schema') && !isset($schemas[$vi->getParameter('schema')])) {
				$schemas[$vi->getParameter('schema')] = true;
				$ret .= $this->getCreateSchemaDDL($vi->getParameter('schema'), true);
			}
		}
		return $ret;
	}

	/**
	 * Overrides the implementation from DefaultPlatform to create the schema
	 * of any newly-added, schema-qualified table before its CREATE TABLE
	 * statement -- see {@link getAddSchemasForTablesDDL()}.
	 *
	 * @return     string
	 * @see        DefaultPlatform::getModifyDatabaseDDL
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

		$ret .= $this->getAddSchemasForTablesDDL($databaseDiff->getAddedTables());

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

	public function getUseSchemaDDL(Table $table)
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			$pattern = "
SET search_path TO %s;
";
			return sprintf($pattern, $this->quoteIdentifier($vi->getParameter('schema')));
		}
	}

	public function getResetSchemaDDL(Table $table)
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			return "
SET search_path TO public;
";
		}
	}

	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		$ret .= $this->getAddSchemasDDL($database);
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getCommentBlockDDL($table->getName());
			$ret .= $this->getDropTableDDL($table);
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
		}
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getAddForeignKeysDDL($table);
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	public function getAddTableDDL(Table $table)
	{
		$ret = '';
		$ret .= $this->getUseSchemaDDL($table);
		$ret .= $this->getAddSequenceDDL($table);

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
CREATE TABLE %s
(
	%s
);
";
		$ret .= sprintf(
			$pattern,
			$this->quoteIdentifier($table->getName()),
			implode($sep, $lines)
		);

		if ($table->hasDescription()) {
			$pattern = "
COMMENT ON TABLE %s IS %s;
";
			$ret .= sprintf(
				$pattern,
				$this->quoteIdentifier($table->getName()),
				$this->quote($table->getDescription())
			);
		}

		$ret .= $this->getAddColumnsComments($table);
		$ret .= $this->getResetSchemaDDL($table);

		return $ret;
	}

	protected function getAddColumnsComments(Table $table)
	{
		$ret = '';
		foreach ($table->getColumns() as $column) {
			$ret .= $this->getAddColumnComment($column);
		}
		return $ret;
	}

	protected function getAddColumnComment(Column $column)
	{
		$pattern = "
COMMENT ON COLUMN %s.%s IS %s;
";
		if ($description = $column->getDescription()) {
			return sprintf(
				$pattern,
				$this->quoteIdentifier($column->getTable()->getName()),
				$this->quoteIdentifier($column->getName()),
				$this->quote($description)
			);
		}
	}

	public function getDropTableDDL(Table $table)
	{
		$ret = '';
		$ret .= $this->getUseSchemaDDL($table);
		$pattern = "
DROP TABLE IF EXISTS %s CASCADE;
";
		$ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()));
		$ret .= $this->getDropSequenceDDL($table);
		$ret .= $this->getResetSchemaDDL($table);
		return $ret;
	}

	public function getPrimaryKeyName(Table $table)
	{
		$tableName = $table->getName();
		return $tableName . '_pkey';
	}

	public function getColumnDDL(Column $col)
	{
		$domain = $col->getDomain();

		$ddl = array($this->quoteIdentifier($col->getName()));
		$sqlType = $domain->getSqlType();
		$table = $col->getTable();
		if ($col->isAutoIncrement() && $table && $table->getIdMethodParameters() == null) {
			$sqlType = $col->getType() === PropulsionTypes::BIGINT ? 'bigserial' : 'serial';
		}
		if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl[] = $sqlType . $domain->printSize();
		} else {
			$ddl[] = $sqlType;
		}
		if ($default = $this->getColumnDefaultValueDDL($col)) {
			$ddl[] = $default;
		}
		if ($notNull = $this->getNullString($col->isNotNull())) {
			$ddl[] = $notNull;
		}
		if ($autoIncrement = $col->getAutoIncrementString()) {
			$ddl[] = $autoIncrement;
		}

		return implode(' ', $ddl);
	}

	public function getUniqueDDL(Unique $unique)
	{
		return sprintf(
			'CONSTRAINT %s UNIQUE (%s)',
			$this->quoteIdentifier($unique->getName()),
			$this->getColumnListDDL($unique->getColumns())
		);
	}

	/**
	 * @see        Platform::supportsSchemas()
	 */
	public function supportsSchemas()
	{
		return true;
	}

	/**
	 * @see        PropulsionPlatformInterface::supportsTransactionalDDL()
	 */
	public function supportsTransactionalDDL()
	{
		return true;
	}

	public function hasSize($sqlType)
	{
		return !("BYTEA" == $sqlType || "TEXT" == $sqlType || "DOUBLE PRECISION" == $sqlType);
	}

	public function hasStreamBlobImpl()
	{
		return true;
	}

	public function supportsVarcharWithoutSize()
	{
		return true;
	}

	/**
	 * Overrides the implementation from DefaultPlatform
	 *
	 * @author     Niklas Närhinen <niklas@narhinen.net>
	 * @return     string
	 * @see        DefaultPlatform::getModifyColumnDDL
	 */
	public function getModifyColumnDDL(PropulsionColumnDiff $columnDiff)
	{
		$ret = '';
		$changedProperties = $columnDiff->getChangedProperties();

		$toColumn = $columnDiff->getToColumn();

		$table = $toColumn->getTable();

		$colName = $this->quoteIdentifier($toColumn->getName());

		$pattern = "
ALTER TABLE %s ALTER COLUMN %s;
";
		foreach ($changedProperties as $key => $property) {
			switch ($key) {
				case 'defaultValueType':
					break;
				case 'size':
				case 'type':
				case 'scale':
					$sqlType = $toColumn->getDomain()->getSqlType();
					if ($toColumn->isAutoIncrement() && $table && $table->getIdMethodParameters() == null) {
						$sqlType = $toColumn->getType() === PropulsionTypes::BIGINT ? 'bigserial' : 'serial';
					}
					if ($this->hasSize($sqlType)) {
						$sqlType .= $toColumn->getDomain()->printSize();
					}
					$ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' TYPE ' . $sqlType);
					break;
				case 'defaultValueValue':
					if ($property[0] !== null && $property[1] === null) {
						$ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' DROP DEFAULT');
					} else {
						$ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' SET ' . $this->getColumnDefaultValueDDL($toColumn));
					}
					break;
				case 'notNull':
					$notNull = " DROP NOT NULL";
					if ($property[1]) {
						$notNull = " SET NOT NULL";
					}
					$ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . $notNull);
					break;
			}
		}
		return $ret;
	}

	/**
	 * Overrides the implementation from DefaultPlatform
	 *
	 * @author     Niklas Närhinen <niklas@narhinen.net>
	 * @return     string
	 * @see        DefaultPlatform::getModifyColumnsDDL
	 */
	public function getModifyColumnsDDL($columnDiffs)
	{
		$ret = '';
		foreach ($columnDiffs as $columnDiff) {
			$ret .= $this->getModifyColumnDDL($columnDiff);
		}
		return $ret;
	}

	/**
	 * Overrides the implementation from DefaultPlatform
	 *
	 * @author     Niklas Närhinen <niklas@narhinen.net>
	 * @return     string
	 * @see        DefaultPlatform::getAddColumnsDLL
	 */
	public function getAddColumnsDDL($columns)
	{
		$ret = '';
		foreach ($columns as $column) {
			$ret .= $this->getAddColumnDDL($column);
		}
		return $ret;
	}

	/**
	 * Overrides the implementation from DefaultPlatform
	 *
	 * @author     Niklas Närhinen <niklas@narhinen.net>
	 * @return     string
	 * @see        DefaultPlatform::getDropIndexDDL
	 */
	public function getDropIndexDDL(Index $index)
	{
		if ($index instanceof Unique) {
			$pattern = "
	ALTER TABLE %s DROP CONSTRAINT %s;
	";
			return sprintf(
				$pattern,
				$this->quoteIdentifier($index->getTable()->getName()),
				$this->quoteIdentifier($index->getName())
			);
		} else {
			return parent::getDropIndexDDL($index);
		}
	}
}
