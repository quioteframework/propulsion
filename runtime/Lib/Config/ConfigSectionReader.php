<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Config;

use Propulsion\Exception\PropulsionException;

/**
 * Strict readers for a section of the runtime configuration array.
 *
 * Shared by the parsed-config value objects ({@see \Propulsion\Cache\QueryCacheConfig},
 * {@see \Propulsion\Connection\ConnectionConfig}) that reject an unknown key or
 * a wrong value type outright instead of silently degrading to a default --
 * following the precedent {@see \Propulsion\Propulsion::processDriverOptions()}
 * set, and for the reason spelled out on QueryCacheConfig: a silently-ignored
 * `'tll' => 300` typo is a bug that never surfaces, because the feature simply
 * runs on its default forever.
 *
 * A trait rather than a base class: these config objects are `final readonly`
 * with constructor-promoted properties, so a shared parent would have to own
 * their constructors too.
 */
trait ConfigSectionReader
{
    /**
     * @param  array<mixed, mixed> $section
     * @param  list<string>        $known
     * @throws PropulsionException
     */
    private static function rejectUnknownKeys(array $section, array $known, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                throw new PropulsionException(
                    'Unknown Propulsion configuration option "' . $path . '.' . (is_string($key) ? $key : (string) $key)
                    . '": Check your configuration file. Known options: ' . implode(', ', $known)
                );
            }
        }
    }

    /**
     * The named key's value as an array, defaulting to empty when absent.
     *
     * @param  array<mixed, mixed> $section
     * @return array<mixed, mixed>
     * @throws PropulsionException
     */
    private static function readSection(array $section, string $key, string $path): array
    {
        $value = $section[$key] ?? [];
        if (!is_array($value)) {
            throw new PropulsionException(
                'Propulsion configuration key "' . $path . '" must be an array: Check your configuration file'
            );
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readBool(array $section, string $key, bool $default, string $path): bool
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        $value = $section[$key];
        if (!is_bool($value)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be a boolean, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readString(array $section, string $key, string $default, string $path): string
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        $value = $section[$key];
        if (!is_string($value)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be a string, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readInt(array $section, string $key, int $default, string $path): int
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        $value = $section[$key];
        if (!is_int($value)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be an integer, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readNullableInt(array $section, string $key, ?int $default, string $path): ?int
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        $value = $section[$key];
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be an integer or null, got ' . get_debug_type($value)
            );
        }

        return $value;
    }

    /**
     * Accepts an int too, so `'multiplier' => 2` in a config file is not a
     * type error the way it would be under a strict is_float() check -- a
     * number written without a decimal point is the same number.
     *
     * @param  array<mixed, mixed> $section
     * @throws PropulsionException
     */
    private static function readFloat(array $section, string $key, float $default, string $path): float
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        $value = $section[$key];
        if (!is_float($value) && !is_int($value)) {
            throw new PropulsionException(
                'Propulsion configuration option "' . $path . '" must be a number, got ' . get_debug_type($value)
            );
        }

        return (float) $value;
    }
}
