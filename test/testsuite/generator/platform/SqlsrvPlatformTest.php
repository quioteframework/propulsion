<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Platform\MssqlPlatform;
use Propulsion\Generator\Platform\SqlsrvPlatform;

/**
 * SqlsrvPlatform is the pdo_sqlsrv counterpart to MssqlPlatform (dblib), and had
 * no coverage: no CI job can load pdo_sqlsrv, which is Windows/Microsoft-ODBC
 * only. Nothing here needs the driver, though -- the platform classes are pure
 * DDL/metadata, and this one adds a single override.
 */
class SqlsrvPlatformTest extends TestCase
{
    public function testMaxColumnNameLengthIsSqlServersLimit()
    {
        $this->assertSame(128, (new SqlsrvPlatform())->getMaxColumnNameLength());
    }

    /**
     * The override is redundant as it stands: MssqlPlatform already returns 128,
     * so this asserts the two agree rather than that the override does something.
     * If they ever diverge, that is a deliberate change and this test should be
     * updated to say why -- it is not load-bearing today and the override could
     * equally be deleted.
     */
    public function testTheOverrideAgreesWithTheDblibPlatformItInherits()
    {
        $this->assertSame(
            (new MssqlPlatform())->getMaxColumnNameLength(),
            (new SqlsrvPlatform())->getMaxColumnNameLength()
        );
    }

    public function testItIsAnMssqlPlatform()
    {
        $this->assertInstanceOf(MssqlPlatform::class, new SqlsrvPlatform());
    }

    /**
     * Inherited MSSQL behaviour still applies -- the subclass changes only the
     * identifier limit, so schema support and quoting come from the parent.
     */
    public function testItInheritsMssqlSchemaSupportAndQuoting()
    {
        $platform = new SqlsrvPlatform();
        $mssql = new MssqlPlatform();
        $this->assertSame($mssql->supportsSchemas(), $platform->supportsSchemas());
        $this->assertSame($mssql->quoteIdentifier('COL'), $platform->quoteIdentifier('COL'));
    }
}
