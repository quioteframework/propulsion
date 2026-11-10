<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Util;

use PDO;
use PDOException;
use Propulsion\Connection\Auth\ConnectionTokenAuth;

/**
 * Opens the plain PDO connections the generator commands (migrations,
 * sql:diff) run over, honouring a build connection's `auth` block the same
 * way the runtime honours a datasource's.
 *
 * That the two are configured separately is the point: the build-time
 * connection lives in buildtime-conf.php and can authenticate as an identity
 * allowed to CREATE/ALTER/DROP, while the application's runtime datasource
 * authenticates as a restricted one. Tokens are cached per identity, so the
 * two never share one even in the same process and the same APCu segment.
 */
final class BuildTimeConnection
{
    /**
     * Process-wide, so a command opening several connections (the migration
     * ledger opens its own beside the DDL one) fetches each token once.
     */
    private static ?ConnectionTokenAuth $tokenAuth = null;

    /**
     * @param string      $datasource the build connection's name, for error messages
     * @param mixed       $auth       the build connection's raw `auth` value; null for password authentication
     * @throws PDOException as PDO's constructor does
     * @throws \Propulsion\Exception\PropulsionException on an `auth` misconfiguration or when no token can be obtained
     */
    public static function open(string $datasource, string $dsn, ?string $user, #[\SensitiveParameter] ?string $password, mixed $auth): PDO
    {
        $tokenAuth = self::$tokenAuth ??= new ConnectionTokenAuth();
        $path = 'datasources.' . $datasource . '.auth';
        $credentials = $tokenAuth->credentialsFor($dsn, $user, $password, $auth, $path);

        try {
            $pdo = new PDO($credentials->dsn, $credentials->user, $credentials->password);
        } catch (PDOException $e) {
            $retry = $tokenAuth->retryAfterRejection($credentials, $user, $dsn, $path);
            if ($retry === null) {
                throw $e;
            }
            $pdo = new PDO($retry->dsn, $retry->user, $retry->password);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Forget the cached providers and tokens. For tests.
     */
    public static function reset(): void
    {
        self::$tokenAuth = null;
    }
}
