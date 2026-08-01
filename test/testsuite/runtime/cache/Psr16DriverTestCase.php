<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\AtomicCache;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * PSR-16 conformance suite that every one of Propulsion's first-party cache
 * drivers must pass identically. Subclasses supply the driver; the null driver
 * flips off the storage assertions via {@see supportsPersistence()}.
 *
 * Expiry is exercised through the "a non-positive TTL deletes rather than
 * stores" convention documented on
 * {@see \Propulsion\Cache\Driver\AbstractCacheDriver}, so none of these tests
 * needs sleep() or a clock abstraction.
 */
abstract class Psr16DriverTestCase extends TestCase
{
    abstract protected function createCache(): CacheInterface;

    /** False only for the null driver, which is a legitimate black hole. */
    protected function supportsPersistence(): bool
    {
        return true;
    }

    /**
     * The payload the shared query cache actually stores: a list of
     * pre-hydration rows as PDO::FETCH_NUM produces them.
     *
     * @return list<array<int, scalar|null>>
     */
    protected function rowPayload(): array
    {
        return [
            [1, 'Harry Potter', null, 4.5],
            [2, "O'Reilly \"quoted\"", 0, -1.25],
        ];
    }

    public function testGetOnAMissReturnsTheDefault()
    {
        $cache = $this->createCache();
        $this->assertNull($cache->get('missing_key'));
        $this->assertSame('fallback', $cache->get('missing_key', 'fallback'));
        $this->assertFalse($cache->has('missing_key'));
    }

    public function testStoresAndReturnsRowPayload()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $rows = $this->rowPayload();

