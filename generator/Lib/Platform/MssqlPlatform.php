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
	/**
	 * Counter used to generate unique cursor/variable names (@reftable_N,
	 * @constraintname_N) across every DROP TABLE block emitted into the same
	 * generated script -- needed because SQL Server scopes DECLARE'd variables to
	 * the whole batch, so two tables' DROP blocks in the same script can't reuse
	 * the same names. Deliberately an *instance* property, not a class-level
	 * static: SqlManager::loadDataModels() creates exactly one platform instance
	 * per generation run and shares it across every schema file that targets the
	 * same database (see AppData::setPlatform()), so instance-scoping still gives
	 * correct uniqueness across a whole real run's concatenated output -- while a
	 * class-level static previously leaked across unrelated calls sharing the same
	 * PHP process (e.g. every test in this class instantiates its own
	 * MssqlPlatform, so a static counter never reset between test methods,
	 * silently coupling each test's expected output to how many DROP blocks every
	 * *other* test that happened to run earlier had already emitted).
	 */
	protected $dropCount = 0;

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

		$this->dropCount++;

		$ret .= "
IF EXISTS (SELECT 1 FROM sysobjects WHERE type = 'U' AND name = '" . $table->getName() . "')
BEGIN
	DECLARE @reftable_" . $this->dropCount . " nvarchar(60), @constraintname_" . $this->dropCount . " nvarchar(60)
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
	FETCH NEXT from refcursor into @reftable_" . $this->dropCount . ", @constraintname_" . $this->dropCount . "
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_" . $this->dropCount . "+' drop constraint '+@constraintname_" . $this->dropCount . ")
		FETCH NEXT from refcursor into @reftable_" . $this->dropCount . ", @constraintname_" . $this->dropCount . "
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

		return '';
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
