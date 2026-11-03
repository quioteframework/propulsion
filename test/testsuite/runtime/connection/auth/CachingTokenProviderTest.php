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
use Propulsion\Connection\Auth\AccessToken;
use Propulsion\Connection\Auth\CachingTokenProvider;
use Propulsion\Exception\PropulsionException;

/**
 * The two cache tiers in front of every token provider. An ArrayCache stands
 * in for the shared pool (APCu, Redis): two CachingTokenProvider instances
 * over one ArrayCache behave like two php-fpm requests over one APCu segment.
 */
class CachingTokenProviderTest extends TestCase
{
    private const RESOURCE = 'https://ossrdbms-aad.database.windows.net';

    private int $now = 1_800_000_000;

    private function caching(TokenAuthFakeProvider $provider, ?ArrayCache $shared = null, int $refreshBefore = 300): CachingTokenProvider
    {
        return new CachingTokenProvider($provider, $shared, $refreshBefore, fn (): int => $this->now);
    }

    public function testATokenIsFetchedOnceAndThenServedFromMemory(): void
    {
        $provider = TokenAuthFakeProvider::constant('tok', $this->now + 3600);
        $caching = $this->caching($provider);

        $this->assertSame('tok', $caching->getToken(self::RESOURCE)->token);
        $this->assertSame('tok', $caching->getToken(self::RESOURCE)->token);
        $this->assertSame(1, $provider->fetches);
        $this->assertSame([self::RESOURCE], $provider->resources);
    }

    public function testTheSharedTierServesAnotherProcess(): void
    {
        $shared = new ArrayCache();
        $first = TokenAuthFakeProvider::constant('tok', $this->now + 3600);
        $second = TokenAuthFakeProvider::constant('other', $this->now + 3600);

        $this->caching($first, $shared)->getToken(self::RESOURCE);
        $token = $this->caching($second, $shared)->getToken(self::RESOURCE);

        $this->assertSame('tok', $token->token, 'the "next request" reuses the first one\'s token');
        $this->assertSame(0, $second->fetches);
    }

    public function testDifferentIdentitiesNeverShareATokenThroughTheSharedTier(): void
    {
        // The least-privilege guarantee: the runtime identity and the
        // migration identity on the same host, same APCu segment.
        $shared = new ArrayCache();
        $runtime = TokenAuthFakeProvider::constant('runtime-token', $this->now + 3600, 'runtime-identity');
        $migrations = TokenAuthFakeProvider::constant('migration-token', $this->now + 3600, 'migration-identity');

        $this->assertSame('runtime-token', $this->caching($runtime, $shared)->getToken(self::RESOURCE)->token);
        $this->assertSame('migration-token', $this->caching($migrations, $shared)->getToken(self::RESOURCE)->token);
        $this->assertSame(1, $migrations->fetches);
    }

