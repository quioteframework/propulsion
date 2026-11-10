<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection\Auth;

/**
 * A source of access tokens used in place of a database password.
 *
 * Implementations only *fetch*: every call should go to the issuer and come
 * back with a token valid for a useful while. Caching -- in process memory
 * and, when configured, in a shared PSR-16 pool -- is {@see CachingTokenProvider}'s
 * job, and wraps every provider Propulsion uses, built-in or not.
 *
 * Implement this to plug in a credential source Propulsion does not ship
 * (a service principal, a secrets manager handing out short-lived database
 * passwords, ...), and put the instance in the connection's `auth.provider`.
 * See docs/CONNECTIONS.md.
 */
interface TokenProvider
{
    /**
     * Fetch a fresh token for $resource.
     *
     * @param string $resource the audience the token must be issued for, e.g.
     *                         `https://ossrdbms-aad.database.windows.net` for
     *                         Azure Database for PostgreSQL/MySQL
     *
     * @throws \Propulsion\Exception\PropulsionException if no token can be obtained
     */
    public function fetchToken(string $resource): AccessToken;

    /**
     * Who the tokens are issued to, stated so that two providers
     * agree only when the tokens they fetch would be interchangeable.
     *
     * It becomes part of the shared-cache key, so getting it wrong in the
     * permissive direction hands one identity's token to another -- the
     * runtime's restricted identity could end up logging in with the
     * migration identity's token, or the reverse. Return null when no such
     * statement can be made; tokens are then cached in process memory only.
     */
    public function principalKey(): ?string;
}
