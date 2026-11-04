<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Connection\Auth\AccessToken;
use Propulsion\Exception\PropulsionException;
use Propulsion\Generator\Util\BuildTimeConnection;
use Propulsion\Propulsion;

/**
 * Token authentication through the real connection paths -- the runtime's
 * Propulsion::getConnection() and the generator's BuildTimeConnection -- with
 * a real server on the other end.
 *
 * There is no Entra ID in a testcontainer, so a fake provider plays the
 * identity endpoint and hands out the container's password as the "token".
 * The server cannot tell the difference, which is exactly what is under test:
 * that the token arrives where the driver expects a password, and that a
 * rejected cached token is replaced and retried once.
 *
 * Only pgsql and mysql/mariadb take a token as the password; on the other
 * platforms the integration cases skip, and the sqlite case below covers the
 * refusal path without Docker.
 */
class TokenAuthConnectionTest extends TestCase
{
    private const DATASOURCE = 'token_auth_test';

    private ?PropulsionStateSnapshot $state = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = PropulsionStateSnapshot::capture();
        BuildTimeConnection::reset();
    }

    protected function tearDown(): void
    {
        Propulsion::forceReconnect(self::DATASOURCE);
        $this->state?->restore();
        BuildTimeConnection::reset();
        parent::tearDown();
    }

    /**
     * @return array{dsn: string, user: string, password: string}
     */
    private function containerOrSkip(): array
    {
        if (!in_array(IntegrationDatabase::pdoDriverPrefix(), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('token-as-password applies to pgsql and mysql only');
        }
        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        [$user, $password] = IntegrationDatabase::pdoCredentials();

        return [
            'dsn' => IntegrationDatabase::pdoDsn($conn['host'], $conn['port'], 'propulsion_test'),
            'user' => $user,
            'password' => $password,
        ];
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function configure(string $adapter, array $connection): void
    {
        /** @var array<string, mixed> $config */
        $config = Propulsion::getConfiguration(\Propulsion\Config\PropulsionConfiguration::TYPE_ARRAY) ?? [];
        $datasources = is_array($config['datasources'] ?? null) ? $config['datasources'] : [];
        $datasources[self::DATASOURCE] = ['adapter' => $adapter, 'connection' => $connection];
        $config['datasources'] = $datasources;
        Propulsion::setConfiguration($config);
    }

    public function testARuntimeConnectionLogsInWithTheToken(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant($db['password'], time() + 3600);
        $this->configure(IntegrationDatabase::generatorPlatform(), [
            'dsn' => $db['dsn'],
            'user' => $db['user'],
            'auth' => ['provider' => $provider, 'cache' => ['driver' => 'none']],
        ]);

        $con = Propulsion::getConnection(self::DATASOURCE);

        $this->assertSame('1', (string) $con->query('SELECT 1')?->fetchColumn());
        $this->assertSame(1, $provider->fetches);

        Propulsion::forceReconnect(self::DATASOURCE);
        Propulsion::getConnection(self::DATASOURCE);
        $this->assertSame(1, $provider->fetches, 'the reconnect reused the cached token');
    }

    public function testARejectedCachedTokenIsReplacedAndRetriedOnce(): void
    {
        $db = $this->containerOrSkip();
        $provider = new TokenAuthFakeProvider([
            new AccessToken('revoked-token', time() + 3600),
            new AccessToken($db['password'], time() + 3600),
        ]);
        $this->configure(IntegrationDatabase::generatorPlatform(), [
            'dsn' => $db['dsn'],
            'user' => $db['user'],
            'auth' => ['provider' => $provider, 'cache' => ['driver' => 'none']],
        ]);

        $con = Propulsion::getConnection(self::DATASOURCE);

        $this->assertSame('1', (string) $con->query('SELECT 1')?->fetchColumn());
        $this->assertSame(2, $provider->fetches);
    }

    public function testARejectionThatATokenRefreshCannotFixSurfacesTheServersError(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant('wrong-token', time() + 3600);
        $this->configure(IntegrationDatabase::generatorPlatform(), [
            'dsn' => $db['dsn'],
            'user' => $db['user'],
            'auth' => ['provider' => $provider, 'cache' => ['driver' => 'none']],
        ]);

        try {
            Propulsion::getConnection(self::DATASOURCE);
            $this->fail('expected the login to fail');
        } catch (PropulsionException $e) {
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            $this->assertStringNotContainsString('wrong-token', $e->getMessage());
        }
        $this->assertSame(2, $provider->fetches, 'one refresh attempt, no loop');
    }

    public function testABuildTimeConnectionLogsInWithItsOwnToken(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant($db['password'], time() + 3600, 'migration-identity');

        $pdo = BuildTimeConnection::open('app', $db['dsn'], $db['user'], null, [
            'provider' => $provider,
            'cache' => ['driver' => 'none'],
        ]);

        $this->assertSame('1', (string) $pdo->query('SELECT 1')?->fetchColumn());
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testTheMigrationManagerUsesTheBuildConnectionsAuth(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant($db['password'], time() + 3600, 'migration-identity');
        $manager = new \Propulsion\Generator\Util\PropulsionMigrationManager();
        $manager->setConnections(['app' => [
            'adapter' => IntegrationDatabase::generatorPlatform(),
            'dsn' => $db['dsn'],
            'user' => $db['user'],
            'auth' => ['provider' => $provider, 'cache' => ['driver' => 'none']],
        ]]);

        $pdo = $manager->getPdoConnection('app');

        $this->assertSame('1', (string) $pdo->query('SELECT 1')?->fetchColumn());
        $this->assertSame(1, $provider->fetches);
    }

    public function testGeneratorConfigCarriesAuthIntoTheBuildPdo(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant($db['password'], time() + 3600, 'migration-identity');
        $config = new \Propulsion\Generator\Config\GeneratorConfig([
            'propulsion.buildtimeConfigArray' => [
                'default' => 'app',
                'datasources' => ['app' => [
                    'adapter' => IntegrationDatabase::generatorPlatform(),
                    'dsn' => $db['dsn'],
                    'user' => $db['user'],
                    'auth' => ['provider' => $provider, 'cache' => ['driver' => 'none']],
                ]],
            ],
        ]);

        $pdo = $config->getBuildPDO('app');

        $this->assertSame('1', (string) $pdo->query('SELECT 1')?->fetchColumn());
        $this->assertSame(1, $provider->fetches);
    }

    public function testTokenAuthOnAnUnsupportedDriverIsRefusedBeforeConnecting(): void
    {
        $provider = TokenAuthFakeProvider::constant('t', time() + 3600);
        $this->configure('sqlite', [
            'dsn' => 'sqlite::memory:',
            'auth' => ['provider' => $provider],
        ]);

        try {
            Propulsion::getConnection(self::DATASOURCE);
            $this->fail('expected a refusal');
        } catch (PropulsionException $e) {
            $this->assertStringContainsString('not supported for the "sqlite" PDO driver', $e->getMessage());
            $this->assertStringContainsString('datasources.' . self::DATASOURCE . '.connection', $e->getMessage());
        }
        $this->assertSame(0, $provider->fetches);
    }

    public function testAnInvalidAuthBlockNamesItsPath(): void
    {
        $this->configure('sqlite', [
            'dsn' => 'sqlite::memory:',
            'auth' => ['provider' => 'azure_managed_identity', 'clientid' => 'typo'],
        ]);

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('datasources.' . self::DATASOURCE . '.connection.auth.clientid');
        Propulsion::getConnection(self::DATASOURCE);
    }

    public function testABuildTimeConnectionReplacesARejectedTokenAndRetriesOnce(): void
    {
        $db = $this->containerOrSkip();
        $provider = new TokenAuthFakeProvider([
            new AccessToken('revoked-token', time() + 3600),
            new AccessToken($db['password'], time() + 3600),
        ], 'migration-identity');

        $pdo = BuildTimeConnection::open('app', $db['dsn'], $db['user'], null, [
            'provider' => $provider,
            'cache' => ['driver' => 'none'],
        ]);

        $this->assertSame('1', (string) $pdo->query('SELECT 1')?->fetchColumn());
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE), 'the retried connection is set up like the first');
        $this->assertSame(2, $provider->fetches);
    }

    public function testABuildTimeRejectionThatATokenRefreshCannotFixSurfacesTheServersError(): void
    {
        $db = $this->containerOrSkip();
        $provider = TokenAuthFakeProvider::constant('wrong-token', time() + 3600, 'migration-identity');

        try {
            BuildTimeConnection::open('app', $db['dsn'], $db['user'], null, [
                'provider' => $provider,
                'cache' => ['driver' => 'none'],
            ]);
            $this->fail('expected the login to fail');
        } catch (\PDOException $e) {
            $this->assertStringNotContainsString('wrong-token', $e->getMessage());
        }
        $this->assertSame(2, $provider->fetches, 'one refresh attempt, no loop');
    }

    public function testTheRegisteredHttpClientAndTokenCacheAreUsed(): void
    {
        // Registered after configuring, as documented: setConfiguration()
        // starts the token state afresh.
        $this->configure('sqlite', ['dsn' => 'sqlite::memory:']);
        $http = TokenAuthHttpClient::json(['access_token' => 'from-registered-client', 'expires_on' => (string) (time() + 3600)]);
        $pool = new ArrayCache();
        Propulsion::setConnectionAuthHttpClient($http);
        Propulsion::setConnectionTokenCache($pool);

        $credentials = Propulsion::getServiceContainer()->getConnectionTokenAuth()->credentialsFor('pgsql:host=x', 'app', null, [
            'provider' => 'azure_managed_identity',
            'client_id' => 'runtime-mi',
            'cache' => ['driver' => 'psr16'],
        ], 'datasources.app.connection.auth');

        $this->assertSame('from-registered-client', $credentials->password);
        $this->assertCount(1, $http->requests);
        $this->assertSame(1, $pool->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function nonStringCredentials(): iterable
    {
        yield 'dsn' => [['dsn' => 42], 'The dsn of datasource [' . self::DATASOURCE . '] must be a string, got int'];
        yield 'user' => [['dsn' => 'sqlite::memory:', 'user' => ['u']], 'The user of datasource [' . self::DATASOURCE . '] must be a string, got array'];
        yield 'password' => [['dsn' => 'sqlite::memory:', 'password' => 1.5], 'The password of datasource [' . self::DATASOURCE . '] must be a string, got float'];
    }

    /**
     * @param array<string, mixed> $connection
     */
    #[DataProvider('nonStringCredentials')]
    public function testNonStringConnectionCredentialsAreRejected(array $connection, string $message): void
    {
        $this->configure('sqlite', $connection);

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage($message);
        Propulsion::getConnection(self::DATASOURCE);
    }
}
