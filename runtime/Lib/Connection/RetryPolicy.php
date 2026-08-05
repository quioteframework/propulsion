<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Connection;

use Propulsion\Exception\PropulsionException;

/**
 * How many times, and how far apart, {@see \Propulsion\Propulsion::transaction()}
 * re-runs a transaction that failed for a transient reason.
 *
 * Exponential backoff with **full jitter** by default: the delay before retry
 * *n* is a uniform random draw from `[0, min(base * multiplier^(n-1), max)]`
 * rather than that bound itself. Un-jittered backoff is actively harmful for
 * the failure this policy exists to handle -- a deadlock or serialization
 * failure has, by definition, at least two transactions involved, and if both
 * back off by the same computed delay they collide again on the retry, and
 * again after that. Jitter is what decorrelates them. It is settable (0.0 for
 * none) mainly so a test can pin the delay to a known value.
 *
 * Delays are milliseconds. The defaults -- 3 attempts, 50ms base, 1s ceiling,
 * doubling -- put the worst case at well under a second of added latency,
 * which is the right order for a web request; a batch job that would rather
 * wait than fail should raise them.
 */
final readonly class RetryPolicy
{
    /**
     * @param int   $maxAttempts total attempts including the first, so 1 means "never retry"
     * @param int   $baseDelayMs delay bound before the first retry
     * @param int   $maxDelayMs  ceiling the exponentially growing bound is clamped to
     * @param float $multiplier  growth factor applied per attempt
     * @param float $jitter      fraction of the computed bound that is randomised: 0.0 = fixed
     *                           backoff, 1.0 = full jitter (draw from the whole interval)
     *
     * @throws PropulsionException on a nonsensical combination
     */
    public function __construct(
        public int $maxAttempts = ConnectionConfig::DEFAULT_MAX_ATTEMPTS,
        public int $baseDelayMs = ConnectionConfig::DEFAULT_BASE_DELAY_MS,
        public int $maxDelayMs = ConnectionConfig::DEFAULT_MAX_DELAY_MS,
        public float $multiplier = ConnectionConfig::DEFAULT_MULTIPLIER,
        public float $jitter = ConnectionConfig::DEFAULT_JITTER,
    ) {
        self::validate($maxAttempts, $baseDelayMs, $maxDelayMs, $multiplier, $jitter, 'RetryPolicy');
    }

    /**
     * Shared by this constructor and {@see ConnectionConfig::fromConfigArray()},
     * so that a bad value in a config file is reported against the config key
     * that carried it rather than against a constructor parameter the operator
     * never wrote.
     *
     * @param  string $path  what to name in the message: a config key prefix, or 'RetryPolicy'
     * @throws PropulsionException
     */
    public static function validate(
        int $maxAttempts,
        int $baseDelayMs,
        int $maxDelayMs,
        float $multiplier,
        float $jitter,
        string $path
    ): void {
        $label = static fn (string $option): string => $path === 'RetryPolicy'
            ? 'RetryPolicy::$' . $option
            : 'Propulsion configuration option "' . $path . '.' . $option . '"';

        if ($maxAttempts < 1) {
            throw new PropulsionException($label('max_attempts') . ' must be at least 1, got ' . $maxAttempts);
        }
        if ($baseDelayMs < 0) {
            throw new PropulsionException($label('base_delay') . ' must not be negative, got ' . $baseDelayMs);
        }
        if ($maxDelayMs < $baseDelayMs) {
            throw new PropulsionException(
                $label('max_delay') . ' must not be below the base delay (' . $baseDelayMs . '), got ' . $maxDelayMs
            );
        }
        if ($multiplier < 1.0) {
            throw new PropulsionException(
                $label('multiplier') . ' must be at least 1.0 -- a shrinking backoff retries faster the longer '
                . 'contention lasts, which is the opposite of what backoff is for; got ' . $multiplier
            );
        }
        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new PropulsionException($label('jitter') . ' must be between 0.0 and 1.0, got ' . $jitter);
        }
    }

    /**
     * The policy meaning "run it once, never retry".
     */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Whether another attempt is allowed after $attemptsSoFar have been made.
     */
    public function shouldRetry(int $attemptsSoFar): bool
    {
        return $attemptsSoFar < $this->maxAttempts;
    }

    /**
     * How long to sleep, in microseconds, before attempt number
     * $attemptsSoFar + 1.
     *
     * @param  int $attemptsSoFar how many attempts have already failed (>= 1)
     */
    public function delayMicrosecondsFor(int $attemptsSoFar): int
    {
        $bound = $this->baseDelayMs * $this->multiplier ** ($attemptsSoFar - 1);
        $bound = min($bound, (float) $this->maxDelayMs);

        // The un-jittered floor stays put and only the remainder is randomised,
        // so jitter=0.5 means "half the delay is fixed, half is random" rather
        // than "half the delay", and jitter=1.0 reduces to the full-jitter
        // draw from [0, bound] described on the class.
        $fixed = $bound * (1.0 - $this->jitter);
        $random = $bound * $this->jitter;
        if ($random > 0.0) {
            // random_int() over a microsecond range rather than mt_rand() over
            // a millisecond one: the delays here are small enough (a 50ms base)
            // that millisecond granularity would quantise the jitter down to a
            // handful of distinct values, which is exactly the correlation the
            // jitter exists to break.
            $fixed += random_int(0, (int) round($random * 1000.0)) / 1000.0;
        }

        return (int) round($fixed * 1000.0);
    }
}
