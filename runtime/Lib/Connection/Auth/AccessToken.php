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

/**
 * A bearer token used as a database credential, and the moment it stops being
 * one.
 *
 * The token string is a credential in its own right -- anyone holding it can
 * log in as the identity it was issued to until it expires -- so it is kept
 * out of `var_dump()`/`print_r()` output and out of serialized stack-trace
 * arguments. Nothing in Propulsion ever puts it into an exception message.
 */
final readonly class AccessToken
{
    /**
     * @param string $token     the bearer token itself
     * @param int    $expiresAt unix timestamp after which the issuer no longer honours it
     */
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public int $expiresAt,
    ) {
        if ($token === '') {
            throw new PropulsionException('An access token must not be empty');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'expiresAt' => $this->expiresAt];
    }
}
