<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Connection\ConnectionConfig;
use Propulsion\Exception\PropulsionException;

/**
 * Parsing and validation of the `connection` runtime-configuration section.
 *
 * The strictness is the point: a silently-ignored typo would leave the feature
 * running on its default forever with nothing to show for it, which is exactly
 * the failure mode {@see \Propulsion\Cache\QueryCacheConfig} was written this
 * way to avoid.
 */
class ConnectionConfigTest extends TestCase
{
    public function testAnAbsentSectionMeansEverythingIsOff(): void
    {
        $config = ConnectionConfig::fromConfigArray([]);

        $this->assertFalse($config->livenessEnabled);
        $this->assertFalse($config->retryEnabled);
        $this->assertNull($config->getRetryPolicy(), 'and transaction() runs the closure exactly once');
        $this->assertEquals(ConnectionConfig::defaults(), $config);
    }

    public function testAnEmptySectionKeepsTheDefaults(): void
    {
        $config = ConnectionConfig::fromConfigArray(['connection' => []]);

        $this->assertFalse($config->livenessEnabled);
        $this->assertSame(ConnectionConfig::DEFAULT_IDLE_THRESHOLD, $config->idleThreshold);
        $this->assertFalse($config->retryEnabled);
    }

    public function testReadsTheLivenessSection(): void
    {
        $config = ConnectionConfig::fromConfigArray([
            'connection' => ['liveness' => ['enabled' => true, 'idle_threshold' => 30.0]],
        ]);

        $this->assertTrue($config->livenessEnabled);
        $this->assertSame(30.0, $config->idleThreshold);
    }

    public function testAnIntegerIdleThresholdIsAccepted(): void
    {
        // A number written without a decimal point is the same number; making
        // that a type error would be a gratuitous trap in a config file.
        $config = ConnectionConfig::fromConfigArray([
            'connection' => ['liveness' => ['idle_threshold' => 30]],
        ]);

        $this->assertSame(30.0, $config->idleThreshold);
    }

    public function testReadsTheRetrySection(): void
    {
        $config = ConnectionConfig::fromConfigArray([
            'connection' => [
                'retry' => [
                    'enabled' => true,
                    'max_attempts' => 5,
                    'base_delay' => 20,
                    'max_delay' => 2000,
                    'multiplier' => 3.0,
                    'jitter' => 0.5,
                ],
            ],
        ]);

        $this->assertTrue($config->retryEnabled);

        $policy = $config->getRetryPolicy();
        $this->assertNotNull($policy);
        $this->assertSame(5, $policy->maxAttempts);
        $this->assertSame(20, $policy->baseDelayMs);
        $this->assertSame(2000, $policy->maxDelayMs);
        $this->assertSame(3.0, $policy->multiplier);
        $this->assertSame(0.5, $policy->jitter);
    }

    public function testRetrySettingsWithoutEnabledYieldNoPolicy(): void
    {
        $config = ConnectionConfig::fromConfigArray([
            'connection' => ['retry' => ['max_attempts' => 5]],
        ]);

        $this->assertNull($config->getRetryPolicy());
        $this->assertSame(5, $config->maxAttempts, 'the setting is still parsed, just not in force');
    }

    public function testRejectsAnUnknownTopLevelKey(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/connection\.livenes/');
        ConnectionConfig::fromConfigArray(['connection' => ['livenes' => []]]);
    }

    public function testRejectsAnUnknownLivenessKey(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/connection\.liveness\.threshold/');
        ConnectionConfig::fromConfigArray([
            'connection' => ['liveness' => ['threshold' => 5]],
        ]);
    }

    public function testRejectsAnUnknownRetryKey(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/connection\.retry\.attempts/');
        ConnectionConfig::fromConfigArray([
            'connection' => ['retry' => ['attempts' => 5]],
        ]);
    }

    public function testRejectsAWrongType(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/connection\.liveness\.enabled.*boolean/');
        ConnectionConfig::fromConfigArray([
            'connection' => ['liveness' => ['enabled' => 'yes']],
        ]);
    }

    public function testRejectsANegativeIdleThreshold(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/idle_threshold.*negative/');
        ConnectionConfig::fromConfigArray([
            'connection' => ['liveness' => ['idle_threshold' => -1.0]],
        ]);
    }

    public function testABadRetrySettingIsReportedAgainstItsConfigKey(): void
    {
        // Validated at parse time rather than deferred to the first
        // transaction, and named after the key the operator actually wrote
        // rather than after a constructor parameter they never saw.
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/"connection\.retry\.max_attempts"/');
        ConnectionConfig::fromConfigArray([
            'connection' => ['retry' => ['max_attempts' => 0]],
        ]);
    }

    public function testRejectsANonArraySection(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/"connection" must be an array/');
        ConnectionConfig::fromConfigArray(['connection' => 'on']);
    }
}
