<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Exception\PropulsionException;
use Propulsion\Formatter\PropulsionArrayFormatter;
use Propulsion\Formatter\PropulsionFormatter;
use Propulsion\Formatter\PropulsionObjectFormatter;
use Propulsion\Formatter\PropulsionOnDemandFormatter;
use Propulsion\Formatter\PropulsionSimpleArrayFormatter;
use Propulsion\Formatter\PropulsionStatementFormatter;

/**
 * Guards the capability contract the global query result cache depends on.
 *
 * A formatter that wrongly reports itself row-cacheable would have its results
 * cached and later reconstructed from rows it cannot actually consume; the two
 * streaming formatters wrongly reporting true would additionally hand callers
 * an exhausted cursor.
 */
class FormatterRowCachingTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('cacheableFormatters')]
    public function testCacheableFormattersOptIn(string $class)
    {
        /** @var PropulsionFormatter $formatter */
        $formatter = new $class();
        $this->assertTrue($formatter->supportsRowCaching(), $class . ' should be row-cacheable');
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function cacheableFormatters(): array
    {
        return [
            'object' => [PropulsionObjectFormatter::class],
            'array' => [PropulsionArrayFormatter::class],
            'simple array' => [PropulsionSimpleArrayFormatter::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncacheableFormatters')]
    public function testStreamingFormattersOptOut(string $class)
    {
        /** @var PropulsionFormatter $formatter */
        $formatter = new $class();
        $this->assertFalse($formatter->supportsRowCaching(), $class . ' must never be row-cached');
    }

    /**
     * PropulsionOnDemandFormatter is the dangerous one: it extends
     * PropulsionObjectFormatter, so it would inherit true unless it explicitly
     * overrides. PropulsionStatementFormatter relies on the base class default.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function uncacheableFormatters(): array
    {
        return [
            'on demand' => [PropulsionOnDemandFormatter::class],
            'statement' => [PropulsionStatementFormatter::class],
        ];
    }

    public function testOnDemandFormatterOverridesItsCacheableParent()
    {
        $this->assertTrue(
            is_subclass_of(PropulsionOnDemandFormatter::class, PropulsionObjectFormatter::class),
            'this test only means anything while the inheritance it guards still exists'
        );

        $reflection = new ReflectionMethod(PropulsionOnDemandFormatter::class, 'supportsRowCaching');
        $this->assertSame(
            PropulsionOnDemandFormatter::class,
            $reflection->getDeclaringClass()->getName(),
            'PropulsionOnDemandFormatter must declare its own supportsRowCaching(), not inherit the cacheable one'
        );
    }

    public function testBaseFormatterDefaultsToNotCacheable()
    {
        // A third-party formatter that predates this feature must not become
        // silently cacheable on upgrade.
        $formatter = new class extends PropulsionFormatter {
            public function format(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function formatOne(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function isObjectFormatter(): bool
            {
                return false;
            }
        };

        $this->assertFalse($formatter->supportsRowCaching());
    }

    public function testBaseRowFormattingThrowsRatherThanSilentlyMisbehaving()
    {
        $formatter = new class extends PropulsionFormatter {
            public function format(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function formatOne(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function isObjectFormatter(): bool
            {
                return false;
            }
        };

        $this->expectException(PropulsionException::class);
        $formatter->formatFromRows([]);
    }

    public function testBaseFormatOneFromRowsThrows()
    {
        $formatter = new class extends PropulsionFormatter {
            public function format(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function formatOne(PDOStatement $stmt): mixed
            {
                return null;
            }

            public function isObjectFormatter(): bool
            {
                return false;
            }
        };

        $this->expectException(PropulsionException::class);
        $formatter->formatOneFromRows([]);
    }
}
