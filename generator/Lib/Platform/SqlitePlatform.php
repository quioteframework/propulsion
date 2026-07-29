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
 * SQLite PropulsionPlatformInterface implementation.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\Index;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\ForeignKey;
class SqlitePlatform extends DefaultPlatform
{

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize(): void
	{
		parent::initialize();
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMERIC, "DECIMAL"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARCHAR, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATE, "DATETIME"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BINARY, "BLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VARBINARY, "MEDIUMBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::LONGVARBINARY, "LONGBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::BLOB, "LONGBLOB"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CLOB, "LONGTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::OBJECT, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::PHP_ARRAY, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::ENUM, "TINYINT"));
		// SQLite has no native JSON column type (its JSON1 extension operates on
		// ordinary TEXT columns via SQL functions) -- store the encoded JSON as TEXT.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSON, "TEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::JSONB, "TEXT"));
		// SQLite has no native UUID column type; fall back to the canonical
		// 36-character hyphenated textual representation (see
		// PropulsionTypes::UUID / Column::isUuidType()).
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::UUID, "CHAR", 36));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INTERVAL, "VARCHAR", 32));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INET, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CIDR, "VARCHAR", 43));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::MACADDR, "VARCHAR", 17));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::CITEXT, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT4RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::INT8RANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::NUMRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::DATERANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSRANGE, "VARCHAR", 64));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSTZRANGE, "VARCHAR", 64));
		// No native vector type -- emulated as unbounded text (not a sized
		// VARCHAR like the other emulated types above) since a high-dimension
		// embedding vector's JSON-encoded text can be long.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::VECTOR, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::GEOMETRY, "MEDIUMTEXT"));
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::TSVECTOR, "MEDIUMTEXT"));
		// No native SET type -- emulated as comma-joined text.
		$this->setSchemaDomainMapping(new Domain(PropulsionTypes::SET, "MEDIUMTEXT"));
	}

	/**
	 * Without the AUTOINCREMENT keyword, SQLite reuses the rowid of the most
	 * recently deleted row for the next insert (see the linked doc), which
	 * silently resurrects stale data for any code path relying on primary keys
	 * being unique for the lifetime of the database, not just among currently-
	 * existing rows.
	 *
	 * @link       http://www.sqlite.org/autoinc.html
	 */
	public function getAutoIncrement()
	{
		return "PRIMARY KEY AUTOINCREMENT";
	}

	public function getMaxColumnNameLength()
	{
		return 1024;
	}

	public function getAddTableDDL(Table $table)
	{
		$tableDescription = $table->hasDescription() ? $this->getCommentLineDDL($table->getDescription()) : '';

		$lines = array();

		foreach ($table->getColumns() as $column) {
			$lines[] = $this->getColumnDDL($column);
		}

		if ($table->hasPrimaryKey() && count($table->getPrimaryKey()) > 1) {
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
			$this->quoteIdentifier($table->getName()),
			implode($sep, $lines)
		);
	}

	public function getDropPrimaryKeyDDL(Table $table)
	{
		// FIXME: not supported by SQLite
		return '';
	}

	public function getAddPrimaryKeyDDL(Table $table)
	{
		// FIXME: not supported by SQLite
		return '';
	}

	public function getAddForeignKeyDDL(ForeignKey $fk)
	{
		// no need for an alter table to return comments
		return $this->getForeignKeyDDL($fk);
	}

	public function getDropForeignKeyDDL(ForeignKey $fk)
	{
		return '';
	}

	public function getForeignKeyDDL(ForeignKey $fk)
	{
		$pattern = "
-- SQLite does not support foreign keys; this is just for reference
-- FOREIGN KEY (%s) REFERENCES %s (%s)
";
		return sprintf($pattern,
			$this->getColumnListDDL($fk->getLocalColumns()),
			$fk->getForeignTableName(),
			$this->getColumnListDDL($fk->getForeignColumns())
		);
	}

	/**
	 * Overrides DefaultPlatform to emit a SQLite (3.31+) generated column --
	 * `GENERATED ALWAYS AS (expr) VIRTUAL|STORED` -- when Column::isGenerated()
	 * is set, in place of the ordinary DEFAULT-value DDL (a generated column
	 * can't also carry a DEFAULT, the same mutual exclusivity
	 * PgsqlPlatform's own tsvectorFrom-generated columns already have).
	 * Otherwise identical to the parent implementation.
	 */
	public function getColumnDDL(Column $col)
	{
		if (!$col->isGenerated()) {
			// No SQLite-specific behavior needed for an ordinary column --
			// defer to the parent implementation (which also handles the
			// nativeEnum CHECK-constraint branch).
			return parent::getColumnDDL($col);
		}

		$domain = $col->getDomain();

		$ddl = array($this->quoteIdentifier($col->getName()));
		$sqlType = $domain->getSqlType();
		if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
			$ddl []= $sqlType . $domain->printSize();
		} else {
			$ddl []= $sqlType;
		}
		// SQLite (3.31+) generated column -- can't also carry a DEFAULT, so
		// this branch is mutually exclusive with getColumnDefaultValueDDL(),
		// the same way PgsqlPlatform's own tsvectorFrom-generated columns are.
		$ddl []= sprintf('GENERATED ALWAYS AS (%s) %s', $col->getGeneratedExpr(), $col->getGeneratedType());
		if ($notNull = $this->getNullString($col->isNotNull())) {
			$ddl []= $notNull;
		}
		if ($autoIncrement = $col->getAutoIncrementString()) {
			$ddl []= $autoIncrement;
		}

		return implode(' ', $ddl);
	}

	/**
	 * Overrides DefaultPlatform to support SQLite's partial-index `WHERE`
	 * predicate (3.8+) and expression index columns (3.9+), reusing the same
	 * `Index::getWhereClause()`/`Index::isExpressionAtPosition()` model
	 * PgsqlPlatform's own `getAddIndexDDL()` already honors. Unlike Postgres,
	 * SQLite has no index access method (`USING`), `INCLUDE`, storage
	 * parameters, or `CONCURRENTLY` clause, so only the column list and
	 * `WHERE` clause differ from the base implementation.
	 */
	public function getAddIndexDDL(Index $index)
	{
		$pattern = "
CREATE %sINDEX %s ON %s (%s)%s;
";
		return sprintf(
			$pattern,
			$index->isUnique() ? 'UNIQUE ' : '',
			$this->quoteIdentifier($index->getName()),
			$this->quoteIdentifier($index->getTable()->getName()),
			$this->getIndexColumnListDDL($index),
			$index->getWhereClause() !== null ? ' WHERE (' . $index->getWhereClause() . ')' : ''
		);
	}

	public function hasSize($sqlType) {
		return !("MEDIUMTEXT" == $sqlType || "LONGTEXT" == $sqlType
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
		if (function_exists('sqlite_escape_string')) {
			return sqlite_escape_string($text);
		} else {
			return parent::disconnectedEscapeText($text);
		}
	}
	*/
	
	public function quoteIdentifier($text)
	{
		return $this->isIdentifierQuotingEnabled ? '[' . $text . ']' : $text;
	}

	/**
	 * @see        Platform::supportsMigrations()
	 */
	public function supportsMigrations()
	{
		return false;
	}

	/**
	 * @see        PropulsionPlatformInterface::supportsTransactionalDDL()
	 */
	public function supportsTransactionalDDL()
	{
		return true;
	}

}
