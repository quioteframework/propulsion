<?php


use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the generated objects for enum column types accessor & mutator
 *
 * @author     Francois Zaninotto
 * @package    generator.builder.om
 */
class GeneratedObjectEnumColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('ComplexColumnTypeEntity3')) {
			$schema = <<<EOF
<database name="generated_object_complex_type_test_3">
	<table name="complex_column_type_entity_3">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="bar" type="ENUM" valueSet="foo, bar, baz, 1, 4,(, foo bar " />
		<column name="bar2" type="ENUM" valueSet="foo, bar" defaultValue="bar" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
			// ok this is hackish but it makes testing of getter and setter independent of each other
			$publicAccessorCode = <<<EOF
class PublicComplexColumnTypeEntity3 extends ComplexColumnTypeEntity3
{
	public ?string \$Bar;
}
EOF;
			eval($publicAccessorCode);
		}
	}

	public function testGetter()
	{
		$this->assertTrue(method_exists('ComplexColumnTypeEntity3', 'getBar'));
		$e = new ComplexColumnTypeEntity3();
		$this->assertNull($e->getBar());
		$e = new PublicComplexColumnTypeEntity3();
		$e->Bar = 0;
		$this->assertEquals('foo', $e->getBar());
		$e->Bar = 3;
		$this->assertEquals('1', $e->getBar());
		$e->Bar = 6;
		$this->assertEquals('foo bar', $e->getBar());
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testGetterThrowsExceptionOnUnknownKey()
	{
		$this->expectException(PropulsionException::class);
		$e = new PublicComplexColumnTypeEntity3();
		$e->Bar = 156;
		$e->getBar();
	}

	public function testGetterDefaultValue()
	{
		$e = new PublicComplexColumnTypeEntity3();
		$this->assertEquals('bar', $e->getBar2());
	}

	public function testSetter()
	{
		$this->assertTrue(method_exists('ComplexColumnTypeEntity3', 'setBar'));
		$e = new PublicComplexColumnTypeEntity3();
		$e->setBar('foo');
		$this->assertEquals(0, $e->Bar);
		$e->setBar(1);
		$this->assertEquals(3, $e->Bar);
		$e->setBar('1');
		$this->assertEquals(3, $e->Bar);
		$e->setBar('foo bar');
		$this->assertEquals(6, $e->Bar);
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testSetterThrowsExceptionOnUnknownValue()
	{
		$this->expectException(PropulsionException::class);
		$e = new ComplexColumnTypeEntity3();
		$e->setBar('bazz');
	}

	public function testValueIsPersisted()
	{
		$e = new ComplexColumnTypeEntity3();
		$e->setBar('baz');
		$e->save();
		ComplexColumnTypeEntity3Peer::clearInstancePool();
		$e = ComplexColumnTypeEntity3Query::create()->findOne();
		$this->assertEquals('baz', $e->getBar());
	}

	public function testValueIsCopied()
	{
		$e1 = new ComplexColumnTypeEntity3();
		$e1->setBar('baz');
		$e2 = new ComplexColumnTypeEntity3();
		$e1->copyInto($e2);
		$this->assertEquals('baz', $e2->getBar());
	}
}
