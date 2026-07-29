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
 * Tests the per-platform DDL generated for GEOMETRY columns. Deliberately
 * emulated as plain text (storing WKT, "well-known text") on every platform,
 * not each platform's real native geometry column type (PostGIS `geometry`,
 * MySQL `GEOMETRY`, MSSQL `geometry`, Oracle `SDO_GEOMETRY`) -- none of those
 * accept/return raw WKT text through a plain parameterized bind, so a
 * genuinely native mapping needs query-layer (BasePeer/Criteria) support
 * this pass doesn't add. See PropulsionTypes::GEOMETRY_NATIVE_TYPE.
 */
class GeometryColumnDDLTest extends TestCase
{
	public function testPgsqlEmulatesAsText()
	{
		$platform = new PgsqlPlatform();
		$this->assertSame('TEXT', $platform->getDomainForType(PropulsionTypes::GEOMETRY)->getSqlType());
	}

	public function testMysqlEmulatesAsText()
	{
		$platform = new MysqlPlatform();
		$this->assertSame('TEXT', $platform->getDomainForType(PropulsionTypes::GEOMETRY)->getSqlType());
	}

	public function testSqliteEmulatesAsText()
	{
		$platform = new SqlitePlatform();
		$this->assertSame('MEDIUMTEXT', $platform->getDomainForType(PropulsionTypes::GEOMETRY)->getSqlType());
	}

	public function testMssqlEmulatesAsText()
	{
		$platform = new MssqlPlatform();
		$this->assertSame('VARCHAR(MAX)', $platform->getDomainForType(PropulsionTypes::GEOMETRY)->getSqlType());
	}

	public function testOracleEmulatesAsClob()
	{
		$platform = new OraclePlatform();
		$this->assertSame('CLOB', $platform->getDomainForType(PropulsionTypes::GEOMETRY)->getSqlType());
	}
}
