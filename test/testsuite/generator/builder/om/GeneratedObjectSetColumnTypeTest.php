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
 * Tests the generated objects for a SET column end to end
 * (accessor/mutator/tester/adder/remover/hydrate/buildCriteria/persistence)
 * against this test's default platform (SQLite), where SET is emulated as
 * comma-joined text -- the same wire format MySQL's real native SET column
 * uses, so no platform-specific test is needed (see MysqlPlatformTest for
 * the native `SET(...)` DDL shape itself).
 */
class GeneratedObjectSetColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('SetColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_set_type_test">
	<table name="set_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="roles" type="SET" valueSet="admin, editor, viewer" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testActiveRecordMethods()
	{
		$this->assertTrue(method_exists('SetColumnTypeEntity', 'getRoles'));
		$this->assertTrue(method_exists('SetColumnTypeEntity', 'hasRole'));
		$this->assertTrue(method_exists('SetColumnTypeEntity', 'setRoles'));
		$this->assertTrue(method_exists('SetColumnTypeEntity', 'addRole'));
		$this->assertTrue(method_exists('SetColumnTypeEntity', 'removeRole'));
	}

	public function testGetterDefaultValue()
	{
		$e = new SetColumnTypeEntity();
		$this->assertSame([], $e->getRoles());
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new SetColumnTypeEntity();
		$e->setRoles(['admin', 'editor']);
		$this->assertSame(['admin', 'editor'], $e->getRoles());
	}

	public function testTesterAdderRemover()
	{
		$e = new SetColumnTypeEntity();
		$this->assertFalse($e->hasRole('admin'));
		$e->addRole('admin');
		$this->assertTrue($e->hasRole('admin'));
		$e->addRole('editor');
		$this->assertSame(['admin', 'editor'], $e->getRoles());
		$e->removeRole('admin');
		$this->assertSame(['editor'], $e->getRoles());
	}

	public function testValueIsPersistedAndRehydratedAsArray()
	{
		$e = new SetColumnTypeEntity();
		$e->setRoles(['admin', 'viewer']);
		$e->save();
		$id = $e->getId();
		SetColumnTypeEntityPeer::clearInstancePool();

		$found = SetColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame(['admin', 'viewer'], $found->getRoles());
	}

	public function testExplicitEmptySetIsPersistedAsEmptyArray()
	{
		$e = new SetColumnTypeEntity();
		$e->setRoles(['admin']);
		$e->setRoles([]);
		$e->save();
		$id = $e->getId();
		SetColumnTypeEntityPeer::clearInstancePool();

		$found = SetColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame([], $found->getRoles());
	}
}
