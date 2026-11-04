<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Connection\Auth\AccessToken;
use Propulsion\Connection\Auth\AzureManagedIdentity;
use Propulsion\Connection\Auth\ConnectionTokenAuth;
use Propulsion\Connection\Auth\TokenAuthConfig;
use Propulsion\Exception\PropulsionException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Validation of the `auth` block, and where the token ends up in the PDO
 * arguments for each driver.
 */
class ConnectionTokenAuthTest extends TestCase
{
    private const PATH = 'datasources.app.connection.auth';

    private function auth(): ConnectionTokenAuth
    {
        return new ConnectionTokenAuth();
    }

    /**
     * @return array<string, mixed>
     */
    private static function fake(string $token = 'the-token'): array
    {
        return ['provider' => TokenAuthFakeProvider::constant($token, time() + 3600), 'cache' => ['driver' => 'none']];
    }

    public function testWithoutAuthTheConfiguredCredentialsPassThrough(): void
    {
        $credentials = $this->auth()->credentialsFor('pgsql:host=db', 'u', 'p', null, self::PATH);

        $this->assertSame(['pgsql:host=db', 'u', 'p'], [$credentials->dsn, $credentials->user, $credentials->password]);
        $this->assertNull($credentials->token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passwordDrivers(): iterable
    {
        yield 'pgsql' => ['pgsql:host=x.postgres.database.azure.com;dbname=app;sslmode=require'];
        yield 'mysql' => ['mysql:host=x.mysql.database.azure.com;dbname=app'];
    }

    #[DataProvider('passwordDrivers')]
    public function testPgsqlAndMysqlTakeTheTokenAsThePassword(string $dsn): void
    {
        $credentials = $this->auth()->credentialsFor($dsn, 'app-identity', null, self::fake(), self::PATH);

        $this->assertSame($dsn, $credentials->dsn);
        $this->assertSame('app-identity', $credentials->user);
        $this->assertSame('the-token', $credentials->password);
    }

    public function testSqlsrvTakesTheTokenInTheDsn(): void
    {
        $credentials = $this->auth()->credentialsFor('sqlsrv:Server=x.database.windows.net;Database=app;', null, null, self::fake(), self::PATH);

        $this->assertSame('sqlsrv:Server=x.database.windows.net;Database=app;AccessToken=the-token', $credentials->dsn);
        $this->assertNull($credentials->user);
        $this->assertNull($credentials->password);
        $this->assertStringNotContainsString('the-token', print_r($credentials, true), 'redacted from dumps');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defaultResources(): iterable
    {
        yield 'pgsql' => ['pgsql:host=x', AzureManagedIdentity::RESOURCE_OSS_RDBMS];
        yield 'mysql' => ['mysql:host=x', AzureManagedIdentity::RESOURCE_OSS_RDBMS];
        yield 'sqlsrv' => ['sqlsrv:Server=x', AzureManagedIdentity::RESOURCE_AZURE_SQL];
    }

    #[DataProvider('defaultResources')]
    public function testTheResourceDefaultsByDriver(string $dsn, string $resource): void
    {
        $provider = TokenAuthFakeProvider::constant('t', time() + 3600);
        $user = str_starts_with($dsn, 'sqlsrv') ? null : 'u';

        $this->auth()->credentialsFor($dsn, $user, null, ['provider' => $provider, 'cache' => ['driver' => 'none']], self::PATH);

        $this->assertSame([$resource], $provider->resources);
    }

    public function testTheResourceCanBeOverriddenForSovereignClouds(): void
    {
        $provider = TokenAuthFakeProvider::constant('t', time() + 3600);

        $this->auth()->credentialsFor('pgsql:host=x', 'u', null, [
            'provider' => $provider,
            'resource' => 'https://ossrdbms-aad.database.chinacloudapi.cn',
            'cache' => ['driver' => 'none'],
        ], self::PATH);

        $this->assertSame(['https://ossrdbms-aad.database.chinacloudapi.cn'], $provider->resources);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function misconfigurations(): iterable
    {
        yield 'password and auth together' => ['pgsql:host=x', 'u', 'secret', 'remove the password'];
        yield 'pgsql without a user' => ['pgsql:host=x', null, null, 'has no "user"'];
        yield 'sqlsrv with a user' => ['sqlsrv:Server=x', 'u', null, 'remove "user"'];
        yield 'sqlsrv dsn already authenticating' => ['sqlsrv:Server=x;Authentication=ActiveDirectoryMsi', null, null, 'already sets Authentication'];
        yield 'sqlsrv dsn with a uid' => ['sqlsrv:Server=x; UID=sa', null, null, 'already sets UID'];
        yield 'dblib' => ['dblib:host=x', 'u', null, 'connect to Azure SQL through pdo_sqlsrv'];
        yield 'sqlite' => ['sqlite::memory:', null, null, 'not supported for the "sqlite" PDO driver'];
        yield 'no driver prefix' => ['host=x;dbname=app', 'u', null, 'Cannot tell the PDO driver from the DSN of the connection at "datasources.app.connection"'];
    }

    #[DataProvider('misconfigurations')]
    public function testMisconfigurationsAreRefusedWithTheReason(string $dsn, ?string $user, ?string $password, string $message): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage($message);

        $this->auth()->credentialsFor($dsn, $user, $password, self::fake(), self::PATH);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidAuthBlocks(): iterable
    {
        yield 'not an array' => ['azure', 'must be an array'];
        yield 'no provider' => [[], '.provider" must be'];
        yield 'unknown provider name' => [['provider' => 'aws'], 'got "aws"'];
        yield 'unknown key' => [['provider' => 'azure_managed_identity', 'clientid' => 'x'], 'Unknown Propulsion configuration option "' . self::PATH . '.clientid"'];
        yield 'empty client id' => [['provider' => 'azure_managed_identity', 'client_id' => ''], 'got an empty string'];
        yield 'negative refresh' => [['provider' => 'azure_managed_identity', 'refresh_before' => -5], 'must not be negative'];
        yield 'unknown cache driver' => [['provider' => 'azure_managed_identity', 'cache' => ['driver' => 'redis']], 'must be one of'];
        yield 'file without a directory' => [['provider' => 'azure_managed_identity', 'cache' => ['driver' => 'file']], 'cache.directory'];
        yield 'directory without file' => [['provider' => 'azure_managed_identity', 'cache' => ['directory' => '/tmp']], 'cache.directory'];
    }

    #[DataProvider('invalidAuthBlocks')]
    public function testInvalidAuthBlocksAreRejected(mixed $auth, string $message): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage($message);

        TokenAuthConfig::fromConfig($auth, self::PATH);
    }

    public function testClientIdOnlyAppliesToTheBuiltInProvider(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('only applies to the built-in');

        TokenAuthConfig::fromConfig(['provider' => TokenAuthFakeProvider::constant('t', time() + 60), 'client_id' => 'x'], self::PATH);
    }

    public function testPsr16WithoutARegisteredPoolIsAClearError(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Propulsion::setConnectionTokenCache()');

        $this->auth()->credentialsFor('pgsql:host=x', 'u', null, [
            'provider' => TokenAuthFakeProvider::constant('t', time() + 60),
            'cache' => ['driver' => 'psr16'],
        ], self::PATH);
    }

    public function testRuntimeAndMigrationIdentitiesGetTheirOwnTokensFromOneSharedPool(): void
    {
        // End to end through the built-in provider: two connections configured
        // with different user-assigned identities, one process, one pool.
        $factory = new Psr17Factory();
        $http = new TokenAuthHttpClient(static function (RequestInterface $request) use ($factory): ResponseInterface {
            parse_str($request->getUri()->getQuery(), $query);
            $client = is_string($query['client_id'] ?? null) ? $query['client_id'] : 'system';

            return $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'access_token' => 'token-for-' . $client,
                'expires_on' => (string) (time() + 3600),
            ], JSON_THROW_ON_ERROR)));
        });
        $auth = new ConnectionTokenAuth($http, new ArrayCache());

