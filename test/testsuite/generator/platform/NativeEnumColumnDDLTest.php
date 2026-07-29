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
 * Tests the per-platform DDL generated for `nativeEnum="true"` ENUM columns:
 * a real `CREATE TYPE ... AS ENUM` on Postgres, inline `ENUM(...)` on MySQL,
 * a `CHECK` constraint on SQLite/Oracle, and untouched (still emulated as an
 * integer) on MSSQL, which has no native mechanism.
 */
class NativeEnumColumnDDLTest extends TestCase
{
	protected function makeEnumColumn(): Column
	{
		$column = new Column('status');
		$column->setType(PropulsionTypes::ENUM);
		$column->setValueSet(['pending', 'active', 'archived']);
		$column->setNativeEnum(true);
		return $column;
	}

	public function testMysqlEmitsInlineNativeEnum()
	{
		$platform = new MysqlPlatform();
		$column = $this->makeEnumColumn();
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::ENUM));
		$this->assertSame(
			"`status` ENUM('pending', 'active', 'archived')",
			$platform->getColumnDDL($column)
		);
	}

	public function testSqliteEmitsCheckConstraint()
	{
		$platform = new SqlitePlatform();
		$column = $this->makeEnumColumn();
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::ENUM));
		$this->assertSame(
			"[status] VARCHAR(8) CHECK ([status] IN ('pending', 'active', 'archived'))",
			$platform->getColumnDDL($column)
		);
	}

	public function testOracleEmitsCheckConstraint()
	{
		$platform = new OraclePlatform();
		$column = $this->makeEnumColumn();
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::ENUM));
		$ddl = $platform->getColumnDDL($column);
		$this->assertStringContainsString("CHECK (status IN ('pending', 'active', 'archived'))", $ddl);
	}

	public function testMssqlStaysEmulatedAndIgnoresNativeEnum()
	{
		$platform = new MssqlPlatform();
		$this->assertFalse($platform->supportsNativeEnumDDL());
		$column = $this->makeEnumColumn();
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::ENUM));
		$ddl = $platform->getColumnDDL($column);
		$this->assertStringNotContainsString('CHECK', $ddl);
		$this->assertStringContainsString('TINYINT', $ddl);
	}

	public function testPgsqlEmitsCreateTypeBeforeTheTable()
	{
		$platform = new PgsqlPlatform();
		$table = new Table('widget');
		$table->setIdMethod('none');
		$column = $this->makeEnumColumn();
		$table->addColumn($column);
		$column->getDomain()->copy($platform->getDomainForType(PropulsionTypes::ENUM));

		$createType = $platform->getAddEnumTypesDDL($table);
		$this->assertStringContainsString(
			"CREATE TYPE \"widget_status_enum\" AS ENUM ('pending', 'active', 'archived');",
			$createType
		);

		$dropType = $platform->getDropEnumTypesDDL($table);
		$this->assertStringContainsString('DROP TYPE IF EXISTS "widget_status_enum";', $dropType);

		$columnDDL = $platform->getColumnDDL($column);
		$this->assertSame('"status" "widget_status_enum"', $columnDDL);
	}
}
