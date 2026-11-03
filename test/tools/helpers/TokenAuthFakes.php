<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Connection\Auth\AccessToken;
use Propulsion\Connection\Auth\TokenProvider;
use Propulsion\Exception\PropulsionException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A token provider handing out a scripted sequence of tokens, counting fetches.
 *
 * An entry that is a PropulsionException is thrown instead of returned, to
 * model an identity endpoint that is down. Once the script runs out the last
 * entry repeats.
 */
final class TokenAuthFakeProvider implements TokenProvider
{
    public int $fetches = 0;

    /** @var list<string> */
    public array $resources = [];

    /**
     * @param list<AccessToken|PropulsionException> $script
     */
    public function __construct(
        private array $script,
        private readonly ?string $principal = 'fake-principal',
    ) {
    }

    public static function constant(string $token, int $expiresAt, ?string $principal = 'fake-principal'): self
    {
        return new self([new AccessToken($token, $expiresAt)], $principal);
    }

    public function fetchToken(string $resource): AccessToken
    {
        $this->fetches++;
        $this->resources[] = $resource;
        $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
        if ($next instanceof PropulsionException) {
            throw $next;
        }

        return $next;
    }

    public function principalKey(): ?string
    {
        return $this->principal;
    }
}

/**
 * PSR-18 client that records every request and answers from a callback.
 */
final class TokenAuthHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param \Closure(RequestInterface): ResponseInterface $respond
     */
    public function __construct(private readonly \Closure $respond)
    {
    }

    /**
     * Always answers 200 with the given JSON body.
     *
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): self
    {
        return new self(static fn (): ResponseInterface => new \Nyholm\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return ($this->respond)($request);
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent');
        }

        return $last;
    }
}