        $runtime = $auth->credentialsFor('pgsql:host=x', 'app_rw', null, [
            'provider' => 'azure_managed_identity', 'client_id' => 'runtime-mi', 'cache' => ['driver' => 'psr16'],
        ], 'datasources.app.connection.auth');
        $migrations = $auth->credentialsFor('pgsql:host=x', 'app_owner', null, [
            'provider' => 'azure_managed_identity', 'client_id' => 'migration-mi', 'cache' => ['driver' => 'psr16'],
        ], 'datasources.app.auth');
        $runtimeAgain = $auth->credentialsFor('pgsql:host=x', 'app_rw', null, [
            'provider' => 'azure_managed_identity', 'client_id' => 'runtime-mi', 'cache' => ['driver' => 'psr16'],
        ], 'datasources.app.connection.auth');

        $this->assertSame('token-for-runtime-mi', $runtime->password);
        $this->assertSame('token-for-migration-mi', $migrations->password);
        $this->assertSame('token-for-runtime-mi', $runtimeAgain->password);
        $this->assertCount(2, $http->requests, 'one fetch per identity, the repeat served from cache');
    }

    public function testRetryAfterRejectionUsesADifferentToken(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('stale', time() + 3600),
            new AccessToken('fresh', time() + 3600),
        ]);
        $auth = $this->auth();
        $config = ['provider' => $provider, 'cache' => ['driver' => 'none']];

        $first = $auth->credentialsFor('pgsql:host=x', 'u', null, $config, self::PATH);
        $retry = $auth->retryAfterRejection($first, 'u', 'pgsql:host=x', self::PATH);

        $this->assertNotNull($retry);
        $this->assertSame('fresh', $retry->password);
        $this->assertSame('fresh', $auth->credentialsFor('pgsql:host=x', 'u', null, $config, self::PATH)->password, 'and it is what is cached now');
    }

    public function testNoRetryWhenTheIssuerReturnsTheSameToken(): void
    {
        $auth = $this->auth();
        $first = $auth->credentialsFor('pgsql:host=x', 'u', null, self::fake('same'), self::PATH);

        $this->assertNull($auth->retryAfterRejection($first, 'u', 'pgsql:host=x', self::PATH));
    }

    public function testNoRetryForPasswordLogins(): void
    {
        $auth = $this->auth();
        $first = $auth->credentialsFor('pgsql:host=x', 'u', 'p', null, self::PATH);

        $this->assertNull($auth->retryAfterRejection($first, 'u', 'pgsql:host=x', self::PATH));
    }

    public function testIdenticallyConfiguredConnectionsShareOneProvider(): void
    {
        $provider = TokenAuthFakeProvider::constant('t', time() + 3600);
        $auth = $this->auth();
        $config = ['provider' => $provider, 'cache' => ['driver' => 'none']];

        $auth->credentialsFor('pgsql:host=primary', 'u', null, $config, 'datasources.app.connection.auth');
        $auth->credentialsFor('pgsql:host=replica', 'u', null, $config, 'datasources.app.slaves.connection.auth');

        $this->assertSame(1, $provider->fetches, 'the replica reuses the primary\'s token');
    }

    public function testAPoolRegisteredAfterConstructionBacksThePsr16Driver(): void
    {
        $pool = new ArrayCache();
        $auth = $this->auth();
        $auth->setTokenCache($pool);

        $credentials = $auth->credentialsFor('pgsql:host=x', 'u', null, [
            'provider' => TokenAuthFakeProvider::constant('pooled', time() + 3600),
            'cache' => ['driver' => 'psr16'],
        ], self::PATH);

        $this->assertSame('pooled', $credentials->password);
        $this->assertSame(1, $pool->count(), 'the token was written to the registered pool');
    }

    public function testNoRetryWhenFetchingAReplacementFails(): void
    {
        $provider = new TokenAuthFakeProvider([
            new AccessToken('stale', time() + 3600),
            new PropulsionException('identity endpoint down'),
        ]);
        $auth = $this->auth();
        $first = $auth->credentialsFor('pgsql:host=x', 'u', null, ['provider' => $provider, 'cache' => ['driver' => 'none']], self::PATH);

        $this->assertNull($auth->retryAfterRejection($first, 'u', 'pgsql:host=x', self::PATH), 'the server\'s rejection is what should surface');
        $this->assertSame(2, $provider->fetches);
    }

    public function testTheAutoCacheDriverWorksWhetherOrNotApcuIsUsable(): void
    {
        $credentials = $this->auth()->credentialsFor('pgsql:host=x', 'u', null, [
            'provider' => TokenAuthFakeProvider::constant('t', time() + 3600),
            'cache' => ['driver' => 'auto'],
        ], self::PATH);

        $this->assertSame('t', $credentials->password);
    }
}
