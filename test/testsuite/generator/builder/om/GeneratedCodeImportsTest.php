<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Every generated class must import the runtime classes it names, rather than
 * relying on the bare-name aliases `runtime/Lib/legacy-class-map.php` installs.
 *
 * Those aliases cost every process ~3.2 MB and ~176 loaded classes (measured;
 * see docs/WORKER_MODE.md), which is why they can be switched off with
 * `PROPULSION_SKIP_LEGACY_CLASS_ALIASES`. Generated code that leans on them
 * makes that switch unusable -- and not with a tidy "class not found" at the
 * point of use: a bare `Criteria` in a *parameter type* of an override whose
 * parent spells the same parameter `Propulsion\Query\Criteria` means PHP cannot
 * prove the signatures compatible, and it fatals while merely *loading* the
 * class.
 *
 * The ordinary suite cannot notice any of this, because it runs with the
 * aliases installed, which is exactly what makes bare names work.
 *
 * This is a static check of the emitted source rather than a load test, so it
 * needs no second PHP process (the constant has to be defined before Propulsion
 * loads, so a load test cannot run in-process once the suite has booted) and it
 * names the offending file and line directly.
 */
class GeneratedCodeImportsTest extends TestCase
{
	/**
	 * Class-position uses of a name -- the contexts where PHP resolves it as a
	 * class, and therefore the ones an import has to cover.
	 *
	 * @return array<int, int>
	 */
	private function classPositionPrefixTokens(): array
	{
		return array(T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_CATCH);
	}

	public function testGeneratedClassesImportEveryRuntimeClassTheyName()
	{
		IntegrationDatabase::ensureClassesGenerated();

		$legacyNames = require dirname(__DIR__, 5) . '/runtime/Lib/legacy-class-map.php';
		$this->assertNotEmpty($legacyNames, 'the legacy class map is readable');

		$offenders = array();
		foreach ($this->generatedFiles() as $path) {
			foreach ($this->bareRuntimeClassRefs($path, $legacyNames) as $ref) {
				$offenders[] = $ref;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Generated code references these runtime classes by bare name with no matching\n"
				. "`use` statement, so it only loads while the legacy class aliases are installed.\n"
				. "Declare them in the builder or behavior modifier that emits the reference\n"
				. "(OMBuilder::declareClass()), so getUseStatements() emits a real import:\n  "
				. implode("\n  ", $offenders)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function generatedFiles(): array
	{
		$roots = array(
			IntegrationDatabase::classesDir(),
			IntegrationDatabase::namespacedClassesDir(),
			IntegrationDatabase::schemasClassesDir(),
		);

		$files = array();
		foreach ($roots as $root) {
			if (!is_dir($root)) {
				continue;
			}
			/** @var SplFileInfo $file */
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
				if ($file->getExtension() === 'php') {
					$files[] = $file->getPathname();
				}
			}
		}
		sort($files);

		return $files;
	}

	/**
	 * @param  array<string, string> $legacyNames
	 * @return array<int, string>
	 */
	private function bareRuntimeClassRefs(string $path, array $legacyNames): array
	{
		$source = file_get_contents($path);
		if ($source === false) {
			return array();
		}
		$tokens = token_get_all($source);
		$imported = $this->importedShortNames($tokens);

		$refs = array();
		foreach ($tokens as $i => $token) {
			// Only an unqualified name can resolve through an alias; a qualified or
			// fully-qualified one already says where it lives.
			if (!is_array($token) || $token[0] !== T_STRING) {
				continue;
			}
			$name = $token[1];
			if (!isset($legacyNames[$name]) || isset($imported[$name])) {
				continue;
			}

			$prev = $this->significantToken($tokens, $i, -1);
			$next = $this->significantToken($tokens, $i, 1);

			// `Foo::bar()` reads as a class; `$x->Foo`, `function Foo`, `Bar::Foo` do not.
			if (in_array($prev, array(T_OBJECT_OPERATOR, T_FUNCTION, T_DOUBLE_COLON, T_NS_SEPARATOR), true)) {
				continue;
			}
			if ($next !== T_DOUBLE_COLON && !in_array($prev, $this->classPositionPrefixTokens(), true)) {
				continue;
			}

			$refs[] = sprintf('%s:%d references %s', $path, $token[2], $name);
		}

		return $refs;
	}

	/**
	 * Short names brought into scope by this file's `use` statements.
	 *
	 * @param  array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array<string, true>
	 */
	private function importedShortNames(array $tokens): array
	{
		$imported = array();
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || $token[0] !== T_USE) {
				continue;
			}
			$statement = '';
			$j = $i;
			while (isset($tokens[++$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{') {
				$statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
			}
			$statement = trim($statement);
			// A closure's `use ($captured)` is not an import.
			if ($statement === '' || strpos($statement, '(') !== false) {
				continue;
			}
			if (preg_match('/\bas\s+(\w+)$/i', $statement, $m)) {
				$imported[$m[1]] = true;
				continue;
			}
			$short = strrchr('\\' . $statement, '\\');
			$imported[substr($short === false ? $statement : $short, 1)] = true;
		}

		return $imported;
	}

	/**
	 * The id of the nearest non-whitespace, non-comment token in $direction, or
	 * null when that neighbour is punctuation (which no caller needs to tell
	 * apart) or the file runs out.
	 *
	 * @param  array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function significantToken(array $tokens, int $from, int $direction): ?int
	{
		$skip = array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);
		$i = $from + $direction;
		while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], $skip, true)) {
			$i += $direction;
		}

		return isset($tokens[$i]) && is_array($tokens[$i]) ? $tokens[$i][0] : null;
	}
}
