<?php

use PHPUnit\Framework\TestCase;
use Propulsion\Type\PgArray;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

class PgArrayTest extends TestCase
{
	public function testEncodeSimpleValues()
	{
		$this->assertSame('{a,b,c}', PgArray::encode(['a', 'b', 'c']));
	}

	public function testEncodeEmptyArray()
	{
		$this->assertSame('{}', PgArray::encode([]));
	}

	public function testEncodeQuotesValuesNeedingEscaping()
	{
		$this->assertSame('{"a,b",c}', PgArray::encode(['a,b', 'c']));
		$this->assertSame('{"say \\"hi\\""}', PgArray::encode(['say "hi"']));
		$this->assertSame('{"back\\\\slash"}', PgArray::encode(['back\\slash']));
		$this->assertSame('{""}', PgArray::encode(['']));
		$this->assertSame('{"NULL"}', PgArray::encode(['NULL']));
	}

	public function testEncodeNullElement()
	{
		$this->assertSame('{a,NULL,b}', PgArray::encode(['a', null, 'b']));
	}

	public function testEncodeNumbersAndBooleans()
	{
		$this->assertSame('{1,2.5,t,f}', PgArray::encode([1, 2.5, true, false]));
	}

	public function testDecodeSimpleValues()
	{
		$this->assertSame(['a', 'b', 'c'], PgArray::decode('{a,b,c}'));
	}

	public function testDecodeEmptyArray()
	{
		$this->assertSame([], PgArray::decode('{}'));
	}

	public function testDecodeQuotedValues()
	{
		$this->assertSame(['a,b', 'c'], PgArray::decode('{"a,b",c}'));
		$this->assertSame(['say "hi"'], PgArray::decode('{"say \\"hi\\""}'));
		$this->assertSame(['back\\slash'], PgArray::decode('{"back\\\\slash"}'));
		$this->assertSame([''], PgArray::decode('{""}'));
	}

	public function testDecodeDistinguishesQuotedNullStringFromNullLiteral()
	{
		$this->assertSame([null], PgArray::decode('{NULL}'));
		$this->assertSame(['NULL'], PgArray::decode('{"NULL"}'));
	}

	public function testDecodeRejectsMalformedLiteral()
	{
		$this->expectException(\InvalidArgumentException::class);
		PgArray::decode('not an array');
	}

	public function testRoundTrip()
	{
		$values = ['plain', 'has,comma', 'has"quote', 'has\\backslash', '', null, 'NULL'];
		$this->assertSame($values, PgArray::decode(PgArray::encode($values)));
	}
}
