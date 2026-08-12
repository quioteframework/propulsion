<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

class PropulsionQuickBuilderTest extends TestCase
{
	public function testGetPlatform()
	{
		require_once dirname(__FILE__) . '/../../../../generator/Lib/Platform/MysqlPlatform.php';
		$builder = new PropulsionQuickBuilder();
		$builder->setPlatform(new MysqlPlatform());
		$this->assertTrue($builder->getPLatform() instanceof MysqlPlatform);
		$builder = new PropulsionQuickBuilder();
		$this->assertTrue($builder->getPLatform() instanceof SqlitePlatform);
	}

	public static function simpleSchemaProvider()
	{
		$schema = <<<EOF
<database name="test_quick_build_2">
	<table name="quick_build_foo_1">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="INTEGER" />
	</table>
</database>
EOF;
		$builder = new PropulsionQuickBuilder();
		$builder->setSchema($schema);
		return array(array($builder));
	}

	/**
	 * @dataProvider simpleSchemaProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('simpleSchemaProvider')]
	public function testGetDatabase($builder)
	{
		$database = $builder->getDatabase();
		$this->assertEquals('test_quick_build_2', $database->getName());
		$this->assertEquals(1, count($database->getTables()));
		$this->assertEquals(2, count($database->getTable('quick_build_foo_1')->getColumns()));
	}

	/**
	 * @dataProvider simpleSchemaProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('simpleSchemaProvider')]
	public function testGetSQL($builder)
	{
		$expected = <<<EOF

-----------------------------------------------------------------------
-- quick_build_foo_1
-----------------------------------------------------------------------

DROP TABLE [quick_build_foo_1];

CREATE TABLE [quick_build_foo_1]
(
	[id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	[bar] INTEGER
);

EOF;
		$this->assertEquals($expected, $builder->getSQL());
	}

	/**
	 * @dataProvider simpleSchemaProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('simpleSchemaProvider')]
	public function testGetClasses($builder)
	{
		$script = $builder->getClasses();
		// Object and query generated code is a trait the stub uses, so the stub
		// carries the real parent. The peer still emits a base class -- it stays
		// one, see docs/GENERATED_TRAITS_PLAN.md.
		$this->assertStringContainsString('class QuickBuildFoo1 extends BaseObject', $script);
		$this->assertStringContainsString('use QuickBuildFoo1Generated;', $script);
		$this->assertStringContainsString('trait QuickBuildFoo1Generated', $script);
		$this->assertStringContainsString('class QuickBuildFoo1Query extends ModelCriteria', $script);
		$this->assertStringContainsString('use QuickBuildFoo1QueryGenerated;', $script);
		$this->assertStringContainsString('trait QuickBuildFoo1QueryGenerated', $script);
		$this->assertStringContainsString('class QuickBuildFoo1Peer extends BaseQuickBuildFoo1Peer', $script);
		$this->assertStringContainsString('class BaseQuickBuildFoo1Peer', $script);
	}

	/**
	 * @dataProvider simpleSchemaProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('simpleSchemaProvider')]
	public function testBuildClasses($builder)
	{
		$builder->buildClasses();
		$foo = new QuickBuildFoo1();
		$this->assertTrue($foo instanceof BaseObject);
		$this->assertTrue(QuickBuildFoo1Peer::getTableMap() instanceof QuickBuildFoo1TableMap);
	}

	public function testBuild()
	{
		$schema = <<<EOF
<database name="test_quick_build_2">
	<table name="quick_build_foo_2">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="INTEGER" />
	</table>
</database>
EOF;
		$builder = new PropulsionQuickBuilder();
		$builder->setSchema($schema);
		$builder->build();
		$this->assertEquals(0, QuickBuildFoo2Query::create()->count());
		$foo = new QuickBuildFoo2();
		$foo->setBar(3);
		$foo->save();
		$this->assertEquals(1, QuickBuildFoo2Query::create()->count());
		$this->assertEquals($foo, QuickBuildFoo2Query::create()->findOne());
	}

	/**
	 * Building a second schema must not unregister the first one's adapter.
	 * build() configures Propulsion only when nothing else has, because
	 * setConfiguration() drops every adapter registered under the configuration
	 * it replaces -- so a per-build call would leave the earlier database
	 * failing with "Unable to find adapter for datasource".
	 */
	public function testBuildKeepsEarlierDatabasesUsable()
	{
		$first = <<<EOF
<database name="test_quick_build_first">
	<table name="quick_build_first">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="INTEGER" />
	</table>
</database>
EOF;
		$second = <<<EOF
<database name="test_quick_build_second">
	<table name="quick_build_second">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="INTEGER" />
	</table>
</database>
EOF;
		$firstBuilder = new PropulsionQuickBuilder();
		$firstBuilder->setSchema($first);
		$firstBuilder->build();

		$secondBuilder = new PropulsionQuickBuilder();
		$secondBuilder->setSchema($second);
		$secondBuilder->build();

		$foo = new QuickBuildFirst();
		$foo->setBar(3);
		$foo->save();
		$this->assertEquals(1, QuickBuildFirstQuery::create()->count(), 'the first database is still queryable after a second one is built');

		$bar = new QuickBuildSecond();
		$bar->setBar(4);
		$bar->save();
		$this->assertEquals(1, QuickBuildSecondQuery::create()->count(), 'the second database works too');
	}

}
