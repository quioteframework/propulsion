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
 * Coverage for `nativeArray="true"` PHP_ARRAY column codegen
 * (ObjectBuilder::addHydrate()/addBuildCriteria()/addBuildPkeyCriteria(),
 * QueryBuilder::addFilterByCol()) -- exercised against a real PgsqlPlatform
 * model/config (no live Postgres connection available in this environment;
 * this asserts on the generated source string the way InheritanceBuilderTest
 * does, rather than a full save()/findPk() round trip).
 */
class GeneratedObjectNativeArrayColumnTypeTest extends TestCase
{
	private function buildModel(): PropulsionQuickBuilder
	{
		$schema = <<<EOF
<database name="generated_object_native_array_type_test">
	<table name="native_array_column_type_entity" phpName="NativeArrayColumnTypeEntity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="tags" type="ARRAY" nativeArray="true" />
		<column name="labels" type="ARRAY" />
	</table>
</database>
EOF;
		$quickBuilder = new PropulsionQuickBuilder();
		$quickBuilder->setPlatform(new PgsqlPlatform());
		$quickBuilder->setSchema($schema);

		return $quickBuilder;
	}

	public function testHydrateUsesPgArrayDecodeForNativeArrayColumn()
	{
		$quickBuilder = $this->buildModel();
		$table = $quickBuilder->getDatabase()->getTable('native_array_column_type_entity');
		$builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'object');

		$script = $builder->build();

		$this->assertStringContainsString('PgArray::decode($v)', $script);
	}

	public function testBuildCriteriaUsesPgArrayEncodeForNativeArrayColumn()
	{
		$quickBuilder = $this->buildModel();
		$table = $quickBuilder->getDatabase()->getTable('native_array_column_type_entity');
		$builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'object');

		$script = $builder->build();

		$this->assertStringContainsString('PgArray::encode($this->Tags)', $script);
	}

	public function testNonNativeArrayColumnKeepsEmulatedFormat()
	{
		$quickBuilder = $this->buildModel();
		$table = $quickBuilder->getDatabase()->getTable('native_array_column_type_entity');
		$builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'object');

		$script = $builder->build();

		// The elements are whatever the application stored, so they are cast
		// before implode(), which wants strings. The point of this assertion is
		// the emulated " | " format, not the exact shape of the mapping.
		$this->assertStringContainsString("implode(' | ', array_map(", $script);
		$this->assertStringContainsString("\$this->Labels))", $script);
	}

	public function testFilterByColEncodesArrayForNativeArrayColumn()
	{
		$quickBuilder = $this->buildModel();
		$table = $quickBuilder->getDatabase()->getTable('native_array_column_type_entity');
		$builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'query');

		$script = $builder->build();

		$this->assertStringContainsString('PgArray::encode($tags)', $script);
		// The emulated-format LIKE-containment convenience method must not be
		// generated for a native array column (see QueryBuilder's guard) --
		// contrast the non-native "labels" column, which does get one.
		$this->assertStringContainsString('function filterByLabel(', $script);
		$this->assertStringNotContainsString('function filterByTag(', $script);
	}

	public function testDdlEmitsPostgresArrayType()
	{
		$quickBuilder = $this->buildModel();
		$ddl = $quickBuilder->getSQL();

		$this->assertStringContainsString('"tags" TEXT[]', $ddl);
		$this->assertStringContainsString('"labels" TEXT', $ddl);
		$this->assertStringNotContainsString('"labels" TEXT[]', $ddl);
	}
}
