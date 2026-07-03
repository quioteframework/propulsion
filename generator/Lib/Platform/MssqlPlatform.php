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
 * @package    propel.generator.platform
 */
use Propulsion\Generator\Model\Domain;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\ForeignKey;

class MssqlPlatform extends DefaultPlatform
{
	protected static $dropCount = 0;

	/**
	 * Initializes db specific domain mapping.
	 */
	protected function initialize()
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
	}

	public function getMaxColumnNameLength()
	{
		return 128;
	}

	public function getNullString($notNull)
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

	public function getDropTableDDL(Table $table)
	{
		$ret = '';
		foreach ($table->getForeignKeys() as $fk) {
			$ret .= "
IF EXISTS (SELECT 1 FROM sysobjects WHERE type ='RI' AND name='" . $fk->getName() . "')
	ALTER TABLE " . $this->quoteIdentifier($table->getName()) . " DROP CONSTRAINT " . $this->quoteIdentifier($fk->getName()) . ";
";
		}

		self::$dropCount++;

		$ret .= "
IF EXISTS (SELECT 1 FROM sysobjects WHERE type = 'U' AND name = '" . $table->getName() . "')
BEGIN
	DECLARE @reftable_" . self::$dropCount . " nvarchar(60), @constraintname_" . self::$dropCount . " nvarchar(60)
	DECLARE refcursor CURSOR FOR
	select reftables.name tablename, cons.name constraintname
		from sysobjects tables,
			sysobjects reftables,
			sysobjects cons,
			sysreferences ref
		where tables.id = ref.rkeyid
			and cons.id = ref.constid
			and reftables.id = ref.fkeyid
			and tables.name = '" . $table->getName() . "'
	OPEN refcursor
	FETCH NEXT from refcursor into @reftable_" . self::$dropCount . ", @constraintname_" . self::$dropCount . "
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_" . self::$dropCount . "+' drop constraint '+@constraintname_" . self::$dropCount . ")
		FETCH NEXT from refcursor into @reftable_" . self::$dropCount . ", @constraintname_" . self::$dropCount . "
	END
	CLOSE refcursor
	DEALLOCATE refcursor
	DROP TABLE " . $this->quoteIdentifier($table->getName()) . "
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
	}

	public function getAddForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return;
		}
		$pattern = "
BEGIN
ALTER TABLE %s ADD %s
END
;
";
		return sprintf($pattern,
			$this->quoteIdentifier($fk->getTable()->getName()),
			$this->getForeignKeyDDL($fk)
		);
	}

	public function getForeignKeyDDL(ForeignKey $fk)
	{
		if ($fk->isSkipSql()) {
			return;
		}
		$pattern = 'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)';
		$script = sprintf($pattern,
			$this->quoteIdentifier($fk->getName()),
			$this->getColumnListDDL($fk->getLocalColumns()),
			$this->quoteIdentifier($fk->getForeignTableName()),
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
