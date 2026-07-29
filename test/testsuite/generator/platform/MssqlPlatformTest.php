<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

class MssqlPlatformTest extends PlatformTestProvider
{
	/**
	 * Get the Platform object for this class
	 *
	 * @return     Platform
	 */
	protected static function getPlatform()
	{
		return new MssqlPlatform();
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
	 * Unlike PgsqlPlatform's own equivalent test, a NATIVE-id-method table with
	 * no explicit id-method-parameter must NOT get a real CREATE SEQUENCE --
	 * MSSQL's implicit default id method is IDENTITY, a column property with no
	 * backing sequence object; getSequenceName() itself still resolves a
	 * default "foo_SEQ" name for this case (see testGetSequenceNameDefault()
	 * above / TableMapBuilder), but that name is never turned into DDL unless a
	 * sequence is named explicitly.
	 */
	public function testGetAddTablesDDLNoImplicitSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$this->assertStringNotContainsString('CREATE SEQUENCE', $this->getPlatform()->getAddTablesDDL($database));
	}

	public function testGetAddTablesDDLNamedSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" defaultExpr="NEXT VALUE FOR [my_custom_sequence]" />
		<id-method-parameter value="my_custom_sequence"/>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString("\nCREATE SEQUENCE [my_custom_sequence] AS BIGINT START WITH 1 INCREMENT BY 1;\n", $ddl);
		$this->assertStringContainsString('[id] INT DEFAULT NEXT VALUE FOR [my_custom_sequence] NOT NULL', $ddl);
	}

	public function testGetDropTableDDLNamedSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" defaultExpr="NEXT VALUE FOR [my_custom_sequence]" />
		<id-method-parameter value="my_custom_sequence"/>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$ddl = $this->getPlatform()->getDropTableDDL($table);
		$this->assertStringContainsString("\nIF EXISTS (SELECT 1 FROM sys.sequences WHERE name = 'my_custom_sequence')\n\tDROP SEQUENCE [my_custom_sequence];\n", $ddl);
	}

	/**
	 * @dataProvider providerForTestGetAddTablesDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesDDL')]
	public function testGetAddTablesDDL($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET QUOTED_IDENTIFIER ON;
SET NUMERIC_ROUNDABORT OFF;

-----------------------------------------------------------------------
-- book
-----------------------------------------------------------------------

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'book' AND temporal_type = 2)
	ALTER TABLE [book] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'book_FK_1')
	ALTER TABLE [book] DROP CONSTRAINT [book_FK_1];

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'book')
BEGIN
	DECLARE @reftable_book nvarchar(60), @constraintname_book nvarchar(60)
	DECLARE refcursor_book CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'book'
	OPEN refcursor_book
	FETCH NEXT from refcursor_book into @reftable_book, @constraintname_book
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_book+' drop constraint '+@constraintname_book)
		FETCH NEXT from refcursor_book into @reftable_book, @constraintname_book
	END
	CLOSE refcursor_book
	DEALLOCATE refcursor_book
	DROP TABLE [book]
END

CREATE TABLE [book]
(
	[id] INT NOT NULL IDENTITY,
	[title] VARCHAR(255) NOT NULL,
	[author_id] INT NULL,
	CONSTRAINT [book_PK] PRIMARY KEY ([id])
);

CREATE INDEX [book_I_1] ON [book] ([title]);

-----------------------------------------------------------------------
-- author
-----------------------------------------------------------------------

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'author' AND temporal_type = 2)
	ALTER TABLE [author] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'author')
BEGIN
	DECLARE @reftable_author nvarchar(60), @constraintname_author nvarchar(60)
	DECLARE refcursor_author CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'author'
	OPEN refcursor_author
	FETCH NEXT from refcursor_author into @reftable_author, @constraintname_author
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_author+' drop constraint '+@constraintname_author)
		FETCH NEXT from refcursor_author into @reftable_author, @constraintname_author
	END
	CLOSE refcursor_author
	DEALLOCATE refcursor_author
	DROP TABLE [author]
END

CREATE TABLE [author]
(
	[id] INT NOT NULL IDENTITY,
	[first_name] VARCHAR(100) NULL,
	[last_name] VARCHAR(100) NULL,
	CONSTRAINT [author_PK] PRIMARY KEY ([id])
);

BEGIN
ALTER TABLE [book] ADD CONSTRAINT [book_FK_1] FOREIGN KEY ([author_id]) REFERENCES [author] ([id])
END
;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesDDLSchema')]
	public function testGetAddTablesDDLSchemas($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET QUOTED_IDENTIFIER ON;
SET NUMERIC_ROUNDABORT OFF;

-----------------------------------------------------------------------
-- x.book
-----------------------------------------------------------------------

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'x.book' AND temporal_type = 2)
	ALTER TABLE [x].[book] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'book_FK_1')
	ALTER TABLE [x].[book] DROP CONSTRAINT [book_FK_1];

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'x.book')
BEGIN
	DECLARE @reftable_x_book nvarchar(60), @constraintname_x_book nvarchar(60)
	DECLARE refcursor_x_book CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'x.book'
	OPEN refcursor_x_book
	FETCH NEXT from refcursor_x_book into @reftable_x_book, @constraintname_x_book
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_x_book+' drop constraint '+@constraintname_x_book)
		FETCH NEXT from refcursor_x_book into @reftable_x_book, @constraintname_x_book
	END
	CLOSE refcursor_x_book
	DEALLOCATE refcursor_x_book
	DROP TABLE [x].[book]
END

CREATE TABLE [x].[book]
(
	[id] INT NOT NULL IDENTITY,
	[title] VARCHAR(255) NOT NULL,
	[author_id] INT NULL,
	CONSTRAINT [book_PK] PRIMARY KEY ([id])
);

CREATE INDEX [book_I_1] ON [x].[book] ([title]);

-----------------------------------------------------------------------
-- y.author
-----------------------------------------------------------------------

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'y.author' AND temporal_type = 2)
	ALTER TABLE [y].[author] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'y.author')
BEGIN
	DECLARE @reftable_y_author nvarchar(60), @constraintname_y_author nvarchar(60)
	DECLARE refcursor_y_author CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'y.author'
	OPEN refcursor_y_author
	FETCH NEXT from refcursor_y_author into @reftable_y_author, @constraintname_y_author
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_y_author+' drop constraint '+@constraintname_y_author)
		FETCH NEXT from refcursor_y_author into @reftable_y_author, @constraintname_y_author
	END
	CLOSE refcursor_y_author
	DEALLOCATE refcursor_y_author
	DROP TABLE [y].[author]
END

CREATE TABLE [y].[author]
(
	[id] INT NOT NULL IDENTITY,
	[first_name] VARCHAR(100) NULL,
	[last_name] VARCHAR(100) NULL,
	CONSTRAINT [author_PK] PRIMARY KEY ([id])
);

-----------------------------------------------------------------------
-- x.book_summary
-----------------------------------------------------------------------

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'x.book_summary' AND temporal_type = 2)
	ALTER TABLE [x].[book_summary] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'book_summary_FK_1')
	ALTER TABLE [x].[book_summary] DROP CONSTRAINT [book_summary_FK_1];

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'x.book_summary')
BEGIN
	DECLARE @reftable_x_book_summary nvarchar(60), @constraintname_x_book_summary nvarchar(60)
	DECLARE refcursor_x_book_summary CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'x.book_summary'
	OPEN refcursor_x_book_summary
	FETCH NEXT from refcursor_x_book_summary into @reftable_x_book_summary, @constraintname_x_book_summary
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_x_book_summary+' drop constraint '+@constraintname_x_book_summary)
		FETCH NEXT from refcursor_x_book_summary into @reftable_x_book_summary, @constraintname_x_book_summary
	END
	CLOSE refcursor_x_book_summary
	DEALLOCATE refcursor_x_book_summary
	DROP TABLE [x].[book_summary]
END

CREATE TABLE [x].[book_summary]
(
	[id] INT NOT NULL IDENTITY,
	[book_id] INT NOT NULL,
	[summary] VARCHAR(MAX) NOT NULL,
	CONSTRAINT [book_summary_PK] PRIMARY KEY ([id])
);

BEGIN
ALTER TABLE [x].[book] ADD CONSTRAINT [book_FK_1] FOREIGN KEY ([author_id]) REFERENCES [y].[author] ([id])
END
;

BEGIN
ALTER TABLE [x].[book_summary] ADD CONSTRAINT [book_summary_FK_1] FOREIGN KEY ([book_id]) REFERENCES [x].[book] ([id]) ON DELETE CASCADE
END
;

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
		$expected = '';
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
-- This is foo table
CREATE TABLE [foo]
(
	[id] INT NOT NULL IDENTITY,
	[bar] VARCHAR(255) NOT NULL,
	CONSTRAINT [foo_PK] PRIMARY KEY ([id])
);
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
CREATE TABLE [foo]
(
	[foo] INT NOT NULL,
	[bar] INT NOT NULL,
	[baz] VARCHAR(255) NOT NULL,
	CONSTRAINT [foo_PK] PRIMARY KEY ([foo],[bar])
);
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
CREATE TABLE [foo]
(
	[id] INT NOT NULL IDENTITY,
	[bar] INT NULL,
	CONSTRAINT [foo_PK] PRIMARY KEY ([id]),
	UNIQUE ([bar])
);
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
CREATE TABLE [Woopah].[foo]
(
	[id] INT NOT NULL IDENTITY,
	[bar] INT NULL,
	CONSTRAINT [foo_PK] PRIMARY KEY ([id])
);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetDropTableDDL()
	{
		$table = new Table('foo');
		$expected = "
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'foo' AND temporal_type = 2)
	ALTER TABLE [foo] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'foo')
BEGIN
	DECLARE @reftable_foo nvarchar(60), @constraintname_foo nvarchar(60)
	DECLARE refcursor_foo CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'foo'
	OPEN refcursor_foo
	FETCH NEXT from refcursor_foo into @reftable_foo, @constraintname_foo
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_foo+' drop constraint '+@constraintname_foo)
		FETCH NEXT from refcursor_foo into @reftable_foo, @constraintname_foo
	END
	CLOSE refcursor_foo
	DEALLOCATE refcursor_foo
	DROP TABLE [foo]
END
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
IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'Woopah.foo' AND temporal_type = 2)
	ALTER TABLE [Woopah].[foo] SET (SYSTEM_VERSIONING = OFF);

IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'Woopah.foo')
BEGIN
	DECLARE @reftable_Woopah_foo nvarchar(60), @constraintname_Woopah_foo nvarchar(60)
	DECLARE refcursor_Woopah_foo CURSOR FOR
	select childtable.name tablename, fk.name constraintname
		from sys.foreign_keys fk
			join sys.tables childtable on fk.parent_object_id = childtable.object_id
			join sys.tables reftable on fk.referenced_object_id = reftable.object_id
		where reftable.name = 'Woopah.foo'
	OPEN refcursor_Woopah_foo
	FETCH NEXT from refcursor_Woopah_foo into @reftable_Woopah_foo, @constraintname_Woopah_foo
	while @@FETCH_STATUS = 0
	BEGIN
		exec ('alter table '+@reftable_Woopah_foo+' drop constraint '+@constraintname_Woopah_foo)
		FETCH NEXT from refcursor_Woopah_foo into @reftable_Woopah_foo, @constraintname_Woopah_foo
	END
	CLOSE refcursor_Woopah_foo
	DEALLOCATE refcursor_Woopah_foo
	DROP TABLE [Woopah].[foo]
END
";
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	public function testGetColumnDDLUuid()
	{
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::UUID));
		$column->setNotNull(true);
		$expected = '[foo] CHAR(36) NOT NULL';
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
		$expected = '[foo] DECIMAL(5,6) DEFAULT 123 NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedVirtual()
	{
		$column = new Column('foo');
		$column->setGeneratedExpr('bar + baz');
		$expected = '[foo] AS (bar + baz)';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedStored()
	{
		$column = new Column('foo');
		$column->setGeneratedExpr('bar + baz');
		$column->setGeneratedType('STORED');
		$column->setNotNull(true);
		$expected = '[foo] AS (bar + baz) PERSISTED NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedStoredNullable()
	{
		$column = new Column('foo');
		$column->setGeneratedExpr('bar + baz');
		$column->setGeneratedType('STORED');
		$expected = '[foo] AS (bar + baz) PERSISTED';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLRowVersion()
	{
		$column = new Column('foo');
		$column->setRowVersion(true);
		$column->setNotNull(true);
		$expected = '[foo] ROWVERSION NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLPeriodRowStart()
	{
		$column = new Column('sys_start_time');
		$column->setPeriodRowStart(true);
		$expected = '[sys_start_time] DATETIME2 GENERATED ALWAYS AS ROW START NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLPeriodRowEnd()
	{
		$column = new Column('sys_end_time');
		$column->setPeriodRowEnd(true);
		$expected = '[sys_end_time] DATETIME2 GENERATED ALWAYS AS ROW END NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetPrimaryKeyDDLSimpleKey()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$column->setPrimaryKey(true);
		$table->addColumn($column);
		$expected = 'CONSTRAINT [foo_PK] PRIMARY KEY ([bar])';
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
		$expected = 'CONSTRAINT [foo_PK] PRIMARY KEY ([bar1],[bar2])';
		$this->assertEquals($expected, $this->getPlatform()->getPrimaryKeyDDL($table));
	}

	public function testGetPrimaryKeyDDLNonClustered()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$column->setPrimaryKey(true);
		$table->addColumn($column);
		$table->setPrimaryKeyClustered(false);
		$expected = 'CONSTRAINT [foo_PK] PRIMARY KEY NONCLUSTERED ([bar])';
		$this->assertEquals($expected, $this->getPlatform()->getPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestPrimaryKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestPrimaryKeyDDL')]
	public function testGetDropPrimaryKeyDDL($table)
	{
		$expected = "
ALTER TABLE [foo] DROP CONSTRAINT [foo_PK];
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
ALTER TABLE [foo] ADD CONSTRAINT [foo_PK] PRIMARY KEY ([bar]);
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
CREATE INDEX [babar] ON [foo] ([bar1],[bar2]);

CREATE INDEX [foo_index] ON [foo] ([bar1]);
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
CREATE INDEX [babar] ON [foo] ([bar1],[bar2]);
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
DROP INDEX [babar];
";
		$this->assertEquals($expected, $this->getPLatform()->getDropIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testGetIndexDDL($index)
	{
		$expected = 'INDEX [babar] ([bar1],[bar2])';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetUniqueDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetUniqueDDL')]
	public function testGetUniqueDDL($index)
	{
		$expected = 'UNIQUE ([bar1],[bar2])';
		$this->assertEquals($expected, $this->getPLatform()->getUniqueDDL($index));
	}

	public function testAddIndexDDLClustered()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setClustered(true);
		$table->addIndex($index);
		$expected = "
CREATE CLUSTERED INDEX [babar] ON [foo] ([bar]);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testAddIndexDDLNonClustered()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setClustered(false);
		$table->addIndex($index);
		$expected = "
CREATE NONCLUSTERED INDEX [babar] ON [foo] ([bar]);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetUniqueDDLClustered()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$table->addColumn($column);
		$unique = new Unique('babar');
		$unique->addColumn($column);
		$unique->setClustered(true);
		$table->addUnique($unique);
		$expected = 'UNIQUE CLUSTERED ([bar])';
		$this->assertEquals($expected, $this->getPlatform()->getUniqueDDL($unique));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeysDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeysDDL')]
	public function testGetAddForeignKeysDDL($table)
	{
		$expected = "
BEGIN
ALTER TABLE [foo] ADD CONSTRAINT [foo_bar_FK] FOREIGN KEY ([bar_id]) REFERENCES [bar] ([id]) ON DELETE CASCADE
END
;

BEGIN
ALTER TABLE [foo] ADD CONSTRAINT [foo_baz_FK] FOREIGN KEY ([baz_id]) REFERENCES [baz] ([id]) ON DELETE SET NULL
END
;
";
		$this->assertEquals($expected, $this->getPLatform()->getAddForeignKeysDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetAddForeignKeyDDL($fk)
	{
		$expected = "
BEGIN
ALTER TABLE [foo] ADD CONSTRAINT [foo_bar_FK] FOREIGN KEY ([bar_id]) REFERENCES [bar] ([id]) ON DELETE CASCADE
END
;
";
		$this->assertEquals($expected, $this->getPLatform()->getAddForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeySkipSqlDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeySkipSqlDDL')]
	public function testGetAddForeignKeySkipSqlDDL($fk)
	{
		$expected = '';
		$this->assertEquals($expected, $this->getPLatform()->getAddForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetDropForeignKeyDDL($fk)
	{
		$expected = "
ALTER TABLE [foo] DROP CONSTRAINT [foo_bar_FK];
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
		$this->assertEquals($expected, $this->getPLatform()->getDropForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeyDDL')]
	public function testGetForeignKeyDDL($fk)
	{
		$expected = 'CONSTRAINT [foo_bar_FK] FOREIGN KEY ([bar_id]) REFERENCES [bar] ([id]) ON DELETE CASCADE';
		$this->assertEquals($expected, $this->getPLatform()->getForeignKeyDDL($fk));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeySkipSqlDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeySkipSqlDDL')]
	public function testGetForeignKeySkipSqlDDL($fk)
	{
		$expected = '';
		$this->assertEquals($expected, $this->getPLatform()->getForeignKeyDDL($fk));
	}

	/**
	 * Case 0 (see MssqlPlatform::computeCascadeDowngrades()): a self-referencing
	 * FK with a CASCADE action must always be downgraded to NO ACTION, since SQL
	 * Server rejects it outright (error 1785) regardless of any other table.
	 */
	public function testGetAddTablesDDLDowngradesSelfReferencingCascade()
	{
		$schema = <<<EOF
<database name="test">
	<table name="node">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="parent_id" type="INTEGER" />
		<foreign-key foreignTable="node" onDelete="cascade" onUpdate="cascade">
			<reference local="parent_id" foreign="id" />
		</foreign-key>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString('REFERENCES [node] ([id]) ON UPDATE NO ACTION ON DELETE NO ACTION', $ddl);
		$this->assertStringNotContainsString('CASCADE', $ddl);
	}

	/**
	 * Case 2: two FKs from the same table straight to the same target table --
	 * only the first (by declaration order) may cascade, the rest are downgraded.
	 */
	public function testGetAddTablesDDLDowngradesRepeatedTargetCascade()
	{
		$schema = <<<EOF
<database name="test">
	<table name="author">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="essay">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="first_author_id" type="INTEGER" />
		<column name="second_author_id" type="INTEGER" />
		<foreign-key foreignTable="author" onUpdate="cascade">
			<reference local="first_author_id" foreign="id" />
		</foreign-key>
		<foreign-key foreignTable="author" onUpdate="cascade">
			<reference local="second_author_id" foreign="id" />
		</foreign-key>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString('FOREIGN KEY ([first_author_id]) REFERENCES [author] ([id]) ON UPDATE CASCADE', $ddl);
		$this->assertStringContainsString('FOREIGN KEY ([second_author_id]) REFERENCES [author] ([id]) ON UPDATE NO ACTION', $ddl);
	}

	/**
	 * SQL Server's "multiple cascade paths" restriction (error 1785) applies to
	 * ON DELETE/ON UPDATE SET NULL exactly the same as it does to CASCADE, not
	 * just to literal CASCADE actions -- empirically confirmed against a live
	 * SQL Server instance (this fork's own `essay`/`bookstore_employee` fixture
	 * tables hit it: `essay` has two ON DELETE SET NULL FKs to `author`, and
	 * `bookstore_employee.supervisor_id` self-references with ON DELETE SET NULL).
	 * This covers case 0 (self-reference) and case 2 (repeated target) with
	 * SET NULL instead of CASCADE.
	 */
	public function testGetAddTablesDDLDowngradesSetNullActionsLikeCascade()
	{
		$schema = <<<EOF
<database name="test">
	<table name="employee">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="supervisor_id" type="INTEGER" />
		<foreign-key foreignTable="employee" onDelete="setnull">
			<reference local="supervisor_id" foreign="id" />
		</foreign-key>
	</table>
	<table name="author">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="essay">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="first_author_id" type="INTEGER" />
		<column name="second_author_id" type="INTEGER" />
		<foreign-key foreignTable="author" onDelete="setnull">
			<reference local="first_author_id" foreign="id" />
		</foreign-key>
		<foreign-key foreignTable="author" onDelete="setnull">
			<reference local="second_author_id" foreign="id" />
		</foreign-key>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString('FOREIGN KEY ([supervisor_id]) REFERENCES [employee] ([id]) ON DELETE NO ACTION', $ddl);
		$this->assertStringContainsString('FOREIGN KEY ([first_author_id]) REFERENCES [author] ([id]) ON DELETE SET NULL', $ddl);
		$this->assertStringContainsString('FOREIGN KEY ([second_author_id]) REFERENCES [author] ([id]) ON DELETE NO ACTION', $ddl);
	}

	/**
	 * Case 1: a diamond via an intermediate table -- `child` cascade-deletes
	 * directly from both `parent_a` and `parent_b`, and `parent_b` itself
	 * cascade-deletes from `parent_a`, so the direct `parent_a` -> `child` edge
	 * is redundant (already reached transitively via `parent_b`) and gets
	 * downgraded; the edges that remain the only path stay CASCADE.
	 */
	public function testGetAddTablesDDLDowngradesDiamondCascade()
	{
		$schema = <<<EOF
<database name="test">
	<table name="parent_a">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="parent_b">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="parent_a_id" type="INTEGER" />
		<foreign-key foreignTable="parent_a" onDelete="cascade">
			<reference local="parent_a_id" foreign="id" />
		</foreign-key>
	</table>
	<table name="child">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="parent_a_id" type="INTEGER" />
		<column name="parent_b_id" type="INTEGER" />
		<foreign-key foreignTable="parent_a" onDelete="cascade">
			<reference local="parent_a_id" foreign="id" />
		</foreign-key>
		<foreign-key foreignTable="parent_b" onDelete="cascade">
			<reference local="parent_b_id" foreign="id" />
		</foreign-key>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$ddl = $this->getPlatform()->getAddTablesDDL($database);
		$this->assertStringContainsString('FOREIGN KEY ([parent_a_id]) REFERENCES [parent_a] ([id]) ON DELETE CASCADE', $ddl);
		$this->assertStringContainsString('FOREIGN KEY ([parent_a_id]) REFERENCES [parent_a] ([id]) ON DELETE NO ACTION', $ddl);
		$this->assertStringContainsString('FOREIGN KEY ([parent_b_id]) REFERENCES [parent_b] ([id]) ON DELETE CASCADE', $ddl);
	}

	public function testGetCommentBlockDDL()
	{
		$expected = "
-----------------------------------------------------------------------
-- foo bar
-----------------------------------------------------------------------
";
		$this->assertEquals($expected, $this->getPLatform()->getCommentBlockDDL('foo bar'));
	}

	public function testGetAddTableDDLTemporal()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" temporal="true">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="VARCHAR" size="255" />
		<column name="sys_start_time" periodRowStart="true" type="INTEGER" />
		<column name="sys_end_time" periodRowEnd="true" type="INTEGER" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE TABLE [foo]
