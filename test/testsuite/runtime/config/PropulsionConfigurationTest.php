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
 * Test for PropulsionConfiguration class
 *
 * @author     Francois Zaninotto
 * @package    runtime.config
 */
class PropulsionConfigurationTest extends TestCase
{
	public static function configurationProvider()
	{
		$initialConf = array(
			'foo' => 'bar0',
			'foo1' => array(
				'foo2' => 'bar1',
			),
			'a' => array(
				'b' => array(
					'c' => 'bar2',
				)
			)
		);
		$c = new PropulsionConfiguration($initialConf);
		return array(array($c));
	}

	/**
	 * @dataProvider configurationProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('configurationProvider')]
	public function testConstructorArrayAccess($c)
	{
		$this->assertEquals('bar0', $c['foo']);
		$this->assertEquals('bar1', $c['foo1']['foo2']);
		$this->assertEquals('bar2', $c['a']['b']['c']);
	}

	/**
	 * @dataProvider configurationProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('configurationProvider')]
	public function testConstructorFlastAccess($c)
	{
		$this->assertEquals('bar0', $c->getParameter('foo'));
		$this->assertEquals('bar1', $c->getParameter('foo1.foo2'));
		$this->assertEquals('bar2', $c->getParameter('a.b.c'));
	}

	public function testArrayAccess()
	{
		$c = new PropulsionConfiguration();
		$this->assertFalse(isset($c[1]));
		$c[1] = 2;
		$this->assertTrue(isset($c[1]));
		$this->assertEquals(2, $c[1]);
		unset($c[1]);
		$this->assertFalse(isset($c[1]));
	}

	public function testNullValue()
	{
		$c = new PropulsionConfiguration();
		$c[1] = null;
		$this->assertTrue(isset($c[1]));
	}

	public function testSetParameterSimpleKey()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('foo', 'bar');
		$this->assertEquals('bar', $c['foo']);
		$this->assertEquals('bar', $c->getParameter('foo'));
	}

	public function testSetParameterSimpleKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('foo', array('bar1' => 'baz1'));
		$this->assertEquals(array('bar1' => 'baz1'), $c['foo']);
		$this->assertNull($c->getParameter('foo'));
		$this->assertEquals('baz1', $c->getParameter('foo.bar1'));
	}

	public function testSetParameterNamespacedKey()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('foo1.foo2', 'bar');
		$this->assertEquals('bar', $c['foo1']['foo2']);
		$this->assertEquals('bar', $c->getParameter('foo1.foo2'));
	}

	public function testSetParameterNamespacedKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('foo1.foo2', array('bar1' => 'baz1'));
		$this->assertEquals(array('bar1' => 'baz1'), $c['foo1']['foo2']);
		$this->assertNull($c->getParameter('foo1.foo2'));
		$this->assertEquals('baz1', $c->getParameter('foo1.foo2.bar1'));
	}

	public function testSetParameterMultiNamespacedKey()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('a.b.c', 'bar');
		$this->assertEquals('bar', $c['a']['b']['c']);
		$this->assertEquals('bar', $c->getParameter('a.b.c'));
	}

	public function testSetParameterMultiNamespacedKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c->setParameter('a.b.c', array('bar1' => 'baz1'));
		$this->assertEquals(array('bar1' => 'baz1'), $c['a']['b']['c']);
		$this->assertNull($c->getParameter('a.b.c'));
		$this->assertEquals('baz1', $c->getParameter('a.b.c.bar1'));
	}

	public function testGetParameterSimpleKey()
	{
		$c = new PropulsionConfiguration();
		$c['foo'] = 'bar';
		$this->assertEquals('bar', $c->getParameter('foo'));
	}

	public function testGetParameterSimpleKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c['foo'] = array('bar1' => 'baz1');
		$this->assertNull($c->getParameter('foo'));
		$this->assertEquals('baz1', $c->getParameter('foo.bar1'));
	}

	public function testGetParameterNamespacedKey()
	{
		$c = new PropulsionConfiguration();
		$c['foo1'] = array('foo2' => 'bar');
		$this->assertEquals('bar', $c->getParameter('foo1.foo2'));
	}

	public function testGetParameterNamespacedKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c['foo1'] = array('foo2' => array('bar1' => 'baz1'));
		$this->assertNull($c->getParameter('foo1.foo2'));
		$this->assertEquals('baz1', $c->getParameter('foo1.foo2.bar1'));
	}

	public function testGetParameterMultiNamespacedKey()
	{
		$c = new PropulsionConfiguration();
		$c['a'] = array('b' => array('c' => 'bar'));
		$this->assertEquals('bar', $c->getParameter('a.b.c'));
	}

	public function testGetParameterMultiNamespacedKeyArrayValue()
	{
		$c = new PropulsionConfiguration();
		$c['a'] = array('b' => array('c' => array('bar1' => 'baz1')));
		$this->assertNull($c->getParameter('a.b.c'));
		$this->assertEquals('baz1', $c->getParameter('a.b.c.bar1'));
	}

	/**
	 * @dataProvider configurationProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('configurationProvider')]
	public function testGetParameters($c)
	{
		$expected = array(
			'foo' => 'bar0',
			'foo1' => array(
				'foo2' => 'bar1',
			),
			'a' => array(
				'b' => array(
					'c' => 'bar2',
				)
			)
		);
		$this->assertEquals($expected, $c->getParameters());
	}

	/**
	 * @dataProvider configurationProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('configurationProvider')]
	public function testGetFlattenedParameters($c)
	{
		$expected = array(
			'foo'       => 'bar0',
			'foo1.foo2' => 'bar1',
			'a.b.c'     => 'bar2',
		);
		$this->assertEquals($expected, $c->getFlattenedParameters());
	}
}
