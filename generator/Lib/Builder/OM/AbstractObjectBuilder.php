<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM;

/**
 * Base class for Peer-building classes.
 *
 * This class is designed so that it can be extended by a PHP4PeerBuilder in addition
 * to the "standard" PHP5PeerBuilder and PHP5ComplexOMPeerBuilder.  Hence, this class
 * should not have any actual template code in it -- simply basic logic & utility
 * methods.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Model\Table;

abstract class AbstractObjectBuilder extends OMBuilder
{

	/**
	 * Constructs a new AbstractPeerBuilder subclass.
	 */
	public function __construct(Table $table) {
		parent::__construct($table);
	}

	/**
	 * Tokenizes this builder's own generated output to answer "what public,
	 * non-static, non-magic instance methods will this table's Generated
	 * trait define, and with what signature" -- without duplicating this
	 * class's (or a behavior modifier's) method-emission logic.
	 *
	 * Used by DelegateBehavior to generate real forwarding methods for a
	 * delegate table's accessors/relations, instead of the old runtime-only
	 * __call() dispatch: that only discovered a delegate's method surface at
	 * call time via method_exists(), invisible to static analysis and to
	 * anything (like a `use Trait { name as ...; }` alias) that needs the
	 * method to exist as a real symbol at compile time.
	 *
	 * Uses PHP's own tokenizer rather than re-deriving names/types from the
	 * table's columns and foreign keys: that would mean re-implementing (and
	 * keeping in sync with) every method-emission path this class and its
	 * behavior modifiers have -- columns, FK/refFK/crossFK accessors in all
	 * their join-method variants, generic accessors, whatever a future
	 * behavior adds -- rather than reading the one place they already all
	 * converge: this builder's own generated output.
	 *
	 * @return array<string, array{params: string, returnType: ?string}>
	 */
	public function getGeneratedMethodSignatures(): array
	{
		$tokens = token_get_all($this->build());
		$useMap = $this->extractUseImportMap($tokens);

		$signatures = array();
		$depth = 0;
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token === '{') {
				$depth++;
				continue;
			}
			if ($token === '}') {
				$depth--;
				continue;
			}
			// Only the trait/class's own top-level members -- not, e.g., a
			// match/closure body nested one level deeper (none exist in
			// today's generated code, but this keeps a future one honest).
			if ($depth !== 1 || !is_array($token) || $token[0] !== T_FUNCTION) {
				continue;
			}

			[$isPublic, $isStatic] = $this->classifyMethodModifiers($tokens, $i);
			if (!$isPublic || $isStatic) {
				continue;
			}

			$nameIndex = $i + 1;
			while ($nameIndex < $count && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_WHITESPACE) {
				$nameIndex++;
			}
			if (!is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
				continue;
			}
			$name = $tokens[$nameIndex][1];
			// Magic methods (__construct, __call, __toString, ...) can't be
			// meaningfully forwarded to a delegate.
			if (str_starts_with($name, '__')) {
				continue;
			}

			$parenIndex = $nameIndex + 1;
			while ($parenIndex < $count && $tokens[$parenIndex] !== '(') {
				$parenIndex++;
			}
			[$params, $afterParams] = $this->extractBalanced($tokens, $parenIndex, '(', ')');

			$returnType = null;
			$r = $afterParams;
			while ($r < $count && is_array($tokens[$r]) && $tokens[$r][0] === T_WHITESPACE) {
				$r++;
			}
			if (isset($tokens[$r]) && $tokens[$r] === ':') {
				$r++;
				$typeText = '';
				while ($r < $count && $tokens[$r] !== '{' && $tokens[$r] !== ';') {
					$typeText .= is_array($tokens[$r]) ? $tokens[$r][1] : $tokens[$r];
					$r++;
				}
				$returnType = trim($typeText);
			}

			$signatures[$name] = array(
				'params' => $this->qualifyTypeHints(trim($params), $useMap),
				'returnType' => $returnType !== null ? $this->qualifyTypeHints($returnType, $useMap) : null,
			);
		}
		return $signatures;
	}

	/**
	 * A bare class name in a param/return type only resolves correctly
	 * because *this* file's own `use` statements brought it into scope --
	 * copying that bare text verbatim into a forwarding method generated on
	 * a *different* table (a different namespace) silently resolves it
	 * against the wrong namespace instead. PHP doesn't error on this: it
	 * just checks values against a class that doesn't exist (or worse,
	 * happens to exist and isn't the one meant), so it surfaces as a
	 * confusing TypeError naming the wrong FQCN at the first call, not a
	 * loud failure at generation time. Absolute references need no import
	 * and carry correctly wherever they're pasted, so every bare class name
	 * this file's own imports can resolve gets normalized to one; anything
	 * this method can't resolve (already absolute, a native type, or
	 * genuinely not imported here) is left untouched.
	 * @param array<string, string> $useMap bare name => FQCN, from extractUseImportMap()
	 */
	private function qualifyTypeHints(string $type, array $useMap): string
	{
		static $reservedTypes = array(
			'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'void', 'never',
			'null', 'false', 'true', 'self', 'static', 'parent', 'callable', 'iterable',
		);
		return preg_replace_callback(
			'/(?<!\\\\)\b[A-Za-z_][A-Za-z0-9_]*\b/',
			function (array $m) use ($useMap, $reservedTypes): string {
				$name = $m[0];
				if (in_array(strtolower($name), $reservedTypes, true)) {
					return $name;
				}
				return isset($useMap[$name]) ? '\\' . $useMap[$name] : $name;
			},
			$type
		) ?? $type;
	}

	/**
	 * The bare-name => FQCN map this file's own `use Some\Namespace\Name;`
	 * (and `use Some\Namespace\Name as Alias;`) import statements establish,
	 * read from the same token stream getGeneratedMethodSignatures() already
	 * has -- only import statements outside any class/trait body (depth 0)
	 * count; a `use SomeTrait;` inside a class body is a trait-use, not an
	 * import, and is naturally excluded by the same depth check.
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array<string, string>
	 */
	private function extractUseImportMap(array $tokens): array
	{
		$map = array();
		$depth = 0;
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token === '{') {
				$depth++;
				continue;
			}
			if ($token === '}') {
				$depth--;
				continue;
			}
			if ($depth !== 0 || !is_array($token) || $token[0] !== T_USE) {
				continue;
			}

			// PHP 8's tokenizer emits a qualified name (`A\B\C`) as a single
			// T_NAME_QUALIFIED token (T_NAME_FULLY_QUALIFIED / T_NAME_RELATIVE for
			// the `\`-leading/`namespace\`-leading forms; a single unqualified
			// segment is still plain T_STRING) -- not one T_STRING per segment.
			$fqcn = null;
			$alias = null;
			$j = $i + 1;
			for (; $j < $count && $tokens[$j] !== ';'; $j++) {
				$t = $tokens[$j];
				if (!is_array($t)) {
					continue;
				}
				if ($fqcn === null && in_array($t[0], array(T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE), true)) {
					$fqcn = ltrim($t[1], '\\');
				} elseif ($t[0] === T_AS) {
					for ($k = $j + 1; $k < $count && $tokens[$k] !== ';'; $k++) {
						if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
							$alias = $tokens[$k][1];
							break;
						}
					}
				}
			}
			if ($fqcn !== null) {
				$parts = explode('\\', $fqcn);
				$bareName = $alias ?? end($parts);
				$map[$bareName] = $fqcn;
			}
			$i = $j;
		}
		return $map;
	}

	/**
	 * Walks backward from a T_FUNCTION token, over the modifier keywords of
	 * its own declaration, stopping at the previous statement/block boundary.
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: bool, 1: bool} [isPublic, isStatic]
	 */
	private function classifyMethodModifiers(array $tokens, int $functionIndex): array
	{
		$isPublic = false;
		$isStatic = false;
		for ($j = $functionIndex - 1; $j >= 0; $j--) {
			$t = $tokens[$j];
			if ($t === '{' || $t === '}' || $t === ';') {
				break;
			}
			if (!is_array($t)) {
				continue;
			}
			if ($t[0] === T_PUBLIC) {
				$isPublic = true;
			} elseif ($t[0] === T_PRIVATE || $t[0] === T_PROTECTED) {
				return array(false, $isStatic);
			} elseif ($t[0] === T_STATIC) {
				$isStatic = true;
			}
		}
		return array($isPublic, $isStatic);
	}

	/**
	 * Concatenates tokens between a balanced pair of single-character
	 * delimiters starting at $openIndex (which must hold $open itself).
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: string, 1: int} [text between the delimiters, index just past the closer]
	 */
	private function extractBalanced(array $tokens, int $openIndex, string $open, string $close): array
	{
		$count = count($tokens);
		$depth = 0;
		$text = '';
		for ($i = $openIndex; $i < $count; $i++) {
			$t = $tokens[$i];
			if ($t === $open) {
				$depth++;
				if ($depth === 1) {
					continue;
				}
			} elseif ($t === $close) {
				$depth--;
				if ($depth === 0) {
					return array($text, $i + 1);
				}
			}
			$text .= is_array($t) ? $t[1] : $t;
		}
		return array($text, $count);
	}

	/**
	 * This method adds the contents of the generated class to the script.
	 *
	 * This method is abstract and should be overridden by the subclasses.
	 *
	 * Hint: Override this method in your subclass if you want to reorganize or
	 * drastically change the contents of the generated peer class.
	 *
	 * @param      string &$script The script will be modified in this method.
	 */
	abstract protected function addClassBody(&$script): void;

	/**
	 * Gets the baseClass classname if specified for table/db.
	 * If not, will return 'BaseObject' (i.e. \Propulsion\OM\BaseObject,
	 * brought into scope by the builder's own declareClass() call).
	 * @return     string
	 */
	protected function getBaseClass() {
		$class = $this->getTable()->getBaseClass();
		if ($class === null) {
			$class = "BaseObject";
		}
		return $class;
	}

	/**
	 * Gets the interface classname if specified for current table.
	 * If not, will return 'Persistent' (i.e. \Propulsion\OM\Persistent,
	 * brought into scope by the builder's own declareClass() call), unless
	 * the table is read-only, in which case there is no interface at all.
	 * @return     string|null
	 */
	protected function getInterface(): ?string {
		return ClassTools::getInterface($this->getTable());
	}

	/**
	 * Whether to add the generic mutator methods (setByName(), setByPosition(), fromArray()).
	 * This is based on the build property propulsion.addGenericMutators, and also whether the
	 * table is read-only or an alias.
	 */
	protected function isAddGenericMutators(): bool
	{
		$table = $this->getTable();
		return (!$table->isAlias() && $this->getBuildProperty('addGenericMutators') && !$table->isReadOnly());
	}

	/**
	 * Whether to add the generic accessor methods (getByName(), getByPosition(), toArray()).
	 * This is based on the build property propulsion.addGenericAccessors, and also whether the
	 * table is an alias.
	 */
	protected function isAddGenericAccessors(): bool
	{
		$table = $this->getTable();
		return (!$table->isAlias() && $this->getBuildProperty('addGenericAccessors'));
	}

	/**
	 * Whether to add the validate() method.
	 * This is based on the build property propulsion.addValidateMethod
	 */
	protected function isAddValidateMethod(): bool
	{
		return (bool) $this->getBuildProperty('addValidateMethod');
	}

	protected function hasDefaultValues(): bool
	{
		foreach ($this->getTable()->getColumns() as $col) {
			if($col->getDefaultValue() !== null) return true;
		}
		return false;
	}

	/**
	 * Checks whether any registered behavior on that table has a modifier for a hook
	 * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
	 * @return boolean
	 */
	public function hasBehaviorModifier($hookName, $modifier = null)
	{
	 	return parent::hasBehaviorModifier($hookName, 'ObjectBuilderModifier');
	}

	/**
	 * Checks whether any registered behavior on that table has a modifier for a hook
	 * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
	 * @param string &$script The script will be modified in this method.
	 */
	public function applyBehaviorModifier($hookName, &$script, string $tab = "		"): void
	{
		$this->applyBehaviorModifierBase($hookName, 'ObjectBuilderModifier', $script, $tab);
	}

	/**
	 * Checks whether any registered behavior content creator on that table exists a contentName
	 * @param string $contentName The name of the content as called from one of this class methods, e.g. "parentClassname"
	 */
	public function getBehaviorContent($contentName): mixed
	{
		return $this->getBehaviorContentBase($contentName, 'ObjectBuilderModifier');
	}

	/**
	 * Returns the class the model object extends.
	 *
	 * This used to be answered by ObjectBuilder, because the generated base class
	 * was the thing doing the extending. Now that the generated code is a trait,
	 * the *stub* carries the real parent, so the answer has to be reachable from
	 * either builder.
	 *
	 * The parentClass behavior hook has no bundled provider since
	 * concrete_inheritance was removed in 3.0, but it stays as an extension point:
	 * a project behavior can still answer it to redirect a model's parent.
	 * @return     string
	 */
	protected function getObjectParentClass(): string
	{
		$parentClass = $this->getBehaviorContent('parentClass');
		return is_string($parentClass) ? $parentClass : ClassTools::classname($this->getBaseClass());
	}

	/**
	 * Returns the interfaces the model object implements, in emission order.
	 *
	 * Never empty: Poolable is unconditional. A read-only table's object is
	 * emitted without save()/delete() and so cannot be Persistent, but it is
	 * still hydrated and still pooled, and <Model>Peer::addInstanceToPool() has
	 * to have one type that accepts both.
	 * @return     list<string>
	 */
	protected function getObjectInterfaces(): array
	{
		$implementsList = array();
		if ($this->getInterface() == "Persistent") {
			$implementsList[] = "Persistent";
		}
		$implementsList[] = "Poolable";
		// setByName()/setByPosition()/fromArray() are only emitted under this same
		// isAddGenericMutators() condition -- WritableModelInterface only ever needs
		// to be implemented in lockstep with whether those methods actually exist.
		if ($this->isAddGenericMutators()) {
			$implementsList[] = "WritableModelInterface";
		}
		return $implementsList;
	}

}
