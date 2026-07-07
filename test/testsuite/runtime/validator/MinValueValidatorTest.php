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
 * Test class for MinValueValidator.
 */
class MinValueValidatorTest extends TestCase
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
		$this->validator = new MinValueValidator();
	}

	public function testIsValidReturnsTrueWhenValueIsAboveMinimum()
	{
		$this->assertTrue($this->validator->isValid($this->map, '15'));
	}

	public function testIsValidReturnsTrueWhenValueEqualsMinimum()
	{
		$this->assertTrue($this->validator->isValid($this->map, '10'));
	}

	public function testIsValidReturnsFalseWhenValueIsBelowMinimum()
	{
		$this->assertFalse($this->validator->isValid($this->map, '5'));
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
