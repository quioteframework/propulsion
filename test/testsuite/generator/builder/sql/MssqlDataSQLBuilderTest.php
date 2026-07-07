<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Builder\SQL\MSSQL\MssqlDataSQLBuilder;

/**
 * Coverage for MssqlDataSQLBuilder::getBlobSql(), which had no test at all.
 * Builds a real Table instance for the constructor but calls the protected
 * method directly via reflection, since it's a pure function of its input
 * with no table/database interaction of its own.
 */
class MssqlDataSQLBuilderTest extends TestCase
{
    private function callGetBlobSql($blob)
    {
        $builder = new MssqlDataSQLBuilder(new Table('foo'));
        $method = new ReflectionMethod($builder, 'getBlobSql');
        return $method->invoke($builder, $blob);
    }

    public function testGetBlobSqlEncodesStringAsHex()
    {
        $this->assertSame('0x' . bin2hex('AB'), $this->callGetBlobSql('AB'));
    }

    public function testGetBlobSqlHasNoSurroundingQuotes()
    {
        $sql = $this->callGetBlobSql('x');
        $this->assertStringStartsWith('0x', $sql);
        $this->assertStringNotContainsString("'", $sql);
    }

    public function testGetBlobSqlConvertsObjectViaToString()
    {
        $blob = new class {
            public function __toString(): string
            {
                return 'CD';
            }
        };
        $this->assertSame('0x' . bin2hex('CD'), $this->callGetBlobSql($blob));
    }
}