    public function testDifferentResourcesAreCachedSeparately(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('pg', $this->now + 3600),
            new AccessToken('sql', $this->now + 3600),
        ]);
        $caching = $this->caching($provider, new ArrayCache());

        $this->assertSame('pg', $caching->getToken(self::RESOURCE)->token);
        $this->assertSame('sql', $caching->getToken('https://database.windows.net/')->token);
        $this->assertSame('pg', $caching->getToken(self::RESOURCE)->token);
    }

    public function testAProviderThatCannotNameItsPrincipalIsNotSharedAcrossProcesses(): void
    {
        $shared = new ArrayCache();
        $anonymous = TokenAuthFakeProvider::constant('tok', $this->now + 3600, null);
        $other = TokenAuthFakeProvider::constant('tok2', $this->now + 3600, null);

        $this->caching($anonymous, $shared)->getToken(self::RESOURCE);
        $this->assertSame('tok2', $this->caching($other, $shared)->getToken(self::RESOURCE)->token);
    }

    public function testATokenIsReplacedBeforeItExpires(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('old', $this->now + 3600),
            new AccessToken('new', $this->now + 7200),
        ]);
        $caching = $this->caching($provider, new ArrayCache(), 300);

        $caching->getToken(self::RESOURCE);
        $this->now += 3600 - 301;
        $this->assertSame('old', $caching->getToken(self::RESOURCE)->token, 'still outside the refresh window');
        $this->now += 2;
        $this->assertSame('new', $caching->getToken(self::RESOURCE)->token, 'inside the refresh window');
    }

    public function testAShortLivedTokenIsNotStaleOnArrival(): void
    {
        // A 4-minute token under a 5-minute margin would otherwise be
        // refetched on every connection.
        $provider = TokenAuthFakeProvider::constant('short', $this->now + 240);
        $caching = $this->caching($provider, null, 300);

        $caching->getToken(self::RESOURCE);
        $this->now += 100;
        $caching->getToken(self::RESOURCE);

        $this->assertSame(1, $provider->fetches, 'refreshes at half its lifetime instead');
    }

    public function testAFailedRefreshFallsBackToTheStillValidToken(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('tok', $this->now + 3600),
            new PropulsionException('IMDS is throttling'),
        ]);
        $caching = $this->caching($provider);

        $caching->getToken(self::RESOURCE);
        $this->now += 3400;

        $this->assertSame('tok', $caching->getToken(self::RESOURCE)->token);
        $this->assertSame(2, $provider->fetches, 'it did try to refresh');
    }

    public function testAFailedRefreshFallsBackToTheSharedTierToo(): void
    {
        $shared = new ArrayCache();
        $this->caching(TokenAuthFakeProvider::constant('tok', $this->now + 3600), $shared)->getToken(self::RESOURCE);
        $this->now += 3400;

        $failing = new TokenAuthFakeProvider([new PropulsionException('down')]);

        $this->assertSame('tok', $this->caching($failing, $shared)->getToken(self::RESOURCE)->token);
    }

    public function testAFailedRefreshIsRethrownOnceTheCachedTokenIsUseless(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('tok', $this->now + 3600),
            new PropulsionException('IMDS is down'),
        ]);
        $caching = $this->caching($provider);

        $caching->getToken(self::RESOURCE);
        $this->now += 3590;

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('IMDS is down');
        $caching->getToken(self::RESOURCE);
    }

    public function testInvalidateDropsTheRejectedTokenFromBothTiers(): void
    {
        $shared = new ArrayCache();
        $provider = new TokenAuthFakeProvider([
            new AccessToken('rejected', $this->now + 3600),
            new AccessToken('fresh', $this->now + 3600),
        ]);
        $caching = $this->caching($provider, $shared);

        $rejected = $caching->getToken(self::RESOURCE);
        $caching->invalidate(self::RESOURCE, $rejected);

        $this->assertSame('fresh', $caching->getToken(self::RESOURCE)->token);
        $this->assertSame(
            'fresh',
            $this->caching(TokenAuthFakeProvider::constant('unused', $this->now + 3600), $shared)->getToken(self::RESOURCE)->token,
            'the shared tier holds the replacement'
        );
    }

    public function testInvalidateLeavesAnotherProcesssReplacementAlone(): void
    {
        $shared = new ArrayCache();
        $mine = $this->caching(TokenAuthFakeProvider::constant('rejected', $this->now + 3600), $shared);
        $rejected = $mine->getToken(self::RESOURCE);

        // Another worker already noticed and stored a good one.
        $shared->clear();
        $this->caching(TokenAuthFakeProvider::constant('good', $this->now + 3600), $shared)->getToken(self::RESOURCE);

        $mine->invalidate(self::RESOURCE, $rejected);

        $this->assertSame(
            'good',
            $this->caching(TokenAuthFakeProvider::constant('unused', $this->now + 3600), $shared)->getToken(self::RESOURCE)->token
        );
    }

    public function testGarbageInTheSharedPoolIsIgnored(): void
    {
        $shared = new ArrayCache();
        $provider = TokenAuthFakeProvider::constant('tok', $this->now + 3600);
        $this->caching($provider, $shared)->getToken(self::RESOURCE);
        foreach ($this->sharedKeys($shared) as $key) {
            $shared->set($key, ['token' => 42]);
        }

        $second = TokenAuthFakeProvider::constant('tok2', $this->now + 3600);
        $this->assertSame('tok2', $this->caching($second, $shared)->getToken(self::RESOURCE)->token);
    }

    public function testANegativeRefreshMarginIsRejected(): void
    {
        $this->expectException(PropulsionException::class);
        new CachingTokenProvider(TokenAuthFakeProvider::constant('t', $this->now + 1), null, -1);
    }

    /**
     * @return list<string>
     */
    private function sharedKeys(ArrayCache $cache): array
    {
        $entries = (new \ReflectionProperty(ArrayCache::class, 'entries'))->getValue($cache);
        $this->assertIsArray($entries);

        return array_values(array_map('strval', array_keys($entries)));
    }
}
