<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * End-to-end schema-XML-to-DDL coverage for partial/expression indexes,
 * index storage parameters, exclusion constraints, and table inheritance
 * (no live connection -- see PgsqlPlatformTest for the lower-level unit
 * coverage of each piece).
 */
class PgsqlOpenSchemaFeaturesDDLTest extends TestCase
{
	private function buildDDL(string $schema): string
	{
		$quickBuilder = new PropulsionQuickBuilder();
		$quickBuilder->setPlatform(new PgsqlPlatform());
		$quickBuilder->setSchema($schema);

		return $quickBuilder->getSQL();
	}

	public function testPartialAndExpressionIndexDDL()
	{
		$ddl = $this->buildDDL(<<<EOF
<database name="partial_index_test">
	<table name="articles">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="title" type="VARCHAR" size="255" />
		<column name="deleted_at" type="TIMESTAMP" />
		<index name="articles_title_lower_idx">
			<index-column expression="lower(title)"/>
		</index>
		<index name="articles_active_idx" where="deleted_at IS NULL">
			<index-column name="title"/>
		</index>
	</table>
</database>
EOF);

		$this->assertStringContainsString(
			'CREATE INDEX "articles_title_lower_idx" ON "articles" ((lower(title)));',
			$ddl
		);
		$this->assertStringContainsString(
			'CREATE INDEX "articles_active_idx" ON "articles" ("title") WHERE (deleted_at IS NULL);',
			$ddl
		);
	}

	public function testIndexStorageParametersAndIncludeAndConcurrentlyDDL()
	{
		$ddl = $this->buildDDL(<<<EOF
<database name="index_storage_test">
	<table name="articles">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="title" type="VARCHAR" size="255" />
		<index name="articles_title_idx" include="id" storageParameters="fillfactor=70" concurrently="true">
			<index-column name="title"/>
		</index>
	</table>
</database>
EOF);

		$this->assertStringContainsString(
			'CREATE INDEX CONCURRENTLY "articles_title_idx" ON "articles" ("title") INCLUDE ("id") WITH (fillfactor=70);',
			$ddl
		);
	}

	public function testExclusionConstraintDDL()
	{
		$ddl = $this->buildDDL(<<<EOF
<database name="exclusion_test">
	<table name="bookings">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="room_id" type="INTEGER" />
		<column name="during" type="TSRANGE" />
		<exclusion name="no_overlapping_bookings">
			<exclusion-column name="room_id" operator="="/>
			<exclusion-column name="during" operator="&amp;&amp;"/>
		</exclusion>
	</table>
</database>
EOF);

		$this->assertStringContainsString(
			'CONSTRAINT "no_overlapping_bookings" EXCLUDE USING gist ("room_id" WITH =, "during" WITH &&)',
			$ddl
		);
	}

	public function testTableInheritanceDDL()
	{
		$ddl = $this->buildDDL(<<<EOF
<database name="inherits_test">
	<table name="animals">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="name" type="VARCHAR" size="255" />
	</table>
	<table name="cats" inheritsFrom="animals">
		<column name="cat_id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="lives_left" type="INTEGER" />
	</table>
</database>
EOF);

		$this->assertStringContainsString(') INHERITS ("animals");', $ddl);
		$this->assertStringNotContainsString('CREATE TABLE "animals"' . "\n(\n\t\"id\" serial NOT NULL,\n\t\"name\" VARCHAR(255)\n) INHERITS", $ddl);
	}
}
