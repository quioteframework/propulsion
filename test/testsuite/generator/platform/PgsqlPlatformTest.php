<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Model\Exclusion;

class PgsqlPlatformTest extends PlatformTestProvider
{
	/**
	 * Get the Platform object for this class
	 *
	 * @return     Platform
	 */
	protected static function getPlatform()
	{
		return new PgsqlPlatform();
	}

	public function testGetSequenceNameDefault()
	{
		$table = new Table('foo');
		$table->setIdMethod(IDMethod::NATIVE);
		$col = new Column('bar');
		$col->getDomain()->copy($this->getPlatform()->getDomainForType('INTEGER'));
		$col->setAutoIncrement(true);
		$table->addColumn($col);
		$expected = 'foo_bar_seq';
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
		$col = new Column('bar');
		$col->getDomain()->copy($this->getPlatform()->getDomainForType('INTEGER'));
		$col->setAutoIncrement(true);
		$table->addColumn($col);
		$expected = 'foo_sequence';
		$this->assertEquals($expected, $this->getPlatform()->getSequenceName($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesDDL')]
	public function testGetAddTablesDDL($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

-----------------------------------------------------------------------
-- book
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "book" CASCADE;

CREATE TABLE "book"
(
	"id" serial NOT NULL,
	"title" VARCHAR(255) NOT NULL,
	"author_id" INTEGER,
	PRIMARY KEY ("id")
);

CREATE INDEX "book_I_1" ON "book" ("title");

-----------------------------------------------------------------------
-- author
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "author" CASCADE;

CREATE TABLE "author"
(
	"id" serial NOT NULL,
	"first_name" VARCHAR(100),
	"last_name" VARCHAR(100),
	PRIMARY KEY ("id")
);

ALTER TABLE "book" ADD CONSTRAINT "book_FK_1"
	FOREIGN KEY ("author_id")
	REFERENCES "author" ("id");

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTablesSkipSQLDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTablesSkipSQLDDL')]
	public function testGetAddTablesDDLSkipSQL($schema)
	{
		$database = $this->getDatabaseFromSchema($schema);
		$expected = '';
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	public function testGetMaxColumnNameLength()
	{
		// PostgreSQL 16+'s real NAMEDATALEN-based limit is 63, not the pre-7.3
		// 32-character limit this used to (incorrectly) return -- see the
		// docblock on PgsqlPlatform::getMaxColumnNameLength().
		$this->assertEquals(63, $this->getPlatform()->getMaxColumnNameLength());
	}

	/**
	 * Regression test: a table using only the primary, cross-platform
	 * `schema="..."` attribute (not the legacy `<vendor type="pgsql">`
	 * convention covered by testGetAddTablesDDLSchemasVendor()) used to get
	 * fully schema-qualified DDL (`CREATE TABLE "x"."book" ...`) without the
	 * schema itself ever being created, which fails outright against a fresh
	 * database. getAddSchemasDDL() must emit `CREATE SCHEMA` for these too.
	 */
	public function testGetAddSchemasDDLNativeSchemaAttribute()
	{
		$schema = <<<EOF
<database name="test">
	<table name="book" schema="x">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="author" schema="y">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="book_summary" schema="x">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

CREATE SCHEMA "x";

CREATE SCHEMA "y";

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddSchemasDDL($database));
	}

	/**
	 * A `schema="..."` table doesn't need `SET search_path` wrapping the way
	 * the legacy vendor-info convention does (see
	 * testGetAddTableDDLSchemaVendor()), because Table::getName() already
	 * returns the fully schema-qualified identifier.
	 */
	public function testGetAddTableDDLNativeSchemaAttributeNoSearchPath()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo" schema="Woopah">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema, 'Woopah.foo');
		$ddl = $this->getPlatform()->getAddTableDDL($table);
		$this->assertStringNotContainsString('search_path', $ddl);
		$this->assertStringContainsString('CREATE TABLE "Woopah"."foo"', $ddl);
	}

	public function testGetAddTablesDDLSchemasVendor()
	{
		$schema = <<<EOF
<database name="test">
	<table name="table1">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<vendor type="pgsql">
			<parameter name="schema" value="Woopah"/>
		</vendor>
	</table>
	<table name="table2">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
	</table>
	<table name="table3">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<vendor type="pgsql">
			<parameter name="schema" value="Yipee"/>
		</vendor>
	</table>
</database>
EOF;
		$database = $this->getDatabaseFromSchema($schema);
		$expected = <<<EOF

CREATE SCHEMA "Woopah";

CREATE SCHEMA "Yipee";

-----------------------------------------------------------------------
-- table1
-----------------------------------------------------------------------

SET search_path TO "Woopah";

DROP TABLE IF EXISTS "table1" CASCADE;

SET search_path TO public;

SET search_path TO "Woopah";

CREATE TABLE "table1"
(
	"id" serial NOT NULL,
	PRIMARY KEY ("id")
);

SET search_path TO public;

-----------------------------------------------------------------------
-- table2
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "table2" CASCADE;

CREATE TABLE "table2"
(
	"id" serial NOT NULL,
	PRIMARY KEY ("id")
);

-----------------------------------------------------------------------
-- table3
-----------------------------------------------------------------------

SET search_path TO "Yipee";

DROP TABLE IF EXISTS "table3" CASCADE;

SET search_path TO public;

SET search_path TO "Yipee";

CREATE TABLE "table3"
(
	"id" serial NOT NULL,
	PRIMARY KEY ("id")
);

SET search_path TO public;

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

CREATE SCHEMA "x";

CREATE SCHEMA "y";

-----------------------------------------------------------------------
-- x.book
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "x"."book" CASCADE;

CREATE TABLE "x"."book"
(
	"id" serial NOT NULL,
	"title" VARCHAR(255) NOT NULL,
	"author_id" INTEGER,
	PRIMARY KEY ("id")
);

CREATE INDEX "book_I_1" ON "x"."book" ("title");

-----------------------------------------------------------------------
-- y.author
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "y"."author" CASCADE;

CREATE TABLE "y"."author"
(
	"id" serial NOT NULL,
	"first_name" VARCHAR(100),
	"last_name" VARCHAR(100),
	PRIMARY KEY ("id")
);

-----------------------------------------------------------------------
-- x.book_summary
-----------------------------------------------------------------------

DROP TABLE IF EXISTS "x"."book_summary" CASCADE;

CREATE TABLE "x"."book_summary"
(
	"id" serial NOT NULL,
	"book_id" INTEGER NOT NULL,
	"summary" TEXT NOT NULL,
	PRIMARY KEY ("id")
);

ALTER TABLE "x"."book" ADD CONSTRAINT "book_FK_1"
	FOREIGN KEY ("author_id")
	REFERENCES "y"."author" ("id");

ALTER TABLE "x"."book_summary" ADD CONSTRAINT "book_summary_FK_1"
	FOREIGN KEY ("book_id")
	REFERENCES "x"."book" ("id")
	ON DELETE CASCADE;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTablesDDL($database));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSimplePK
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSimplePK')]
	public function testGetAddTableDDLSimplePK($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE TABLE "foo"
(
	"id" serial NOT NULL,
	"bar" VARCHAR(255) NOT NULL,
	PRIMARY KEY ("id")
);

COMMENT ON TABLE "foo" IS 'This is foo table';

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLCompositePK
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLCompositePK')]
	public function testGetAddTableDDLCompositePK($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE TABLE "foo"
(
	"foo" INTEGER NOT NULL,
	"bar" INTEGER NOT NULL,
	"baz" VARCHAR(255) NOT NULL,
	PRIMARY KEY ("foo","bar")
);

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLUniqueIndex
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLUniqueIndex')]
	public function testGetAddTableDDLUniqueIndex($schema)
	{
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE TABLE "foo"
(
	"id" serial NOT NULL,
	"bar" INTEGER,
	PRIMARY KEY ("id"),
	CONSTRAINT "foo_U_1" UNIQUE ("bar")
);

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLSchemaVendor()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<vendor type="pgsql">
			<parameter name="schema" value="Woopah"/>
		</vendor>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

SET search_path TO "Woopah";

CREATE TABLE "foo"
(
	"id" serial NOT NULL,
	PRIMARY KEY ("id")
);

SET search_path TO public;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSchema')]
	public function testGetAddTableDDLSchema($schema)
	{
		$table = $this->getTableFromSchema($schema, 'Woopah.foo');
		$expected = <<<EOF

CREATE TABLE "Woopah"."foo"
(
	"id" serial NOT NULL,
	"bar" INTEGER,
	PRIMARY KEY ("id")
);

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLSequence()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<id-method-parameter value="my_custom_sequence_name"/>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE SEQUENCE "my_custom_sequence_name";

CREATE TABLE "foo"
(
	"id" INTEGER NOT NULL,
	PRIMARY KEY ("id")
);

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetAddTableDDLColumnComments()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" description="identifier column"/>
		<column name="bar" type="INTEGER" description="your name here"/>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

CREATE TABLE "foo"
(
	"id" serial NOT NULL,
	"bar" INTEGER,
	PRIMARY KEY ("id")
);

COMMENT ON COLUMN "foo"."id" IS 'identifier column';

COMMENT ON COLUMN "foo"."bar" IS 'your name here';

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getAddTableDDL($table));
	}

	public function testGetDropTableDDL()
	{
		$table = new Table('foo');
		$expected = "
DROP TABLE IF EXISTS \"foo\" CASCADE;
";
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	public function testGetDropTableDDLSchemaVendor()
	{
		$schema = <<<EOF
<database name="test">
	<table name="foo">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<vendor type="pgsql">
			<parameter name="schema" value="Woopah"/>
		</vendor>
	</table>
</database>
EOF;
		$table = $this->getTableFromSchema($schema);
		$expected = <<<EOF

SET search_path TO "Woopah";

DROP TABLE IF EXISTS "foo" CASCADE;

SET search_path TO public;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetAddTableDDLSchema
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetAddTableDDLSchema')]
	public function testGetDropTableDDLSchema($schema)
	{
		$table = $this->getTableFromSchema($schema, 'Woopah.foo');
		$expected = <<<EOF

DROP TABLE IF EXISTS "Woopah"."foo" CASCADE;

EOF;
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	public function testGetDropTableWithSequenceDDL()
	{
		$table = new Table('foo');
		$idMethodParameter = new IdMethodParameter();
		$idMethodParameter->setValue('foo_sequence');
		$table->addIdMethodParameter($idMethodParameter);
		$table->setIdMethod(IDMethod::NATIVE);
		$expected = "
DROP TABLE IF EXISTS \"foo\" CASCADE;

DROP SEQUENCE IF EXISTS \"foo_sequence\";
";
		$this->assertEquals($expected, $this->getPlatform()->getDropTableDDL($table));
	}

	public function testGetColumnDDL()
	{
		$c = new Column('foo');
		$c->getDomain()->copy($this->getPlatform()->getDomainForType('DOUBLE'));
		$c->getDomain()->replaceScale(2);
		$c->getDomain()->replaceSize(3);
		$c->setNotNull(true);
		$c->getDomain()->setDefaultValue(new ColumnDefaultValue(123, ColumnDefaultValue::TYPE_VALUE));
		$expected = '"foo" DOUBLE PRECISION DEFAULT 123 NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($c));
	}

	public function testGetColumnDDLAutoIncrement()
	{
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$table = new Table('foo_table');
		$table->setIdMethod(IDMethod::NATIVE);
		$database->addTable($table);
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::BIGINT));
		$column->setAutoIncrement(true);
		$table->addColumn($column);
		$expected = '"foo" bigserial';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLAutoIncrementIdentity()
	{
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$table = new Table('foo_table');
		$table->setIdMethod(IDMethod::NATIVE);
		$database->addTable($table);
		$column = new Column('foo');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::BIGINT));
		$column->setAutoIncrement(true);
		$column->setIdentity(true);
		$table->addColumn($column);
		$expected = '"foo" INT8 GENERATED BY DEFAULT AS IDENTITY';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLNativeArray()
	{
		$column = new Column('tags');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::PHP_ARRAY));
		$column->setNativeArray(true);
		$expected = '"tags" TEXT[]';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLArrayWithoutNativeFlagStaysEmulated()
	{
		$column = new Column('tags');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::PHP_ARRAY));
		$expected = '"tags" TEXT';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLPlainTsvector()
	{
		$column = new Column('search_vector');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::TSVECTOR));
		$expected = '"search_vector" TSVECTOR';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedTsvector()
	{
		$table = new Table('foo_table');
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$database->addTable($table);
		$column = new Column('search_vector');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::TSVECTOR));
		$column->setTsvectorSourceColumns(['title', 'body']);
		$table->addColumn($column);
		$expected = '"search_vector" TSVECTOR GENERATED ALWAYS AS '
			. "(to_tsvector('english', coalesce(\"title\", '') || ' ' || coalesce(\"body\", ''))) STORED";
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLGeneratedTsvectorCustomConfig()
	{
		$table = new Table('foo_table');
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$database->addTable($table);
		$column = new Column('search_vector');
		$column->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::TSVECTOR));
		$column->setTsvectorSourceColumns(['title']);
		$column->setTsvectorConfig('simple');
		$table->addColumn($column);
		$expected = '"search_vector" TSVECTOR GENERATED ALWAYS AS '
			. "(to_tsvector('simple', coalesce(\"title\", ''))) STORED";
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetAddIndexDDLWithIndexType()
	{
		$table = new Table('foo');
		$column = new Column('search_vector');
		$column->getDomain()->copy(new Domain('TSVECTOR'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setIndexType('gin');
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" USING gin (\"search_vector\");
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLWithOpclass()
	{
		// The pgvector ANN-index shape: HNSW refuses to build without an
		// operator class, so this is what makes such an index generatable.
		$table = new Table('doc');
		$column = new Column('embedding');
		$column->getDomain()->copy(new Domain('VECTOR'));
		$table->addColumn($column);
		$index = new Index('doc_embedding_idx');
		$index->addColumn(array('name' => 'embedding', 'opclass' => 'vector_l2_ops'));
		$index->setIndexType('hnsw');
		$index->setStorageParameters('m=16, ef_construction=64');
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"doc_embedding_idx\" ON \"doc\" USING hnsw (\"embedding\" vector_l2_ops) WITH (m=16, ef_construction=64);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLOpclassOnlyAppliesToTheColumnItWasDeclaredOn()
	{
		$table = new Table('doc');
		foreach (array('a', 'b') as $name) {
			$column = new Column($name);
			$column->getDomain()->copy(new Domain('FOOTYPE'));
			$table->addColumn($column);
		}
		$index = new Index('multi');
		$index->addColumn(array('name' => 'a'));
		$index->addColumn(array('name' => 'b', 'opclass' => 'text_pattern_ops'));
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"multi\" ON \"doc\" (\"a\",\"b\" text_pattern_ops);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLWithoutIndexTypeStaysDefault()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\");
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLPartial()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setWhereClause('bar1 IS NOT NULL');
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\") WHERE (bar1 IS NOT NULL);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLExpression()
	{
		$table = new Table('foo');
		$column = new Column('title');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn(['expression' => 'lower(title)']);
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" ((lower(title)));
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLMixedColumnAndExpression()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->addColumn(['expression' => 'lower(title)']);
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\",(lower(title)));
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLIncludeColumns()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setIncludeColumns(['bar2', 'bar3']);
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\") INCLUDE (\"bar2\",\"bar3\");
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLStorageParameters()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setStorageParameters('fillfactor=70');
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\") WITH (fillfactor=70);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLConcurrently()
	{
		$table = new Table('foo');
		$column = new Column('bar1');
		$column->getDomain()->copy(new Domain('FOOTYPE'));
		$table->addColumn($column);
		$index = new Index('babar');
		$index->addColumn($column);
		$index->setConcurrent(true);
		$table->addIndex($index);

		$expected = "
CREATE INDEX CONCURRENTLY \"babar\" ON \"foo\" (\"bar1\");
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetAddIndexDDLEverythingCombined()
	{
		$table = new Table('articles');
		$column = new Column('search_vector');
		$column->getDomain()->copy(new Domain('TSVECTOR'));
		$table->addColumn($column);
		$index = new Index('articles_search_idx');
		$index->addColumn($column);
		$index->setIndexType('gin');
		$index->setIncludeColumns(['id']);
		$index->setStorageParameters('fastupdate=off');
		$index->setWhereClause('deleted_at IS NULL');
		$table->addIndex($index);

		$expected = "
CREATE INDEX \"articles_search_idx\" ON \"articles\" USING gin (\"search_vector\") INCLUDE (\"id\") WITH (fastupdate=off) WHERE (deleted_at IS NULL);
";
		$this->assertEquals($expected, $this->getPlatform()->getAddIndexDDL($index));
	}

	public function testGetExclusionDDL()
	{
		$table = new Table('bookings');
		$roomCol = new Column('room_id');
		$roomCol->getDomain()->copy(new Domain('INTEGER'));
		$table->addColumn($roomCol);
		$duringCol = new Column('during');
		$duringCol->getDomain()->copy(new Domain('TSRANGE'));
		$table->addColumn($duringCol);

		$exclusion = new Exclusion('no_overlapping_bookings');
		$exclusion->setTable($table);
		$exclusion->addColumn(['name' => 'room_id', 'operator' => '=']);
		$exclusion->addColumn(['name' => 'during', 'operator' => '&&']);

		$expected = 'CONSTRAINT "no_overlapping_bookings" EXCLUDE USING gist ("room_id" WITH =, "during" WITH &&)';
		$this->assertEquals($expected, $this->getPlatform()->getExclusionDDL($exclusion));
	}

	public function testGetExclusionDDLWithWhereClauseAndCustomIndexType()
	{
		$table = new Table('bookings');
		$exclusion = new Exclusion('no_overlap');
		$exclusion->setTable($table);
		$exclusion->setIndexType('btree');
		$exclusion->setWhereClause('active');
		$exclusion->addColumn(['name' => 'room_id', 'operator' => '=']);

		$expected = 'CONSTRAINT "no_overlap" EXCLUDE USING btree ("room_id" WITH =) WHERE (active)';
		$this->assertEquals($expected, $this->getPlatform()->getExclusionDDL($exclusion));
	}

	public function testGetAddTableDDLWithExclusionConstraint()
	{
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$table = new Table('bookings');
		$database->addTable($table);
		$roomCol = new Column('room_id');
		$roomCol->getDomain()->copy(new Domain('INTEGER'));
		$table->addColumn($roomCol);

		$exclusion = new Exclusion('no_overlap');
		$exclusion->addColumn(['name' => 'room_id', 'operator' => '=']);
		$table->addExclusion($exclusion);

		$ddl = $this->getPlatform()->getAddTableDDL($table);
		$this->assertStringContainsString('CONSTRAINT "no_overlap" EXCLUDE USING gist ("room_id" WITH =)', $ddl);
	}

	public function testGetAddTableDDLWithInheritsFrom()
	{
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$table = new Table('child_table');
		$table->setInheritsFrom('parent_table');
		$database->addTable($table);
		$column = new Column('extra');
		$column->getDomain()->copy(new Domain('INTEGER'));
		$table->addColumn($column);

		$ddl = $this->getPlatform()->getAddTableDDL($table);
		$this->assertStringContainsString(') INHERITS ("parent_table");', $ddl);
	}

	public function testGetAddTableDDLWithoutInheritsFromStaysDefault()
	{
		$database = new Database();
		$database->setPlatform($this->getPlatform());
		$table = new Table('plain_table');
		$database->addTable($table);
		$column = new Column('extra');
		$column->getDomain()->copy(new Domain('INTEGER'));
		$table->addColumn($column);

		$ddl = $this->getPlatform()->getAddTableDDL($table);
		$this->assertStringNotContainsString('INHERITS', $ddl);
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
		$expected = '"foo" DECIMAL(5,6) DEFAULT 123 NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($column));
	}

	public function testGetColumnDDLUuid()
	{
		$c = new Column('foo');
		$c->getDomain()->copy($this->getPlatform()->getDomainForType(PropulsionTypes::UUID));
		$c->setNotNull(true);
		$expected = '"foo" UUID NOT NULL';
		$this->assertEquals($expected, $this->getPlatform()->getColumnDDL($c));
	}

	public function testGetPrimaryKeyDDLSimpleKey()
	{
		$table = new Table('foo');
		$column = new Column('bar');
		$column->setPrimaryKey(true);
		$table->addColumn($column);
		$expected = 'PRIMARY KEY ("bar")';
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
		$expected = 'PRIMARY KEY ("bar1","bar2")';
		$this->assertEquals($expected, $this->getPlatform()->getPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestPrimaryKeyDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestPrimaryKeyDDL')]
	public function testGetDropPrimaryKeyDDL($table)
	{
		$expected = "
ALTER TABLE \"foo\" DROP CONSTRAINT \"foo_pkey\";
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
ALTER TABLE \"foo\" ADD PRIMARY KEY (\"bar\");
";
		$this->assertEquals($expected, $this->getPlatform()->getAddPrimaryKeyDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testAddIndexDDL($index)
	{
		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\",\"bar2\");
";
		$this->assertEquals($expected, $this->getPLatform()->getAddIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetIndicesDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndicesDDL')]
	public function testAddIndicesDDL($table)
	{
		$expected = "
CREATE INDEX \"babar\" ON \"foo\" (\"bar1\",\"bar2\");

CREATE INDEX \"foo_index\" ON \"foo\" (\"bar1\");
";
		$this->assertEquals($expected, $this->getPLatform()->getAddIndicesDDL($table));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testDropIndexDDL($index)
	{
		$expected = "
DROP INDEX \"babar\";
";
		$this->assertEquals($expected, $this->getPLatform()->getDropIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetIndexDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetIndexDDL')]
	public function testGetIndexDDL($index)
	{
		$expected = 'INDEX "babar" ("bar1","bar2")';
		$this->assertEquals($expected, $this->getPLatform()->getIndexDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetUniqueDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetUniqueDDL')]
	public function testGetUniqueDDL($index)
	{
		$expected = 'CONSTRAINT "babar" UNIQUE ("bar1","bar2")';
		$this->assertEquals($expected, $this->getPlatform()->getUniqueDDL($index));
	}

	/**
	 * @dataProvider providerForTestGetForeignKeysDDL
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('providerForTestGetForeignKeysDDL')]
	public function testGetAddForeignKeysDDL($table)
	{
		$expected = "
ALTER TABLE \"foo\" ADD CONSTRAINT \"foo_bar_FK\"
	FOREIGN KEY (\"bar_id\")
	REFERENCES \"bar\" (\"id\")
	ON DELETE CASCADE;

ALTER TABLE \"foo\" ADD CONSTRAINT \"foo_baz_FK\"
	FOREIGN KEY (\"baz_id\")
	REFERENCES \"baz\" (\"id\")
	ON DELETE SET NULL;
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
ALTER TABLE \"foo\" ADD CONSTRAINT \"foo_bar_FK\"
	FOREIGN KEY (\"bar_id\")
	REFERENCES \"bar\" (\"id\")
	ON DELETE CASCADE;
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
ALTER TABLE \"foo\" DROP CONSTRAINT \"foo_bar_FK\";
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
		$expected = "CONSTRAINT \"foo_bar_FK\"
	FOREIGN KEY (\"bar_id\")
	REFERENCES \"bar\" (\"id\")
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
		$this->assertEquals($expected, $this->getPLatform()->getForeignKeyDDL($fk));
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

}
