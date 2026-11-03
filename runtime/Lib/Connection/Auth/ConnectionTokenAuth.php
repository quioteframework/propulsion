<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection\Auth;

use Propulsion\Cache\Driver\ApcuCache;
use Propulsion\Cache\Driver\FileCache;
use Propulsion\Exception\PropulsionException;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Turns a connection's `dsn`/`user`/`password` plus its `auth` block into the
 * arguments PDO is actually constructed with.
 *
 * One instance per process side: the runtime keeps one on
 * {@see \Propulsion\ServiceContainer}, the generator commands (migrations,
 * sql diff) their own. It owns the {@see CachingTokenProvider}s, so the
 * in-memory token tier lives as long as it does; providers are reused across
 * connections that are configured identically and kept apart when they are
 * not -- in particular when the identity differs.
 *
 * How the token reaches the server depends on the PDO driver, read off the
 * DSN prefix:
 *
 *  - `pgsql`, `mysql`: as the password, with the configured `user` naming the
 *    database role mapped to the identity. Azure Database for MySQL asks for
 *    it via `mysql_clear_password`, which mysqlnd answers unconditionally --
 *    so the connection must use TLS, or the token crosses the network in
 *    clear text.
 *  - `sqlsrv`: as `AccessToken=` in the DSN, with no user or password, as
 *    pdo_sqlsrv requires.
 *  - anything else is refused. In particular `dblib`: FreeTDS has no way to
 *    present an Entra token -- use pdo_sqlsrv for Azure SQL.
 */
final class ConnectionTokenAuth
{
    /** @var array<string, CachingTokenProvider> */
    private array $providers = [];

    /** @var array<string, CacheInterface|null> */
    private array $sharedCaches = [];

    public function __construct(
        private ?ClientInterface $httpClient = null,
        private ?CacheInterface $tokenCache = null,
    ) {
    }

    /**
     * PSR-18 client for the built-in provider's token requests, bypassing
     * php-http/discovery. Applies to providers built after this call.
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->httpClient = $client;
        $this->providers = [];
    }

    /**
     * The pool behind `auth.cache.driver = psr16` -- e.g. Redis shared by
     * every host. Applies to providers built after this call.
     */
    public function setTokenCache(CacheInterface $cache): void
    {
        $this->tokenCache = $cache;
        $this->providers = [];
        $this->sharedCaches = [];
    }

