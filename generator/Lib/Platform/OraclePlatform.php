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
 * Oracle PropulsionPlatformInterface implementation.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @author     Denis Dalmais
 * @version    $Revision$
 */

 use Propulsion\Generator\Exception\EngineException;
 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\Diff\PropulsionColumnDiff;
 use Propulsion\Generator\Model\Domain;
 use Propulsion\Generator\Model\PropulsionTypes;
 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Model\ForeignKey;
 use Propulsion\Generator\Model\Database;
 use Propulsion\Generator\Model\IDMethod;
 use Propulsion\Generator\Model\Unique;
 use Propulsion\Generator\Model\Index;
class OraclePlatform extends DefaultPlatform
{
	/**
	 * Table/Unique/Index/ForeignKey's own getName()/getTable()/etc. getters are
	 * typed nullable throughout this codebase's model classes (schema-XML
	 * parsing leaves them unset until later in the load process), but by the
	 * time DDL generation actually runs on a real, fully-loaded schema they're
	 * always populated -- these two guards turn that implicit assumption into
	 * an explicit, real failure instead of silently widening this class's own
	 * types to tolerate null everywhere DDL string-building actually requires
	 * a real value.
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
	 * VendorInfo::getParameter() is untyped (a generic bag for arbitrary
	 * `<vendor><parameter>` XML attributes), but every Oracle-specific
	 * physical-storage parameter this class reads back out of it (PCTFree,
	 * InitTrans, MinExtents, ...) is, by construction, a plain string XML
	 * attribute value -- this makes that assumption an explicit, checked one.
	 */
	private function requireStringParam(mixed $value, string $description): string
	{
		if (!is_string($value)) {
			throw new EngineException("$description is required but was not a string.");
		}
		return $value;
	}

