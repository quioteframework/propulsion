<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\QueryResultCache;
use Propulsion\Cache\SharedQueryCache;
use Propulsion\Cache\SharedQueryCacheConfig;
use Propulsion\Cache\TableVersionRegistry;
use Propulsion\Cache\TieredQueryCache;

/**
 * How remember() coordinates the two tiers, and in particular *when it decides
 * to materialise rows*.
 *
 * The shared tier stores raw rows, so populating it means holding the whole
 * result set as an array in addition to the formatted result. Admission control
 * rejects the first execution of every query (`min_sightings` defaults to 2) and
 * every execution of a query whose key never repeats -- so materialising
 * unconditionally paid double peak memory for exactly the cold case, then threw
 * the array away. These tests pin the behaviour that avoids it.
 */
class TieredQueryCacheUnitTest extends TestCase
{
    private ArrayCache $pool;

    /** @var array<string, int> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = new ArrayCache(10000);
        $this->calls = ['execute' => 0, 'formatStatement' => 0, 'formatRows' => 0];
    }

    /**
     * A fresh tiered cache over the same process-shared pool -- i.e. what the
     * next request in the same worker process gets.
     */
    private function newRequest(): TieredQueryCache
    {
        $config = new SharedQueryCacheConfig(namespace: 'test');

        return new TieredQueryCache(
            new QueryResultCache(),
            new SharedQueryCache($this->pool, $config, new TableVersionRegistry($this->pool, $config))
        );
    }

    /**
     * A live statement over in-memory SQLite, so the row-materialising path
     * runs against a real PDOStatement rather than a stand-in.
     */
    private function statement(): PDOStatement
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (a INTEGER)');
        $pdo->exec('INSERT INTO t (a) VALUES (1), (2), (3)');
        $stmt = $pdo->query('SELECT a FROM t');
        self::assertInstanceOf(PDOStatement::class, $stmt);

        return $stmt;
    }

    private function remember(TieredQueryCache $cache): mixed
    {
        return $cache->remember(
            dbName: 'bookstore',
            sql: 'SELECT a FROM t WHERE a = :p1',
            params: [['table' => 't', 'column' => 'a', 'value' => 1]],
            touchedTables: ['t'],
            variant: 'objects',
            execute: function (): PDOStatement {
                $this->calls['execute']++;

                return $this->statement();
            },
            formatStatement: function (PDOStatement $stmt): string {
                $this->calls['formatStatement']++;

                return 'formatted';
            },
            formatRows: function (iterable $rows): string {
                $this->calls['formatRows']++;

                return 'formatted';
            },
        );
    }

    public function testFirstExecutionStreamsInsteadOfMaterialisingRows()
    {
        $result = $this->remember($this->newRequest());

        $this->assertSame('formatted', $result);
        $this->assertSame(1, $this->calls['execute']);
        $this->assertSame(
            1,
            $this->calls['formatStatement'],
            'a first execution is not admitted to the shared tier, so it must format straight off the live statement'
        );
        $this->assertSame(
            0,
            $this->calls['formatRows'],
            'materialising the row set for an entry admission control will reject is wasted peak memory'
        );
    }

    public function testASecondRequestForTheSameQueryIsAdmittedAndMaterialises()
    {
        $this->remember($this->newRequest());   // first sighting, streamed
        $this->remember($this->newRequest());   // second sighting, admitted

        $this->assertSame(2, $this->calls['execute']);
        $this->assertSame(1, $this->calls['formatStatement'], 'only the first, unadmitted execution streamed');
        $this->assertSame(1, $this->calls['formatRows'], 'the admitted execution materialises so it can store');
    }

    public function testAThirdRequestIsServedFromTheSharedTierWithoutExecuting()
    {
        $this->remember($this->newRequest());
        $this->remember($this->newRequest());
        $executesBefore = $this->calls['execute'];

        $result = $this->remember($this->newRequest());

        $this->assertSame('formatted', $result);
        $this->assertSame($executesBefore, $this->calls['execute'], 'a shared-tier hit must not touch the database');
        $this->assertSame(2, $this->calls['formatRows'], 'the hit is rehydrated from the stored rows');
    }

    public function testTheRequestScopedTierStillShortCircuitsWithinOneRequest()
    {
        $cache = $this->newRequest();

        $this->remember($cache);
        $this->remember($cache);

        $this->assertSame(1, $this->calls['execute'], 'the second call in the same request is an L1 hit');
    }

    public function testAQueryWhoseKeyNeverRepeatsNeverPopulatesTheSharedTier()
    {
        for ($i = 0; $i < 5; $i++) {
            $cache = $this->newRequest();
            $cache->remember(
                dbName: 'bookstore',
                // A distinct key every time, as a `WHERE id = <varying>` produces.
                sql: 'SELECT a FROM t WHERE a = ' . $i,
                params: [],
                touchedTables: ['t'],
                variant: 'objects',
                execute: fn (): PDOStatement => $this->statement(),
                formatStatement: function (PDOStatement $stmt): string {
                    $this->calls['formatStatement']++;

                    return 'formatted';
                },
                formatRows: function (iterable $rows): string {
                    $this->calls['formatRows']++;

                    return 'formatted';
                },
            );
        }

        $this->assertSame(5, $this->calls['formatStatement'], 'every never-repeating key streams');
        $this->assertSame(0, $this->calls['formatRows'], 'and none of them materialises a row set');
    }

    public function testInvalidationStillEvictsAcrossBothTiers()
    {
        $this->remember($this->newRequest());
        $this->remember($this->newRequest());   // now stored in the shared tier

        $cache = $this->newRequest();
        $cache->invalidateTable('t', null, 'bookstore');

        $executesBefore = $this->calls['execute'];
        $this->remember($cache);

        $this->assertSame(
            $executesBefore + 1,
            $this->calls['execute'],
            'a write to a depended-on table must send the next read back to the database'
        );
    }
}