    /**
     * Credentials for one connection attempt.
     *
     * @param  mixed  $auth the connection's raw `auth` value; null means password authentication, unchanged
     * @param  string $path where `auth` sits in the configuration, for error messages
     * @throws PropulsionException on a misconfiguration or when no token can be obtained
     */
    public function credentialsFor(string $dsn, ?string $user, #[\SensitiveParameter] ?string $password, mixed $auth, string $path): ConnectionCredentials
    {
        if ($auth === null) {
            return ConnectionCredentials::static($dsn, $user, $password);
        }

        $config = TokenAuthConfig::fromConfig($auth, $path);
        $driver = self::driverOf($dsn, $path);
        $resource = $config->resource ?? self::defaultResource($driver);

        if ($password !== null && $password !== '') {
            throw new PropulsionException(sprintf(
                'The connection at "%s" configures both a password and token authentication ("%s"); remove the password',
                self::parentPath($path),
                $path
            ));
        }

        $tokenSource = $this->providerFor($config);

        return $this->present($dsn, $user, $driver, $tokenSource->getToken($resource), $resource, $tokenSource, $config, $path);
    }

    /**
     * After the server refused $rejected's login: drop its token from every
     * cache tier and build credentials from a new one -- or return null when
     * there is nothing different to try (password authentication, or the
     * issuer hands back the same token), so the caller rethrows the original
     * error instead of looping on a rejection that will repeat.
     */
    public function retryAfterRejection(ConnectionCredentials $rejected, ?string $user, string $originalDsn, string $path): ?ConnectionCredentials
    {
        if ($rejected->token === null || $rejected->tokenSource === null || $rejected->resource === null || $rejected->config === null) {
            return null;
        }

        $rejected->tokenSource->invalidate($rejected->resource, $rejected->token);
        try {
            $token = $rejected->tokenSource->getToken($rejected->resource);
        } catch (PropulsionException) {
            return null;
        }
        if ($token->token === $rejected->token->token) {
            return null;
        }

        return $this->present(
            $originalDsn,
            $user,
            self::driverOf($originalDsn, $path),
            $token,
            $rejected->resource,
            $rejected->tokenSource,
            $rejected->config,
            $path
        );
    }

    private function present(
        string $dsn,
        ?string $user,
        string $driver,
        AccessToken $token,
        string $resource,
        CachingTokenProvider $tokenSource,
        TokenAuthConfig $config,
        string $path,
    ): ConnectionCredentials {
        if ($driver === 'sqlsrv') {
            if ($user !== null && $user !== '') {
                throw new PropulsionException(sprintf(
                    'The connection at "%s" uses token authentication with pdo_sqlsrv, which identifies the login by the token alone; remove "user"',
                    self::parentPath($path)
                ));
            }
            if (preg_match('/(?:^sqlsrv:|;)\s*(AccessToken|Authentication|UID|PWD)\s*=/i', $dsn, $m) === 1) {
                throw new PropulsionException(sprintf(
                    'The DSN of the connection at "%s" already sets %s, which pdo_sqlsrv does not allow alongside an access token',
                    self::parentPath($path),
                    $m[1]
                ));
            }

            return new ConnectionCredentials(rtrim($dsn, "; \t") . ';AccessToken=' . $token->token, null, null, $token, $resource, $tokenSource, $config);
        }

        if ($user === null || $user === '') {
            throw new PropulsionException(sprintf(
                'The connection at "%s" uses token authentication but has no "user": set it to the database role created for the identity',
                self::parentPath($path)
            ));
        }

        return new ConnectionCredentials($dsn, $user, $token->token, $token, $resource, $tokenSource, $config);
    }

    private function providerFor(TokenAuthConfig $config): CachingTokenProvider
    {
        $provider = $config->provider;
        $identity = $provider instanceof TokenProvider
            ? 'instance:' . spl_object_id($provider)
            : 'azure:' . ($config->clientId ?? '');
        $key = $identity . '|' . $config->refreshBefore . '|' . $config->cacheDriver . '|' . ($config->cacheDirectory ?? '');

        return $this->providers[$key] ??= new CachingTokenProvider(
            $provider instanceof TokenProvider ? $provider : new AzureManagedIdentity($config->clientId, $this->httpClient),
            $this->sharedCache($config),
            $config->refreshBefore
        );
    }

    private function sharedCache(TokenAuthConfig $config): ?CacheInterface
    {
        $key = $config->cacheDriver . '|' . ($config->cacheDirectory ?? '');
        if (array_key_exists($key, $this->sharedCaches)) {
            return $this->sharedCaches[$key];
        }

        return $this->sharedCaches[$key] = match ($config->cacheDriver) {
            TokenAuthConfig::CACHE_NONE => null,
            TokenAuthConfig::CACHE_AUTO => self::apcuUsable() ? new ApcuCache() : null,
            TokenAuthConfig::CACHE_APCU => new ApcuCache(),
            // Owner-only permissions: what is cached here are bearer tokens.
            TokenAuthConfig::CACHE_FILE => new FileCache((string) $config->cacheDirectory, FileCache::DEFAULT_LEVELS, null, null, 0o700, 0o600),
            TokenAuthConfig::CACHE_PSR16 => $this->tokenCache ?? throw new PropulsionException(
                'Token cache driver "psr16" requires a pool supplied by the application: call '
                . 'Propulsion::setConnectionTokenCache() before the first connection, or choose another auth.cache.driver'
            ),
            default => throw new PropulsionException('Unknown token cache driver "' . $config->cacheDriver . '"'),
        };
    }

    private static function apcuUsable(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    private static function driverOf(string $dsn, string $path): string
    {
        if (preg_match('/^\s*([A-Za-z0-9_]+):/', $dsn, $m) !== 1) {
            throw new PropulsionException(sprintf('Cannot tell the PDO driver from the DSN of the connection at "%s"', self::parentPath($path)));
        }
        $driver = strtolower($m[1]);

        if (!in_array($driver, ['pgsql', 'mysql', 'sqlsrv'], true)) {
            throw new PropulsionException(sprintf(
                'Token authentication is not supported for the "%s" PDO driver (connection at "%s"); it is for pgsql, mysql and sqlsrv%s',
                $driver,
                self::parentPath($path),
                $driver === 'dblib' ? '. FreeTDS cannot present an Entra access token -- connect to Azure SQL through pdo_sqlsrv instead' : ''
            ));
        }

        return $driver;
    }

    private static function defaultResource(string $driver): string
    {
        return $driver === 'sqlsrv' ? AzureManagedIdentity::RESOURCE_AZURE_SQL : AzureManagedIdentity::RESOURCE_OSS_RDBMS;
    }

    private static function parentPath(string $path): string
    {
        $dot = strrpos($path, '.');

        return $dot === false ? $path : substr($path, 0, $dot);
    }
}
