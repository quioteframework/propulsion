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
 * End-to-end full-text-search DDL coverage: a `tsvectorFrom`-generated
 * TSVECTOR column plus a GIN index over it, from schema XML through to the
 * final Postgres DDL (no live connection -- see PgsqlPlatformTest for the
 * lower-level unit coverage of each piece).
 */
class PgsqlFullTextSearchDDLTest extends TestCase
{
	public function testGeneratedTsvectorColumnAndGinIndexDDL()
	{
		$schema = <<<EOF
<database name="fts_test">
	<table name="articles">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="title" type="VARCHAR" size="255" />
		<column name="body" type="LONGVARCHAR" />
		<column name="search_vector" type="TSVECTOR" tsvectorFrom="title, body" />
		<index name="articles_search_idx" indexType="gin">
			<index-column name="search_vector"/>
		</index>
	</table>
</database>
EOF;
		$quickBuilder = new PropulsionQuickBuilder();
		$quickBuilder->setPlatform(new PgsqlPlatform());
		$quickBuilder->setSchema($schema);

		$ddl = $quickBuilder->getSQL();

		$this->assertStringContainsString(
			"\"search_vector\" TSVECTOR GENERATED ALWAYS AS (to_tsvector('english', coalesce(\"title\", '') || ' ' || coalesce(\"body\", ''))) STORED",
			$ddl
		);
		$this->assertStringContainsString(
			'CREATE INDEX "articles_search_idx" ON "articles" USING gin ("search_vector");',
			$ddl
		);
	}

	public function testPlainTsvectorColumnHasNoGeneratedClause()
	{
		$schema = <<<EOF
<database name="fts_test_plain">
	<table name="articles">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="search_vector" type="TSVECTOR" />
	</table>
</database>
EOF;
		$quickBuilder = new PropulsionQuickBuilder();
		$quickBuilder->setPlatform(new PgsqlPlatform());
		$quickBuilder->setSchema($schema);

		$ddl = $quickBuilder->getSQL();

		$this->assertStringContainsString('"search_vector" TSVECTOR', $ddl);
		$this->assertStringNotContainsString('GENERATED ALWAYS AS', $ddl);
	}
}
