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
 *
 * @package    generator.platform
 */
class DefaultPlatformTest extends TestCase
{
	protected $platform;

	/**
	 * Get the Platform object for this class
	 *
	 * @return     Platform
	 */
	protected function getPlatform()
	{
		if (null === $this->platform) {
			$this->platform = new DefaultPlatform();
		}
		return $this->platform;
	}

	protected function tearDown(): void
	{
		$this->platform = null;
	}

	public function testQuote()
	{
		$p = $this->getPlatform();

		$unquoted = "Nice";
		$quoted = $p->quote($unquoted);

		$this->assertEquals("'$unquoted'", $quoted);

		$unquoted = "Naughty ' string";
		$quoted = $p->quote($unquoted);
		$expected = "'Naughty '' string'";
		$this->assertEquals($expected, $quoted);
	}

	protected static function createColumn($type, $defaultValue)
	{
		$column = new Column();
		$column->setType($type);
		$column->setDefaultValue($defaultValue);
		return $column;
	}

	public static function createEnumColumn($defaultValues, $defaultValue)
	{
		$column = new Column();
		$column->setType(PropulsionTypes::ENUM);
		$column->setValueSet($defaultValues);
		$column->setDefaultValue($defaultValue);
		return $column;
	}

	public static function getColumnDefaultValueDDLDataProvider()
	{
		return array(
			array(static::createColumn(PropulsionTypes::INTEGER, 0), "DEFAULT 0"),
			array(static::createColumn(PropulsionTypes::INTEGER, '0'), "DEFAULT 0"),
			array(static::createColumn(PropulsionTypes::VARCHAR, 'foo'), "DEFAULT 'foo'"),
			array(static::createColumn(PropulsionTypes::VARCHAR, 0), "DEFAULT '0'"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, true), "DEFAULT 1"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, false), "DEFAULT 0"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, 'true'), "DEFAULT 1"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, 'false'), "DEFAULT 0"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, 'TRUE'), "DEFAULT 1"),
			array(static::createColumn(PropulsionTypes::BOOLEAN, 'FALSE'), "DEFAULT 0"),
			array(static::createEnumColumn(array('foo', 'bar', 'baz'), 'foo'), "DEFAULT 0"),
			array(static::createEnumColumn(array('foo', 'bar', 'baz'), 'bar'), "DEFAULT 1"),
			array(static::createEnumColumn(array('foo', 'bar', 'baz'), 'baz'), "DEFAULT 2"),
		);
	}

	/**
	 * @dataProvider getColumnDefaultValueDDLDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getColumnDefaultValueDDLDataProvider')]
	public function testGetColumnDefaultValueDDL($column, $default)
	{
		$this->assertEquals($default, $this->getPlatform()->getColumnDefaultValueDDL($column));
	}

}
