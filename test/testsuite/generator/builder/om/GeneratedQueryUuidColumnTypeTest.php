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
 * Tests the generated queries for UUID column types filters (filterBy<Column>()).
 */
class GeneratedQueryUuidColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('ComplexColumnTypeEntity21')) {
			$schema = <<<EOF
<database name="generated_object_complex_type_test_21">
	<table name="complex_column_type_entity_21">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="uuid" type="UUID" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
			$e0 = new ComplexColumnTypeEntity21();
			$e0->setUuid('550e8400-e29b-41d4-a716-446655440000');
			$e0->save();
			$e1 = new ComplexColumnTypeEntity21();
			$e1->setUuid('123e4567-e89b-12d3-a456-426614174000');
			$e1->save();
			$e2 = new ComplexColumnTypeEntity21();
			$e2->save();
			ComplexColumnTypeEntity21Peer::clearInstancePool();
		}
	}

	public function testFilterByColumnExactMatch()
	{
		$e = ComplexColumnTypeEntity21Query::create()
			->filterByUuid('550e8400-e29b-41d4-a716-446655440000')
			->findOne();
		$this->assertNotNull($e);
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $e->getUuid());
	}

	public function testFilterByColumnNoMatch()
	{
		$e = ComplexColumnTypeEntity21Query::create()
			->filterByUuid('ffffffff-ffff-ffff-ffff-ffffffffffff')
			->findOne();
		$this->assertNull($e);
	}

	public function testFilterByColumnArrayUsesIn()
	{
		$nb = ComplexColumnTypeEntity21Query::create()
			->filterByUuid(array('550e8400-e29b-41d4-a716-446655440000', '123e4567-e89b-12d3-a456-426614174000'))
			->count();
		$this->assertEquals(2, $nb);
	}

	public function testFilterByColumnNotEqual()
	{
		// SQL's three-valued logic means "<> 'x'" never matches a NULL uuid row,
		// so this only ever matches the one populated, non-equal row.
		$e = ComplexColumnTypeEntity21Query::create()
			->filterByUuid('550e8400-e29b-41d4-a716-446655440000', Criteria::NOT_EQUAL)
			->findOne();
		$this->assertEquals('123e4567-e89b-12d3-a456-426614174000', $e->getUuid());
	}
}
