<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection\Auth;

use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;

/**
 * Caches a {@see TokenProvider}'s tokens in two tiers, so a connection costs a
 * token fetch only when every cached copy is near expiry.
 *
 *  - **Process memory**, always. Under a persistent worker this alone means
 *    one fetch per process per token lifetime.
 *  - **A shared PSR-16 pool**, when one is configured. This is what matters
 *    under PHP-FPM, where process memory dies with every request: APCu shares
 *    one token across every worker on the host, Redis across hosts.
 *
 * A token is replaced *before* it expires -- `refreshBefore` seconds early,
 * capped at half its remaining lifetime when it was fetched, so a short-lived
 * token is not treated as stale the moment it arrives. Between that refresh
 * point and the real expiry the token is still accepted by the server, which
 * buys the one piece of resilience worth having here: if the refresh fails
 * (an identity endpoint blip, IMDS throttling), the still-valid token is used
 * and the failure logged rather than turned into a failed connection.
 *
 * Shared entries are keyed by the provider's class, its
 * {@see TokenProvider::principalKey()} and the resource, never by datasource:
 * two datasources authenticating as the same identity share a token, two
 * identities never do. A provider that cannot name its principal is cached in
 * process memory only.
 */
final class CachingTokenProvider
{
    /** Tokens are not handed out with less than this left, even as a fallback. */
    private const MINIMUM_REMAINING_SECONDS = 30;

    private const CACHE_KEY_PREFIX = 'propulsion_db_token_';

    /** @var array<string, array{token: AccessToken, refreshAt: int}> */
    private array $memory = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param CacheInterface|null    $sharedCache   pool shared beyond this process, or null for process memory only
     * @param int                    $refreshBefore seconds before expiry at which a token is replaced
     * @param (\Closure(): int)|null $clock         current unix time
     */
    public function __construct(
        private readonly TokenProvider $provider,
        private readonly ?CacheInterface $sharedCache = null,
        private readonly int $refreshBefore = 300,
        ?\Closure $clock = null,
    ) {
        if ($refreshBefore < 0) {
            throw new PropulsionException('The token refresh margin must not be negative, got ' . $refreshBefore);
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function getProvider(): TokenProvider
    {
        return $this->provider;
    }

    /**
     * A token for $resource that is not due for refresh, from the cheapest
     * tier that has one.
     *
     * @throws PropulsionException if no cached token is usable and the fetch fails
     */
    public function getToken(string $resource): AccessToken
    {
        $now = ($this->clock)();

        $cached = $this->memory[$resource] ?? null;
        if ($cached !== null && $now < $cached['refreshAt']) {
            return $cached['token'];
        }

        $sharedKey = $this->sharedKey($resource);
        $shared = $this->readShared($sharedKey);
        if ($shared !== null && $now < $shared['refreshAt']) {
            $this->memory[$resource] = $shared;

            return $shared['token'];
        }

        try {
            $token = $this->provider->fetchToken($resource);
        } catch (PropulsionException $e) {
            // Prefer whichever cached copy lives longest; either is still a
            // token the server accepts.
            $fallback = $cached;
            if ($shared !== null && ($fallback === null || $shared['token']->expiresAt > $fallback['token']->expiresAt)) {
                $fallback = $shared;
            }
            if ($fallback !== null && $fallback['token']->expiresAt - $now >= self::MINIMUM_REMAINING_SECONDS) {
                Propulsion::log(
                    'Refreshing the database access token failed; using the cached one until it expires',
                    LogLevel::WARNING,
                    ['resource' => $resource, 'expires_at' => $fallback['token']->expiresAt, 'exception' => $e]
                );

                return $fallback['token'];
            }

            throw $e;
        }

        $entry = ['token' => $token, 'refreshAt' => $this->refreshAt($token, $now)];
        $this->memory[$resource] = $entry;
        $this->writeShared($sharedKey, $entry, $now);

        return $token;
    }

    /**
     * Forget $rejected wherever it is cached, after the server refused it.
     *
     * Only entries still holding that exact token are dropped: another
     * process may already have replaced the shared copy with a good one, and
     * that one should survive.
     */
    public function invalidate(string $resource, AccessToken $rejected): void
    {
        if (($this->memory[$resource]['token'] ?? null)?->token === $rejected->token) {
            unset($this->memory[$resource]);
        }

        $sharedKey = $this->sharedKey($resource);
        if ($sharedKey !== null && ($this->readShared($sharedKey)['token'] ?? null)?->token === $rejected->token) {
            $this->sharedCache?->delete($sharedKey);
        }
    }

    private function refreshAt(AccessToken $token, int $now): int
    {
        $remaining = $token->expiresAt - $now;

        return $token->expiresAt - min($this->refreshBefore, intdiv($remaining, 2));
    }

    private function sharedKey(string $resource): ?string
    {
        if ($this->sharedCache === null) {
            return null;
        }
        $principal = $this->provider->principalKey();
        if ($principal === null) {
            return null;
        }

        // Hashed: PSR-16 reserves {}()/\@: in keys, and resources are URLs.
        return self::CACHE_KEY_PREFIX . hash('sha256', $this->provider::class . "\0" . $principal . "\0" . $resource);
    }

    /**
     * The shared entry under $key, or null when there is none or it is not
     * in the shape {@see writeShared()} stores -- a pool shared with other
     * software is not trusted to hold only our values.
     *
     * @return array{token: AccessToken, refreshAt: int}|null
     */
    private function readShared(?string $key): ?array
    {
        if ($key === null || $this->sharedCache === null) {
            return null;
        }

        $value = $this->sharedCache->get($key);
        if (!is_array($value)) {
            return null;
        }
        $token = $value['token'] ?? null;
        $expiresAt = $value['expires_at'] ?? null;
        $refreshAt = $value['refresh_at'] ?? null;
        if (!is_string($token) || $token === '' || !is_int($expiresAt) || !is_int($refreshAt)) {
            return null;
        }

        return ['token' => new AccessToken($token, $expiresAt), 'refreshAt' => $refreshAt];
    }

    /**
     * @param array{token: AccessToken, refreshAt: int} $entry
     */
    private function writeShared(?string $key, array $entry, int $now): void
    {
        if ($key === null || $this->sharedCache === null) {
            return;
        }
        $ttl = $entry['refreshAt'] - $now;
        if ($ttl <= 0) {
            return;
        }

        // Stored as plain scalars rather than the AccessToken object: the
        // file driver unserializes with allowed_classes => false.
        $this->sharedCache->set($key, [
            'token' => $entry['token']->token,
            'expires_at' => $entry['token']->expiresAt,
            'refresh_at' => $entry['refreshAt'],
        ], $ttl);
    }
}
