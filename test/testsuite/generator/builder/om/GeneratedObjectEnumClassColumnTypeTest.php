<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

enum EnumColumnTypeStatus: string
{
	case Active = 'active';
	case Inactive = 'inactive';
	case Archived = 'archived';
}

/**
 * Tests the generated objects for an ENUM column declaring `enumClass`,
 * which hydrates to/from a backed PHP enum instead of a plain string label.
 */
class GeneratedObjectEnumClassColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('EnumClassColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_enum_class_type_test">
	<table name="enum_class_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="status" type="ENUM" enumClass="EnumColumnTypeStatus" />
		<column name="status_with_default" type="ENUM" enumClass="EnumColumnTypeStatus" defaultValue="active" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testGetterReturnsEnumInstance()
	{
		$this->assertTrue(method_exists('EnumClassColumnTypeEntity', 'getStatus'));
		$e = new EnumClassColumnTypeEntity();
		$this->assertNull($e->getStatus());
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new EnumClassColumnTypeEntity();
		$e->setStatus(EnumColumnTypeStatus::Archived);
		$this->assertSame(EnumColumnTypeStatus::Archived, $e->getStatus());
	}

	public function testSetterAcceptsNull()
	{
		$e = new EnumClassColumnTypeEntity();
		$e->setStatus(EnumColumnTypeStatus::Active);
		$e->setStatus(null);
		$this->assertNull($e->getStatus());
	}

	public function testDefaultValueIsAnEnumInstance()
	{
		$e = new EnumClassColumnTypeEntity();
		$this->assertSame(EnumColumnTypeStatus::Active, $e->getStatusWithDefault());
	}

	public function testValueIsPersistedAndRehydratedAsEnumInstance()
	{
		$e = new EnumClassColumnTypeEntity();
		$e->setStatus(EnumColumnTypeStatus::Inactive);
		$e->save();
		EnumClassColumnTypeEntityPeer::clearInstancePool();
		$found = EnumClassColumnTypeEntityQuery::create()->findOne();
		$this->assertSame(EnumColumnTypeStatus::Inactive, $found->getStatus());
	}

	public function testValueIsCopied()
	{
		$e1 = new EnumClassColumnTypeEntity();
		$e1->setStatus(EnumColumnTypeStatus::Archived);
		$e2 = new EnumClassColumnTypeEntity();
		$e1->copyInto($e2);
		$this->assertSame(EnumColumnTypeStatus::Archived, $e2->getStatus());
	}
}
