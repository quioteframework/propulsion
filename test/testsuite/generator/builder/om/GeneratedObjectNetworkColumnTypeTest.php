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
 * Tests the generated objects for INET/CIDR/MACADDR/CITEXT columns, which
 * hydrate as plain strings (no rich value object, matching UUID's approach)
 * on this test's platform (SQLite, where all four are emulated as VARCHAR/TEXT).
 */
class GeneratedObjectNetworkColumnTypeTest extends TestCase
{
	public function setUp(): void
	{
		if (!class_exists('NetworkColumnTypeEntity')) {
			$schema = <<<EOF
<database name="generated_object_network_type_test">
	<table name="network_column_type_entity">
		<column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" />
		<column name="ip" type="INET" />
		<column name="subnet" type="CIDR" />
		<column name="mac" type="MACADDR" />
		<column name="label" type="CITEXT" />
	</table>
</database>
EOF;
			PropulsionQuickBuilder::buildSchema($schema);
		}
	}

	public function testSetterAndGetterRoundTrip()
	{
		$e = new NetworkColumnTypeEntity();
		$e->setIp('192.168.1.1');
		$e->setSubnet('10.0.0.0/8');
		$e->setMac('08:00:2b:01:02:03');
		$e->setLabel('MixedCase');

		$this->assertSame('192.168.1.1', $e->getIp());
		$this->assertSame('10.0.0.0/8', $e->getSubnet());
		$this->assertSame('08:00:2b:01:02:03', $e->getMac());
		$this->assertSame('MixedCase', $e->getLabel());
	}

	public function testValueIsPersistedAndRehydrated()
	{
		$e = new NetworkColumnTypeEntity();
		$e->setIp('::1');
		$e->save();
		$id = $e->getId();
		NetworkColumnTypeEntityPeer::clearInstancePool();

		$found = NetworkColumnTypeEntityQuery::create()->findPk($id);
		$this->assertSame('::1', $found->getIp());
	}
}
