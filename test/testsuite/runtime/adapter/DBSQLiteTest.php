<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests the DBSQLite adapter. All of its methods are pure SQL-string builders,
 * so unlike DBOracleTest/DBMySQLTest, this needs no live database fixture.
 */
class DBSQLiteTest extends TestCase
{
    private DBSQLite $db;

    protected function setUp(): void
    {
        $this->db = new DBSQLite();
    }

    public function testToUpperCase()
    {
        $this->assertSame('UPPER(foo)', $this->db->toUpperCase('foo'));
    }

    public function testIgnoreCase()
    {
        $this->assertSame('UPPER(foo)', $this->db->ignoreCase('foo'));
    }

    public function testConcatString()
    {
        $this->assertSame('(foo || bar)', $this->db->concatString('foo', 'bar'));
    }

    public function testSubString()
    {
        $this->assertSame('substr(foo, 1, 3)', $this->db->subString('foo', 1, 3));
    }

    public function testStrLength()
    {
        $this->assertSame('length(foo)', $this->db->strLength('foo'));
    }

    public function testQuoteIdentifier()
    {
        $this->assertSame('[foo]', $this->db->quoteIdentifier('foo'));
    }

    public function testRandom()
    {
        $this->assertSame('random()', $this->db->random());
        $this->assertSame('random()', $this->db->random('some seed'));
    }

    public function testSetCharsetIsANoOp()
    {
        // SQLite has no per-connection charset concept; this must not throw.
        $pdo = new PDO('sqlite::memory:');
        $this->db->setCharset($pdo, 'utf8');
        $this->addToAssertionCount(1);
    }

    public function testApplyLimitWithLimitOnly()
    {
        $sql = 'SELECT * FROM foo';
        $this->db->applyLimit($sql, 0, 10);
        $this->assertSame('SELECT * FROM foo LIMIT 10', $sql);
    }

    public function testApplyLimitWithLimitAndOffset()
    {
        $sql = 'SELECT * FROM foo';
        $this->db->applyLimit($sql, 5, 10);
        $this->assertSame('SELECT * FROM foo LIMIT 10 OFFSET 5', $sql);
    }

    public function testApplyLimitWithOffsetOnly()
    {
        $sql = 'SELECT * FROM foo';
        $this->db->applyLimit($sql, 5, 0);
        $this->assertSame('SELECT * FROM foo LIMIT -1 OFFSET 5', $sql);
    }

    public function testApplyLimitWithNeitherIsANoOp()
    {
        $sql = 'SELECT * FROM foo';
        $this->db->applyLimit($sql, 0, 0);
        $this->assertSame('SELECT * FROM foo', $sql);
    }
}
