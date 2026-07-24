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
 * Tests the generated objects for JSON/JSONB column types: accessor/mutator
 * behavior, hydrate()/save() round-tripping through real json_encode()/
 * json_decode(), and the error handling around malformed JSON.
 */
class GeneratedObjectJsonColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('ComplexColumnTypeEntity20')) {
			$schema = <<<EOF
<database name="generated_object_complex_type_test_20">
	<table name="complex_column_type_entity_20">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="data" type="JSON" />
		<column name="data_binary" type="JSONB" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterDefaultValue()
	{
		$e = new ComplexColumnTypeEntity20();
		$this->assertNull($e->getData(), 'JSON columns default to null');
		$this->assertNull($e->getDataBinary(), 'JSONB columns default to null');
	}

	public function testSetterAndGetterRoundTripArray()
	{
		$e = new ComplexColumnTypeEntity20();
		$value = array('foo' => 'bar', 'count' => 3, 'nested' => array(1, 2, 3));
		$e->setData($value);
		$this->assertEquals($value, $e->getData(), 'JSON columns can store PHP arrays in memory');
	}

	public function testSetterAndGetterRoundTripScalarAndNull()
	{
		$e = new ComplexColumnTypeEntity20();
		$e->setData('a plain string');
		$this->assertSame('a plain string', $e->getData());
		$e->setData(42);
		$this->assertSame(42, $e->getData());
		$e->setData(null);
		$this->assertNull($e->getData());
	}

	public function testValueIsPersistedAndHydratedArray()
	{
		ComplexColumnTypeEntity20Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity20();
		$value = array('foo' => 'bar', 'list' => array('a', 'b'));
		$e->setData($value);
		$e->setDataBinary(array('jsonb' => true));
		$e->save();
		ComplexColumnTypeEntity20Peer::clearInstancePool();

		$found = ComplexColumnTypeEntity20Query::create()->findOne();
		$this->assertEquals($value, $found->getData(), 'JSON columns are persisted and re-hydrated correctly (array)');
		$this->assertEquals(array('jsonb' => true), $found->getDataBinary(), 'JSONB columns are persisted and re-hydrated correctly (array)');
	}

	public function testValueIsPersistedAndHydratedNull()
	{
		ComplexColumnTypeEntity20Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity20();
		$e->setData(null);
		$e->save();
		ComplexColumnTypeEntity20Peer::clearInstancePool();

		$found = ComplexColumnTypeEntity20Query::create()->findOne();
		$this->assertNull($found->getData(), 'a null JSON value round-trips as null, not the string "null"');
	}

	public function testValueIsPersistedAndHydratedScalar()
	{
		ComplexColumnTypeEntity20Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity20();
		$e->setData(123);
		$e->save();
		ComplexColumnTypeEntity20Peer::clearInstancePool();

		$found = ComplexColumnTypeEntity20Query::create()->findOne();
		$this->assertSame(123, $found->getData(), 'a JSON scalar round-trips as the same PHP scalar type');
	}

	public function testMalformedJsonRaisesClearExceptionOnHydrate()
	{
		ComplexColumnTypeEntity20Query::create()->deleteAll();
		$e = new ComplexColumnTypeEntity20();
		$e->setData(array('valid' => true));
		$e->save();
		$id = $e->getId();
		ComplexColumnTypeEntity20Peer::clearInstancePool();

		// Bypass the mutator/json_encode() entirely and write malformed JSON
		// straight into the column, simulating data corrupted (or hand-edited)
		// outside of Propulsion, to confirm hydrate() surfaces this loudly
		// instead of silently returning null or corrupting the object.
		$con = Propulsion::getConnection('generated_object_complex_type_test_20');
		$con->exec("UPDATE complex_column_type_entity_20 SET data = '{not valid json' WHERE id = $id");

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessageMatches('/data/');
		ComplexColumnTypeEntity20Query::create()->findPk($id);
	}
}
