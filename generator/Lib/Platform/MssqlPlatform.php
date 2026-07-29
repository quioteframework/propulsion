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
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Unique;
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
	 * Foreign key names (ForeignKey::getName()) to emit as NO ACTION instead of
	 * CASCADE on DELETE/UPDATE respectively, to avoid SQL Server error 1785
	 * ("...may cause cycles or multiple cascade paths"). Computed once per
	 * getAddTablesDDL() call (which has the whole Database graph available),
	 * consulted by getForeignKeyDDL() (which only sees one ForeignKey at a
	 * time) -- see computeCascadeDowngrades().
	 *
	 * @var array<string, true>
	 */
	private array $cascadeDeleteDowngrades = array();

	/** @var array<string, true> Same as $cascadeDeleteDowngrades, for ON UPDATE CASCADE. */
	private array $cascadeUpdateDowngrades = array();

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
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTERVAL, "VARCHAR", 32));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INET, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CIDR, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::MACADDR, "VARCHAR", 17));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CITEXT, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT4RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT8RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATERANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSTZRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VECTOR, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::GEOMETRY, "VARCHAR(MAX)"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSVECTOR, "VARCHAR(MAX)"));
		// No native SET type -- emulated as comma-joined text (see
		// PropulsionTypes::SET_NATIVE_TYPE for why the same comma-join/explode
		// code works unmodified across every platform).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::SET, "VARCHAR(MAX)"));
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
	 * Overridden only to look past `Table::finalize()`'s "idMethod=native but
	 * no autoIncrement column on the table -> silently downgrade to
	 * NO_ID_METHOD" rule (see Table.php), which
	 * `DefaultPlatform::getSequenceName()`'s own NATIVE-only gate would
	 * otherwise make this platform's named-sequence feature (see
	 * getAddSequenceDDL() below) unreachable for -- a MSSQL table using an
	 * explicit sequence has no reason to also declare an autoIncrement column
	 * (its column instead uses `defaultExpr="NEXT VALUE FOR <sequence>"`), so
	 * it never stays NATIVE post-finalize() the way Postgres's own usage of
	 * this same `<id-method-parameter>` mechanism does (always paired with
	 * `autoIncrement="true"`, precisely because SERIAL's implicit sequence and
	 * a named explicit one are the same kind of object there). Falls through
	 * to the inherited NATIVE-gated behavior -- including its `<table>_SEQ`
	 * default-name fallback, still relied on by
	 * `TableMapBuilder::setPrimaryKeyMethodInfo()` and this platform's own
	 * pre-existing testGetSequenceNameDefault()/testGetSequenceNameCustom() --
	 * whenever no id-method-parameter is declared at all.
	 *
	 * @param      Table $table
	 * @return     string|null
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
	 * A named sequence, opt in via `<table><id-method-parameter value="..."/>
	 * </table>` -- unlike Postgres, MSSQL has no implicit per-autoIncrement-
	 * column sequence to back, since IDENTITY (DefaultPlatform::getAutoIncrement(),
	 * still this platform's default id method) is a column property, not backed
	 * by a real CREATE SEQUENCE object at all. A column that wants its value
	 * from the sequence created here does so via its own `defaultExpr="NEXT
	 * VALUE FOR <sequence>"` -- CREATE SEQUENCE itself carries no column
	 * association for getAddSequenceDDL()/getDropSequenceDDL() to hook a
	 * column's DDL through, and pairing it with `autoIncrement="true"` on the
	 * same column would be a genuine schema-author error (IDENTITY and a
	 * sequence-driven DEFAULT would both try to supply the same column's
	 * value), not something this method attempts to validate.
	 *
	 * @param      Table $table
	 * @return     string|null
	 */
	protected function getAddSequenceDDL(Table $table): ?string
	{
		if (empty($table->getIdMethodParameters())) {
			return null;
		}
		$sequenceName = $this->requireString($this->getSequenceName($table), 'Sequence name');
		// SQL Server 2012+. START WITH 1 is specified explicitly -- omitting it
		// would default to the sequence type's minimum value (a large negative
		// number for BIGINT), not 1.
		$pattern = "
CREATE SEQUENCE %s AS BIGINT START WITH 1 INCREMENT BY 1;
";
		return sprintf($pattern, $this->quoteIdentifier($sequenceName));
	}

	/**
	 * @param      Table $table
	 * @return     string|null
	 */
	protected function getDropSequenceDDL(Table $table): ?string
	{
		if (empty($table->getIdMethodParameters())) {
			return null;
		}
		$sequenceName = $this->requireString($this->getSequenceName($table), 'Sequence name');
		$pattern = "
IF EXISTS (SELECT 1 FROM sys.sequences WHERE name = '%s')
	DROP SEQUENCE %s;
";
		return sprintf($pattern, $sequenceName, $this->quoteIdentifier($sequenceName));
	}

	/**
	 * MSSQL has no native enum type; unlike SQLite/Oracle (see
	 * DefaultPlatform::getEnumCheckConstraintDDL()), it also doesn't get an
	 * emulated CHECK-constraint substitute -- `nativeEnum="true"` is simply
	 * ignored here and the column stays the plain emulated integer.
	 */
	public function supportsNativeEnumDDL()
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
		$this->cascadeDeleteDowngrades = $this->computeCascadeDowngrades($database, fn (ForeignKey $fk) => $fk->getOnDelete());
		$this->cascadeUpdateDowngrades = $this->computeCascadeDowngrades($database, fn (ForeignKey $fk) => $fk->getOnUpdate());

		$tables = $database->getTablesForSql();
		$ret = $this->getBeginDDL();
		if ($tables) {
			// This exact six-option combination (ON for every one except
			// NUMERIC_ROUNDABORT) is what SQL Server itself requires -- and
			// names in its own error text -- for the *session that creates it*
			// for several object types (computed columns -- see
			// getColumnDDL()'s isGenerated() branch -- indexed views, filtered
			// indexes, and indexes on computed columns among them); all six
			// already default correctly for SSMS and the pdo_sqlsrv driver
			// (DBSQLSRV), but FreeTDS's pdo_dblib (the driver this codebase's
			// own MSSQL integration tests connect over, see
			// IntegrationDatabase::pdoDriverPrefix()) does not -- a
			// generated-DDL-with-a-PERSISTED-computed-column run through
			// SqlExecManager (bin/propulsion sql:exec), which opens a bare PDO
			// with no MSSQL-specific session setup of its own, failed outright
			// against a live azure-sql-edge container over dblib (SQL Server
			// error 20018, "SET options have incorrect settings: ...") while
			// verifying getColumnDDL()'s new isGenerated() branch -- confirmed
			// this is a real, session-level requirement, not a DDL-string
			// mistake. Setting all six explicitly here, once at the top of the
			// file (guarded on there being any table to actually emit, so an
			// all-skipSql schema still produces a truly empty string), is
			// harmless on every other connection -- these are already in
			// effect there -- and needs no platform-agnostic SqlExecManager
			// change to take effect, since every one is a session SET option
			// that persists for the rest of that one connection's statements.
			$ret .= "
SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET QUOTED_IDENTIFIER ON;
SET NUMERIC_ROUNDABORT OFF;
";
		}
		foreach ($tables as $table) {
			$ret .= $this->getCommentBlockDDL($this->requireString($table->getName(), 'Table name'));
			$ret .= $this->getDropTableDDL($table);
			$ret .= $this->getAddSequenceDDL($table);
			$ret .= $this->getAddTableDDL($table);
			$ret .= $this->getAddIndicesDDL($table);
		}
		foreach ($tables as $table) {
			$ret .= $this->getAddForeignKeysDDL($table);
		}
		$ret .= $this->getEndDDL();
		return $ret;
	}

	/**
	 * SQL Server refuses to create a foreign key with a *modifying* ON DELETE or
	 * ON UPDATE action ("$actionGetter") -- CASCADE or SET NULL, anything other
	 * than NO ACTION -- if doing so would let the same row be touched by more
	 * than one such path -- error 1785/20018. This restriction applies equally
	 * to CASCADE and SET NULL (empirically confirmed against a live SQL Server:
	 * mixing them, or using SET NULL on both sides of a conflicting pair, fails
	 * exactly the same way CASCADE-on-both-sides does). Three distinct shapes of
	 * this fork's own bookstore fixture hit it deliberately:
	 *
	 * 0. **Self-reference**: `essay.next_essay_id -> essay.id` (ON UPDATE
	 *    CASCADE) and `bookstore_employee.supervisor_id -> bookstore_employee.id`
	 *    (ON DELETE SET NULL) -- a table can never have a modifying action on a
	 *    FK that targets itself.
	 * 1. **Diamond via an intermediate table** ("Test multiple foreign keys for a
	 *    single column"): `reader_favorite` cascade-deletes from both `book` and
	 *    `book_reader` directly, *and* from both of those again indirectly via
	 *    `book_opinion` (which itself cascade-deletes from `book` and
	 *    `book_reader`) -- deleting a `book` row would need to cascade into
	 *    `reader_favorite` by two different routes.
	 * 2. **Two FKs from the same table straight to the same target**: `essay` has
	 *    two separate FKs to `author` (`first_author` and `second_author`), both
	 *    with a modifying ON DELETE/ON UPDATE action -- updating or deleting an
	 *    `author` row could need to touch the *same* `essay` row twice, once per
	 *    column.
	 *
	 * Detects all three schema-wide and returns the set of FKs to downgrade to NO
	 * ACTION instead. For (1), the *direct* edge from the ancestor is redundant --
	 * deleting/updating it already cascades transitively through the other
	 * parent, so dropping the direct edge loses no actual cleanup behavior, it
	 * just removes the extra path SQL Server won't allow. For (2), only the first
	 * (by declaration order) FK to a given repeated target keeps its modifying
	 * action; the rest are downgraded.
	 *
	 * @param      Database $database
	 * @param      callable(ForeignKey): ?string $actionGetter ForeignKey::getOnDelete() or getOnUpdate().
	 * @return     array<string, true> Set of ForeignKey names (see ForeignKey::getName()) to downgrade.
	 */
	private function computeCascadeDowngrades(Database $database, callable $actionGetter): array
	{
		$downgrades = array();
		$isModifying = fn (?string $action): bool => $action !== null && $action !== ForeignKey::NOACTION;

		// Case 0: a self-referencing FK (local and foreign table are the same,
		// e.g. essay.next_essay_id -> essay.id) with a modifying action -- SQL
		// Server rejects this outright, the same error 1785 family, regardless of
		// any other table's FKs. Always downgrade.
		foreach ($database->getTables() as $table) {
			$tableName = $table->getName();
			if ($tableName === null) {
				continue;
			}
			foreach ($table->getForeignKeys() as $fk) {
				if ($isModifying($actionGetter($fk)) && $fk->getForeignTableName() === $tableName) {
					$fkName = $fk->getName();
					if ($fkName !== null) {
						$downgrades[$fkName] = true;
					}
				}
			}
		}

		// Case 2: same table, 2+ direct modifying-action FKs to the same target --
		// keep only the first, downgrade the rest. Done before building the graph
		// below so case 1's diamond detection sees the graph *after* these
		// redundant edges are already removed.
		foreach ($database->getTables() as $table) {
			$seenTargets = array();
			foreach ($table->getForeignKeys() as $fk) {
				$parentName = $fk->getForeignTableName();
				$fkName = $fk->getName();
				if (!$isModifying($actionGetter($fk)) || $parentName === null
					|| ($fkName !== null && isset($downgrades[$fkName]))
				) {
					continue;
				}
				if (isset($seenTargets[$parentName])) {
					if ($fkName !== null) {
						$downgrades[$fkName] = true;
					}
				} else {
					$seenTargets[$parentName] = true;
				}
			}
		}

		// Whole-schema cascade graph (post-case-2): edge parentTable -> childTable
		// for every surviving modifying-action FK (child.col REFERENCES
		// parentTable.col [ON DELETE|ON UPDATE] CASCADE/SET NULL means a change to
		// a parent row touches the child row).
		$cascadeChildren = array();
		foreach ($database->getTables() as $table) {
			$childName = $table->getName();
			if ($childName === null) {
				continue;
			}
			foreach ($table->getForeignKeys() as $fk) {
				$parentName = $fk->getForeignTableName();
				$fkName = $fk->getName();
				if ($isModifying($actionGetter($fk)) && $parentName !== null
					&& ($fkName === null || !isset($downgrades[$fkName]))
				) {
					$cascadeChildren[$parentName][$childName] = true;
				}
			}
		}

		// Case 1: diamonds via an intermediate table.
		foreach ($database->getTables() as $table) {
			$childName = $table->getName();
			if ($childName === null) {
				continue;
			}

			$directParentFks = array();
			foreach ($table->getForeignKeys() as $fk) {
				$fkName = $fk->getName();
				if ($isModifying($actionGetter($fk)) && $fk->getForeignTableName() !== null
					&& ($fkName === null || !isset($downgrades[$fkName]))
				) {
					$directParentFks[] = $fk;
				}
			}
			if (count($directParentFks) < 2) {
				continue;
			}

			foreach ($directParentFks as $fk) {
				$parentName = $fk->getForeignTableName();
				if ($parentName === null) {
					continue;
				}
				foreach ($directParentFks as $otherFk) {
					$otherParentName = $otherFk->getForeignTableName();
					if ($otherParentName === null || $otherFk === $fk || $parentName === $otherParentName) {
						continue;
					}
					if ($this->cascadeReaches($cascadeChildren, $parentName, $otherParentName, $childName)) {
						$fkName = $fk->getName();
						if ($fkName !== null) {
							$downgrades[$fkName] = true;
						}
						break;
					}
				}
			}
		}

		return $downgrades;
	}

	/**
	 * Whether $target is reachable from $source by following $cascadeChildren
	 * edges (parentTable => [childTable => true, ...]), never passing through
	 * $exclude -- excluded so a path that only "reaches" $target by going
	 * through the very table being evaluated in computeCascadeDowngrades()
	 * doesn't count as a redundant-path conflict.
	 *
	 * @param      array<string, array<string, true>> $cascadeChildren
	 * @param      string $source
	 * @param      string $target
	 * @param      string $exclude
	 * @return     bool
	 */
	private function cascadeReaches(array $cascadeChildren, string $source, string $target, string $exclude): bool
	{
		$visited = array($source => true);
		$queue = array($source);
		while ($queue) {
			$current = array_shift($queue);
			foreach (($cascadeChildren[$current] ?? array()) as $next => $unused) {
				if ($next === $exclude || isset($visited[$next])) {
					continue;
				}
				if ($next === $target) {
					return true;
				}
				$visited[$next] = true;
				$queue[] = $next;
			}
		}
		return false;
	}

	public function getDropTableDDL(Table $table)
	{
		// Computed once up front (rather than at each of this method's several
		// $table->getName() call sites) so the null-guard only has to happen once.
		$tableName = $this->requireString($table->getName(), 'Table name');

		// A system-versioned temporal table can't be dropped (or have its FK/PK
		// constraints altered) while versioning is still on -- SQL Server error
		// 13552. Checked against sys.tables.temporal_type (2 =
		// SYSTEM_VERSIONED_TEMPORAL_TABLE) rather than $table->isTemporal(),
		// since this DDL may run against a live database left over from a
		// schema that used to declare `temporal="true"` and no longer does.
		$ret = "
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = '" . $tableName . "' AND temporal_type = 2)
	ALTER TABLE " . $this->quoteIdentifier($tableName) . " SET (SYSTEM_VERSIONING = OFF);
