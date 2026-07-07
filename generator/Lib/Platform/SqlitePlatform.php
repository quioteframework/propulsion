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
use Propulsion\Generator\Model\Domain;
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
