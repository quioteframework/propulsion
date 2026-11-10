<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection\Auth;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Propulsion\Exception\PropulsionException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Microsoft Entra ID tokens for the Azure identity the process runs as.
 *
 * Where the token comes from is decided by the environment, the same way the
 * Azure SDKs decide it, checked in this order:
 *
 *  1. **AKS Workload Identity** -- `AZURE_FEDERATED_TOKEN_FILE` is set (the
 *     workload identity webhook injects it, with `AZURE_CLIENT_ID`,
 *     `AZURE_TENANT_ID` and `AZURE_AUTHORITY_HOST`). The projected service
 *     account token is exchanged for an Entra token at the authority. The
 *     file is re-read on every fetch, because the kubelet rotates it.
 *  2. **App Service, Functions, Container Apps** -- `IDENTITY_ENDPOINT` and
 *     `IDENTITY_HEADER` are set.
 *  3. **Everything else on Azure compute** (VMs, scale sets, AKS node
 *     identity) -- the Instance Metadata Service at 169.254.169.254.
 *
 * A configured `$clientId` selects a user-assigned identity. Without one,
 * App Service and IMDS use the system-assigned identity, and Workload Identity
 * uses `AZURE_CLIENT_ID`. `AZURE_CLIENT_ID` is deliberately *not* consulted
 * for the first two. When the app and its migrations run as different
 * identities, a variable shared by both processes silently deciding which
 * identity a connection uses is exactly the wrong failure mode -- name the
 * identity in each connection's configuration instead.
 *
 * HTTP goes through PSR-18, found via php-http/discovery unless a client is
 * supplied. IMDS must be reached directly: if the process has an HTTP proxy
 * configured, exclude 169.254.169.254 from it (`NO_PROXY`).
 */
final class AzureManagedIdentity implements TokenProvider
{
    public const SOURCE_WORKLOAD_IDENTITY = 'workload_identity';
    public const SOURCE_APP_SERVICE = 'app_service';
    public const SOURCE_IMDS = 'imds';

    /** The Entra resource Azure Database for PostgreSQL and MySQL accept tokens for. */
    public const RESOURCE_OSS_RDBMS = 'https://ossrdbms-aad.database.windows.net';

    /** The Entra resource Azure SQL Database and Managed Instance accept tokens for. */
    public const RESOURCE_AZURE_SQL = 'https://database.windows.net/';

    private const IMDS_ENDPOINT = 'http://169.254.169.254/metadata/identity/oauth2/token';
    private const IMDS_API_VERSION = '2018-02-01';
    private const APP_SERVICE_API_VERSION = '2019-08-01';
    private const DEFAULT_AUTHORITY_HOST = 'https://login.microsoftonline.com/';

