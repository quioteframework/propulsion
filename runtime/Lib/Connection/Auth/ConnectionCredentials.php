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
 * The three PDO constructor arguments, with the token already put wherever
 * the driver expects it -- the password for pgsql/mysql, the DSN for sqlsrv.
 *
 * Also remembers which token went into them and where it came from, so that a
 * login the server refuses can invalidate exactly that token and try once
 * more ({@see ConnectionTokenAuth::retryAfterRejection()}).
 */
final readonly class ConnectionCredentials
{
    public function __construct(
        #[\SensitiveParameter] public string $dsn,
        public ?string $user,
        #[\SensitiveParameter] public ?string $password,
        public ?AccessToken $token = null,
        public ?string $resource = null,
        public ?CachingTokenProvider $tokenSource = null,
        public ?TokenAuthConfig $config = null,
    ) {
    }

    /**
     * Plain credentials straight from configuration: nothing to refresh.
     */
    public static function static(string $dsn, ?string $user, #[\SensitiveParameter] ?string $password): self
    {
        return new self($dsn, $user, $password);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'dsn' => $this->token === null ? $this->dsn : '[redacted]',
            'user' => $this->user,
            'password' => $this->password === null ? null : '[redacted]',
            'token' => $this->token,
            'resource' => $this->resource,
        ];
    }
}
