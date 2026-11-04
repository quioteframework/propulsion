<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Http\Discovery\ClassDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Propulsion\Connection\Auth\AzureManagedIdentity;
use Propulsion\Exception\PropulsionException;

/**
 * The built-in managed identity provider against a recorded HTTP client: which
 * endpoint each Azure host shape is asked, with what, and how the answers --
 * good and bad -- are read. No Azure involved; the request shapes are the
 * documented ones for IMDS, the App Service identity endpoint (2019-08-01) and
 * the Entra v2 token endpoint used by AKS Workload Identity.
 */
class AzureManagedIdentityTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $env
     */
    private function provider(TokenAuthHttpClient $http, array $env = [], ?string $clientId = null): AzureManagedIdentity
    {
        $factory = new Psr17Factory();

        return new AzureManagedIdentity($clientId, $http, $factory, $factory, $env, static fn (): int => self::NOW);
    }

    /**
     * @return array<string, string>
     */
    private static function query(\Psr\Http\Message\RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    public function testImdsIsUsedWhenNoOtherSourceIsPresent(): void
    {
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => (string) (self::NOW + 3600)]);

        $token = $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertSame('tok', $token->token);
        $this->assertSame(self::NOW + 3600, $token->expiresAt);
        $request = $http->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('169.254.169.254', $request->getUri()->getHost());
        $this->assertSame('/metadata/identity/oauth2/token', $request->getUri()->getPath());
        $this->assertSame('true', $request->getHeaderLine('Metadata'));
        $this->assertSame(
            ['api-version' => '2018-02-01', 'resource' => AzureManagedIdentity::RESOURCE_OSS_RDBMS],
            self::query($request),
            'no client_id: the system-assigned identity'
        );
    }

    public function testAUserAssignedIdentityIsSelectedByClientId(): void
    {
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => (string) (self::NOW + 3600)]);

        $this->provider($http, [], 'client-123')->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertSame('client-123', self::query($http->lastRequest())['client_id']);
    }

    public function testAzureClientIdFromTheEnvironmentDoesNotPickTheManagedIdentity(): void
    {
        // A variable shared by the app and its migration job must not quietly
        // decide which identity a connection runs as; that is configuration.
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => (string) (self::NOW + 3600)]);

        $this->provider($http, ['AZURE_CLIENT_ID' => 'from-env'])->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertArrayNotHasKey('client_id', self::query($http->lastRequest()));
    }

    public function testAppServiceEndpointIsUsedWhenItsVariablesArePresent(): void
    {
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => (string) (self::NOW + 3600)]);
        $env = ['IDENTITY_ENDPOINT' => 'http://localhost:42356/msi/token', 'IDENTITY_HEADER' => 'secret-header'];

        $provider = $this->provider($http, $env, 'client-123');
        $provider->fetchToken(AzureManagedIdentity::RESOURCE_AZURE_SQL);

        $this->assertSame(AzureManagedIdentity::SOURCE_APP_SERVICE, $provider->source());
        $request = $http->lastRequest();
        $this->assertSame('localhost', $request->getUri()->getHost());
        $this->assertSame('/msi/token', $request->getUri()->getPath());
        $this->assertSame('secret-header', $request->getHeaderLine('X-IDENTITY-HEADER'));
        $this->assertSame(
            ['api-version' => '2019-08-01', 'resource' => AzureManagedIdentity::RESOURCE_AZURE_SQL, 'client_id' => 'client-123'],
            self::query($request)
        );
    }

    public function testWorkloadIdentityExchangesTheFederatedToken(): void
    {
        $tokenFile = $this->tempFile("projected-sa-token\n");
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        $env = [
            'AZURE_FEDERATED_TOKEN_FILE' => $tokenFile,
            'AZURE_CLIENT_ID' => 'wi-client',
            'AZURE_TENANT_ID' => 'tenant-1',
            'AZURE_AUTHORITY_HOST' => 'https://login.microsoftonline.com',
        ];

        $provider = $this->provider($http, $env);
        $token = $provider->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertSame(AzureManagedIdentity::SOURCE_WORKLOAD_IDENTITY, $provider->source());
        $this->assertSame(self::NOW + 3599, $token->expiresAt, 'expires_in is relative to now');
        $request = $http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token', (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $form);
        $this->assertSame([
            'client_assertion' => 'projected-sa-token',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_id' => 'wi-client',
            'grant_type' => 'client_credentials',
            'scope' => 'https://ossrdbms-aad.database.windows.net/.default',
        ], $form);
    }

    public function testWorkloadIdentityPrefersTheConfiguredClientId(): void
    {
        // The migration job's identity, federated with the same service account.
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_in' => 3599]);
        $env = ['AZURE_FEDERATED_TOKEN_FILE' => $this->tempFile('sa'), 'AZURE_CLIENT_ID' => 'runtime-client', 'AZURE_TENANT_ID' => 't'];

        $this->provider($http, $env, 'migration-client')->fetchToken(AzureManagedIdentity::RESOURCE_AZURE_SQL);

        parse_str((string) $http->lastRequest()->getBody(), $form);
        $this->assertSame('migration-client', $form['client_id']);
        $this->assertSame('https://database.windows.net/.default', $form['scope'], 'no doubled slash before .default');
    }

    public function testWorkloadIdentityWithoutTenantIsAClearError(): void
    {
        $http = TokenAuthHttpClient::json([]);
        $env = ['AZURE_FEDERATED_TOKEN_FILE' => $this->tempFile('sa'), 'AZURE_CLIENT_ID' => 'c'];

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('AZURE_TENANT_ID');
        $this->provider($http, $env)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testAnUnreadableFederatedTokenFileIsAClearError(): void
    {
        $http = TokenAuthHttpClient::json([]);
        $env = ['AZURE_FEDERATED_TOKEN_FILE' => '/nonexistent/token', 'AZURE_CLIENT_ID' => 'c', 'AZURE_TENANT_ID' => 't'];

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('/nonexistent/token');
        $this->provider($http, $env)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testARefusalCarriesTheEndpointsExplanation(): void
    {
        $http = TokenAuthHttpClient::json(
            ['error' => 'invalid_request', 'error_description' => 'Identity not found'],
            400
        );

        try {
            $this->provider($http, [], 'wrong-client')->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
            $this->fail('expected an exception');
        } catch (PropulsionException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('Identity not found', $e->getMessage());
            $this->assertStringContainsString('wrong-client', $e->getMessage());
        }
    }

    public function testAResponseWithoutATokenIsRejected(): void
    {
        $http = TokenAuthHttpClient::json(['expires_on' => (string) (self::NOW + 3600)]);

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('without an access_token');
        $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testAnAlreadyExpiredTokenIsRejected(): void
    {
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => (string) (self::NOW - 1)]);

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('already expired');
        $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testAnUnreachableEndpointIsWrapped(): void
    {
        $http = new TokenAuthHttpClient(static function (\Psr\Http\Message\RequestInterface $request): never {
            throw new class('connection timed out') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                public function getRequest(): \Psr\Http\Message\RequestInterface
                {
                    throw new \LogicException('unused');
                }
            };
        });

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Could not reach the Azure IMDS managed identity token endpoint');
        $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testPrincipalKeysSeparateIdentities(): void
    {
        $http = TokenAuthHttpClient::json([]);

        $runtime = $this->provider($http, [], 'runtime-client')->principalKey();
        $migrations = $this->provider($http, [], 'migration-client')->principalKey();
        $system = $this->provider($http)->principalKey();

        $this->assertNotSame($runtime, $migrations);
        $this->assertNotSame($runtime, $system);
        $this->assertSame($runtime, $this->provider($http, [], 'runtime-client')->principalKey(), 'stable for the same identity');
    }

    public function testSystemAssignedAppServiceIdentitiesOfDifferentAppsDoNotShareAKey(): void
    {
        $http = TokenAuthHttpClient::json([]);
        $a = $this->provider($http, ['IDENTITY_ENDPOINT' => 'http://localhost/msi', 'IDENTITY_HEADER' => 'app-a'])->principalKey();
        $b = $this->provider($http, ['IDENTITY_ENDPOINT' => 'http://localhost/msi', 'IDENTITY_HEADER' => 'app-b'])->principalKey();

        $this->assertNotSame($a, $b);
        $this->assertStringNotContainsString('app-a', $a, 'the identity header is a secret and is only used hashed');
    }

    public function testTheTokenIsRedactedFromDumps(): void
    {
        $http = TokenAuthHttpClient::json(['access_token' => 'super-secret', 'expires_on' => (string) (self::NOW + 3600)]);

        $token = $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertStringNotContainsString('super-secret', print_r($token, true));
    }

    public function testAnEmptyClientIdIsRejected(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('omit it to use the default identity');

        new AzureManagedIdentity('');
    }

    public function testAnEmptyResourceIsRejected(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('resource must not be empty');

        $this->provider(TokenAuthHttpClient::json([]))->fetchToken('');
    }

    public function testWorkloadIdentityWithoutAClientIdIsAClearError(): void
    {
        $http = TokenAuthHttpClient::json([]);
        $env = ['AZURE_FEDERATED_TOKEN_FILE' => $this->tempFile('sa'), 'AZURE_TENANT_ID' => 't'];

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('AKS Workload Identity needs a client ID');
        $this->provider($http, $env)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testWorkloadIdentityPrincipalKeysNameTenantAndClient(): void
    {
        $http = TokenAuthHttpClient::json([]);
        $env = ['AZURE_FEDERATED_TOKEN_FILE' => $this->tempFile('sa'), 'AZURE_TENANT_ID' => 'tenant-a', 'AZURE_CLIENT_ID' => 'runtime-client'];

        $runtime = $this->provider($http, $env)->principalKey();
        $migrations = $this->provider($http, $env, 'migration-client')->principalKey();
        $otherTenant = $this->provider($http, ['AZURE_TENANT_ID' => 'tenant-b'] + $env)->principalKey();

        $this->assertNotSame($runtime, $migrations, 'the configured client ID wins over AZURE_CLIENT_ID');
        $this->assertNotSame($runtime, $otherTenant);
        $this->assertSame($runtime, $this->provider($http, $env)->principalKey());
    }

    public function testADateStringExpiresOnIsUnderstood(): void
    {
        // Very old App Service API versions answered with a date, not seconds.
        $http = TokenAuthHttpClient::json(['access_token' => 'tok', 'expires_on' => gmdate('D, d M Y H:i:s', self::NOW + 3600) . ' GMT']);

        $token = $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);

        $this->assertSame(self::NOW + 3600, $token->expiresAt);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function sources(): iterable
    {
        yield 'IMDS' => [[], 'IMDS managed identity'];
        yield 'App Service' => [['IDENTITY_ENDPOINT' => 'http://localhost/msi', 'IDENTITY_HEADER' => 'h'], 'App Service managed identity'];
        yield 'Workload Identity' => [['AZURE_FEDERATED_TOKEN_FILE' => '{tokenfile}', 'AZURE_TENANT_ID' => 't', 'AZURE_CLIENT_ID' => 'c'], 'Workload Identity'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('sources')]
    public function testATokenWithoutAnExpiryIsRejectedNamingItsSource(array $env, string $source): void
    {
        $env = array_map(fn (string $v): string => $v === '{tokenfile}' ? $this->tempFile('sa') : $v, $env);
        $http = TokenAuthHttpClient::json(['access_token' => 'tok']);

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('The Azure ' . $source . ' token endpoint returned a token without a usable expires_on or expires_in');
        $this->provider($http, $env)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    public function testARefusalWithoutAnExplanationStillCarriesTheStatus(): void
    {
        $http = new TokenAuthHttpClient(static fn (): \Psr\Http\Message\ResponseInterface => new \Nyholm\Psr7\Response(502, [], 'Bad Gateway'));

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/refused to issue a token for \S+: HTTP 502$/');
        $this->provider($http)->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
    }

    /**
     * @return iterable<string, array{bool, bool, bool, array<string, string>, string}>
     */
    public static function missingHttpPieces(): iterable
    {
        // [client given, request factory given, stream factory given, env, what the message names]
        yield 'client' => [false, true, true, [], 'a PSR-18 HTTP client'];
        yield 'request factory' => [true, false, true, [], 'a PSR-17 request factory'];
        // Only the Workload Identity POST needs a stream for its form body.
        yield 'stream factory' => [true, true, false, ['AZURE_FEDERATED_TOKEN_FILE' => '{tokenfile}', 'AZURE_TENANT_ID' => 't', 'AZURE_CLIENT_ID' => 'c'], 'a PSR-17 stream factory'];
    }

    /**
     * With nothing installed for php-http/discovery to find, the error says
     * what to install and how to bypass discovery -- not discovery's own
     * generic message.
     *
     * @param array<string, string> $env
     */
    #[DataProvider('missingHttpPieces')]
    public function testMissingHttpPiecesNameWhatToInstall(bool $withClient, bool $withRequests, bool $withStreams, array $env, string $missing): void
    {
        $env = array_map(fn (string $v): string => $v === '{tokenfile}' ? $this->tempFile('sa') : $v, $env);
        $factory = new Psr17Factory();
        $provider = new AzureManagedIdentity(
            null,
            $withClient ? TokenAuthHttpClient::json([]) : null,
            $withRequests ? $factory : null,
            $withStreams ? $factory : null,
            $env,
            static fn (): int => self::NOW
        );

        $strategies = [...ClassDiscovery::getStrategies()];
        ClassDiscovery::setStrategies([]);
        try {
            $provider->fetchToken(AzureManagedIdentity::RESOURCE_OSS_RDBMS);
            $this->fail('expected discovery to fail');
        } catch (PropulsionException $e) {
            $this->assertStringContainsString('needs ' . $missing . ' to fetch tokens', $e->getMessage());
            $this->assertStringContainsString('Propulsion::setConnectionAuthHttpClient()', $e->getMessage());
            $this->assertInstanceOf(\Http\Discovery\Exception\NotFoundException::class, $e->getPrevious());
        } finally {
            ClassDiscovery::setStrategies($strategies);
        }
    }

    private function tempFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'propulsion-wi-');
        if ($file === false) {
            $this->fail('could not create a temp file');
        }
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }
}
