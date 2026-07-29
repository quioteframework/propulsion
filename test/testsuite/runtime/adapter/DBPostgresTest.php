<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBPostgres;

/**
 * SQL-string-shape tests for DBPostgres::supportsExplain()/getExplainSql() --
 * no live Postgres instance available in this environment (no Docker), so
 * these assert on the generated SQL text only, the same convention several
 * other unverified-against-a-live-instance items in PLATFORM_FEATURES.md use.
 */
class DBPostgresTest extends TestCase
{
    private DBPostgres $db;

    protected function setUp(): void
    {
        $this->db = new DBPostgres();
    }

    public function testSupportsExplain()
    {
        $this->assertTrue($this->db->supportsExplain());
    }

    public function testGetExplainSql()
    {
        $this->assertSame(
            'EXPLAIN SELECT * FROM foo WHERE id=:p1',
            $this->db->getExplainSql('SELECT * FROM foo WHERE id=:p1')
        );
    }

    public function testGetExplainSqlWithAnalyze()
    {
        $this->assertSame(
            'EXPLAIN ANALYZE SELECT * FROM foo',
            $this->db->getExplainSql('SELECT * FROM foo', true)
        );
    }
}