    /** @var array<string, string> */
    private readonly array $environment;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string|null                  $clientId    client ID of a user-assigned identity, or null for the default one
     * @param array<string, string>|null   $environment environment variables to detect the host from; null reads the real ones
     * @param (\Closure(): int)|null       $clock       current unix time, for `expires_in` responses
     */
    public function __construct(
        private readonly ?string $clientId = null,
        private ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        ?array $environment = null,
        ?\Closure $clock = null,
    ) {
        if ($clientId === '') {
            throw new PropulsionException('An Azure managed identity client ID must not be empty; omit it to use the default identity');
        }
        $this->environment = $environment ?? getenv();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Which of the three token sources this environment uses -- one of the
     * SOURCE_* constants.
     */
    public function source(): string
    {
        if ($this->env('AZURE_FEDERATED_TOKEN_FILE') !== null) {
            return self::SOURCE_WORKLOAD_IDENTITY;
        }
        if ($this->env('IDENTITY_ENDPOINT') !== null && $this->env('IDENTITY_HEADER') !== null) {
            return self::SOURCE_APP_SERVICE;
        }

        return self::SOURCE_IMDS;
    }

    public function principalKey(): string
    {
        $source = $this->source();
        if ($source === self::SOURCE_WORKLOAD_IDENTITY) {
            return $source . '|' . $this->workloadTenantId() . '|' . $this->workloadClientId();
        }
        if ($this->clientId !== null) {
            // A user-assigned identity's client ID names one principal everywhere.
            return $source . '|client|' . $this->clientId;
        }

        // A system-assigned identity belongs to *this* resource, so the same
        // "no client ID" means a different principal on another VM or in
        // another app. A shared pool (Redis behind psr16) can span both, so the
        // key has to name the resource too. The hostname is enough for IMDS;
        // under App Service the identity endpoint/header pair is per app.
        if ($source === self::SOURCE_APP_SERVICE) {
            return $source . '|system|' . hash('sha256', $this->env('IDENTITY_ENDPOINT') . "\0" . $this->env('IDENTITY_HEADER'));
        }

        return $source . '|system|' . (gethostname() ?: 'unknown-host');
    }

    public function fetchToken(string $resource): AccessToken
    {
        if ($resource === '') {
            throw new PropulsionException('An Azure token resource must not be empty');
        }

        $source = $this->source();
        $request = match ($source) {
            self::SOURCE_WORKLOAD_IDENTITY => $this->workloadIdentityRequest($resource),
            self::SOURCE_APP_SERVICE => $this->appServiceRequest($resource),
            default => $this->imdsRequest($resource),
        };

        try {
            $response = $this->client()->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new PropulsionException(
                sprintf('Could not reach the Azure %s token endpoint (%s): %s', $this->describe($source), $this->endpointForMessage($request), $e->getMessage()),
                $e instanceof \Exception ? $e : null
            );
        }

        $body = (string) $response->getBody();
        $status = $response->getStatusCode();
        $decoded = json_decode($body, true);
        $payload = is_array($decoded) ? $decoded : [];

        if ($status !== 200) {
            throw new PropulsionException(sprintf(
                'The Azure %s token endpoint (%s) refused to issue a token for %s%s: HTTP %d%s',
                $this->describe($source),
                $this->endpointForMessage($request),
                $resource,
                $this->clientId !== null ? ' as client ID ' . $this->clientId : '',
                $status,
                self::errorDetail($payload)
            ));
        }

        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new PropulsionException(sprintf(
                'The Azure %s token endpoint (%s) answered HTTP 200 without an access_token',
                $this->describe($source),
                $this->endpointForMessage($request)
            ));
        }

        return new AccessToken($token, $this->expiry($payload, $source));
    }