";
		foreach ($table->getForeignKeys() as $fk) {
			$fkName = $this->requireString($fk->getName(), 'Foreign key name');
			$ret .= "
IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = '" . $fkName . "')
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
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = '" . $tableName . "')
BEGIN
	DECLARE @reftable_{$suffix} nvarchar(60), @constraintname_{$suffix} nvarchar(60)
	DECLARE {$cursorName} CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = '" . $tableName . "'
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
		$ret .= $this->getDropSequenceDDL($table);
		if ($table->isTemporal()) {
			// Turning SYSTEM_VERSIONING off (above) demotes the history table to
			// an ordinary table -- it's dropped here explicitly, the same way
			// dropping the base table doesn't do it automatically on real SQL
			// Server either.
			$historyTableName = $this->getHistoryTableName($table);
			$ret .= "
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = '" . $this->getHistoryTableUnqualifiedName($table) . "')
	DROP TABLE " . $this->quoteIdentifier($historyTableName) . ";
";
		}
		return $ret;
	}

	/**
	 * Computed columns (`generatedAs="expr"` -- see SqlitePlatform's own generated
	 * column support, this is the same platform-generic `Column` attribute) and
	 * `rowVersion="true"` (SQL Server's auto-maintained `ROWVERSION` binary
	 * column, the standard optimistic-concurrency token there).
	 *
	 * T-SQL's grammar for a computed column is stricter than SQLite's: `NOT NULL`
	 * is only legal alongside `PERSISTED` (a non-persisted/virtual computed
	 * column can never be declared `NOT NULL`, and neither can be declared plain
	 * `NULL` at all -- there's no keyword for it), and `IDENTITY`/a `DEFAULT`
	 * make no sense on a column whose value is always derived from its
	 * expression, so neither is emitted here the way SqlitePlatform's own
	 * override still emits an (always-true) null-string branch.
	 *
	 * `ROWVERSION` is a fixed, server-maintained binary(8) type: no size, no
	 * `DEFAULT`, and only one such column is allowed per table (a schema
	 * declaring more than one is the author's own error -- not validated here,
	 * the same way this codebase doesn't validate a second `autoIncrement`
	 * column elsewhere).
	 */
	public function getColumnDDL(Column $col)
	{
		if ($col->isGenerated()) {
			$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')), sprintf('AS (%s)', $col->getGeneratedExpr()));
			if ($col->isGeneratedStored()) {
				$ddl []= 'PERSISTED';
				if ($col->isNotNull()) {
					$ddl []= 'NOT NULL';
				}
			}
			return implode(' ', $ddl);
		}

		if ($col->isRowVersion()) {
			$ddl = array($this->quoteIdentifier($this->requireString($col->getName(), 'Column name')), 'ROWVERSION');
			if ($notNull = $this->getNullString($col->isNotNull())) {
				$ddl []= $notNull;
			}
			return implode(' ', $ddl);
		}

		if ($col->isPeriodRowStart() || $col->isPeriodRowEnd()) {
			// The PERIOD FOR SYSTEM_TIME boundary columns of a temporal table
			// (see getAddTableDDL()) -- always DATETIME2, always server-generated
			// and NOT NULL, regardless of the column's own declared domain type.
			return sprintf('%s DATETIME2 GENERATED ALWAYS AS ROW %s NOT NULL',
				$this->quoteIdentifier($this->requireString($col->getName(), 'Column name')),
				$col->isPeriodRowStart() ? 'START' : 'END'
			);
		}

		return parent::getColumnDDL($col);
	}

	/**
	 * The history table backing a temporal table's row versions --
	 * `Table::getHistoryTable()` if explicitly declared, else `<table>_History`
	 * (the same naming convention PgsqlPlatform's own getSequenceName() uses
	 * for an unnamed sequence), always qualified with an explicit schema --
	 * confirmed against a live azure-sql-edge container while implementing
	 * this that `HISTORY_TABLE = <single-part name>` is rejected outright
	 * ("not specified in two-part name format", error 13735), even for a base
	 * table with no schema of its own -- which is why this can't simply reuse
	 * `$table->getName()` (already schema-qualified when the table has one,
	 * see Table::getName()) unconditionally the way getAddSequenceDDL()'s
	 * sequence naming does: a schema-less table still needs the "dbo." this
	 * falls back to, but $table->getName() would return just the bare name.
	 *
	 * An explicit `historyTable="..."` is auto-qualified the same way when it
	 * names a bare table with no schema prefix of its own -- a schema author
	 * naming just `historyTable="foo_versions"` almost certainly means "in
	 * whichever schema the versioned table itself is in", not a name SQL
	 * Server will actually accept as-is.
	 *
	 * @see        getHistoryTableUnqualifiedName()
	 */
	protected function getHistoryTableName(Table $table): string
	{
		$historyTable = $table->getHistoryTable()
			?? ($this->requireString($table->getCommonName(), 'Table name') . '_History');
		if (strpos($historyTable, '.') !== false) {
			return $historyTable;
		}
		return ($table->getSchema() ?? 'dbo') . '.' . $historyTable;
	}

	/**
	 * The bare table name (no schema prefix) getHistoryTableName() resolves to
	 * -- needed because `sys.tables.name` itself is never schema-qualified, so
	 * an `IF EXISTS (SELECT 1 FROM sys.tables WHERE name = '...')` guard (see
	 * getDropTableDDL()) must filter on this, not getHistoryTableName()'s own
	 * two-part return value.
	 */
	protected function getHistoryTableUnqualifiedName(Table $table): string
	{
		$name = $this->getHistoryTableName($table);
		$dotPos = strrpos($name, '.');
		return $dotPos === false ? $name : substr($name, $dotPos + 1);
	}

	/**
	 * SQL Server system-versioned temporal tables (`temporal="true"`, SQL
	 * Server 2016+/Azure SQL): a plain `CREATE TABLE` gains a trailing
	 * `PERIOD FOR SYSTEM_TIME (start, end)` clause naming the table's two
	 * `Column::isPeriodRowStart()`/`isPeriodRowEnd()` columns (see
	 * getColumnDDL()), plus a `WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE =
	 * ...))` clause after the column list, which is what actually turns on
	 * versioning and starts SQL Server auto-populating the history table on
	 * every UPDATE/DELETE.
	 *
	 * @see        DefaultPlatform::getAddTableDDL()
	 */
	public function getAddTableDDL(Table $table)
	{
		if (!$table->isTemporal()) {
			return parent::getAddTableDDL($table);
		}

		$tableDescription = $table->hasDescription() ? $this->getCommentLineDDL($this->requireString($table->getDescription(), 'Table description')) : '';

		$periodStart = null;
		$periodEnd = null;
		$lines = array();
		foreach ($table->getColumns() as $column) {
			$lines[] = $this->getColumnDDL($column);
			if ($column->isPeriodRowStart()) {
				$periodStart = $column;
			} elseif ($column->isPeriodRowEnd()) {
				$periodEnd = $column;
			}
		}
		if ($periodStart === null || $periodEnd === null) {
			throw new EngineException(sprintf(
				'Table "%s" is temporal="true" but does not declare exactly one periodRowStart="true" and one periodRowEnd="true" column',
				$this->requireString($table->getName(), 'Table name')
			));
		}
		$lines[] = sprintf('PERIOD FOR SYSTEM_TIME (%s, %s)',
			$this->quoteIdentifier($this->requireString($periodStart->getName(), 'Column name')),
			$this->quoteIdentifier($this->requireString($periodEnd->getName(), 'Column name'))
		);

		if ($table->hasPrimaryKey()) {
			$lines[] = $this->getPrimaryKeyDDL($table);
		}
		foreach ($table->getUnices() as $unique) {
			$lines[] = $this->getUniqueDDL($unique);
		}

		$pattern = "
%sCREATE TABLE %s
(
	%s
)
WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = %s));
";
		return sprintf($pattern,
			$tableDescription,
			$this->quoteIdentifier($this->requireString($table->getName(), 'Table name')),
			implode(",\n\t", $lines),
			$this->quoteIdentifier($this->getHistoryTableName($table))
		);
	}

	/**
	 * `Table::isPrimaryKeyClustered()` defaults to true, matching SQL Server's
	 * own implicit default for a `PRIMARY KEY` constraint (`CLUSTERED` unless
	 * another clustered index/constraint already exists on the table) -- so
	 * the common case emits no explicit keyword at all, identical to the DDL
	 * this method has always produced. `primaryKeyClustered="false"` emits an
	 * explicit `NONCLUSTERED` to free that slot for a `<unique clustered="true">`/
	 * `<index clustered="true">` elsewhere (see Index::isClustered()).
	 */
	public function getPrimaryKeyDDL(Table $table)
	{
		if ($table->hasPrimaryKey()) {
			$pattern = 'CONSTRAINT %s PRIMARY KEY %s(%s)';
			return sprintf($pattern,
				$this->quoteIdentifier($this->getPrimaryKeyName($table)),
				$table->isPrimaryKeyClustered() ? '' : 'NONCLUSTERED ',
				$this->getColumnListDDL($table->getPrimaryKey())
			);
		}

		return '';
	}

	/**
	 * `Index::isClustered()` is null (unspecified) by default, matching this
	 * method's previous, un-overridden `DefaultPlatform::getAddIndexDDL()`
	 * output exactly -- only an explicit `clustered="true"`/`"false"` emits the
	 * corresponding keyword at all.
	 *
	 * @see        DefaultPlatform::getAddIndexDDL()
	 */
	public function getAddIndexDDL(Index $index)
	{
		$clustered = $index->isClustered();
		$pattern = "
CREATE %s%sINDEX %s ON %s (%s);
";
		return sprintf($pattern,
			$index->isUnique() ? 'UNIQUE ' : '',
			$clustered === null ? '' : ($clustered ? 'CLUSTERED ' : 'NONCLUSTERED '),
			$this->quoteIdentifier($this->requireString($index->getName(), 'Index name')),
			$this->quoteIdentifier($this->requireString($this->requireTable($index->getTable())->getName(), 'Table name')),
			$this->getColumnListDDL($index->getColumns())
		);
	}

	/**
	 * @see        DefaultPlatform::getUniqueDDL()
	 */
	public function getUniqueDDL(Unique $unique)
	{
		$clustered = $unique->isClustered();
		return sprintf('UNIQUE %s(%s)',
			$clustered === null ? '' : ($clustered ? 'CLUSTERED ' : 'NONCLUSTERED '),
			$this->getColumnListDDL($unique->getColumns())
		);
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
		$fkName = $this->requireString($fk->getName(), 'Foreign key name');
		$pattern = 'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)';
		$script = sprintf($pattern,
			$this->quoteIdentifier($fkName),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($this->requireString($fk->getForeignTableName(), 'Foreign key target table name')),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
		// See computeCascadeDowngrades(): a modifying action (CASCADE or SET NULL)
		// that would create a second such path SQL Server won't allow (error 1785)
		// is emitted as NO ACTION instead -- the update/delete still reaches this
		// table transitively through whichever other parent's path (or
		// first-declared same-target FK) was kept.
		if ($fk->hasOnUpdate()) {
			$onUpdate = isset($this->cascadeUpdateDowngrades[$fkName]) ? ForeignKey::NOACTION : $fk->getOnUpdate();
			$script .= ' ON UPDATE ' . $onUpdate;
		}
		if ($fk->hasOnDelete()) {
			$onDelete = isset($this->cascadeDeleteDowngrades[$fkName]) ? ForeignKey::NOACTION : $fk->getOnDelete();
			$script .= ' ON DELETE '.  $onDelete;
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
