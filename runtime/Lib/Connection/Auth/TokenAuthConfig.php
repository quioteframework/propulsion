<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection\Auth;

use Propulsion\Config\ConfigSectionReader;
use Propulsion\Exception\PropulsionException;

/**
 * Parsed, validated form of a connection's `auth` block -- token
 * authentication in place of a password. See docs/CONNECTIONS.md.
 *
 *     'connection' => [
 *         'dsn'  => 'pgsql:host=myserver.postgres.database.azure.com;dbname=app;sslmode=require',
 *         'user' => 'app-runtime-identity',
 *         'auth' => [
 *             'provider'  => 'azure_managed_identity',
 *             'client_id' => '00000000-0000-0000-0000-000000000000', // user-assigned; omit for system-assigned
 *             'cache'     => ['driver' => 'auto'],                    // auto | none | apcu | file | psr16
 *         ],
 *     ],
 *
 * The block sits on each connection rather than in one global section,
 * because the point is that different connections can authenticate as
 * different identities -- the application's datasource as a restricted one,
 * the build-time connection that runs migrations as one allowed to
 * CREATE/ALTER/DROP.
 *
 * Unknown keys and wrong types are rejected outright, like every other
 * Propulsion configuration section.
 */
final readonly class TokenAuthConfig
{
    use ConfigSectionReader;

    public const PROVIDER_AZURE_MANAGED_IDENTITY = 'azure_managed_identity';

    /** APCu when it is usable in this SAPI, process memory only otherwise. */
    public const CACHE_AUTO = 'auto';
    public const CACHE_NONE = 'none';
    public const CACHE_APCU = 'apcu';
    public const CACHE_FILE = 'file';
    /** A pool the application registers with Propulsion::setConnectionTokenCache(). */
    public const CACHE_PSR16 = 'psr16';

    private const CACHE_DRIVERS = [self::CACHE_AUTO, self::CACHE_NONE, self::CACHE_APCU, self::CACHE_FILE, self::CACHE_PSR16];

    public const DEFAULT_REFRESH_BEFORE = 300;

    /**
     * @param string|TokenProvider $provider       the built-in provider's name, or an application-supplied provider
     * @param string|null          $clientId       user-assigned identity for the built-in provider
     * @param string|null          $resource       token audience; null derives it from the DSN's driver
     * @param int                  $refreshBefore  seconds before expiry at which a token is replaced
     * @param string               $cacheDriver    one of the CACHE_* constants
     * @param string|null          $cacheDirectory directory for the `file` cache driver
     */
    public function __construct(
        public string|TokenProvider $provider,
        public ?string $clientId = null,
        public ?string $resource = null,
        public int $refreshBefore = self::DEFAULT_REFRESH_BEFORE,
        public string $cacheDriver = self::CACHE_AUTO,
        public ?string $cacheDirectory = null,
    ) {
    }

    /**
     * @param  mixed  $auth the raw `auth` value from a connection's configuration
     * @param  string $path where it sits in the configuration, for error messages
     * @throws PropulsionException on an unknown key, a wrong type, or an inconsistent combination
     */
    public static function fromConfig(mixed $auth, string $path): self
    {
        if (!is_array($auth)) {
            throw new PropulsionException(
                'Propulsion configuration key "' . $path . '" must be an array: Check your configuration file'
            );
        }
        self::rejectUnknownKeys($auth, ['provider', 'client_id', 'resource', 'refresh_before', 'cache'], $path);

        $provider = $auth['provider'] ?? null;
        if ($provider !== self::PROVIDER_AZURE_MANAGED_IDENTITY && !$provider instanceof TokenProvider) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '.provider" must be "' . self::PROVIDER_AZURE_MANAGED_IDENTITY
                . '" or an instance of ' . TokenProvider::class . ', got ' . (is_string($provider) ? '"' . $provider . '"' : get_debug_type($provider))
            );
        }

        $clientId = self::readOptionalString($auth, 'client_id', $path . '.client_id');
        if ($clientId !== null && $provider instanceof TokenProvider) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '.client_id" only applies to the built-in "'
                . self::PROVIDER_AZURE_MANAGED_IDENTITY . '" provider; configure your own provider instance directly'
            );
        }

        $refreshBefore = self::readInt($auth, 'refresh_before', self::DEFAULT_REFRESH_BEFORE, $path . '.refresh_before');
        if ($refreshBefore < 0) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '.refresh_before" must not be negative, got ' . $refreshBefore
            );
        }

        $cache = self::readSection($auth, 'cache', $path . '.cache');
        self::rejectUnknownKeys($cache, ['driver', 'directory'], $path . '.cache');
        $cacheDriver = self::readString($cache, 'driver', self::CACHE_AUTO, $path . '.cache.driver');
        if (!in_array($cacheDriver, self::CACHE_DRIVERS, true)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '.cache.driver" must be one of '
                . implode(', ', self::CACHE_DRIVERS) . ', got "' . $cacheDriver . '"'
            );
        }
        $cacheDirectory = self::readOptionalString($cache, 'directory', $path . '.cache.directory');
        if (($cacheDriver === self::CACHE_FILE) !== ($cacheDirectory !== null)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '.cache.directory" is required by, and only valid for, the "file" cache driver'
            );
        }

        return new self(
            provider: $provider,
            clientId: $clientId,
            resource: self::readOptionalString($auth, 'resource', $path . '.resource'),
            refreshBefore: $refreshBefore,
            cacheDriver: $cacheDriver,
            cacheDirectory: $cacheDirectory,
        );
    }

    /**
     * A non-empty string or null when absent. An empty string is rejected
     * rather than read as "absent": for client_id in particular, quietly
     * falling back to the system-assigned identity is the wrong identity.
     *
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readOptionalString(array $section, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $section) || $section[$key] === null) {
            return null;
        }
        $value = $section[$key];
        if (!is_string($value) || $value === '') {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be a non-empty string, got '
                . (is_string($value) ? 'an empty string' : get_debug_type($value))
            );
        }

        return $value;
    }
}
