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
 * Tests the per-platform DDL generated for VECTOR columns: native `vector(n)`
 * on Postgres (pgvector, plus a `CREATE EXTENSION` for it); emulated as
 * unbounded text everywhere else, including MySQL/MariaDB -- confirmed live
 * against a real MariaDB 11.8 server that its own native `VECTOR` type
 * rejects a plain bound/literal bracket-JSON string outright ("Incorrect
 * vector value"), the same "needs SQL-level wrapping a plain bind can't
 * provide" problem this codebase's own GEOMETRY type already avoids by
 * staying text-only (see MysqlPlatform::initialize()'s own comment on the
 * VECTOR domain mapping for detail).
 */
class VectorColumnDDLTest extends TestCase
{
	protected function columnDDLFor(PropulsionPlatformInterface $platform, int $size): string
	{
		$column = new Column('embedding');
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::VECTOR));
		$column->getDomain()->replaceSize($size);
		return $platform->getColumnDDL($column);
	}

	public function testPgsqlEmitsNativeVectorWithDimension()
	{
		$platform = new PgsqlPlatform();
		$this->assertSame('"embedding" vector(3)', $this->columnDDLFor($platform, 3));
	}

	public function testPgsqlEmitsCreateExtensionWhenVectorIsUsed()
	{
		$platform = new PgsqlPlatform();
		$table = new Table('doc');
		$table->setIdMethod('none');
		$col = new Column('embedding');
		$col->setType(PropulsionTypes::VECTOR);
		$table->addColumn($col);
		$this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS vector;', $platform->getAddExtensionsDDL($table));
	}

	public function testMysqlEmulatesAsUnboundedText()
	{
		$platform = new MysqlPlatform();
		$this->assertSame('`embedding` TEXT', $this->columnDDLFor($platform, 1536));
	}

	public function testSqliteEmulatesAsUnboundedText()
	{
		$platform = new SqlitePlatform();
		$this->assertSame('MEDIUMTEXT', $platform->getDomainForType(PropulsionTypes::VECTOR)->getSqlType());
	}

	public function testMssqlEmulatesAsUnboundedText()
	{
		$platform = new MssqlPlatform();
		$this->assertSame('VARCHAR(MAX)', $platform->getDomainForType(PropulsionTypes::VECTOR)->getSqlType());
	}

	public function testOracleEmulatesAsClob()
	{
		$platform = new OraclePlatform();
		$this->assertSame('CLOB', $platform->getDomainForType(PropulsionTypes::VECTOR)->getSqlType());
	}
}
