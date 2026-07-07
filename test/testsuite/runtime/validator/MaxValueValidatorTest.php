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
 * Test class for MaxValueValidator.
 */
class MaxValueValidatorTest extends TestCase
{
	protected $validator;
	protected $map;

	protected function setUp(): void
	{
		$dmap = new DatabaseMap('foodb');
		$tmap = new TableMap('foo', $dmap);
		$cmap = new ColumnMap('bar', $tmap);
		$this->map = new ValidatorMap($cmap);
		$this->map->setValue('10');
		$this->validator = new MaxValueValidator();
	}

	public function testIsValidReturnsTrueWhenValueIsBelowMaximum()
	{
		$this->assertTrue($this->validator->isValid($this->map, '5'));
	}

	public function testIsValidReturnsTrueWhenValueEqualsMaximum()
	{
		$this->assertTrue($this->validator->isValid($this->map, '10'));
	}

	public function testIsValidReturnsFalseWhenValueIsAboveMaximum()
	{
		$this->assertFalse($this->validator->isValid($this->map, '15'));
	}

	public function testIsValidReturnsFalseWhenValueIsNull()
	{
		$this->assertFalse($this->validator->isValid($this->map, null));
	}

	public function testIsValidReturnsFalseWhenValueIsNotNumeric()
	{
		$this->assertFalse($this->validator->isValid($this->map, 'not-a-number'));
	}
}
