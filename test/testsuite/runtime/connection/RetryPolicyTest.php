<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Connection\RetryPolicy;
use Propulsion\Exception\PropulsionException;

/**
 * {@see RetryPolicy}'s arithmetic and its refusal of nonsensical settings.
 */
class RetryPolicyTest extends TestCase
{
    public function testShouldRetryCountsAttemptsNotRetries(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertTrue($policy->shouldRetry(2));
        $this->assertFalse($policy->shouldRetry(3), 'three attempts made means three attempts made');
    }

    public function testNoneNeverRetries(): void
    {
        $this->assertFalse(RetryPolicy::none()->shouldRetry(1));
    }

    public function testBackoffGrowsExponentiallyWithJitterDisabled(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 50, maxDelayMs: 10000, multiplier: 2.0, jitter: 0.0);

        $this->assertSame(50000, $policy->delayMicrosecondsFor(1));
        $this->assertSame(100000, $policy->delayMicrosecondsFor(2));
        $this->assertSame(200000, $policy->delayMicrosecondsFor(3));
    }

    public function testBackoffIsClampedToTheCeiling(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 50, maxDelayMs: 120, multiplier: 2.0, jitter: 0.0);

        $this->assertSame(100000, $policy->delayMicrosecondsFor(2));
        $this->assertSame(120000, $policy->delayMicrosecondsFor(3), 'clamped rather than 200ms');
        $this->assertSame(120000, $policy->delayMicrosecondsFor(9), 'and stays clamped');
    }

    public function testFullJitterDrawsFromTheWholeInterval(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 10000, multiplier: 2.0, jitter: 1.0);

        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $delay = $policy->delayMicrosecondsFor(1);
            $this->assertGreaterThanOrEqual(0, $delay);
            $this->assertLessThanOrEqual(100000, $delay);
            $seen[$delay] = true;
        }

        // The point of jitter is decorrelating two transactions that deadlocked
        // with each other, which a delay taking only a handful of distinct
        // values cannot do. 200 draws over a 100ms interval at microsecond
        // granularity collide vanishingly rarely; 50 distinct values is a floor
        // loose enough never to flake and tight enough to catch the delay
        // having quantised (or stopped varying at all).
        $this->assertGreaterThan(50, count($seen));
    }

    public function testPartialJitterRandomisesOnlyItsShare(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 10000, multiplier: 2.0, jitter: 0.25);

        for ($i = 0; $i < 100; $i++) {
            $delay = $policy->delayMicrosecondsFor(1);
            $this->assertGreaterThanOrEqual(75000, $delay, 'the un-jittered 75% floor stays put');
            $this->assertLessThanOrEqual(100000, $delay);
        }
    }

    public function testZeroBaseDelayMeansRetryImmediately(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0);

        $this->assertSame(0, $policy->delayMicrosecondsFor(1));
        $this->assertSame(0, $policy->delayMicrosecondsFor(5));
    }

    public function testRejectsFewerThanOneAttempt(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/max_attempts.*at least 1/');
        new RetryPolicy(maxAttempts: 0);
    }

    public function testRejectsACeilingBelowTheBaseDelay(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/max_delay/');
        new RetryPolicy(baseDelayMs: 500, maxDelayMs: 100);
    }

    public function testRejectsAShrinkingBackoff(): void
    {
        // A multiplier below 1 retries faster the longer contention lasts,
        // which is the opposite of what backoff is for.
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/multiplier/');
        new RetryPolicy(multiplier: 0.5);
    }

    public function testRejectsJitterOutsideZeroToOne(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/jitter/');
        new RetryPolicy(jitter: 1.5);
    }
}
