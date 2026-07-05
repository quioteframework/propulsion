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
 * Test for PropulsionException class
 *
 * @author     Francois Zaninotto
 */
class PropulsionExceptionTest extends TestCase
{
	public function testSimpleConstructor()
	{
		$e = new PropulsionException('this is an error');
		$this->assertTrue($e instanceof Exception);
		$this->assertEquals('this is an error', $e->getMessage());
	}

	public function testExceptionConstructor()
	{
		$e1 = new FooException('real cause');
		$e = new PropulsionException($e1);
		$this->assertEquals(' [wrapped: real cause]', $e->getMessage());
	}

	public function testCompositeConstructor()
	{
		$e1 = new FooException('real cause');
		$e = new PropulsionException('this is an error', $e1);
		$this->assertEquals('this is an error [wrapped: real cause]', $e->getMessage());
	}

	/**
	 * @expectedException PropulsionException
	 */
	public function testIsThrowable()
	{
		$this->expectException(PropulsionException::class);
		$e = new PropulsionException('this is an error');
		throw $e;
	}

	public function testGetCause()
	{
		$e1 = new FooException('real cause');
		$e = new PropulsionException('this is an error', $e1);
		$this->assertEquals($e1, $e->getCause());
	}

	public function testGetPrevious()
	{
		if (version_compare(PHP_VERSION, '5.3.0') < 0) {
			$this->markTestSkipped();
		}
		$e1 = new FooException('real cause');
		$e = new PropulsionException('this is an error', $e1);
		$this->assertEquals($e1, $e->getPrevious());
	}
}

class FooException extends Exception {}
