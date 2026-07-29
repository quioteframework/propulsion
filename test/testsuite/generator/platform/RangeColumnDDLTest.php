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
 * Tests the per-platform DDL generated for Postgres range types
 * (int4range/int8range/numrange/daterange/tsrange/tstzrange): native on
 * Postgres, emulated as a VARCHAR storing the range literal text elsewhere.
 */
class RangeColumnDDLTest extends TestCase
{
	public function testPgsqlRangeTypesAreNative()
	{
		$platform = new PgsqlPlatform();
		$this->assertSame('INT4RANGE', $platform->getDomainForType(PropulsionTypes::INT4RANGE)->getSqlType());
		$this->assertSame('INT8RANGE', $platform->getDomainForType(PropulsionTypes::INT8RANGE)->getSqlType());
		$this->assertSame('NUMRANGE', $platform->getDomainForType(PropulsionTypes::NUMRANGE)->getSqlType());
		$this->assertSame('DATERANGE', $platform->getDomainForType(PropulsionTypes::DATERANGE)->getSqlType());
		$this->assertSame('TSRANGE', $platform->getDomainForType(PropulsionTypes::TSRANGE)->getSqlType());
		$this->assertSame('TSTZRANGE', $platform->getDomainForType(PropulsionTypes::TSTZRANGE)->getSqlType());
	}

	public function testMysqlEmulatesAsVarchar()
	{
		$platform = new MysqlPlatform();
		$domain = $platform->getDomainForType(PropulsionTypes::INT4RANGE);
		$this->assertSame('VARCHAR', $domain->getSqlType());
		$this->assertSame(64, $domain->getSize());
	}

	public function testSqliteEmulatesAsVarchar()
	{
		$platform = new SqlitePlatform();
		$this->assertSame('VARCHAR', $platform->getDomainForType(PropulsionTypes::TSTZRANGE)->getSqlType());
	}

	public function testMssqlEmulatesAsVarchar()
	{
		$platform = new MssqlPlatform();
		$this->assertSame('VARCHAR', $platform->getDomainForType(PropulsionTypes::DATERANGE)->getSqlType());
	}

	public function testOracleEmulatesAsVarchar2()
	{
		$platform = new OraclePlatform();
		$this->assertSame('VARCHAR2', $platform->getDomainForType(PropulsionTypes::NUMRANGE)->getSqlType());
	}
}
