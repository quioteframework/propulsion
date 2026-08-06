<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;

/**
 * A connection with `MAX_CACHED_PREPARED_STATEMENTS` lowered far enough to
 * reach the bound in a test without preparing 257 statements.
 *
 * The constant is `protected` and read through `static::`, which is what makes
 * this possible -- and the eviction loop is written as a `while` specifically
 * so that lowering it in a subclass still converges. This is that subclass.
 */
class SmallCacheSqlitePDO extends SqlitePropulsionPDO
{
    protected const MAX_CACHED_PREPARED_STATEMENTS = 3;

    /** @return array<string, \PDOStatement> */
    public function cachedStatements(): array
    {
        return $this->preparedStatements;
    }
}

/**
 * The opt-in prepared-statement cache on {@see PropulsionPDO}: that it is off
 * by default, that it hands back the same statement for the same SQL, that it
 * stays bounded, and that a failed prepare is not remembered.
 *
 * The bound is the part worth testing. Without it, a process issuing an
 * unbounded variety of SQL -- which is what a query builder does the moment
 * anything interpolates a value instead of binding it -- grows this array
 * forever, and under a persistent worker that is a slow leak with no symptom
 * until the process is OOM-killed. Real sqlite::memory: connections
 * throughout; nothing here is mocked.
 */
class PreparedStatementCacheTest extends TestCase
{
    private function connection(bool $small = false): PropulsionPDO
    {
        $pdo = $small ? new SmallCacheSqlitePDO('sqlite::memory:') : new SqlitePropulsionPDO('sqlite::memory:');
        $pdo->setConfiguration(new PropulsionConfiguration(array()));
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        return $pdo;
    }

    public function testCachingIsOffByDefault()
    {
        $pdo = $this->connection();

        $this->assertFalse($pdo->getAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES));
        $this->assertNotSame(
            $pdo->prepare('SELECT * FROM widgets'),
            $pdo->prepare('SELECT * FROM widgets'),
            'without caching, each prepare() is its own statement'
        );
    }

    public function testTheSameSqlReusesTheSameStatementWhenCachingIsOn()
    {
        $pdo = $this->connection();
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

        $this->assertTrue($pdo->getAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES));
        $this->assertSame(
            $pdo->prepare('SELECT * FROM widgets'),
            $pdo->prepare('SELECT * FROM widgets')
        );
        $this->assertNotSame(
            $pdo->prepare('SELECT * FROM widgets'),
            $pdo->prepare('SELECT id FROM widgets'),
            'different SQL is a different entry'
        );
    }

    public function testTheCacheStaysBounded()
    {
        // The leak this guards against: one entry per distinct SQL string,
        // never released, on a connection that outlives the request.
        $pdo = $this->connection(small: true);
        $this->assertInstanceOf(SmallCacheSqlitePDO::class, $pdo);
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

        for ($i = 0; $i < 20; $i++) {
            $pdo->prepare('SELECT id FROM widgets WHERE id = ' . $i);
        }

        $this->assertCount(3, $pdo->cachedStatements(), 'the bound holds however much distinct SQL arrives');
    }

    public function testEvictionIsOldestFirst()
    {
        $pdo = $this->connection(small: true);
        $this->assertInstanceOf(SmallCacheSqlitePDO::class, $pdo);
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

        $first = $pdo->prepare('SELECT id FROM widgets WHERE id = 1');
        $pdo->prepare('SELECT id FROM widgets WHERE id = 2');
        $pdo->prepare('SELECT id FROM widgets WHERE id = 3');
        $this->assertSame($first, $pdo->prepare('SELECT id FROM widgets WHERE id = 1'), 'still cached at the bound');

        // One more distinct statement pushes the cache over the bound, and the
        // *first stored* entry goes -- insertion order, not least-recently-used;
        // re-preparing it above deliberately does not promote it.
        $pdo->prepare('SELECT id FROM widgets WHERE id = 4');
        $this->assertCount(3, $pdo->cachedStatements());
        $this->assertNotSame(
            $first,
            $pdo->prepare('SELECT id FROM widgets WHERE id = 1'),
            'the oldest entry was the one evicted'
        );
    }

    public function testClearStatementCacheEmptiesIt()
    {
        $pdo = $this->connection(small: true);
        $this->assertInstanceOf(SmallCacheSqlitePDO::class, $pdo);
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

        $statement = $pdo->prepare('SELECT id FROM widgets');
        $this->assertCount(1, $pdo->cachedStatements());

        $pdo->clearStatementCache();

        $this->assertCount(0, $pdo->cachedStatements());
        $this->assertNotSame($statement, $pdo->prepare('SELECT id FROM widgets'));
    }

    public function testAFailedPrepareIsNotCached()
    {
        // isset() is true for a stored `false`, so memoising a failed prepare
        // would hand that false back for the same SQL for the rest of the
        // connection's life -- even once the transient cause (a table a
        // concurrent migration had not created yet) is gone.
        $pdo = $this->connection(small: true);
        $this->assertInstanceOf(SmallCacheSqlitePDO::class, $pdo);
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        $sql = 'SELECT id FROM not_yet_created';
        $this->assertFalse($pdo->prepare($sql));
        $this->assertCount(0, $pdo->cachedStatements(), 'a failure must not occupy a cache slot');

        $pdo->exec('CREATE TABLE not_yet_created (id INTEGER PRIMARY KEY)');
        $this->assertNotFalse($pdo->prepare($sql), 'and the next attempt must be free to succeed');
    }

    public function testTheAttributeRefusesANonBoolean()
    {
        $pdo = $this->connection();

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('PROPEL_ATTR_CACHE_PREPARES expects a boolean value, got string');
        $pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, 'yes');
    }

    public function testOtherAttributesStillReachPdo()
    {
        $pdo = $this->connection();

        $this->assertTrue($pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION));
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }
}
