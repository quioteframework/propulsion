<?php

use PHPUnit\Framework\TestCase;
use Propulsion\Type\Range;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the generated objects for a range column (INT4RANGE), which
 * hydrates to/from a real Propulsion\Type\Range instance, stored as a range
 * literal string (emulated as VARCHAR on this test's platform, SQLite).
 */
class GeneratedObjectRangeColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('RangeColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_range_type_test">
	<table name="range_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="span" type="INT4RANGE" />
		<column name="span_with_default" type="INT4RANGE" defaultValue="[1,10)" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterReturnsNullByDefault()
	{
		$e = new RangeColumnTypeEntity();
		$this->assertNull($e->getSpan());
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new RangeColumnTypeEntity();
		$range = Range::parse('[3,7)');
		$e->setSpan($range);
		$this->assertSame($range, $e->getSpan());
	}

	public function testDefaultValueIsARangeInstance()
	{
		$e = new RangeColumnTypeEntity();
		$default = $e->getSpanWithDefault();
		$this->assertInstanceOf(Range::class, $default);
		$this->assertSame('1', $default->getLower());
		$this->assertSame('10', $default->getUpper());
		$this->assertTrue($default->isLowerInclusive());
		$this->assertFalse($default->isUpperInclusive());
	}

	public function testValueIsPersistedAndRehydratedAsRangeInstance()
	{
		$e = new RangeColumnTypeEntity();
		$e->setSpan(Range::parse('[5,15]'));
		$e->save();
		$id = $e->getId();
		RangeColumnTypeEntityPeer::clearInstancePool();

		$found = RangeColumnTypeEntityQuery::create()->findPk($id);
		$rehydrated = $found->getSpan();
		$this->assertInstanceOf(Range::class, $rehydrated);
		$this->assertSame('5', $rehydrated->getLower());
		$this->assertSame('15', $rehydrated->getUpper());
		$this->assertTrue($rehydrated->isUpperInclusive());
	}

	public function testValueIsCopied()
	{
		$e1 = new RangeColumnTypeEntity();
		$e1->setSpan(Range::parse('[0,1)'));
		$e2 = new RangeColumnTypeEntity();
		$e1->copyInto($e2);
		$this->assertSame('0', $e2->getSpan()->getLower());
	}
}
