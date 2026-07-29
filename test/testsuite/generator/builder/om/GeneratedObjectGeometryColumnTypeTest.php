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
 * Tests the generated objects for a GEOMETRY column, which hydrates as a
 * plain WKT string (no rich value object, matching UUID/network types'
 * approach) on this test's platform (SQLite, emulated as MEDIUMTEXT).
 */
class GeneratedObjectGeometryColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('GeometryColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_geometry_type_test">
	<table name="geometry_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="location" type="GEOMETRY" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new GeometryColumnTypeEntity();
		$e->setLocation('POINT(1 2)');
		$this->assertSame('POINT(1 2)', $e->getLocation());
	}

	public function testValueIsPersistedAndRehydrated()
	{
		$e = new GeometryColumnTypeEntity();
		$e->setLocation('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
		$e->save();
		$id = $e->getId();
		GeometryColumnTypeEntityPeer::clearInstancePool();

		$found = GeometryColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))', $found->getLocation());
	}
}
