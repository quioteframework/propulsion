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
use Propulsion\Generator\Model\Exclusion;

class PgsqlPlatform extends DefaultPlatform
{

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize(): void
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
		// PostgreSQL has real native JSON and JSONB column types -- JSONB stores a
		// decomposed binary representation (faster to query/index, slightly slower to
		// write) while JSON stores the exact input text verbatim; let schema authors pick
		// either. Both are still bound as plain strings (see PropulsionTypes::getPDOType()),
		// since PDO's pgsql driver has no dedicated JSON parameter type.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSON, "JSON"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSONB, "JSONB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::UUID, "UUID"));
		// Native `interval` type -- forced to ISO-8601 text output via
		// `SET intervalstyle = 'iso_8601'` on connect (DBPostgres::initConnection())
		// so `new DateInterval($v)` round-trips it the same way every other
		// platform's emulated (VARCHAR) INTERVAL column already does.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTERVAL, "INTERVAL"));
		// Native network address types -- no rich PHP value object for these
		// (v1), hydrated as plain strings the same way UUID already is.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INET, "INET"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CIDR, "CIDR"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::MACADDR, "MACADDR"));
		// citext ships as a contrib extension, not a built-in type -- see
		// getAddExtensionsDDL(), which emits `CREATE EXTENSION IF NOT EXISTS
		// citext` before the table whenever a column actually uses it.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CITEXT, "CITEXT"));
		// Native range types -- hydrate to/from Propulsion\Type\Range (see
		// ObjectBuilder's isRangeType() branches), not a plain string.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT4RANGE, "INT4RANGE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT8RANGE, "INT8RANGE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMRANGE, "NUMRANGE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATERANGE, "DATERANGE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSRANGE, "TSRANGE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSTZRANGE, "TSTZRANGE"));
		// pgvector's `vector` type ships as an extension, not a built-in type --
		// see getAddExtensionsDDL(). Dimension comes from the column's ordinary
		// `size` attribute (e.g. `size="1536"`), handled generically by the
		// same printSize()/hasSize() machinery VARCHAR(n) already goes through
		// -- no VECTOR-specific DDL override needed.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VECTOR, "vector"));
		// Emulated as plain text (WKT), not PostGIS's real `geometry` type --
		// see PropulsionTypes::GEOMETRY_NATIVE_TYPE for why.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::GEOMETRY, "TEXT"));
		// Native full-text-search vector type. A tsvector column is normally
		// populated via a `tsvectorFrom`-driven GENERATED ALWAYS AS
		// (to_tsvector(...)) STORED column (see getColumnDDL()) rather than
		// written to directly.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSVECTOR, "TSVECTOR"));
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
	 * (2002) -- which is long past this codebase's PostgreSQL 16+ floor (see
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
	public function getSequenceName(Table $table): ?string
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

	protected function getAddSequenceDDL(Table $table): ?string
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