(
	[id] INT NOT NULL IDENTITY,
	[bar] VARCHAR(255) NULL,
	[sys_start_time] DATETIME2 GENERATED ALWAYS AS ROW START NOT NULL,
	[sys_end_time] DATETIME2 GENERATED ALWAYS AS ROW END NOT NULL,
	PERIOD FOR SYSTEM_TIME ([sys_start_time], [sys_end_time]),
	CONSTRAINT [foo_PK] PRIMARY KEY ([id])
)
WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = [dbo].[foo_History]));

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLTemporalNamedHistoryTable()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" temporal="true" historyTable="foo_versions">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="sys_start_time" periodRowStart="true" type="INTEGER" />
		<column name="sys_end_time" periodRowEnd="true" type="INTEGER" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$this->assertStringContainsString('WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = [dbo].[foo_versions]));', $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLTemporalMissingPeriodColumnsThrows()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" temporal="true">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$this->expectException(EngineException::class);
		$this->getPlatform()->getAddTableDDL($table);
	}

	public function testGetDropTableDDLTemporal()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" temporal="true">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="sys_start_time" periodRowStart="true" type="INTEGER" />
		<column name="sys_end_time" periodRowEnd="true" type="INTEGER" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$ddl = $this->getPlatform()->getDropTableDDL($table);
		$this->assertStringContainsString("\nIF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'foo' AND temporal_type = 2)\n\tALTER TABLE [foo] SET (SYSTEM_VERSIONING = OFF);\n", $ddl);
		$this->assertStringContainsString("\nIF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'foo_History')\n\tDROP TABLE [dbo].[foo_History];\n", $ddl);
	}
}
