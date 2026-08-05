<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

class MysqlPlatformTest extends PlatformTestProvider
{
	/**
	 * Get the Platform object for this class
	 *
	 * @return     Platform
	 */
	protected static function getPlatform()
	{
		return new MysqlPlatform();
	}

	public function testGetSequenceNameDefault()
	{
		$table = new Table('foo');
		$table->setIdMethod(IDMethod::NATIVE);
		$expected = 'foo_SEQ';
		$this->assertEquals($expected, $this->getPlatform()->getSequenceName($table));
	}

	public function testGetSequenceNameCustom()
	{
		$table = new Table('foo');
		$table->setIdMethod(IDMethod::NATIVE);
		$idMethodParameter = new IdMethodParameter();
		$idMethodParameter->setValue('foo_sequence');
		$table->addIdMethodParameter($idMethodParameter);
		$table->setIdMethod(IDMethod::NATIVE);
		$expected = 'foo_sequence';
		$this->assertEquals($expected, $this->getPlatform()->getSequenceName($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesDDLSchema')]
	public function testGetAddTablesDDLSchema($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- x.book
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `x`.`book`;

CREATE TABLE `x`.`book`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`title` VARCHAR(255) NOT NULL,
	`author_id` INTEGER,
	PRIMARY KEY (`id`),
	INDEX `book_I_1` (`title`),
	INDEX `book_FI_1` (`author_id`),
	CONSTRAINT `book_FK_1`
		FOREIGN KEY (`author_id`)
		REFERENCES `y`.`author` (`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- y.author
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `y`.`author`;

CREATE TABLE `y`.`author`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`first_name` VARCHAR(100),
	`last_name` VARCHAR(100),
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- x.book_summary
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `x`.`book_summary`;

CREATE TABLE `x`.`book_summary`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`book_id` INTEGER NOT NULL,
	`summary` TEXT NOT NULL,
	PRIMARY KEY (`id`),
	INDEX `book_summary_FI_1` (`book_id`),
	CONSTRAINT `book_summary_FK_1`
		FOREIGN KEY (`book_id`)
		REFERENCES `x`.`book` (`id`)
		ON DELETE CASCADE
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesDDL')]
	public function testGetAddTablesDDL($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- book
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `book`;

CREATE TABLE `book`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`title` VARCHAR(255) NOT NULL,
	`author_id` INTEGER,
	PRIMARY KEY (`id`),
	INDEX `book_I_1` (`title`),
	INDEX `book_FI_1` (`author_id`),
	CONSTRAINT `book_FK_1`
		FOREIGN KEY (`author_id`)
		REFERENCES `author` (`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- author
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `author`;

CREATE TABLE `author`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`first_name` VARCHAR(100),
	`last_name` VARCHAR(100),
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesSkipSQLDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesSkipSQLDDL')]
	public function testGetAddTablesSkipSQLDDL($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = "
# This is a fix for InnoDB in MySQL >= 4.1.x
# It \"suspends judgement\" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSimplePK
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSimplePK')]
	public function testGetAddTableDDLSimplePK($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar` VARCHAR(255) NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='This is foo table';
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLCompositePK
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLCompositePK')]
	public function testGetAddTableDDLCompositePK($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`foo` INTEGER NOT NULL,
	`bar` INTEGER NOT NULL,
	`baz` VARCHAR(255) NOT NULL,
	PRIMARY KEY (`foo`,`bar`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLUniqueIndex
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLUniqueIndex')]
	public function testGetAddTableDDLUniqueIndex($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar` INTEGER,
	PRIMARY KEY (`id`),
	UNIQUE INDEX `foo_U_1` (`bar`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLIndex()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="INTEGER" />
		<index>
			<index-column name="bar" />
		</index>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar` INTEGER,
	PRIMARY KEY (`id`),
	INDEX `foo_I_1` (`bar`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLForeignKey()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar_id" type="INTEGER" />
		<foreign-key foreignTable="bar">
			<reference local="bar_id" foreign="id" />
		</foreign-key>
	</table>
	<table name="bar">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar_id` INTEGER,
	PRIMARY KEY (`id`),
	INDEX `foo_FI_1` (`bar_id`),
	CONSTRAINT `foo_FK_1`
		FOREIGN KEY (`bar_id`)
		REFERENCES `bar` (`id`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLForeignKeySkipSql()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar_id" type="INTEGER" />
		<foreign-key foreignTable="bar" skipSql="true">
			<reference local="bar_id" foreign="id" />
		</foreign-key>
	</table>
	<table name="bar">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar_id` INTEGER,
	PRIMARY KEY (`id`),
	INDEX `foo_FI_1` (`bar_id`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLEngine()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$platform = new MysqlPlatform();
		$platform->setTableEngineKeyword('TYPE');
		$platform->setDefaultTableEngine('MEMORY');
		$xtad = new XmlToAppData($platform);
		$appData = $xtad->parseString($schema);
		$table = $appData->getDatabase()->getTable('foo');
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`)
) TYPE=MEMORY;
";
		$this->assertEquals($expected, $platform->getAddTableDDL($table));
	}

	public function testGetAddTableDDLVendor()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<vendor type="mysql">
			<parameter name="Engine" value="InnoDB"/>
			<parameter name="Charset" value="utf8"/>
		</vendor>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = "
CREATE TABLE `foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARACTER SET='utf8';
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSchema')]
	public function testGetAddTableDDLSchema($schema)
	{
		$table = $this->getTableFromSchema($schema, 'Woopah.foo');
		$expected = "
CREATE TABLE `Woopah`.`foo`
(
	`id` INTEGER NOT NULL AUTO_INCREMENT,
	`bar` INTEGER,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetDropTableDDL()
	{
		$table = new Table('foo');
		$expected = "
DROP TABLE IF EXISTS `foo`;
";
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSchema')]
	public function testGetDropTableDDLSchema($schema)
	{
		$table = $this->getTableFromSchema($schema, 'Woopah.foo');
		$expected = "
DROP TABLE IF EXISTS `Woopah`.`foo`;
";
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	public function testGetColumnDDL()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('DOUBLE'));
		$column->getDomain()->replaceScale(2);
		$column->getDomain()->replaceSize(3);
		$column->setNotNull(true);
		$column->getDomain()->setDefaultValue(new ColumnDefaultValue(123, ColumnDefaultValue::TYPE_VALUE));
		$expected = '`foo` DOUBLE(3,2) DEFAULT 123 NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLUnsigned()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::INTEGER));
		$column->setUnsigned(true);
		$expected = '`foo` INTEGER UNSIGNED';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLZerofillImpliesUnsigned()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::INTEGER));
		$column->setZerofill(true);
		$expected = '`foo` INTEGER UNSIGNED ZEROFILL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLUnsignedIgnoredOnNonNumericColumn()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::VARCHAR));
		$column->getDomain()->replaceSize(255);
		$column->setUnsigned(true);
		$expected = '`foo` VARCHAR(255)';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLUuid()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::UUID));
		$column->setNotNull(true);
		$expected = '`foo` CHAR(36) NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLSet()
	{
		$column = new Column('roles');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::SET));
		$column->setValueSet(['admin', 'editor', 'viewer']);
		$expected = "`roles` SET('admin', 'editor', 'viewer')";
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLSetIsAlwaysNativeUnconditionally()
	{
		// Unlike ENUM, SET has no opt-in flag -- MySQL always gets the real
		// SET(...) type since there's no reasonable emulated alternative.
		$column = new Column('roles');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::SET));
		$column->setValueSet(['a', 'b']);
		$this->assertStringStartsWith('`roles` SET(', $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLCharsetVendor()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$vendor = new VendorInfo('mysql');
		$vendor->setParameter('Charset', 'greek');
		$column->addVendorInfo($vendor);
		$expected = '`foo` TEXT CHARACTER SET \'greek\'';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLCharsetCollation()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$vendor = new VendorInfo('mysql');
		$vendor->setParameter('Collate', 'latin1_german2_ci');
		$column->addVendorInfo($vendor);
		$expected = '`foo` TEXT COLLATE \'latin1_german2_ci\'';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));

		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$vendor = new VendorInfo('mysql');
		$vendor->setParameter('Collation', 'latin1_german2_ci');
		$column->addVendorInfo($vendor);
		$expected = '`foo` TEXT COLLATE \'latin1_german2_ci\'';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLComment()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('INTEGER'));
		$column->setDescription('This is column Foo');
		$expected = '`foo` INTEGER COMMENT \'This is column Foo\'';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLCharsetNotNull()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$column->setNotNull(true);
		$vendor = new VendorInfo('mysql');
		$vendor->setParameter('Charset', 'greek');
		$column->addVendorInfo($vendor);
		$expected = '`foo` TEXT CHARACTER SET \'greek\' NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLCustomSqlType()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType('DOUBLE'));
		$column->getDomain()->replaceScale(2);
		$column->getDomain()->replaceSize(3);
		$column->setNotNull(true);
		$column->getDomain()->setDefaultValue(new ColumnDefaultValue(123, ColumnDefaultValue::TYPE_VALUE));
		$column->getDomain()->replaceSqlType('DECIMAL(5,6)');
		$expected = '`foo` DECIMAL(5,6) DEFAULT 123 NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetPrimaryKeyDDLSimpleKey()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$column->setPrimaryKey(true);
		$table->addColumn($column);
		$expected = 'PRIMARY KEY (`bar`)';
		$this->assertEquals($expected, $this->getPlatform()->getPrimaryKeyDDL($table));
	}

	public function testGetPrimaryKeyDDLCompositeKey()
	{
		$table = new Table('foo');
		$column1 = new Column('bar1');
		$column1->setPrimaryKey(true);
		$table->addColumn($column1);
		$column2 = new Column('bar2');
		$column2->setPrimaryKey(true);
		$table->addColumn($column2);
		$expected = 'PRIMARY KEY (`bar1`,`bar2`)';
		$this->assertEquals($expected, $this->getPlatform()->getPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestPrimaryKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestPrimaryKeyDDL')]
	public function testGetDropPrimaryKeyDDL($table)
	{
		$expected = "
ALTER TABLE `foo` DROP PRIMARY KEY;
";
		$this->assertEquals($expected, $this->getPlatform()->getDropPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestPrimaryKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestPrimaryKeyDDL')]
	public function testGetAddPrimaryKeyDDL($table)
	{
		$expected = "
ALTER TABLE `foo` ADD PRIMARY KEY (`bar`);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetIndicesDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndicesDDL')]
	public function testAddIndicesDDL($table)
	{
		$expected = "
CREATE INDEX `babar` ON `foo` (`bar1`,`bar2`);

CREATE INDEX `foo_index` ON `foo` (`bar1`);
";
		$this->assertEquals($expected, $this->getPLatform()->getAddIndicesDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testAddIndexDDL($index)
	{
		$expected = "
CREATE INDEX `babar` ON `foo` (`bar1`,`bar2`);
";
		$this->assertEquals($expected, $this->getPLatform()->getAddIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testDropIndexDDL($index)
	{
		$expected = "
DROP INDEX `babar` ON `foo`;
";
		$this->assertEquals($expected, $this->getPLatform()->getDropIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testGetIndexDDL($index)
	{
		$expected = 'INDEX `babar` (`bar1`, `bar2`)';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	public function testGetIndexDDLKeySize()
	{
		$table = new Table('foo');
		$column1 = new Column('bar1');
		$column1->getDomain()->copy($this->getPlatform()->getDomainForType('VARCHAR'));
		$column1->setSize(5);
		$table->addColumn($column1);
		$index = new Index('bar_index');
		$index->addColumn($column1);
		$table->addIndex($index);
		$expected = 'INDEX `bar_index` (`bar1`(5))';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	public function testGetIndexDDLFulltext()
	{
		$table = new Table('foo');
		$column1 = new Column('bar1');
		$column1->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$table->addColumn($column1);
		$index = new Index('bar_index');
		$index->addColumn($column1);
		$vendor = new VendorInfo('mysql');
		$vendor->setParameter('Index_type', 'FULLTEXT');
		$index->addVendorInfo($vendor);
		$table->addIndex($index);
		$expected = 'FULLTEXT INDEX `bar_index` (`bar1`)';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	public function testGetIndexDDLFulltextViaIndexType()
	{
		$table = new Table('foo');
		$column1 = new Column('bar1');
		$column1->getDomain()->copy($this->getPlatform()->getDomainForType('LONGVARCHAR'));
		$table->addColumn($column1);
		$index = new Index('bar_index');
		$index->addColumn($column1);
		$index->setIndexType('fulltext');
		$table->addIndex($index);
		$expected = 'FULLTEXT INDEX `bar_index` (`bar1`)';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	public function testGetAddIndexDDLSpatialViaIndexType()
	{
		$table = new Table('foo');
		$column1 = new Column('bar1');
		$column1->getDomain()->copy($this->getPlatform()->getDomainForType('GEOMETRY'));
		$table->addColumn($column1);
		$index = new Index('bar_index');
		$index->addColumn($column1);
		$index->setIndexType('spatial');
		$table->addIndex($index);
		$expected = "
CREATE SPATIAL INDEX `bar_index` ON `foo` (`bar1`);
";
		$this->assertEquals($expected, $this->getPLatform()->getAddIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetUniqueDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetUniqueDDL')]
	public function testGetUniqueDDL($index)
	{
		$expected = 'UNIQUE INDEX `babar` (`bar1`, `bar2`)';
		$this->assertEquals($expected, $this->getPLatform()->getUniqueDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeysDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeysDDL')]
	public function testGetAddForeignKeysDDL($table)
	{
		$expected = "
ALTER TABLE `foo` ADD CONSTRAINT `foo_bar_FK`
	FOREIGN KEY (`bar_id`)
	REFERENCES `bar` (`id`)
	ON DELETE CASCADE;

ALTER TABLE `foo` ADD CONSTRAINT `foo_baz_FK`
	FOREIGN KEY (`baz_id`)
	REFERENCES `baz` (`id`)
	ON DELETE SET NULL;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddForeignKeysDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetAddForeignKeyDDL($fk)
	{
		$expected = "
ALTER TABLE `foo` ADD CONSTRAINT `foo_bar_FK`
	FOREIGN KEY (`bar_id`)
	REFERENCES `bar` (`id`)
	ON DELETE CASCADE;
";
		$this->assertEquals($expected, $this->getPlatform()->getAddForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeySkipSqlDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeySkipSqlDDL')]
	public function testGetAddForeignKeySkipSqlDDL($fk)
	{
		$expected = '';
		$this->assertEquals($expected, $this->getPlatform()->getAddForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetDropForeignKeyDDL($fk)
	{
		$expected = "
ALTER TABLE `foo` DROP FOREIGN KEY `foo_bar_FK`;
";
		$this->assertEquals($expected, $this->getPLatform()->getDropForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeySkipSqlDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeySkipSqlDDL')]
	public function testGetDropForeignKeySkipSqlDDL($fk)
	{
		$expected = '';
		$this->assertEquals($expected, $this->getPlatform()->getDropForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetForeignKeyDDL($fk)
	{
		$expected = "CONSTRAINT `foo_bar_FK`
	FOREIGN KEY (`bar_id`)
	REFERENCES `bar` (`id`)
	ON DELETE CASCADE";
		$this->assertEquals($expected, $this->getPLatform()->getForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeySkipSqlDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeySkipSqlDDL')]
	public function testGetForeignKeySkipSqlDDL($fk)
	{
		$expected = '';
		$this->assertEquals($expected, $this->getPlatform()->getForeignKeyDDL($fk));
	}

	public function testGetCommentBlockDDL()
	{
		$expected = "
-- ---------------------------------------------------------------------
-- foo bar
-- ---------------------------------------------------------------------
";
		$this->assertEquals($expected, $this->getPLatform()->getCommentBlockDDL('foo bar'));
	}

	public function testGetColumnDDLGeneratedVirtual()
	{
		$table = new Table('foo');
		$column = new Column('title_upper');
		$column->setTable($table);
		$column->getDomain()->copy(new Domain('VARCHAR'));
		$column->getDomain()->replaceSize(255);
		$column->setGeneratedExpr('upper(title)');
		$table->addColumn($column);

		$expected = '`title_upper` VARCHAR(255) GENERATED ALWAYS AS (upper(title)) VIRTUAL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedStored()
	{
		$table = new Table('foo');
		$column = new Column('title_upper');
		$column->setTable($table);
		$column->getDomain()->copy(new Domain('VARCHAR'));
		$column->getDomain()->replaceSize(255);
		$column->setGeneratedExpr('upper(title)');
		$column->setGeneratedType('stored');
		$table->addColumn($column);

		$expected = '`title_upper` VARCHAR(255) GENERATED ALWAYS AS (upper(title)) STORED';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	/**
	 * A NATIVE-id-method table with no explicit id-method-parameter must NOT
	 * get a real CREATE SEQUENCE, even with `nativeSequence="true"` set --
	 * plain AUTO_INCREMENT (this platform's implicit default id method) has
	 * no backing sequence object, the same "no implicit sequence" story
	 * MssqlPlatform's own equivalent test documents for IDENTITY.
	 */
	public function testGetAddTablesDDLNoImplicitSequenceEvenWithNativeSequenceFlag()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" nativeSequence="true">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$this->assertStringNotContainsString('CREATE SEQUENCE', $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * A named `<id-method-parameter>` alone, without `nativeSequence="true"`,
	 * must NOT get a real CREATE SEQUENCE either -- plain MySQL has no
	 * sequence object at any version, so this stays the existing silent
	 * no-op unless the schema author explicitly opts in.
	 */
	public function testGetAddTablesDDLNamedSequenceWithoutOptInIsNoop()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" defaultExpr="NEXTVAL(my_custom_sequence)" />
		<id-method-parameter value="my_custom_sequence"/>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$this->assertStringNotContainsString('CREATE SEQUENCE', $this->getPlatform()->getAddTablesDDL($database));
	}

	public function testGetAddTablesDDLNativeSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" nativeSequence="true">
		<column name="id" primaryKey="true" type="INTEGER" defaultExpr="NEXTVAL(my_custom_sequence)" />
		<id-method-parameter value="my_custom_sequence"/>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString("\nCREATE SEQUENCE `my_custom_sequence` START WITH 1 INCREMENT BY 1;\n", $ddl);
		$this->assertStringContainsString('`id` INTEGER DEFAULT NEXTVAL(my_custom_sequence) NOT NULL', $ddl);
	}

	public function testGetDropTableDDLNativeSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" nativeSequence="true">
		<column name="id" primaryKey="true" type="INTEGER" defaultExpr="NEXTVAL(my_custom_sequence)" />
		<id-method-parameter value="my_custom_sequence"/>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$ddl = $this->getPlatform()->getDropTableDDL($table);
		$this->assertStringContainsString("\nDROP SEQUENCE IF EXISTS `my_custom_sequence`;\n", $ddl);
	}

	public function testGetColumnDDLNativeUuid()
	{
		$table = new Table('foo');
		$column = new Column('external_id');
		$column->setTable($table);
		$column->getDomain()->copy((new MysqlPlatform())->getDomainForType(PropulsionTypes::UUID));
		$column->setType(PropulsionTypes::UUID);
		$column->setNativeUuid(true);
		$table->addColumn($column);

		$expected = '`external_id` UUID';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLUuidEmulatedByDefault()
	{
		$table = new Table('foo');
		$column = new Column('external_id');
		$column->setTable($table);
		$column->getDomain()->copy((new MysqlPlatform())->getDomainForType(PropulsionTypes::UUID));
		$column->setType(PropulsionTypes::UUID);
		$table->addColumn($column);

		$expected = '`external_id` CHAR(36)';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLNativeVector()
	{
		$table = new Table('foo');
		$column = new Column('embedding');
		$column->setTable($table);
		$column->getDomain()->copy((new MysqlPlatform())->getDomainForType(PropulsionTypes::VECTOR));
		$column->setType(PropulsionTypes::VECTOR);
		$column->setSize(1536);
		$column->setNativeVector(true);
		$table->addColumn($column);

		$expected = '`embedding` VECTOR(1536)';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLVectorEmulatedAsTextByDefault()
	{
		// Without the opt-in, the size stays off the emitted type: the
		// emulated column is unbounded TEXT, and printing TEXT(1536) would
		// imply a length constraint that isn't there. See hasSize().
		$table = new Table('foo');
		$column = new Column('embedding');
		$column->setTable($table);
		$column->getDomain()->copy((new MysqlPlatform())->getDomainForType(PropulsionTypes::VECTOR));
		$column->setType(PropulsionTypes::VECTOR);
		$column->setSize(1536);
		$table->addColumn($column);

		$expected = '`embedding` TEXT';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLNativeVectorWithoutSizeThrows()
	{
		$table = new Table('foo');
		$column = new Column('embedding');
		$column->setTable($table);
		$column->getDomain()->copy((new MysqlPlatform())->getDomainForType(PropulsionTypes::VECTOR));
		$column->setType(PropulsionTypes::VECTOR);
		$column->setNativeVector(true);
		$table->addColumn($column);

		$this->expectException(EngineException::class);
		$this->expectExceptionMessage('requires an explicit dimension');
		$this->getPlatform()->getColumnDDL($column);
	}

	public function testGetColumnDDLGeneratedNotNull()
	{
		// Unlike MSSQL, MySQL allows NOT NULL on a VIRTUAL generated column,
		// not just a STORED one -- confirmed against a live MariaDB 11.8
		// server (see MysqlPlatform::getGeneratedColumnDDL()'s own docblock).
		$table = new Table('foo');
		$column = new Column('title_upper');
		$column->setTable($table);
		$column->getDomain()->copy(new Domain('VARCHAR'));
		$column->getDomain()->replaceSize(255);
		$column->setGeneratedExpr('upper(title)');
		$column->setNotNull(true);
		$table->addColumn($column);

		$expected = '`title_upper` VARCHAR(255) GENERATED ALWAYS AS (upper(title)) VIRTUAL NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

}