    private function imdsRequest(string $resource): RequestInterface
    {
        $query = ['api-version' => self::IMDS_API_VERSION, 'resource' => $resource];
        if ($this->clientId !== null) {
            $query['client_id'] = $this->clientId;
        }

        return $this->requestFactory()
            ->createRequest('GET', self::IMDS_ENDPOINT . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withHeader('Metadata', 'true');
    }

    private function appServiceRequest(string $resource): RequestInterface
    {
        $query = ['api-version' => self::APP_SERVICE_API_VERSION, 'resource' => $resource];
        if ($this->clientId !== null) {
            $query['client_id'] = $this->clientId;
        }
        $endpoint = (string) $this->env('IDENTITY_ENDPOINT');

        return $this->requestFactory()
            ->createRequest('GET', $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withHeader('X-IDENTITY-HEADER', (string) $this->env('IDENTITY_HEADER'));
    }

    private function workloadIdentityRequest(string $resource): RequestInterface
    {
        $tokenFile = (string) $this->env('AZURE_FEDERATED_TOKEN_FILE');
        $assertion = is_readable($tokenFile) ? @file_get_contents($tokenFile) : false;
        if ($assertion === false || trim($assertion) === '') {
            throw new PropulsionException(sprintf(
                'AKS Workload Identity: the federated token file named by AZURE_FEDERATED_TOKEN_FILE (%s) is missing, unreadable or empty',
                $tokenFile
            ));
        }

        $authority = $this->env('AZURE_AUTHORITY_HOST') ?? self::DEFAULT_AUTHORITY_HOST;
        $url = rtrim($authority, '/') . '/' . rawurlencode($this->workloadTenantId()) . '/oauth2/v2.0/token';
        $form = http_build_query([
            'client_assertion' => trim($assertion),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_id' => $this->workloadClientId(),
            'grant_type' => 'client_credentials',
            // The v2 endpoint takes scopes, not resources; "<resource>/.default"
            // is the scope meaning "whatever the identity has been granted there".
            'scope' => rtrim($resource, '/') . '/.default',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestFactory()
            ->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory()->createStream($form));
    }

    private function workloadClientId(): string
    {
        $clientId = $this->clientId ?? $this->env('AZURE_CLIENT_ID');
        if ($clientId === null) {
            throw new PropulsionException(
                'AKS Workload Identity needs a client ID: set auth.client_id on the connection, or AZURE_CLIENT_ID in the environment'
            );
        }

        return $clientId;
    }

    private function workloadTenantId(): string
    {
        $tenantId = $this->env('AZURE_TENANT_ID');
        if ($tenantId === null) {
            throw new PropulsionException('AKS Workload Identity needs AZURE_TENANT_ID in the environment');
        }

        return $tenantId;
    }

    /**
     * IMDS and the App Service endpoint answer with `expires_on` (unix seconds,
     * as a string; very old App Service API versions used a date string), the
     * Entra v2 endpoint with `expires_in` (seconds from now).
     *
     * @param array<mixed> $payload
     */
    private function expiry(array $payload, string $source): int
    {
        $now = ($this->clock)();
        $expiresOn = $payload['expires_on'] ?? null;
        if (is_int($expiresOn) || (is_string($expiresOn) && ctype_digit($expiresOn))) {
            $expiresAt = (int) $expiresOn;
        } elseif (is_string($expiresOn) && ($parsed = strtotime($expiresOn)) !== false) {
            $expiresAt = $parsed;
        } else {
            $expiresIn = $payload['expires_in'] ?? null;
            if (!is_int($expiresIn) && !(is_string($expiresIn) && ctype_digit($expiresIn))) {
                throw new PropulsionException(sprintf(
                    'The Azure %s token endpoint returned a token without a usable expires_on or expires_in',
                    $this->describe($source)
                ));
            }
            $expiresAt = $now + (int) $expiresIn;
        }

        if ($expiresAt <= $now) {
            throw new PropulsionException(sprintf(
                'The Azure %s token endpoint returned a token that has already expired (expires at %d, now %d)',
                $this->describe($source),
                $expiresAt,
                $now
            ));
        }

        return $expiresAt;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function errorDetail(array $payload): string
    {
        foreach (['error_description', 'message', 'error'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                $detail = $payload[$key];

                return ' (' . (strlen($detail) > 400 ? substr($detail, 0, 400) . '...' : $detail) . ')';
            }
        }

        return '';
    }

    private function describe(string $source): string
    {
        return match ($source) {
            self::SOURCE_WORKLOAD_IDENTITY => 'Workload Identity',
            self::SOURCE_APP_SERVICE => 'App Service managed identity',
            default => 'IMDS managed identity',
        };
    }

    /** The request URL without its query string -- the endpoint is what helps, and the query only repeats the resource. */
    private function endpointForMessage(RequestInterface $request): string
    {
        return (string) $request->getUri()->withQuery('');
    }

    private function env(string $name): ?string
    {
        $value = $this->environment[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function client(): ClientInterface
    {
        try {
            return $this->httpClient ??= Psr18ClientDiscovery::find();
        } catch (NotFoundException $e) {
            throw new PropulsionException(self::missingHttpMessage('a PSR-18 HTTP client', 'psr/http-client-implementation'), $e);
        }
    }

    private function requestFactory(): RequestFactoryInterface
    {
        try {
            return $this->requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException $e) {
            throw new PropulsionException(self::missingHttpMessage('a PSR-17 request factory', 'psr/http-factory-implementation'), $e);
        }
    }

    private function streamFactory(): StreamFactoryInterface
    {
        try {
            return $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $e) {
            throw new PropulsionException(self::missingHttpMessage('a PSR-17 stream factory', 'psr/http-factory-implementation'), $e);
        }
    }

    private static function missingHttpMessage(string $what, string $virtualPackage): string
    {
        return 'Azure managed identity authentication needs ' . $what . ' to fetch tokens, and none is installed: '
            . 'composer require guzzlehttp/guzzle (or any package providing ' . $virtualPackage . '), '
            . 'or call Propulsion::setConnectionAuthHttpClient() with one directly';
    }
}
