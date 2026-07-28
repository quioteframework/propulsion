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

    public function testSupportsInsertReturning()
    {
        $this->assertTrue($this->db->supportsInsertReturning());
    }

    public function testGetInsertReturningSql()
    {
        $sql = $this->db->getInsertReturningSql('INSERT INTO foo (bar) VALUES (:p1)', 'id');
        $this->assertSame('INSERT INTO foo (bar) VALUES (:p1) RETURNING id', $sql);
    }

    public function testExtractInsertedId()
    {
        // Real end-to-end check (not just a SQL-string assertion): SQLite actually
        // supports RETURNING and hands back the generated rowid this way.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY, bar TEXT)');

        $sql = $this->db->getInsertReturningSql("INSERT INTO foo (bar) VALUES ('hello')", 'id');
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $this->assertEquals(1, $this->db->extractInsertedId($stmt));
    }

    public function testSupportsRowReturning()
    {
        $this->assertTrue($this->db->supportsRowReturning());
    }

    public function testGetUpdateReturningSql()
    {
        $sql = $this->db->getUpdateReturningSql("UPDATE foo SET bar=:p1 WHERE id=:p2", array('id', 'bar'));
        $this->assertSame("UPDATE foo SET bar=:p1 WHERE id=:p2 RETURNING id, bar", $sql);
    }

    public function testGetDeleteReturningSql()
    {
        $sql = $this->db->getDeleteReturningSql("DELETE FROM foo WHERE id=:p1", array('id', 'bar'));
        $this->assertSame("DELETE FROM foo WHERE id=:p1 RETURNING id, bar", $sql);
    }

    public function testUpdateReturningEndToEnd()
    {
        // Real end-to-end check, not just a SQL-string assertion.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY, bar TEXT)');
        $pdo->exec("INSERT INTO foo (id, bar) VALUES (1, 'before')");

        $sql = $this->db->getUpdateReturningSql("UPDATE foo SET bar='after' WHERE id=1", array('id', 'bar'));
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $this->assertSame(array(array('id' => 1, 'bar' => 'after')), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testDeleteReturningEndToEnd()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY, bar TEXT)');
        $pdo->exec("INSERT INTO foo (id, bar) VALUES (1, 'hello')");

        $sql = $this->db->getDeleteReturningSql("DELETE FROM foo WHERE id=1", array('id', 'bar'));
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $this->assertSame(array(array('id' => 1, 'bar' => 'hello')), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testSupportsUpsert()
    {
        $this->assertTrue($this->db->supportsUpsert());
    }

    public function testGetUpsertSqlDoUpdate()
    {
        $sql = $this->db->getUpsertSql('INSERT INTO foo (id,bar) VALUES (:p1,:p2)', array('id'), 'bar=:p3');
        $this->assertSame('INSERT INTO foo (id,bar) VALUES (:p1,:p2) ON CONFLICT (id) DO UPDATE SET bar=:p3', $sql);
    }

    public function testGetUpsertSqlDoNothing()
    {
        $sql = $this->db->getUpsertSql('INSERT INTO foo (id,bar) VALUES (:p1,:p2)', array('id'), '');
        $this->assertSame('INSERT INTO foo (id,bar) VALUES (:p1,:p2) ON CONFLICT (id) DO NOTHING', $sql);
    }

    public function testGetUpsertSqlThrowsWithoutConflictColumns()
    {
        $this->expectException(PropulsionException::class);
        $this->db->getUpsertSql('INSERT INTO foo (id) VALUES (:p1)', array(), 'id=:p2');
    }
}
