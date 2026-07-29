<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Type;

/**
 * Encodes/decodes a Postgres one-dimensional array literal (`{a,"b,c",NULL}`)
 * -- the wire format for a `nativeArray="true"` PHP_ARRAY column on Postgres
 * (see `PgsqlPlatform`/`Column::isNativeArray()`), as opposed to this
 * codebase's other, emulated `" | "`-delimited PHP_ARRAY format used
 * everywhere else (and on Postgres itself when `nativeArray` isn't set).
 *
 * Elements are kept as plain strings (or null), the same "no rich per-element
 * PHP type" choice `Range` already made for its bounds -- a single generic
 * PHP_ARRAY column has no way to know whether its elements should become
 * int/float/string.
 */
final class PgArray
{
	private function __construct()
	{
	}

	/**
	 * @param array<int, string|int|float|bool|null> $values
	 */
	public static function encode(array $values): string
	{
		$parts = array_map(self::encodeElement(...), $values);
		return '{' . implode(',', $parts) . '}';
	}

	private static function encodeElement(string|int|float|bool|null $value): string
	{
		if ($value === null) {
			return 'NULL';
		}
		$str = is_bool($value) ? ($value ? 't' : 'f') : (string) $value;
		if ($str === '' || strcasecmp($str, 'NULL') === 0 || preg_match('/[,{}"\\\\\s]/', $str)) {
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
		}
		return $str;
	}

	/**
	 * @throws \InvalidArgumentException if $literal isn't a well-formed Postgres array literal
	 * @return array<int, string|null>
	 */
	public static function decode(string $literal): array
	{
		$literal = trim($literal);
		if (strlen($literal) < 2 || $literal[0] !== '{' || $literal[-1] !== '}') {
			throw new \InvalidArgumentException(sprintf('Malformed Postgres array literal: "%s"', $literal));
		}
		$inner = substr($literal, 1, -1);
		if ($inner === '') {
			return [];
		}

		$elements = [];
		$current = '';
		$wasQuoted = false;
		$inQuotes = false;
		$len = strlen($inner);
		for ($i = 0; $i < $len; $i++) {
			$char = $inner[$i];
			if ($inQuotes) {
				if ($char === '\\' && $i + 1 < $len) {
					$current .= $inner[++$i];
				} elseif ($char === '"') {
					$inQuotes = false;
				} else {
					$current .= $char;
				}
				continue;
			}
			if ($char === '"') {
				$inQuotes = true;
				$wasQuoted = true;
			} elseif ($char === ',') {
				$elements[] = self::decodeElement($current, $wasQuoted);
				$current = '';
				$wasQuoted = false;
			} else {
				$current .= $char;
			}
		}
		$elements[] = self::decodeElement($current, $wasQuoted);

		return $elements;
	}

	private static function decodeElement(string $raw, bool $wasQuoted): ?string
	{
		return !$wasQuoted && strcasecmp($raw, 'NULL') === 0 ? null : $raw;
	}
}