	/**
	 * Whether $table's auto-increment primary key is declared `identity="true"`
	 * and therefore uses Oracle 12c+'s native `GENERATED ... AS IDENTITY`
	 * column mechanism (its own implicit, hidden sequence) instead of this
	 * platform's legacy explicit `CREATE SEQUENCE` + `BEFORE INSERT` trigger
	 * pair -- see getColumnDDL()/getAddSequencesDDL()/
	 * getAddAutoIncrementTriggerDDL(). Guarded on `getIdMethodParameters()`
	 * being empty the same way PgsqlPlatform's own `identity` guard is: a
	 * table using a named/external sequence (`<id-method-parameter>`) is
	 * explicitly opting into that sequence's own naming, not this column's
	 * implicit one, so `identity="true"` is ignored there.
	 */
	private function usesNativeIdentity(Table $table): bool
	{
		if ($table->getIdMethodParameters()) {
			return false;
		}
		$pk = $table->getAutoIncrementPrimaryKey();
		return $pk !== null && $pk->isIdentity();
	}

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize(): void
	{
		parent::initialize();
		$this->schemaDomainMap[PropulsionTypes::BOOLEAN] = new Domain(PropulsionTypes::BOOLEAN_EMU, "NUMBER", "1", "0");
		$this->schemaDomainMap[PropulsionTypes::CLOB] = new Domain(PropulsionTypes::CLOB_EMU, "CLOB");
		$this->schemaDomainMap[PropulsionTypes::CLOB_EMU] = $this->schemaDomainMap[PropulsionTypes::CLOB];
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TINYINT, "NUMBER", "3", "0"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::SMALLINT, "NUMBER", "5", "0"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTEGER, "NUMBER"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BIGINT, "NUMBER", "20", "0"));
		// Native IEEE-754 binary floating-point types (available since
		// Oracle 10g, long past this pass's 12c+ floor) instead of the
		// previous, decimal-exact NUMBER/FLOAT mapping -- NUMBER is exact
		// decimal (like SQL NUMERIC), so it was already a poor fit for
		// PropulsionTypes::REAL/DOUBLE's own "approximate binary float"
		// semantics (matching every other platform's REAL/DOUBLE ->
		// float4/float8-equivalent mapping); BINARY_FLOAT/BINARY_DOUBLE are
		// Oracle's real equivalent of PHP's own float type, including the
		// same rounding behavior, rather than an arbitrary-precision decimal.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::REAL, "BINARY_FLOAT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DOUBLE, "BINARY_DOUBLE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DECIMAL, "NUMBER"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMERIC, "NUMBER"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARCHAR, "NVARCHAR2"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARCHAR, "NVARCHAR2", "2000"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TIME, "DATE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATE, "DATE"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TIMESTAMP, "TIMESTAMP"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BINARY, "LONG RAW"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARBINARY, "BLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARBINARY, "LONG RAW"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::OBJECT, "NVARCHAR2", "2000"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::PHP_ARRAY, "NVARCHAR2", "2000"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::ENUM, "NUMBER", "3", "0"));
		// Native JSON column type (Oracle 21c+ -- see this pass's own floor
		// note in PLATFORM_FEATURES.md). Oracle enforces well-formed JSON on
		// this type itself (the same guarantee 12c+'s "IS JSON" CHECK
		// constraint gives a plain CLOB, without needing that constraint
		// spelled out separately here), and it's bound/fetched through
		// PDO the same as a plain string -- no CLOB_EMU-style LOB bind
		// handling needed in DBOracle::bindValue(), the same as before this
		// change (JSON was never routed through the CLOB_EMU alias to begin
		// with, just a bare "CLOB" sqlType).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSON, "JSON"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSONB, "JSON"));
		// Oracle has no native UUID column type; fall back to the canonical
		// 36-character hyphenated textual representation (see
		// PropulsionTypes::UUID / Column::isUuidType()).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::UUID, "CHAR", "36"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTERVAL, "VARCHAR2", 32));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INET, "VARCHAR2", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CIDR, "VARCHAR2", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::MACADDR, "VARCHAR2", 17));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CITEXT, "NVARCHAR2", "2000"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT4RANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT8RANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMRANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATERANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSRANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSTZRANGE, "VARCHAR2", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VECTOR, "CLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::GEOMETRY, "CLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSVECTOR, "CLOB"));
		// No native SET type -- emulated as comma-joined text.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::SET, "CLOB"));

	}

	/**
	 * Adds the `GENERATED BY DEFAULT AS IDENTITY` clause (Oracle 12c+) for an
	 * `identity="true"` auto-increment column -- see usesNativeIdentity()'s
	 * own doc comment for the guard this shares with PgsqlPlatform's own
	 * `identity` support. Every other column (including a plain, non-identity
	 * auto-increment one, still handled by the legacy sequence+trigger pair)
	 * falls through to `DefaultPlatform::getColumnDDL()` unchanged.
	 *
	 * $allowIdentity is `false` only from getModifyColumnDDL() below: Oracle
	 * rejects re-declaring `GENERATED ... AS IDENTITY` on a column that
	 * already has it (ORA-30675), so an ALTER TABLE ... MODIFY doesn't
	 * attempt to change a column's identity-ness itself, matching the same
	 * "not attempted here" limitation PgsqlPlatform's own identity/serial
	 * migration path already has.
	 */
	public function getColumnDDL(Column $col, bool $allowIdentity = true)
	{
		$table = $col->getTable();
		if ($allowIdentity && $col->isAutoIncrement() && $col->isIdentity() && $table && $this->usesNativeIdentity($table)) {
			$domain = $col->getDomain();
			$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')));
			$sqlType = $this->requireString($domain->getSqlType(), 'Column SQL type');
			if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
				$ddl []= $sqlType . $domain->printSize();
			} else {
				$ddl []= $sqlType;
			}
			// An auto-increment column has no user-declared default anyway,
			// so there's no DEFAULT-vs-identity clash to resolve here (unlike
			// TSVECTOR's generated-column branch elsewhere in this codebase).
			if ($notNull = $this->getNullString($col->isNotNull())) {
				$ddl []= $notNull;
			}
			$ddl []= 'GENERATED BY DEFAULT AS IDENTITY';

			return implode(' ', $ddl);
		}

		return parent::getColumnDDL($col);
	}

	public function getModifyColumnDDL(PropulsionColumnDiff $columnDiff)
	{
		$toColumn = $this->requireColumn($columnDiff->getToColumn());
		$table = $this->requireTable($toColumn->getTable());
		$pattern = "
ALTER TABLE %s MODIFY %s;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			$this->getColumnDDL($toColumn, false)
		);
	}

	/**
	 * BINARY_FLOAT/BINARY_DOUBLE (unlike the NUMBER/FLOAT they replaced --
	 * see initialize()) and JSON take no parenthesized size/precision
	 * argument in Oracle at all; DefaultPlatform's own hasSize() is
	 * unconditionally true, which would otherwise print a schema-declared
	 * `size`/`scale` (carried over from a plain numeric column, or simply
	 * unset and defaulted) as invalid syntax like `BINARY_DOUBLE(3)`.
	 */
	public function hasSize($sqlType)
	{
		return !("BINARY_FLOAT" == $sqlType || "BINARY_DOUBLE" == $sqlType || "JSON" == $sqlType);
	}

	public function getMaxColumnNameLength()
	{
		return 30;
	}

	public function getNativeIdMethod()
	{
		return PropulsionPlatformInterface::SEQUENCE;
	}

	public function getAutoIncrement()
	{
		return "";
	}

	public function supportsNativeDeleteTrigger()
	{
		return true;
	}

	public function getBeginDDL()
	{
		return "
ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD';
ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS';
";
	}

	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getCommentBlockDDL($this->requireString($table->getName(), 'Table name'));
			$ret .= $this->getDropTableDDL($table);
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
		}
		$ret2 = '';
		foreach ($database->getTablesForSql() as $table) {
			$ret2 .= $this->getAddForeignKeysDDL($table);
		}
		if ($ret2) {
			$ret .= $this->getCommentBlockDDL('Foreign Keys') . $ret2;
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	public function getAddTableDDL(Table $table)
	{
		$tableDescription = $table->hasDescription() ? $this->getCommentLineDDL($this->requireString($table->getDescription(), 'Table description')) : '';

		$lines = array();

		foreach ($table->getColumns() as $column) {
			$lines[] = $this->getColumnDDL($column);
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
)%s;
";
		$ret = sprintf($pattern,
			$tableDescription,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			implode($sep, $lines),
			$this->generateBlockStorage($table)
		);

		$ret .= $this->getAddPrimaryKeyDDL($table);
		$ret .= $this->getAddSequencesDDL($table);
		$ret .= $this->getAddAutoIncrementTriggerDDL($table);

		return $ret;
	}

	/**
	 * Unlike Postgres/MySQL/MSSQL's native auto-increment/SERIAL/IDENTITY, an
	 * Oracle SEQUENCE (see getAddSequencesDDL()) only generates the next value
	 * when something explicitly asks for it -- an INSERT that simply omits the
	 * PK column (exactly what Propulsion's own generated save()/doInsert() does
	 * NOT do, since DBOracle::getId()/isGetIdBeforeInsert() fetch it explicitly
	 * first, but what any other raw-SQL INSERT reasonably expects the database
	 * to handle transparently, the same as it would on every other platform
	 * this project supports) would otherwise insert a NULL PK and fail
	 * (ORA-01400). A BEFORE INSERT trigger that only fires when the PK wasn't
	 * already supplied (so an explicit allowPkInsert-style value still wins)
	 * closes that gap, matching the other platforms' actual behavior instead
	 * of only Propulsion's own code path.
	 *
	 * Skipped entirely for an `identity="true"` PK (see usesNativeIdentity()):
	 * a native `GENERATED ... AS IDENTITY` column already auto-populates
	 * itself on a PK-omitting INSERT with no trigger needed at all.
	 */
	public function getAddAutoIncrementTriggerDDL(Table $table): string
	{
		if ($table->getIdMethod() !== IDMethod::NATIVE || $this->usesNativeIdentity($table)) {
			return '';
		}
		$pk = $table->getAutoIncrementPrimaryKey();
		if ($pk === null) {
			return '';
		}

		$pattern = "
CREATE OR REPLACE TRIGGER %s
BEFORE INSERT ON %s
FOR EACH ROW
WHEN (new.%s IS NULL)
BEGIN
	SELECT %s.NEXTVAL INTO :new.%s FROM dual;
END;
";
		$pkName = $this->requireString($pk->getName(), 'Primary key column name');

		return sprintf(
			$pattern,
			$this->quoteIdentifier($this->getTriggerName($table)),
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			$this->quoteIdentifier($pkName),
			$this->quoteIdentifier($this->requireString($this->getSequenceName($table), 'Sequence name')),
			$this->quoteIdentifier($pkName)
		);
	}

	/**
	 * Name of the BEFORE INSERT trigger getAddAutoIncrementTriggerDDL() creates
	 * for a native-idMethod table -- truncated the same way getPrimaryKeyName()
	 * already is, to stay within Oracle's (pre-12.2) 30-character identifier
	 * limit once the "_TRG" suffix is appended.
	 */
	public function getTriggerName(Table $table): string
	{
		$tableName = $this->requireString($table->getName(), 'Table name');
		$tableName = substr($tableName, 0, min(26, strlen($tableName)));
		return $tableName . '_TRG';
	}

	public function getAddPrimaryKeyDDL(Table $table)
	{
		if (count($table->getPrimaryKey())) {
			return parent::getAddPrimaryKeyDDL($table);
		}

		return '';
	}

	public function getAddSequencesDDL(Table $table): ?string
	{
		// An identity="true" PK (see usesNativeIdentity()) gets its value from
		// its own hidden, implicit sequence rather than this explicit named
		// one -- skipped here the same way its trigger counterpart is above.
		if ($table->getIdMethod() == "native" && !$this->usesNativeIdentity($table)) {
			$pattern = "
CREATE SEQUENCE %s
	INCREMENT BY 1 START WITH 1 NOMAXVALUE NOCYCLE NOCACHE ORDER;
";
			return sprintf($pattern,
				$this->quoteIdentifier($this->requireString($this->getSequenceName($table), 'Sequence name'))
			);
		}

		return null;
	}

	public function getDropTableDDL(Table $table)
	{
		$ret = "
DROP TABLE " . $this->quoteIdentifier($this->requireString($table->getName(), 'Table name')) . " CASCADE CONSTRAINTS;
";
		if ($table->getIdMethod() == IDMethod::NATIVE && !$this->usesNativeIdentity($table)) {
			$ret .= "
DROP SEQUENCE " . $this->quoteIdentifier($this->requireString($this->getSequenceName($table), 'Sequence name')) . ";
";
		}
		return $ret;
	}

	public function getPrimaryKeyName(Table $table)
	{
		$tableName = $this->requireString($table->getName(), 'Table name');
		// pk constraint name must be 30 chars at most
		$tableName = substr($tableName, 0, min(27, strlen($tableName)));
		return $tableName . '_PK';
	}

	public function getPrimaryKeyDDL(Table $table)
	{
		if ($table->hasPrimaryKey()) {
			$pattern = 'CONSTRAINT %s PRIMARY KEY (%s)%s';
			return sprintf($pattern,
				$this->quoteIdentifier($this->getPrimaryKeyName($table)),
				$this->getColumnListDDL($table->getPrimaryKey()),
				$this->generateBlockStorage($table, true)
			);
		}

		return '';
	}

	public function getUniqueDDL(Unique $unique)
	{
		return sprintf('CONSTRAINT %s UNIQUE (%s)',
			$this->quoteIdentifier($this->requireString($unique->getName(), 'Unique constraint name')),
			$this->getColumnListDDL($unique->getColumns())
		);
	}

	public function getForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "CONSTRAINT %s
	FOREIGN KEY (%s) REFERENCES %s (%s)";
		$script = sprintf($pattern,
			$this->quoteIdentifier($this->requireString($fk->getName(), 'Foreign key name')),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($this->requireString($fk->getForeignTableName(), 'Foreign key target table name')),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
		if ($fk->hasOnDelete()) {
			$script .= "
	ON DELETE " . $fk->getOnDelete();
		}

		return $script;
	}

	/**
	 * Whether the underlying PDO driver for this platform returns BLOB columns as streams (instead of strings).
	 * @return     boolean
	 */
	public function hasStreamBlobImpl()
	{
		return true;
	}

	/**
	 * Oracle SQL's reserved words (Oracle Database SQL Language Reference,
	 * "Oracle SQL Reserved Words") -- an identifier matching one of these
	 * (case-insensitively) can't be used unquoted. Confirmed the hard way:
	 * this fork's own bookstore fixture has a column named `uid`
	 * (`acct_audit_log.uid`), which collides with UID, Oracle's reserved
	 * pseudo-column for the current session's numeric user ID -- generating
	 * unquoted DDL for it fails outright ("ORA-03050: invalid identifier:
	 * "UID" is a reserved word") against a real Oracle instance.
	 */
	private const RESERVED_WORDS = [
		'ACCESS', 'ADD', 'ALL', 'ALTER', 'AND', 'ANY', 'AS', 'ASC', 'AUDIT',
		'BETWEEN', 'BY', 'CHAR', 'CHECK', 'CLUSTER', 'COLUMN', 'COLUMN_VALUE',
		'COMMENT', 'COMPRESS', 'CONNECT', 'CREATE', 'CURRENT', 'DATE', 'DECIMAL',
		'DEFAULT', 'DELETE', 'DESC', 'DISTINCT', 'DROP', 'ELSE', 'EXCLUSIVE',
		'EXISTS', 'FILE', 'FLOAT', 'FOR', 'FROM', 'GRANT', 'GROUP', 'HAVING',
		'IDENTIFIED', 'IMMEDIATE', 'IN', 'INCREMENT', 'INDEX', 'INITIAL',
		'INSERT', 'INTEGER', 'INTERSECT', 'INTO', 'IS', 'LEVEL', 'LIKE', 'LOCK',
		'LONG', 'MAXEXTENTS', 'MINUS', 'MLSLABEL', 'MODE', 'MODIFY',
		'NESTED_TABLE_ID', 'NOAUDIT', 'NOCOMPRESS', 'NOT', 'NOWAIT', 'NULL',
		'NUMBER', 'OF', 'OFFLINE', 'ON', 'ONLINE', 'OPTION', 'OR', 'ORDER',
		'PCTFREE', 'PRIOR', 'PUBLIC', 'RAW', 'RENAME', 'RESOURCE', 'REVOKE',
		'ROW', 'ROWID', 'ROWNUM', 'ROWS', 'SELECT', 'SESSION', 'SET', 'SHARE',
		'SIZE', 'SMALLINT', 'START', 'SUCCESSFUL', 'SYNONYM', 'SYSDATE',
		'TABLE', 'THEN', 'TO', 'TRIGGER', 'UID', 'UNION', 'UNIQUE', 'UPDATE',
		'USER', 'VALIDATE', 'VALUES', 'VARCHAR', 'VARCHAR2', 'VIEW',
		'WHENEVER', 'WHERE', 'WITH',
	];

	/**
	 * Deliberately does NOT unconditionally quote every identifier the way
	 * Pgsql/MssqlPlatform's own quoteIdentifier() overrides do: a quoted
	 * Oracle identifier is case-sensitive forever after, while an unquoted
	 * one is folded to uppercase -- code elsewhere that reads these names
	 * back via catalog views (e.g. OracleSchemaParser reverse-engineering)
	 * consistently assumes the latter. Quoting *every* identifier would
	 * require auditing and changing all of that too, for no benefit to the
	 * overwhelming majority of schemas that never hit a reserved word in the
	 * first place. Quoting (and uppercasing, matching the same folding
	 * convention) only identifiers that are actual reserved words fixes the
	 * real, confirmed failure with zero behavioral change for every other
	 * identifier.
	 */
	public function quoteIdentifier($text)
	{
		if (str_contains($text, '.')) {
			return implode('.', array_map(fn (string $part): string => $this->quoteIdentifier($part), explode('.', $text)));
		}
		return in_array(strtoupper($text), self::RESERVED_WORDS, true)
			? '"' . strtoupper($text) . '"'
			: $text;
	}

	public function getTimestampFormatter()
	{
		return 'Y-m-d H:i:s';
	}

	/**
	 * @note       While Oracle supports schemas, they're user-based and
	 *             are really only good for creating a database layout in
	 *             one fell swoop.
	 * @see        Platform::supportsSchemas()
	 */
	public function supportsSchemas()
	{
		return false;
	}

	/**
	 * Generate oracle block storage
	 *
	 * @param     Table|Index $object object with vendor parameters
	 * @param     bool        $isPrimaryKey is a primary key vendor part
	 *
	 * @return    string      oracle vendor sql part
	 */
	public function generateBlockStorage($object, $isPrimaryKey = false)
	{
		$vendorSpecific = $object->getVendorInfoForType('oracle');
		if ($vendorSpecific->isEmpty()) {
			return '';
		}

		if ($isPrimaryKey) {
			$physicalParameters = "
USING INDEX
";
			$prefix = "PK";
		} else {
			$physicalParameters = "\n";
			$prefix = "";
		}

		if ($vendorSpecific->hasParameter($prefix.'PCTFree')) {
			$physicalParameters .= "PCTFREE " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'PCTFree'), $prefix.'PCTFree') . "
";
		}
		if ($vendorSpecific->hasParameter($prefix.'InitTrans')) {
			$physicalParameters .= "INITRANS " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'InitTrans'), $prefix.'InitTrans') . "
