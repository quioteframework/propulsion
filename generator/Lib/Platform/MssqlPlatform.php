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
 * MS SQL PropulsionPlatformInterface implementation.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @version    $Revision$
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\ForeignKey;

class MssqlPlatform extends DefaultPlatform
{
	/**
	 * Table/ForeignKey's own getName()/getTable()/etc. getters are typed
	 * nullable throughout this codebase's model classes (schema-XML parsing
	 * leaves them unset until later in the load process), but by the time DDL
	 * generation actually runs on a real, fully-loaded schema they're always
	 * populated -- these two guards turn that implicit assumption into an
	 * explicit, real failure instead of silently widening this class's own
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

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize(): void
	{
		parent::initialize();
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTEGER, "INT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BOOLEAN, "INT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DOUBLE, "FLOAT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARCHAR, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CLOB, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATE, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BU_DATE, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TIME, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TIMESTAMP, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BU_TIMESTAMP, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BINARY, "BINARY(7132)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARBINARY, "VARBINARY(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARBINARY, "VARBINARY(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BLOB, "VARBINARY(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::OBJECT, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::PHP_ARRAY, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::ENUM, "TINYINT"));
		// SQL Server has no native JSON column type (JSON is validated/queried via
		// functions over ordinary NVARCHAR columns) -- VARCHAR(MAX) mirrors the
		// LONGVARCHAR/OBJECT/PHP_ARRAY fallback above.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSON, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSONB, "VARCHAR(MAX)"));
		// SQL Server has a native UNIQUEIDENTIFIER type, but it stores/returns
		// GUIDs in a different byte order (and default string casing) than the
		// canonical RFC 4122 textual form used everywhere else in this codebase.
		// Falling back to CHAR(36) keeps the on-the-wire representation
		// (and comparisons/round-tripping through PDO) identical across every
		// platform (see PropulsionTypes::UUID / Column::isUuidType()).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::UUID, "CHAR", 36));
	}

	public function getMaxColumnNameLength()
	{
		return 128;
	}

	public function getNullString(bool $notNull)
	{
		return ($notNull ? "NOT NULL" : "NULL");
	}

	public function supportsNativeDeleteTrigger()
	{
		return true;
	}

	public function supportsInsertNullPk()
	{
		return false;
	}

	/**
	 * Overridden (matching PgsqlPlatform/OraclePlatform's own override of this
	 * same method) to add every table's foreign keys in a second pass, after
	 * every table has already been created -- DefaultPlatform's un-overridden
	 * version interleaves each table's own FK constraints immediately after
	 * that table, which breaks the instant a table's FK references another
	 * table declared *later* in the schema (e.g. this fork's own bookstore
	 * fixture: `book` is declared before `publisher`/`author`, and has FKs to
	 * both) -- the referenced table genuinely doesn't exist yet at the point
	 * DefaultPlatform would try to add that constraint. Real SQL Server has no
	 * equivalent to MySQL's `SET FOREIGN_KEY_CHECKS = 0` escape hatch, so
	 * declaration order must actually be respected here, not just tolerated.
	 */
	public function getAddTablesDDL(Database $database)
	{
		$ret = $this->getBeginDDL();
		foreach ($database->getTablesForSql() as $table) {
			$ret .= $this->getCommentBlockDDL($this->requireString($table->getName(), 'Table name'));
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

	public function getDropTableDDL(Table $table)
	{
		// Computed once up front (rather than at each of this method's several
		// $table->getName() call sites) so the null-guard only has to happen once.
		$tableName = $this->requireString($table->getName(), 'Table name');

		$ret = '';
		foreach ($table->getForeignKeys() as $fk) {
			$fkName = $this->requireString($fk->getName(), 'Foreign key name');
			$ret .= "
IF EXISTS (SELECT 1 FROM sysobjects WHERE type ='RI' AND name='" . $fkName . "')
	ALTER TABLE " . $this->quoteIdentifier($tableName) . " DROP CONSTRAINT " . $this->quoteIdentifier($fkName) . ";
";
		}

		// Derived from the table's own name rather than a shared counter: a real
		// generation run doesn't reliably share one MssqlPlatform instance across
		// every schema file it processes the way a naive read of
		// SqlManager::loadDataModels() suggests -- AppData::addDatabase() calls
		// GeneratorConfig::getConfiguredPlatform() per <database> element parsed
		// from XML, and that always does `new $platformClass()`, no caching. Several
		// schema files sharing one <database name="..."> (SqlManager's own docblock
		// gives "a project's behavior-*-schema.xml files" as the exact example) each
		// get their own fresh MssqlPlatform, so an instance-scoped counter starts
		// back at 0/1 for every file -- and their DDL is concatenated into the same
		// output file (and, since MSSQL's PDO driver accepts a whole multi-statement
		// batch in one exec(), potentially executed as a single SQL Server batch),
		// where SQL Server scopes every DECLARE'd variable *and* cursor name to the
		// whole batch. A counter that restarts per file collides silently; deriving
		// the suffix from the table name itself can't, since two tables' DROP blocks
		// in the same script are never for the same table.
		$suffix = preg_replace('/[^A-Za-z0-9_]/', '_', $tableName);
		$cursorName = 'refcursor_' . $suffix;

		$ret .= "
IF EXISTS (SELECT 1 FROM sysobjects WHERE type = 'U' AND name = '" . $tableName . "')
BEGIN
	DECLARE @reftable_{$suffix} nvarchar(60), @constraintname_{$suffix} nvarchar(60)
	DECLARE {$cursorName} CURSOR FOR
	select reftables.name tablename, cons.name constraintname
		from sysobjects tables,
			sysobjects reftables,
			sysobjects cons,
			sysreferences ref
		where tables.id = ref.rkeyid
			and cons.id = ref.constid
			and reftables.id = ref.fkeyid
			and tables.name = '" . $tableName . "'
	OPEN {$cursorName}
	FETCH NEXT from {$cursorName} into @reftable_{$suffix}, @constraintname_{$suffix}
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_{$suffix}+' drop constraint '+@constraintname_{$suffix})
		FETCH NEXT from {$cursorName} into @reftable_{$suffix}, @constraintname_{$suffix}
	END
	CLOSE {$cursorName}
	DEALLOCATE {$cursorName}
	DROP TABLE " . $this->quoteIdentifier($tableName) . "
END
";
		return $ret;
	}

	public function getPrimaryKeyDDL(Table $table)
	{
		if ($table->hasPrimaryKey()) {
			$pattern = 'CONSTRAINT %s PRIMARY KEY (%s)';
			return sprintf($pattern,
				$this->quoteIdentifier($this->getPrimaryKeyName($table)),
				$this->getColumnListDDL($table->getPrimaryKey())
			);
		}

		return '';
	}

	public function getAddForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = "
BEGIN
ALTER TABLE %s ADD %s
END
;
";
		return sprintf($pattern,
			$this->quoteIdentifier($this->requireString($this->requireTable($fk->getTable())->getName(), 'Table name')),
			$this->getForeignKeyDDL($fk)
		);
	}

	public function getForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return '';
		}
		$pattern = 'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)';
		$script = sprintf($pattern,
			$this->quoteIdentifier($this->requireString($fk->getName(), 'Foreign key name')),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($this->requireString($fk->getForeignTableName(), 'Foreign key target table name')),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
		if ($fk->hasOnUpdate() && $fk->getOnUpdate() != ForeignKey::SETNULL) {
			$script .= ' ON UPDATE ' . $fk->getOnUpdate();
		}
		if ($fk->hasOnDelete() && $fk->getOnDelete() != ForeignKey::SETNULL) {
			$script .= ' ON DELETE '.  $fk->getOnDelete();
		}

		return $script;
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
		return !("INT" == $sqlType || "TEXT" == $sqlType);
	}

	public function quoteIdentifier($text)
	{
		return $this->isIdentifierQuotingEnabled ? '[' . strtr($text, array('.' => '].[')) . ']' : $text;
	}

	public function getTimestampFormatter()
	{
		return 'Y-m-d H:i:s';
	}

}