		return null;
	}

	protected function getDropSequenceDDL(Table $table): ?string
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

		return null;
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
	public function getAddSchemasDDL(Database $database): string
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

	public function getAddSchemaDDL(Table $table): ?string
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			return $this->getCreateSchemaDDL($vi->getParameter('schema'));
		};

		return null;
	}

	protected function getCreateSchemaDDL(string $schemaName, bool $ifNotExists = false): string
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

	public function getUseSchemaDDL(Table $table): ?string
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			$pattern = "
SET search_path TO %s;
";
			return sprintf($pattern, $this->quoteIdentifier($vi->getParameter('schema')));
		}

		return null;
	}

	public function getResetSchemaDDL(Table $table): ?string
	{
		$vi = $table->getVendorInfoForType('pgsql');
		if ($vi->hasParameter('schema')) {
			return "
SET search_path TO public;
";
		}

		return null;
	}

	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		$ret .= $this->getAddSchemasDDL($database);
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getAddExtensionsDDL($table);
			$ret .= $this->getCommentBlockDDL($table->getName());
			$ret .= $this->getDropTableDDL($table);
			// The enum type must be dropped after the table that depends on it
			// (a table's column type can't be dropped out from under it) and
			// (re)created before the table that references it.
			$ret .= $this->getDropEnumTypesDDL($table);
			$ret .= $this->getAddEnumTypesDDL($table);
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
		}
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getAddForeignKeysDDL($table);
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	/**
	 * Emits `CREATE EXTENSION IF NOT EXISTS citext` before the table if any of
	 * its columns actually use the CITEXT type -- citext ships as a contrib
	 * extension, not a built-in Postgres type, so the column type alone isn't
	 * enough to make it available.
	 *
	 * @return     string
	 */
	public function getAddExtensionsDDL(Table $table)
	{
		$ret = '';
		$extensions = [];
		foreach ($table->getColumns() as $col) {
			if ($col->getType() === PropulsionTypes::CITEXT) {
				$extensions['citext'] = true;
			} elseif ($col->isVectorType()) {
				$extensions['vector'] = true;
			}
		}
		foreach (array_keys($extensions) as $extension) {
			$ret .= "
CREATE EXTENSION IF NOT EXISTS $extension;
";
		}
		return $ret;
	}

	/**
	 * The name of the `CREATE TYPE ... AS ENUM` type backing a native-storage
	 * ENUM column -- table- and column-qualified so two tables can each have
	 * their own enum column without a type-name collision.
	 *
	 * @return     string
	 */
	protected function getEnumTypeRawName(Column $col, Table $table)
	{
		return $table->getName() . '_' . $col->getName() . '_enum';
	}

	/**
	 * Builds `CREATE TYPE ... AS ENUM (...)` DDL for every `nativeEnum="true"`
	 * ENUM column on $table, emitted before the table itself (see
	 * getAddTablesDDL()) since the column's type must already exist.
	 *
	 * @return     string
	 */
	public function getAddEnumTypesDDL(Table $table)
	{
		$ret = '';
		foreach ($table->getColumns() as $col) {
			if ($col->isEnumType() && $col->isNativeEnum()) {
				$values = implode(', ', array_map(fn ($v) => $this->quote($v), $col->getValueSet()));
				$ret .= "
CREATE TYPE " . $this->quoteIdentifier($this->getEnumTypeRawName($col, $table)) . " AS ENUM ($values);
";
			}
		}
		return $ret;
	}

	/**
	 * Drops every `nativeEnum="true"` ENUM column's backing type for $table
	 * -- emitted after the table itself is dropped (see getAddTablesDDL()).
	 *
	 * @return     string
	 */
	public function getDropEnumTypesDDL(Table $table)
	{
		$ret = '';
		foreach ($table->getColumns() as $col) {
			if ($col->isEnumType() && $col->isNativeEnum()) {
				$ret .= "
DROP TYPE IF EXISTS " . $this->quoteIdentifier($this->getEnumTypeRawName($col, $table)) . ";
";
			}
		}
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

		foreach ($table->getExclusions() as $exclusion) {
			$lines[] = $this->getExclusionDDL($exclusion);
		}

		$sep = ",
	";
		$pattern = "
CREATE TABLE %s
(
	%s
)%s;
";
		$ret .= sprintf(
			$pattern,
			$this->quoteIdentifier($table->getName()),
			implode($sep, $lines),
			$table->getInheritsFrom() ? ' INHERITS (' . $this->quoteIdentifier($table->getInheritsFrom()) . ')' : ''
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

	protected function getAddColumnsComments(Table $table): string
	{
		$ret = '';
		foreach ($table->getColumns() as $column) {
			$ret .= $this->getAddColumnComment($column);
		}
		return $ret;
	}

	protected function getAddColumnComment(Column $column): ?string
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

		return null;
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
		$isIdentity = false;
		if ($col->isAutoIncrement() && $table && $table->getIdMethodParameters() == null) {
			if ($col->isIdentity()) {
				// GENERATED ... AS IDENTITY (PG10+) needs the column's real
				// integer type -- unlike `serial`/`bigserial`, it isn't itself
				// a pseudo-type, so $sqlType is left as the plain mapped type.
				$isIdentity = true;
			} else {
				$sqlType = $col->getType() === PropulsionTypes::BIGINT ? 'bigserial' : 'serial';
			}
		} elseif ($col->isEnumType() && $col->isNativeEnum() && $table) {
			// The real `CREATE TYPE ... AS ENUM` this column's type name refers
			// to is emitted separately, before the table -- see getAddTablesDDL()/
			// getAddEnumTypesDDL(). Postgres stores the label text natively here
			// (not the emulated integer index), enforced by the enum type itself
			// rather than a CHECK constraint (contrast SQLite/Oracle).
			$sqlType = $this->quoteIdentifier($this->getEnumTypeRawName($col, $table));
		} elseif ($col->getType() === PropulsionTypes::PHP_ARRAY && $col->isNativeArray()) {
			// See Column::isNativeArray() -- no per-element PHP type is known
			// for a generic PHP_ARRAY column, so this always emits a plain
			// text[], the same "no rich subtype" choice INET/CIDR/MACADDR
			// made for their own emulated-elsewhere types.
			$sqlType = 'TEXT[]';
		}
		$tsvectorSources = $col->isTsvectorType() ? $col->getTsvectorSourceColumns() : [];
		if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl[] = $sqlType . $domain->printSize();
		} else {
			$ddl[] = $sqlType;
		}
		if ($isIdentity) {
			// A column can't carry both a DEFAULT and an identity clause; an
			// auto-increment column has no user-declared default anyway.
			$ddl[] = 'GENERATED BY DEFAULT AS IDENTITY';
		} elseif ($tsvectorSources) {
			// Auto-populated tsvector (GENERATED ALWAYS AS ... STORED, PG12+)
			// -- see Column::getTsvectorSourceColumns()/getTsvectorConfig().
			// A generated column can't also carry a DEFAULT.
			$parts = array_map(
				fn ($sourceColumn) => 'coalesce(' . $this->quoteIdentifier($sourceColumn) . ", '')",
				$tsvectorSources
			);
			$ddl[] = sprintf(
				"GENERATED ALWAYS AS (to_tsvector(%s, %s)) STORED",
				$this->quote($col->getTsvectorConfig()),
				implode(" || ' ' || ", $parts)
			);
		} elseif ($default = $this->getColumnDefaultValueDDL($col)) {
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
	 * Builds the inline `CONSTRAINT ... EXCLUDE USING <method> (col WITH
	 * operator, ...) [WHERE (...)]` DDL fragment for an exclusion constraint
	 * -- see the Exclusion class.
	 *
	 * @return     string
	 */
	public function getExclusionDDL(Exclusion $exclusion)
	{
		$parts = array();
		foreach ($exclusion->getColumns() as $col) {
			$parts[] = $this->quoteIdentifier($col['name']) . ' WITH ' . $col['operator'];
		}
		$ddl = sprintf(
			'CONSTRAINT %s EXCLUDE USING %s (%s)',
			$this->quoteIdentifier((string) $exclusion->getName()),
			$exclusion->getIndexType(),
			implode(', ', $parts)
		);
		if ($exclusion->getWhereClause() !== null) {
			$ddl .= ' WHERE (' . $exclusion->getWhereClause() . ')';
		}
		return $ddl;
	}

	/**
	 * Builds the DDL SQL for an index's column list, honoring
	 * Index::isExpressionAtPosition() -- a plain column name is quoted as an
	 * identifier as usual, while an expression entry is emitted verbatim,
	 * wrapped in an extra pair of parentheses (always safe/valid even when
	 * the expression is itself already parenthesized, e.g. a function call,
	 * and required when it isn't, e.g. `a || b`).
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
	 * Overrides DefaultPlatform to add Postgres-specific index DDL this
	 * codebase's shared `Index` model now supports: a `USING <method>`
	 * clause for a non-default index access method (`indexType`, e.g. GIN
	 * over a full-text-search column), expression index columns
	 * (Index::isExpressionAtPosition()), a partial-index `WHERE` predicate,
	 * `INCLUDE (...)` covering columns, a `WITH (...)` storage-parameters
	 * clause, and `CONCURRENTLY`.
	 *
	 * @return     string
	 */
	public function getAddIndexDDL(Index $index)
	{
		$pattern = "
CREATE %sINDEX %s%s ON %s%s (%s)%s%s%s;
";
		return sprintf(
			$pattern,
			$index->isUnique() ? 'UNIQUE ' : '',
			$index->isConcurrent() ? 'CONCURRENTLY ' : '',
			$this->quoteIdentifier($index->getName()),
			$this->quoteIdentifier($index->getTable()->getName()),
			$index->getIndexType() ? ' USING ' . $index->getIndexType() : '',
			$this->getIndexColumnListDDL($index),
			$index->getIncludeColumns() ? ' INCLUDE (' . $this->getColumnListDDL($index->getIncludeColumns()) . ')' : '',
			$index->getStorageParameters() ? ' WITH (' . $index->getStorageParameters() . ')' : '',
			$index->getWhereClause() !== null ? ' WHERE (' . $index->getWhereClause() . ')' : ''
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
		return !("BYTEA" == $sqlType || "TEXT" == $sqlType || "TEXT[]" == $sqlType || "DOUBLE PRECISION" == $sqlType || "TSVECTOR" == $sqlType);
	}

	public function supportsNativeArrayDDL()
	{
		return true;
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
					if ($toColumn->isAutoIncrement() && !$toColumn->isIdentity() && $table && $table->getIdMethodParameters() == null) {
						$sqlType = $toColumn->getType() === PropulsionTypes::BIGINT ? 'bigserial' : 'serial';
					} elseif ($toColumn->getType() === PropulsionTypes::PHP_ARRAY && $toColumn->isNativeArray()) {
						$sqlType = 'TEXT[]';
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
	 * Overrides the implementation from DefaultPlatform
	 *
	 * @author     Niklas Närhinen <niklas@narhinen.net>
	 * @return     string
	 * @see        DefaultPlatform::getAddColumnsDLL
	 */
	/**
	 * @param      array<string, Column> $columns
	 */
	public function getAddColumnsDDL(array $columns)
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
