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
 * Tests the per-platform DDL generated for Postgres network address types
 * (INET/CIDR/MACADDR) and CITEXT: native on Postgres (CITEXT additionally
 * emitting `CREATE EXTENSION IF NOT EXISTS citext`), emulated as a sized
 * VARCHAR/TEXT everywhere else.
 */
class NetworkColumnDDLTest extends TestCase
{
	protected function columnDDLFor(PropulsionPlatformInterface $platform, string $propulsionType): string
	{
		$column = new Column('foo');
		$column->getDomain()->copy($platform->getDomainForType($propulsionType));
		return $platform->getColumnDDL($column);
	}

	public function testPgsqlNetworkTypesAreNative()
	{
		$platform = new PgsqlPlatform();
		$this->assertSame('INET', $platform->getDomainForType(PropulsionTypes::INET)->getSqlType());
		$this->assertSame('CIDR', $platform->getDomainForType(PropulsionTypes::CIDR)->getSqlType());
		$this->assertSame('MACADDR', $platform->getDomainForType(PropulsionTypes::MACADDR)->getSqlType());
		$this->assertSame('CITEXT', $platform->getDomainForType(PropulsionTypes::CITEXT)->getSqlType());
	}

	public function testPgsqlEmitsCreateExtensionOnlyWhenCitextIsUsed()
	{
		$platform = new PgsqlPlatform();

		$withCitext = new Table('widget');
		$withCitext->setIdMethod('none');
		$col = new Column('name');
		$col->setType(PropulsionTypes::CITEXT);
		$withCitext->addColumn($col);
		$this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS citext;', $platform->getAddExtensionsDDL($withCitext));

		$withoutCitext = new Table('gadget');
		$withoutCitext->setIdMethod('none');
		$col2 = new Column('name');
		$col2->setType(PropulsionTypes::VARCHAR);
		$withoutCitext->addColumn($col2);
		$this->assertSame('', $platform->getAddExtensionsDDL($withoutCitext));
	}

	public function testMysqlEmulatesAsVarchar()
	{
		$platform = new MysqlPlatform();
		$this->assertSame('VARCHAR', $platform->getDomainForType(PropulsionTypes::INET)->getSqlType());
		$this->assertSame(43, $platform->getDomainForType(PropulsionTypes::INET)->getSize());
		$this->assertSame(17, $platform->getDomainForType(PropulsionTypes::MACADDR)->getSize());
		$this->assertSame('TEXT', $platform->getDomainForType(PropulsionTypes::CITEXT)->getSqlType());
	}

	public function testSqliteEmulatesAsVarchar()
	{
		$platform = new SqlitePlatform();
		$this->assertSame('VARCHAR', $platform->getDomainForType(PropulsionTypes::CIDR)->getSqlType());
	}

	public function testMssqlEmulatesAsVarchar()
	{
		$platform = new MssqlPlatform();
		$this->assertSame('VARCHAR', $platform->getDomainForType(PropulsionTypes::MACADDR)->getSqlType());
	}

	public function testOracleEmulatesAsVarchar2()
	{
		$platform = new OraclePlatform();
		$this->assertSame('VARCHAR2', $platform->getDomainForType(PropulsionTypes::INET)->getSqlType());
	}
}
