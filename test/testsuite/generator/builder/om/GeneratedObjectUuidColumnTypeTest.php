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
 * Tests the generated objects for UUID column types (accessor, mutator, format
 * validation and persistence round-trip).
 */
class GeneratedObjectUuidColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('ComplexColumnTypeEntity22')) {
			$schema = <<<EOF
<database name="generated_object_complex_type_test_22">
	<table name="complex_column_type_entity_22">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="uuid" type="UUID" />
		<column name="uuid_required" type="UUID" required="true" />
		<column name="uuid_default" type="UUID" defaultValue="550e8400-e29b-41d4-a716-446655440000" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterAndSetterExist()
	{
		$this->assertTrue(method_exists('ComplexColumnTypeEntity22', 'getUuid'));
		$this->assertTrue(method_exists('ComplexColumnTypeEntity22', 'setUuid'));
	}

	public function testDefaultValueIsNull()
	{
		$e = new ComplexColumnTypeEntity22();
		$this->assertNull($e->getUuid());
	}

	public function testSetterAcceptsWellFormedUuid()
	{
		$e = new ComplexColumnTypeEntity22();
		$e->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $e->getUuid());
	}

	public function testSetterNormalizesCaseToLowercase()
	{
		$e = new ComplexColumnTypeEntity22();
		$e->setUuid('550E8400-E29B-41D4-A716-446655440000');
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $e->getUuid());
	}

	public function testSetterAcceptsNull()
	{
		$e = new ComplexColumnTypeEntity22();
		$e->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$e->setUuid(null);
		$this->assertNull($e->getUuid());
	}

	public function testSetterFluentInterface()
	{
		$e = new ComplexColumnTypeEntity22();
		$this->assertSame($e, $e->setUuid('550e8400-e29b-41d4-a716-446655440000'));
	}

	public function testDefaultValueIsAppliedAndValid()
	{
		$e = new ComplexColumnTypeEntity22();
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $e->getUuidDefault());
	}

	/**
	 * @dataProvider malformedUuidProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('malformedUuidProvider')]
	public function testSetterRejectsMalformedUuid(string $malformed)
	{
		$this->expectException(PropulsionException::class);
		$e = new ComplexColumnTypeEntity22();
		$e->setUuid($malformed);
	}

	public static function malformedUuidProvider(): array
	{
		return array(
			'missing hyphens' => array('550e8400e29b41d4a716446655440000'),
			'too short' => array('550e8400-e29b-41d4-a716-44665544000'),
			'too long' => array('550e8400-e29b-41d4-a716-4466554400000'),
			'non-hex characters' => array('550e8400-e29b-41d4-a716-44665544000g'),
			'wrong grouping' => array('550e8400e-29b-41d4-a716-446655440000'),
			'empty string' => array(''),
			'random text' => array('not-a-uuid-at-all'),
		);
	}

	public function testValueIsPersisted()
	{
		ComplexColumnTypeEntity22Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity22();
		$e->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$e->setUuidRequired('123e4567-e89b-12d3-a456-426614174000');
		$e->save();
		ComplexColumnTypeEntity22Peer::clearInstancePool();
		$found = ComplexColumnTypeEntity22Query::create()->orderById(Criteria::DESC)->findOne();
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $found->getUuid());
		$this->assertEquals('123e4567-e89b-12d3-a456-426614174000', $found->getUuidRequired());
	}

	public function testNullValueIsPersisted()
	{
		ComplexColumnTypeEntity22Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity22();
		$e->setUuidRequired('123e4567-e89b-12d3-a456-426614174000');
		$e->save();
		ComplexColumnTypeEntity22Peer::clearInstancePool();
		$found = ComplexColumnTypeEntity22Query::create()->orderById(Criteria::DESC)->findOne();
		$this->assertNull($found->getUuid());
	}

	public function testValueIsCopied()
	{
		$e1 = new ComplexColumnTypeEntity22();
		$e1->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$e2 = new ComplexColumnTypeEntity22();
		$e1->copyInto($e2);
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $e2->getUuid());
	}
}
