<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

enum NativeEnumColumnTypeStatus: string
{
	case Pending = 'pending';
	case Active = 'active';
	case Archived = 'archived';
}

/**
 * Tests the generated objects for `nativeEnum="true"` ENUM columns end to
 * end (accessor/mutator/hydrate/buildCriteria/persistence), with and without
 * a paired `enumClass`, against SQLite's CHECK-constraint-backed native
 * storage (see NativeEnumColumnDDLTest for the DDL shape itself).
 */
class GeneratedObjectNativeEnumColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('NativeEnumColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_native_enum_type_test">
	<table name="native_enum_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="status" type="ENUM" valueSet="pending, active, archived" nativeEnum="true" />
		<column name="status_enum_class" type="ENUM" enumClass="NativeEnumColumnTypeStatus" nativeEnum="true" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testLabelBasedAccessorMutatorRoundTrip()
	{
		$e = new NativeEnumColumnTypeEntity();
		$this->assertNull($e->getStatus());
		$e->setStatus('archived');
		$this->assertSame('archived', $e->getStatus());
	}

	public function testLabelBasedValueIsPersistedAsTheLabelItself()
	{
		$e = new NativeEnumColumnTypeEntity();
		$e->setStatus('active');
		$e->save();
		$id = $e->getId();
		NativeEnumColumnTypeEntityPeer::clearInstancePool();

		$found = NativeEnumColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame('active', $found->getStatus());
	}

	public function testEnumClassAccessorMutatorRoundTrip()
	{
		$e = new NativeEnumColumnTypeEntity();
		$this->assertNull($e->getStatusEnumClass());
		$e->setStatusEnumClass(NativeEnumColumnTypeStatus::Pending);
		$this->assertSame(NativeEnumColumnTypeStatus::Pending, $e->getStatusEnumClass());
	}

	public function testEnumClassValueIsPersistedAndRehydratedAsEnumInstance()
	{
		$e = new NativeEnumColumnTypeEntity();
		$e->setStatusEnumClass(NativeEnumColumnTypeStatus::Archived);
		$e->save();
		$id = $e->getId();
		NativeEnumColumnTypeEntityPeer::clearInstancePool();

		$found = NativeEnumColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame(NativeEnumColumnTypeStatus::Archived, $found->getStatusEnumClass());
	}
}
