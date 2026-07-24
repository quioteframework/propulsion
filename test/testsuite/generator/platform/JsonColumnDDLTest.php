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
 * Tests the per-platform DDL generated for JSON/JSONB columns: native JSON/JSONB
 * on PostgreSQL, native JSON on MySQL, and a text/CLOB fallback everywhere else
 * (SQLite, MSSQL, Oracle) that has no dedicated JSON column type.
 */
class JsonColumnDDLTest extends TestCase
{
	protected function columnDDLFor(PropulsionPlatformInterface $platform, string $propulsionType): string
	{
		$column = new Column('foo');
		$column->getDomain()->copy($platform->getDomainForType($propulsionType));
		return $platform->getColumnDDL($column);
	}

	public function testPgsqlJsonAndJsonbAreNative()
	{
		$platform = new PgsqlPlatform();
		$this->assertSame('JSON', $platform->getDomainForType(PropulsionTypes::JSON)->getSqlType());
		$this->assertSame('JSONB', $platform->getDomainForType(PropulsionTypes::JSONB)->getSqlType());
		$this->assertSame('"foo" JSON', $this->columnDDLFor($platform, PropulsionTypes::JSON));
		$this->assertSame('"foo" JSONB', $this->columnDDLFor($platform, PropulsionTypes::JSONB));
	}

	public function testMysqlJsonAndJsonbBothMapToJson()
	{
		$platform = new MysqlPlatform();
		$this->assertSame('JSON', $platform->getDomainForType(PropulsionTypes::JSON)->getSqlType());
		$this->assertSame('JSON', $platform->getDomainForType(PropulsionTypes::JSONB)->getSqlType());
	}

	public function testSqliteJsonAndJsonbFallBackToText()
	{
		$platform = new SqlitePlatform();
		$this->assertSame('TEXT', $platform->getDomainForType(PropulsionTypes::JSON)->getSqlType());
		$this->assertSame('TEXT', $platform->getDomainForType(PropulsionTypes::JSONB)->getSqlType());
	}

	public function testMssqlJsonAndJsonbFallBackToVarcharMax()
	{
		$platform = new MssqlPlatform();
		$this->assertSame('VARCHAR(MAX)', $platform->getDomainForType(PropulsionTypes::JSON)->getSqlType());
		$this->assertSame('VARCHAR(MAX)', $platform->getDomainForType(PropulsionTypes::JSONB)->getSqlType());
	}

	public function testOracleJsonAndJsonbFallBackToClob()
	{
		$platform = new OraclePlatform();
		$this->assertSame('CLOB', $platform->getDomainForType(PropulsionTypes::JSON)->getSqlType());
		$this->assertSame('CLOB', $platform->getDomainForType(PropulsionTypes::JSONB)->getSqlType());
	}
}
