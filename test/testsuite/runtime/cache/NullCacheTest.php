<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Cache\Driver\NullCache;
use Psr\SimpleCache\CacheInterface;

/**
 * @see Psr16DriverTestCase for the shared PSR-16 conformance assertions.
 */
class NullCacheTest extends Psr16DriverTestCase
{
    protected function createCache(): CacheInterface
    {
        return new NullCache();
    }

    protected function supportsPersistence(): bool
    {
        return false;
    }

    public function testEverythingIsAMiss()
    {
        $cache = new NullCache();
        $this->assertTrue($cache->set('k', 'v'));

        $this->assertFalse($cache->has('k'));
        $this->assertSame('MISS', $cache->get('k', 'MISS'));
    }

    /**
     * Validating keys even though nothing is stored is deliberate: a project
     * developing against the null driver would otherwise only discover an
     * illegal key the first time it deployed against a real backend.
     */
    public function testStillValidatesKeys()
    {
        $cache = new NullCache();
        $this->expectException(Psr\SimpleCache\InvalidArgumentException::class);
        $cache->set('illegal:key', 'v');
    }

    public function testWritesAndClearsReportSuccess()
    {
        $cache = new NullCache();
        $this->assertTrue($cache->set('k', 'v'));
        $this->assertTrue($cache->delete('k'));
        $this->assertTrue($cache->clear());
        $this->assertTrue($cache->setMultiple(['a' => 1]));
        $this->assertTrue($cache->deleteMultiple(['a']));
    }
}