";
		}
		if ($vendorSpecific->hasParameter($prefix.'MinExtents') || $vendorSpecific->hasParameter($prefix.'MaxExtents') || $vendorSpecific->hasParameter($prefix.'PCTIncrease')) {
			$physicalParameters .= "STORAGE
(
";
			if ($vendorSpecific->hasParameter($prefix.'MinExtents')) {
				$physicalParameters .= "	MINEXTENTS " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'MinExtents'), $prefix.'MinExtents') . "
";
			}
			if ($vendorSpecific->hasParameter($prefix.'MaxExtents')) {
				$physicalParameters .= "	MAXEXTENTS " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'MaxExtents'), $prefix.'MaxExtents') . "
";
			}
			if ($vendorSpecific->hasParameter($prefix.'PCTIncrease')) {
				$physicalParameters .= "	PCTINCREASE " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'PCTIncrease'), $prefix.'PCTIncrease') . "
";
			}
			$physicalParameters .= ")
";
		}
		if ($vendorSpecific->hasParameter($prefix.'Tablespace')) {
			$physicalParameters .= "TABLESPACE " . $this->requireStringParam($vendorSpecific->getParameter($prefix.'Tablespace'), $prefix.'Tablespace');
		}
		return $physicalParameters;
	}

	/**
	 * Builds the DDL SQL to add an Index.
	 *
	 * @param      Index $index
	 * @return     string
	 */
	public function getAddIndexDDL(Index $index)
	{
		$table = $this->requireTable($index->getTable());
		$indexName = $this->requireString($index->getName(), 'Index name');

		// don't create index form primary key
		if ($this->getPrimaryKeyName($table) == $this->quoteIdentifier($indexName)) {
			return "";
		}

		$pattern = "
CREATE %sINDEX %s ON %s (%s)%s;
";
		return sprintf($pattern,
			$index->isUnique() ? 'UNIQUE ' : '',
			$this->quoteIdentifier($indexName),
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			$this->getColumnListDDL($index->getColumns()),
			$this->generateBlockStorage($index)
		);
	}
}
