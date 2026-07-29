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
 * A PHP-side representation of a Postgres range value (`int4range`,
 * `int8range`, `numrange`, `daterange`, `tsrange`, `tstzrange`) -- bounds are
 * kept as raw strings rather than parsed into a subtype-specific PHP value
 * (int/float/DateTime), since a single class here has no way to know which
 * of those a given range column's bounds should become. Callers that need a
 * typed bound can cast/parse `getLower()`/`getUpper()` themselves.
 *
 * @author     GitHub Copilot
 */
final class Range implements \Stringable
{
	public function __construct(
		private readonly ?string $lower,
		private readonly ?string $upper,
		private readonly bool $lowerInclusive = true,
		private readonly bool $upperInclusive = false,
		private readonly bool $empty = false,
	) {
	}

	/**
	 * Parses a Postgres range literal (e.g. "[1,10)", "(,5]", "empty") into a
	 * Range instance.
	 *
	 * @throws \InvalidArgumentException if $text isn't a well-formed range literal
	 */
	public static function parse(string $text): self
	{
		$text = trim($text);
		if (strcasecmp($text, 'empty') === 0) {
			return new self(null, null, false, false, true);
		}
		if (!preg_match('/^([\[(])\s*((?:"(?:[^"\\\\]|\\\\.)*"|[^,])*)\s*,\s*((?:"(?:[^"\\\\]|\\\\.)*"|[^)\]])*)\s*([)\]])$/', $text, $m)) {
			throw new \InvalidArgumentException(sprintf('Malformed range literal: "%s"', $text));
		}
		return new self(
			self::unquote($m[2]),
			self::unquote($m[3]),
			$m[1] === '[',
			$m[4] === ']',
		);
	}

	private static function unquote(string $raw): ?string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[-1] === '"') {
			return str_replace('\\"', '"', str_replace('\\\\', '\\', substr($raw, 1, -1)));
		}
		return $raw;
	}

	/**
	 * The lower bound's raw text, or null if the range is unbounded below (or empty).
	 */
	public function getLower(): ?string
	{
		return $this->lower;
	}

	/**
	 * The upper bound's raw text, or null if the range is unbounded above (or empty).
	 */
	public function getUpper(): ?string
	{
		return $this->upper;
	}

	public function isLowerInclusive(): bool
	{
		return $this->lowerInclusive;
	}

	public function isUpperInclusive(): bool
	{
		return $this->upperInclusive;
	}

	public function isEmpty(): bool
	{
		return $this->empty;
	}

	/**
	 * Formats this Range back into a Postgres range literal.
	 */
	public function __toString(): string
	{
		if ($this->empty) {
			return 'empty';
		}
		$open = $this->lowerInclusive ? '[' : '(';
		$close = $this->upperInclusive ? ']' : ')';
		return $open . ($this->lower ?? '') . ',' . ($this->upper ?? '') . $close;
	}
}
