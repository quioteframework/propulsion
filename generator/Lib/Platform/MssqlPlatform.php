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
		$this->cascadeDeleteDowngrades = $this->computeCascadeDowngrades($database, fn (ForeignKey $fk) => $fk->getOnDelete());
		$this->cascadeUpdateDowngrades = $this->computeCascadeDowngrades($database, fn (ForeignKey $fk) => $fk->getOnUpdate());

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

	/**
	 * SQL Server refuses to create a foreign key with a cascading ON DELETE or ON
	 * UPDATE action ("$actionGetter") if doing so would let the same row be
	 * touched by more than one cascade path -- error 1785. Two distinct shapes of
	 * this fork's own bookstore fixture hit it deliberately:
	 *
	 * 1. **Diamond via an intermediate table** ("Test multiple foreign keys for a
	 *    single column"): `reader_favorite` cascade-deletes from both `book` and
	 *    `book_reader` directly, *and* from both of those again indirectly via
	 *    `book_opinion` (which itself cascade-deletes from `book` and
	 *    `book_reader`) -- deleting a `book` row would need to cascade into
	 *    `reader_favorite` by two different routes.
	 * 2. **Two FKs from the same table straight to the same target**: `essay` has
	 *    two separate `ON UPDATE CASCADE` FKs to `author` (`first_author` and
	 *    `second_author`) -- updating an `author.id` could need to cascade into
	 *    the *same* `essay` row twice, once per column.
	 *
	 * Detects both schema-wide and returns the set of FKs to downgrade to NO
	 * ACTION instead. For (1), the *direct* edge from the ancestor is redundant --
	 * deleting/updating it already cascades transitively through the other
	 * parent, so dropping the direct edge loses no actual cleanup behavior, it
	 * just removes the extra path SQL Server won't allow. For (2), only the first
	 * (by declaration order) FK to a given repeated target is kept as cascading;
	 * the rest are downgraded.
	 *
	 * @param      Database $database
	 * @param      callable(ForeignKey): ?string $actionGetter ForeignKey::getOnDelete() or getOnUpdate().
	 * @return     array<string, true> Set of ForeignKey names (see ForeignKey::getName()) to downgrade.
	 */
	private function computeCascadeDowngrades(Database $database, callable $actionGetter): array
	{
		$downgrades = array();

		// Case 0: a self-referencing FK (local and foreign table are the same,
		// e.g. essay.next_essay_id -> essay.id) with a cascading action -- SQL
		// Server rejects this outright, the same error 1785 family, regardless of
		// any other table's FKs. Always downgrade.
		foreach ($database->getTables() as $table) {
			$tableName = $table->getName();
			if ($tableName === null) {
				continue;
			}
			foreach ($table->getForeignKeys() as $fk) {
				if ($actionGetter($fk) === ForeignKey::CASCADE && $fk->getForeignTableName() === $tableName) {
					$fkName = $fk->getName();
					if ($fkName !== null) {
						$downgrades[$fkName] = true;
					}
				}
			}
		}

		// Case 2: same table, 2+ direct CASCADE FKs to the same target --
		// keep only the first, downgrade the rest. Done before building the graph
		// below so case 1's diamond detection sees the graph *after* these
		// redundant edges are already removed.
		foreach ($database->getTables() as $table) {
			$seenTargets = array();
			foreach ($table->getForeignKeys() as $fk) {
				$parentName = $fk->getForeignTableName();
				$fkName = $fk->getName();
				if ($actionGetter($fk) !== ForeignKey::CASCADE || $parentName === null
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
		// for every surviving CASCADE FK (child.col REFERENCES parentTable.col
		// [ON DELETE|ON UPDATE] CASCADE means a change to a parent row cascades to
		// the child row).
		$cascadeChildren = array();
		foreach ($database->getTables() as $table) {
			$childName = $table->getName();
			if ($childName === null) {
				continue;
			}
			foreach ($table->getForeignKeys() as $fk) {
				$parentName = $fk->getForeignTableName();
				$fkName = $fk->getName();
				if ($actionGetter($fk) === ForeignKey::CASCADE && $parentName !== null
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
				if ($actionGetter($fk) === ForeignKey::CASCADE && $fk->getForeignTableName() !== null
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
		$fkName = $this->requireString($fk->getName(), 'Foreign key name');
		$pattern = 'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)';
		$script = sprintf($pattern,
			$this->quoteIdentifier($fkName),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($this->requireString($fk->getForeignTableName(), 'Foreign key target table name')),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
		// See computeCascadeDowngrades(): a CASCADE that would create a second
		// cascade path SQL Server won't allow (error 1785) is emitted as NO ACTION
		// instead -- the update/delete still reaches this table transitively
		// through whichever other parent's path (or first-declared same-target
		// FK) was kept.
		if ($fk->hasOnUpdate() && $fk->getOnUpdate() != ForeignKey::SETNULL) {
			$onUpdate = $fk->getOnUpdate();
			if ($onUpdate === ForeignKey::CASCADE && isset($this->cascadeUpdateDowngrades[$fkName])) {
				$onUpdate = ForeignKey::NOACTION;
			}
			$script .= ' ON UPDATE ' . $onUpdate;
		}
		if ($fk->hasOnDelete() && $fk->getOnDelete() != ForeignKey::SETNULL) {
			$onDelete = $fk->getOnDelete();
			if ($onDelete === ForeignKey::CASCADE && isset($this->cascadeDeleteDowngrades[$fkName])) {
				$onDelete = ForeignKey::NOACTION;
			}
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
