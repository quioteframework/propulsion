<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

use Propulsion\Config\ConfigSectionReader;
use Propulsion\Exception\PropulsionException;

/**
 * Parsed, validated form of the optional `connection` section of the runtime
 * configuration -- the execution-strategy settings: the pre-checkout liveness
 * check and the transaction retry policy. See docs/CONNECTIONS.md.
 *
 * Both are **off by default**, and deliberately so. Each spends something real
 * (a round trip per idle checkout; running a caller's closure more than once)
 * to buy resilience that only some deployments need, and neither can be turned
 * on safely without the deployment having thought about it -- retry in
 * particular re-executes application code, which is only correct if that code
 * has no side effects outside the transaction it wraps.
 *
 *     'connection' => [
 *         'liveness' => ['enabled' => true, 'idle_threshold' => 5.0],
 *         'retry'    => ['enabled' => true, 'max_attempts' => 3],
 *     ]
 *
 * Like {@see \Propulsion\Cache\QueryCacheConfig}, this rejects unknown keys and
 * wrong value types outright rather than silently ignoring them.
 */
final readonly class ConnectionConfig
{
    use ConfigSectionReader;

    /**
     * How long a pooled connection must have sat unused before checking it out
     * is worth a `SELECT 1`.
     *
     * The default is a compromise between the two ways this setting is wrong.
     * Too low and a busy process pays a round trip on checkouts where the
     * connection demonstrably worked moments ago; too high and the window in
     * which a connection reaped by a server-side idle timeout, a load
     * balancer, or a failover still looks fresh grows to match. Five seconds
     * is far below any default idle timeout worth worrying about (MySQL's
     * `wait_timeout` is 8 hours, pgbouncer's `server_idle_timeout` 10 minutes)
     * while still collapsing to ~zero pings under sustained traffic.
     */
    public const DEFAULT_IDLE_THRESHOLD = 5.0;

    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_BASE_DELAY_MS = 50;
    public const DEFAULT_MAX_DELAY_MS = 1000;
    public const DEFAULT_MULTIPLIER = 2.0;
    public const DEFAULT_JITTER = 1.0;

    /**
     * @param bool  $livenessEnabled  whether to ping an idle pooled connection before handing it out
     * @param float $idleThreshold    seconds of idleness above which that ping happens; 0.0 pings every checkout
     * @param bool  $retryEnabled     whether {@see \Propulsion\Propulsion::transaction()} retries by default
     * @param int   $maxAttempts      total attempts, not retries: 1 disables retrying
     * @param int   $baseDelayMs      delay before the first retry
     * @param int   $maxDelayMs       ceiling the exponential backoff is clamped to
     * @param float $multiplier       per-attempt backoff growth factor
     * @param float $jitter           0.0 = no jitter, 1.0 = full jitter (the default; see RetryPolicy)
     */
    public function __construct(
        public bool $livenessEnabled = false,
        public float $idleThreshold = self::DEFAULT_IDLE_THRESHOLD,
        public bool $retryEnabled = false,
        public int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        public int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        public int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
        public float $multiplier = self::DEFAULT_MULTIPLIER,
        public float $jitter = self::DEFAULT_JITTER,
    ) {
    }

    /**
     * The "nothing configured" config: what an absent `connection` section
     * resolves to, and byte-for-byte the behaviour that predates this feature.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * The retry policy this configuration describes, or null when retrying is
     * switched off -- which {@see \Propulsion\Propulsion::transaction()} reads
     * as "run the closure exactly once".
     */
    public function getRetryPolicy(): ?RetryPolicy
    {
        if (!$this->retryEnabled) {
            return null;
        }

        return new RetryPolicy(
            maxAttempts: $this->maxAttempts,
            baseDelayMs: $this->baseDelayMs,
            maxDelayMs: $this->maxDelayMs,
            multiplier: $this->multiplier,
            jitter: $this->jitter,
        );
    }

    /**
     * Build from the whole runtime configuration array. An absent `connection`
     * section yields {@see self::defaults()}.
     *
     * @param  array<string, mixed> $config
     * @throws PropulsionException on an unknown key or a wrong value type
     */
    public static function fromConfigArray(array $config): self
    {
        if (!array_key_exists('connection', $config)) {
            return self::defaults();
        }

        $section = self::readSection($config, 'connection', 'connection');
        self::rejectUnknownKeys($section, ['liveness', 'retry'], 'connection');

        $liveness = self::readSection($section, 'liveness', 'connection.liveness');
        self::rejectUnknownKeys($liveness, ['enabled', 'idle_threshold'], 'connection.liveness');

        $idleThreshold = self::readFloat(
            $liveness,
            'idle_threshold',
            self::DEFAULT_IDLE_THRESHOLD,
            'connection.liveness.idle_threshold'
        );
        if ($idleThreshold < 0.0) {
            throw new PropulsionException(
                'Propulsion configuration option "connection.liveness.idle_threshold" must not be negative, got ' . $idleThreshold
            );
        }

        $retry = self::readSection($section, 'retry', 'connection.retry');
        self::rejectUnknownKeys(
            $retry,
            ['enabled', 'max_attempts', 'base_delay', 'max_delay', 'multiplier', 'jitter'],
            'connection.retry'
        );

        $maxAttempts = self::readInt($retry, 'max_attempts', self::DEFAULT_MAX_ATTEMPTS, 'connection.retry.max_attempts');
        $baseDelayMs = self::readInt($retry, 'base_delay', self::DEFAULT_BASE_DELAY_MS, 'connection.retry.base_delay');
        $maxDelayMs = self::readInt($retry, 'max_delay', self::DEFAULT_MAX_DELAY_MS, 'connection.retry.max_delay');
        $multiplier = self::readFloat($retry, 'multiplier', self::DEFAULT_MULTIPLIER, 'connection.retry.multiplier');
        $jitter = self::readFloat($retry, 'jitter', self::DEFAULT_JITTER, 'connection.retry.jitter');

        // Validated here rather than only in RetryPolicy's own constructor so a
        // misconfiguration is a startup error naming the offending config key,
        // not a confusing one thrown from the first transaction that runs.
        RetryPolicy::validate($maxAttempts, $baseDelayMs, $maxDelayMs, $multiplier, $jitter, 'connection.retry');

        return new self(
            livenessEnabled: self::readBool($liveness, 'enabled', false, 'connection.liveness.enabled'),
            idleThreshold: $idleThreshold,
            retryEnabled: self::readBool($retry, 'enabled', false, 'connection.retry.enabled'),
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            maxDelayMs: $maxDelayMs,
            multiplier: $multiplier,
            jitter: $jitter,
        );
    }
}
