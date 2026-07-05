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
 * Test class for ObjectBuilder::getDefaultValueString().
 *
 * Was PHP5ObjectBuilderTest / TestablePHP5ObjectBuilder against the
 * (now-archived, see archaeology/php5-builders/) PHP5ObjectBuilder --
 * ObjectBuilder (the promoted PHP84 builder, and since Phase 3's PHP5
 * removal the only "object" builder left, see KNOWN_ISSUES.md) shares the
 * same getDefaultValueString() logic, so the test was ported rather than
 * dropped.
 *
 * @author     François Zaninotto
 * @version    $Id$
 */
class PHP5ObjectBuilderTest extends TestCase
{
	protected $builder;

	public function setUp(): void
	{
		$builder = new TestableObjectBuilder(new Table('Foo'));
		$builder->setPlatform(new MysqlPlatform());
		$this->builder = $builder;
	}

	public static function getDefaultValueStringProvider()
	{
		$col1 = new Column('Bar');
		$col1->setDomain(new Domain('VARCHAR'));
		$col1->setDefaultValue(new ColumnDefaultValue('abc', ColumnDefaultValue::TYPE_VALUE));
		$val1 = "'abc'";
		$col2 = new Column('Bar');
		$col2->setDomain(new Domain('INTEGER'));
		$col2->setDefaultValue(new ColumnDefaultValue(1234, ColumnDefaultValue::TYPE_VALUE));
		$val2 = "1234";
		$col3 = new Column('Bar');
		$col3->setDomain(new Domain('DATE'));
		$col3->setDefaultValue(new ColumnDefaultValue('0000-00-00', ColumnDefaultValue::TYPE_VALUE));
		$val3 = "NULL";
		return array(
			array($col1, $val1),
			array($col2, $val2),
			array($col3, $val3),
		);
	}

	/**
	 * @dataProvider getDefaultValueStringProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getDefaultValueStringProvider')]
	public function testGetDefaultValueString($column, $value)
	{
		$this->assertEquals($value, $this->builder->getDefaultValueString($column));
	}

}

class TestableObjectBuilder extends ObjectBuilder
{
	public function getDefaultValueString(Column $col)
	{
		return parent::getDefaultValueString($col);
	}
}
