<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache;

/**
 * Request-scoped cache of compiled SELECT SQL strings, keyed by a caller-supplied
 * "shape" key (see {@see \Propulsion\Query\Criteria::setCompiledQueryCache()}).
 *
 * This is a different axis than {@see QueryResultCache}: that one caches *rows*
 * for a given SQL+params pair; this one caches the *SQL string itself* so that a
 * `Criteria` rebuilt with the same shape but different bound values (the common
 * case for the same generated Query/Peer method called repeatedly in a long-lived
 * worker process) skips re-walking joins/columns/criterions to re-derive text that
 * would come out identical every time. Bound parameter *values* are never part of
 * the cached entry -- only the SQL template and how many placeholders it expects.
 *
 * Owned by {@see \Propulsion\Session} and cleared at the same request boundary
 * {@see QueryResultCache} is, for the same reason: a compiled entry keyed by a
 * caller-chosen string must not leak between unrelated worker requests using the
 * same key for a differently-scoped query.
 */
class CompiledQueryCache
{
    /**
     * @var array<string, array{sql: string, paramCount: int}>
     */
    private array $entries = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /**
     * @return array{sql: string, paramCount: int}|null
     */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, string $sql, int $paramCount): void
    {
        $this->entries[$key] = ['sql' => $sql, 'paramCount' => $paramCount];
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
