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
 * Tests the generated objects for an INTERVAL column, which hydrates to/from
 * a real `DateInterval` instance, stored as an ISO-8601 duration string
 * (emulated as VARCHAR on this test's platform, SQLite).
 */
class GeneratedObjectIntervalColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('IntervalColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_interval_type_test">
	<table name="interval_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="duration" type="INTERVAL" />
		<column name="duration_with_default" type="INTERVAL" defaultValue="P1DT2H" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterReturnsNullByDefault()
	{
		$e = new IntervalColumnTypeEntity();
		$this->assertNull($e->getDuration());
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new IntervalColumnTypeEntity();
		$interval = new DateInterval('P2DT3H4M5S');
		$e->setDuration($interval);
		$this->assertSame($interval, $e->getDuration());
	}

	public function testDefaultValueIsADateIntervalInstance()
	{
		$e = new IntervalColumnTypeEntity();
		$default = $e->getDurationWithDefault();
		$this->assertInstanceOf(DateInterval::class, $default);
		$this->assertSame(1, $default->d);
		$this->assertSame(2, $default->h);
	}

	public function testValueIsPersistedAndRehydratedAsDateIntervalInstance()
	{
		$e = new IntervalColumnTypeEntity();
		$e->setDuration(new DateInterval('P3DT4H5M6S'));
		$e->save();
		$id = $e->getId();
		IntervalColumnTypeEntityPeer::clearInstancePool();

		$found = IntervalColumnTypeEntityQuery::create()->findPk($id);
		$rehydrated = $found->getDuration();
		$this->assertInstanceOf(DateInterval::class, $rehydrated);
		$this->assertSame(3, $rehydrated->d);
		$this->assertSame(4, $rehydrated->h);
		$this->assertSame(5, $rehydrated->i);
		$this->assertSame(6, $rehydrated->s);
	}

	public function testValueIsCopied()
	{
		$e1 = new IntervalColumnTypeEntity();
		$e1->setDuration(new DateInterval('P1D'));
		$e2 = new IntervalColumnTypeEntity();
		$e1->copyInto($e2);
		$this->assertSame(1, $e2->getDuration()->d);
	}
}