        $this->assertTrue($cache->set('rows_key', $rows));
        $this->assertTrue($cache->has('rows_key'));
        $this->assertSame($rows, $cache->get('rows_key'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roundTripValues')]
    public function testRoundTripsValueShapes(string $label, mixed $value)
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('shape_' . $label, $value);

        $this->assertTrue($cache->has('shape_' . $label), $label . ' should be a hit');
        $this->assertSame($value, $cache->get('shape_' . $label, 'SENTINEL'), $label . ' should round trip');
    }

    /**
     * The null/false cases are the traps: a stored null must be distinguishable
     * from a miss, which is exactly what the shared cache relies on to cache a
     * findOne() that legitimately returned null.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function roundTripValues(): array
    {
        return [
            'null' => ['null', null],
            'false' => ['false', false],
            'true' => ['true', true],
            'zero' => ['zero', 0],
            'emptystring' => ['emptystring', ''],
            'emptyarray' => ['emptyarray', []],
            'int' => ['int', 42],
            'float' => ['float', 1.5],
            'string' => ['string', 'hello'],
            'nested' => ['nested', [['a' => 1, 'b' => null]]],
        ];
    }

    public function testStoredNullIsAHitNotAMiss()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('null_key', null);

        $this->assertTrue($cache->has('null_key'));
        $this->assertNull($cache->get('null_key', 'SENTINEL'));
    }

    public function testDeleteRemovesAnEntry()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('doomed', 'value');

        $this->assertTrue($cache->delete('doomed'));
        $this->assertFalse($cache->has('doomed'));
    }

    public function testDeletingAMissingKeyReportsSuccess()
    {
        $cache = $this->createCache();
        $this->assertTrue($cache->delete('never_existed'));
    }

    public function testClearEmptiesTheCache()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('a', 1);
        $cache->set('b', 2);

        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function testGetMultipleReturnsHitsAndDefaultsForMisses()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('present_one', 'v1');
        $cache->set('present_two', 'v2');

        $result = $cache->getMultiple(['present_one', 'absent', 'present_two'], 'MISS');
        $result = is_array($result) ? $result : iterator_to_array($result);

        $this->assertSame(['present_one' => 'v1', 'absent' => 'MISS', 'present_two' => 'v2'], $result);
    }

    public function testGetMultipleAcceptsAGenerator()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('gen_one', 'v1');

        $keys = (static function (): Generator {
            yield 'gen_one';
            yield 'gen_absent';
        })();

        $result = $cache->getMultiple($keys, 'MISS');
        $result = is_array($result) ? $result : iterator_to_array($result);

        $this->assertSame(['gen_one' => 'v1', 'gen_absent' => 'MISS'], $result);
    }

    public function testSetMultipleAndDeleteMultiple()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();

        $this->assertTrue($cache->setMultiple(['m1' => 'a', 'm2' => 'b']));
        $this->assertSame('a', $cache->get('m1'));
        $this->assertSame('b', $cache->get('m2'));

        $this->assertTrue($cache->deleteMultiple(['m1', 'm2']));
        $this->assertFalse($cache->has('m1'));
        $this->assertFalse($cache->has('m2'));
    }

    public function testPositiveTtlKeepsTheEntry()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('ttl_live', 'value', 3600);

        $this->assertTrue($cache->has('ttl_live'));
        $this->assertSame('value', $cache->get('ttl_live'));
    }

    public function testDateIntervalTtlIsAccepted()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('ttl_interval', 'value', new DateInterval('PT1H'));

        $this->assertSame('value', $cache->get('ttl_interval'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('elapsedTtls')]
    public function testNonPositiveTtlStoresNothing(int $ttl)
    {
        $cache = $this->createCache();
        $this->assertTrue($cache->set('ttl_elapsed', 'value', $ttl));
        $this->assertFalse($cache->has('ttl_elapsed'));
        $this->assertSame('MISS', $cache->get('ttl_elapsed', 'MISS'));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function elapsedTtls(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'very negative' => [-3600]];
    }

    public function testNonPositiveTtlAlsoEvictsAnExistingEntry()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $cache->set('replaced', 'original');
        $cache->set('replaced', 'newer', -1);

        $this->assertFalse($cache->has('replaced'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illegalKeys')]
    public function testIllegalKeysAreRejected(string $key)
    {
        $cache = $this->createCache();
        $this->expectException(Psr16InvalidArgumentException::class);
        $cache->get($key);
    }

    /**
     * PSR-16 reserves these characters. Propulsion's own keys are hex digests
     * plus dots, so rejecting rather than encoding is what keeps us portable to
     * third-party pools that enforce the same rule.
     *
     * @return array<string, array{0: string}>
     */
    public static function illegalKeys(): array
    {
        return [
            'empty' => [''],
            'brace open' => ['a{b'],
            'brace close' => ['a}b'],
            'paren open' => ['a(b'],
            'paren close' => ['a)b'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'at' => ['a@b'],
            'colon' => ['a:b'],
            'space' => ['a b'],
            'pipe' => ['a|b'],
        ];
    }

    public function testIllegalKeyIsRejectedOnEveryEntryPoint()
    {
        $cache = $this->createCache();

        foreach (['get', 'set', 'delete', 'has'] as $method) {
            try {
                match ($method) {
                    'get' => $cache->get('bad:key'),
                    'set' => $cache->set('bad:key', 'v'),
                    'delete' => $cache->delete('bad:key'),
                    'has' => $cache->has('bad:key'),
                };
                $this->fail($method . '() should reject an illegal key');
            } catch (Psr16InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLongAndBoundaryLengthKeysAreSupported()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();

        foreach ([64, 250] as $length) {
            $key = str_repeat('k', $length);
            $this->assertTrue($cache->set($key, $length));
            $this->assertSame($length, $cache->get($key));
        }
    }

    public function testKeysWithLegalPunctuationWork()
    {
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }
        $cache = $this->createCache();
        $key = 'propulsion.q.0123456789abcdef_ABCDEF';

        $cache->set($key, 'v');
        $this->assertSame('v', $cache->get($key));
    }

    public function testAddCreatesOnlyOnceWhenTheDriverIsAtomic()
    {
        $cache = $this->createCache();
        if (!$cache instanceof AtomicCache) {
            $this->markTestSkipped('driver does not implement AtomicCache');
        }
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }

        $this->assertTrue($cache->add('lock_key', 'first'), 'first add() should win');
        $this->assertFalse($cache->add('lock_key', 'second'), 'second add() must lose');
        $this->assertSame('first', $cache->get('lock_key'), 'the loser must not overwrite the winner');
    }

    public function testAddSucceedsAgainOnceTheEntryIsGone()
    {
        $cache = $this->createCache();
        if (!$cache instanceof AtomicCache) {
            $this->markTestSkipped('driver does not implement AtomicCache');
        }
        if (!$this->supportsPersistence()) {
            $this->markTestSkipped('driver does not persist');
        }

        $this->assertTrue($cache->add('relock', 'first'));
        $cache->delete('relock');
        $this->assertTrue($cache->add('relock', 'second'), 'add() should win again after the lock is released');
        $this->assertSame('second', $cache->get('relock'));
    }

    public function testAddWithElapsedTtlStoresNothing()
    {
        $cache = $this->createCache();
        if (!$cache instanceof AtomicCache) {
            $this->markTestSkipped('driver does not implement AtomicCache');
        }

        $this->assertFalse($cache->add('elapsed_lock', 'v', -1));
        $this->assertFalse($cache->has('elapsed_lock'));
    }
}
