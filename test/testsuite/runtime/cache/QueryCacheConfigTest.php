<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Exception\PropulsionException;

/**
 * The `cache.query` section is brand new, so it has no back-compatibility
 * surface to protect -- which is why it rejects unknown keys and wrong types
 * outright (following Propulsion::processDriverOptions()) rather than silently
 * ignoring them the way the older datasource readers do. A quietly ignored
 * 'tll' => 300 typo would leave the cache running on defaults forever.
 */
class QueryCacheConfigTest extends TestCase
{
    public function testAbsentCacheSectionIsDisabled()
    {
        $config = QueryCacheConfig::fromConfigArray(['datasources' => []]);

        $this->assertFalse($config->enabled);
        $this->assertFalse($config->isActive());
        $this->assertSame('', $config->driver);
    }

    public function testAbsentQuerySubsectionIsDisabled()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => []]);

        $this->assertFalse($config->enabled);
        $this->assertFalse($config->isActive());
    }

    public function testDefaults()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['enabled' => true, 'driver' => 'array']]]);

        $this->assertTrue($config->enabled);
        $this->assertSame('array', $config->driver);
        $this->assertSame(QueryCacheConfig::DEFAULT_TTL, $config->ttl);
        $this->assertSame(QueryCacheConfig::DEFAULT_NAMESPACE, $config->namespace);
        $this->assertSame(2, $config->minSightings);
        $this->assertSame(1.0, $config->beta);
        $this->assertSame(5, $config->lockTtl);
        $this->assertTrue($config->isActive());
    }

    /**
     * TTL defaults to a finite 300s rather than "never": it is the only
     * backstop against writes that bypass the ORM entirely.
     */
    public function testTtlDefaultsToAFiniteValue()
    {
        $config = QueryCacheConfig::disabled();
        $this->assertSame(300, $config->ttl);
    }

    public function testFullSectionIsParsed()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => [
            'enabled' => true,
            'driver' => 'file',
            'ttl' => 60,
            'namespace' => 'bookstore',
            'admission' => ['min_sightings' => 3, 'window' => 30],
            'stampede' => ['beta' => 0.5, 'lock_ttl' => 9],
            'file' => ['directory' => '/tmp/x', 'levels' => 1],
        ]]]);

        $this->assertTrue($config->enabled);
        $this->assertSame('file', $config->driver);
        $this->assertSame(60, $config->ttl);
        $this->assertSame('bookstore', $config->namespace);
        $this->assertSame(3, $config->minSightings);
        $this->assertSame(30, $config->admissionWindow);
        $this->assertSame(30, $config->getAdmissionWindow());
        $this->assertSame(0.5, $config->beta);
        $this->assertSame(9, $config->lockTtl);
        $this->assertSame(['directory' => '/tmp/x', 'levels' => 1], $config->driverOptions);
    }

    public function testAdmissionWindowFallsBackToTtl()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['ttl' => 120]]]);

        $this->assertNull($config->admissionWindow);
        $this->assertSame(120, $config->getAdmissionWindow());
    }

    public function testNullTtlMeansNoExpiry()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['ttl' => null]]]);

        $this->assertNull($config->ttl);
    }

    public function testOnlyTheSelectedDriversOptionsAreCarried()
    {
        // Keeping a 'file' block around while running 'apcu' is a normal thing
        // to do, so unselected driver blocks are ignored rather than rejected.
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => [
            'enabled' => true,
            'driver' => 'array',
            'array' => ['max_entries' => 11],
            'file' => ['directory' => '/tmp/unused'],
        ]]]);

        $this->assertSame(['max_entries' => 11], $config->driverOptions);
    }

    public function testDisabledWithADriverIsNotActive()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['enabled' => false, 'driver' => 'array']]]);

        $this->assertFalse($config->isActive(), 'configuring a driver is not consent to serve stale data');
    }

    public function testEnabledWithNullDriverIsNotActive()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['enabled' => true, 'driver' => 'null']]]);

        $this->assertFalse($config->isActive());
    }

    public function testPsr16DriverIsAccepted()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['enabled' => true, 'driver' => 'psr16']]]);

        $this->assertSame('psr16', $config->driver);
        $this->assertTrue($config->isActive());
        $this->assertSame([], $config->driverOptions);
    }

    public function testUnknownTopLevelKeyIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('cache.query.tll');

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['tll' => 300]]]);
    }

    public function testUnknownCacheSubsectionIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('cache.compiled');

        QueryCacheConfig::fromConfigArray(['cache' => ['compiled' => []]]);
    }

    public function testUnknownAdmissionKeyIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('cache.query.admission.sightings');

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['admission' => ['sightings' => 2]]]]);
    }

    public function testUnknownStampedeKeyIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('cache.query.stampede.jitter');

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['stampede' => ['jitter' => 1]]]]);
    }

    public function testUnknownDriverIsRejected()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unsupported Propulsion cache driver: redis');

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['driver' => 'redis']]]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongTypes')]
    public function testWrongValueTypesAreRejected(array $query, string $expectedFragment)
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage($expectedFragment);

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => $query]]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function wrongTypes(): array
    {
        return [
            'enabled' => [['enabled' => 'yes'], 'cache.query.enabled" must be a boolean'],
            'driver' => [['driver' => 42], 'cache.query.driver" must be a string'],
            'ttl' => [['ttl' => '300'], 'cache.query.ttl" must be an integer or null'],
            'namespace' => [['namespace' => 5], 'cache.query.namespace" must be a string'],
            'admission block' => [['admission' => 'on'], 'cache.query.admission" must be an array'],
            'stampede block' => [['stampede' => 'on'], 'cache.query.stampede" must be an array'],
            'min_sightings' => [['admission' => ['min_sightings' => '2']], 'min_sightings" must be an integer'],
            'beta' => [['stampede' => ['beta' => 'high']], 'beta" must be a number'],
            'lock_ttl' => [['stampede' => ['lock_ttl' => 1.5]], 'lock_ttl" must be an integer'],
        ];
    }

    public function testCacheSectionMustBeAnArray()
    {
        $this->expectException(PropulsionException::class);
        QueryCacheConfig::fromConfigArray(['cache' => 'on']);
    }

    public function testNegativeBetaIsRejected()
    {
        $this->expectException(PropulsionException::class);
        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['stampede' => ['beta' => -1.0]]]]);
    }

    public function testZeroBetaIsAcceptedAndDisablesEarlyRecomputation()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['stampede' => ['beta' => 0]]]]);

        $this->assertSame(0.0, $config->beta);
    }

    public function testMinSightingsBelowOneIsRejected()
    {
        $this->expectException(PropulsionException::class);
        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['admission' => ['min_sightings' => 0]]]]);
    }

    public function testMinSightingsOfOneRestoresCacheOnFirstMiss()
    {
        $config = QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['admission' => ['min_sightings' => 1]]]]);

        $this->assertSame(1, $config->minSightings);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illegalNamespaces')]
    public function testIllegalNamespaceIsRejected(string $namespace)
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('cache.query.namespace');

        QueryCacheConfig::fromConfigArray(['cache' => ['query' => ['namespace' => $namespace]]]);
    }

    /**
     * The namespace is a literal prefix of every cache key, and PSR-16 keys are
     * limited to A-Za-z0-9_. and 64 characters.
     *
     * @return array<string, array{0: string}>
     */
    public static function illegalNamespaces(): array
    {
        return [
            'empty' => [''],
            'colon' => ['my:app'],
            'slash' => ['my/app'],
            'space' => ['my app'],
            'too long' => [str_repeat('n', 25)],
        ];
    }
}
