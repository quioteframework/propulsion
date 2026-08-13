<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Rector;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Migrates a model stub from extending its generated base class to using its
 * generated trait.
 *
 *     class Book extends BaseBook {}
 *     class Book extends BaseObject implements Persistent, Poolable, WritableModelInterface { use BookGenerated; }
 *
 * Propulsion 3.0 emits generated object, query and node code as traits rather
 * than base classes, so that `$this` inside generated code is provably the
 * model class (see docs/GENERATED_TRAITS_PLAN.md). Stubs are generated once and
 * then owned by the project, so regeneration will not fix them -- this rule
 * does.
 *
 * `parent::` is the one ergonomic regression, and the delicate part of this
 * rule. Before the change, `parent::` from a stub reached the generated base,
 * which meant it resolved *either* a generated method or something the base
 * inherited from the real parent. After the change only the latter still works.
 * So each `parent::foo()` is classified by asking the new parent whether it has
 * `foo`:
 *
 * - Yes (`parent::preSave()`, declared on BaseObject): left alone. It kept
 *   working, and aliasing it would be a fatal -- PHP rejects an alias for a
 *   method the trait does not define ("An alias (x) was defined for method
 *   foo(), but this method does not exist").
 * - No (`parent::save()`, generated): the trait method is aliased and the call
 *   becomes `$this->generatedSave()`.
 *
 * Deliberately not handled:
 * - Peer stubs. Peers still extend a generated base in 3.0; only object, query
 *   and node code moved to traits.
 * - Stubs whose parent cannot be resolved by the reflection provider. Without
 *   knowing the new parent's methods, every `parent::` call would be a guess,
 *   and guessing wrong emits code that fatals at compile time.
 * - `parent::` inside a closure or nested anonymous class, which binds to a
 *   different scope than the stub's.
 */
final class StubBaseClassToGeneratedTraitRector extends AbstractRector
{
    /**
     * Suffix of the stub class name => [new parent, interfaces it must declare].
     *
     * Longest suffix first: 'BookNodePeer' has to match the NodePeer entry, not
     * the Peer one, and 'BookQuery' the Query entry rather than the object
     * fallback.
     *
     * @var array<string, array{parent: ?string, implements: list<string>}>
     */
    private const STUB_KINDS = [
        'NodePeer' => ['parent' => null, 'implements' => []],
        'Node' => ['parent' => null, 'implements' => ['IteratorAggregate']],
        'Query' => ['parent' => 'Propulsion\\Query\\ModelCriteria', 'implements' => []],
    ];

    /**
     * Applied when no STUB_KINDS suffix matches -- i.e. an object stub.
     *
     * @var array{parent: string, implements: list<string>}
     */
    private const OBJECT_KIND = [
        'parent' => 'Propulsion\\OM\\BaseObject',
        'implements' => ['Propulsion\\OM\\Persistent', 'Propulsion\\OM\\Poolable', 'Propulsion\\OM\\WritableModelInterface'],
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || $node->extends === null || $node->name === null) {
            return null;
        }

        $stubName = $node->name->toString();

        // Compared on the short name. Rector resolves `extends` to the fully
        // qualified name, so a namespaced stub's parent reads as
        // App\Model\OM\BaseBookQuery, not BaseBookQuery -- matching against the
        // bare 'Base' . $stubName silently skipped every namespaced project,
        // which is very nearly all of them.
        if ($node->extends->getLast() !== 'Base' . $stubName) {
            return null;
        }

        // The trait lives exactly where the base it replaces did -- the
        // generator emits both into the same `om` package. Deriving its name
        // from the old parent rather than from the stub is what keeps this
        // correct for a namespaced project, where the stub sits in the parent
        // namespace and the generated code one level down in OM\.
        $traitName = $this->buildTraitName($node->extends, $stubName);

        $kind = $this->resolveKind($stubName);
        if ($kind === null) {
            return null;
        }

        $newParent = $kind['parent'];
        if ($newParent !== null && !$this->reflectionProvider->hasClass($newParent)) {
            // Without the new parent's method list, classifying parent:: calls
            // would be guesswork, and a wrong guess is a compile-time fatal.
            return null;
        }

        $aliases = $this->rewriteParentCalls($node, $newParent);

        $node->extends = $newParent === null ? null : new FullyQualified($newParent);
        foreach ($kind['implements'] as $interface) {
            $node->implements[] = new FullyQualified($interface);
        }

        array_unshift($node->stmts, $this->buildTraitUse($traitName, $aliases));

        return $node;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Migrates a model stub from extending its generated base class to using its generated trait, aliasing generated methods that the stub reaches through parent::.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class Book extends BaseBook
{
    public function save(?PropulsionPDO $con = null): int
    {
        return parent::save($con);
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use BookGenerated { save as private generatedSave; }

    public function save(?PropulsionPDO $con = null): int
    {
        return $this->generatedSave($con);
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array{parent: ?string, implements: list<string>}|null null for a peer stub, which does not move
     */
    private function resolveKind(string $stubName): ?array
    {
        foreach (self::STUB_KINDS as $suffix => $kind) {
            if (str_ends_with($stubName, $suffix)) {
                return $kind;
            }
        }

        // Checked after STUB_KINDS so that 'BookNodePeer' resolves as a node peer.
        if (str_ends_with($stubName, 'Peer')) {
            return null;
        }

        return self::OBJECT_KIND;
    }

    /**
     * Rewrites every `parent::foo(...)` the new parent does not answer into
     * `$this->generatedFoo(...)`, and reports the aliases the trait use needs.
     *
     * @return array<string, string> generated method name => alias name
     */
    private function rewriteParentCalls(Class_ $class, ?string $newParent): array
    {
        $aliases = [];
        $existingNames = $this->collectDeclaredNames($class);

        $nodeFinder = new NodeFinder();
        /** @var list<StaticCall> $staticCalls */
        $staticCalls = $nodeFinder->findInstanceOf($class->stmts, StaticCall::class);

        foreach ($staticCalls as $staticCall) {
            if (!$staticCall->class instanceof Name || !$this->isName($staticCall->class, 'parent')) {
                continue;
            }
            if (!$staticCall->name instanceof Identifier) {
                continue;
            }

            $method = $staticCall->name->toString();
            if ($newParent !== null && $this->parentHasMethod($newParent, $method)) {
                // Still reaches a real method on the real parent -- leave it.
                continue;
            }

            if (!isset($aliases[$method])) {
                $aliases[$method] = $this->pickAliasName($method, $existingNames);
                $existingNames[strtolower($aliases[$method])] = true;
            }
        }

        if ($aliases === []) {
            return [];
        }

        // Second pass, now that every alias name is settled, so two calls to the
        // same generated method cannot disagree about what to call it.
        $this->traverseNodesWithCallable($class->stmts, function (Node $subNode) use ($aliases, $newParent): ?Node {
            if (!$subNode instanceof StaticCall || !$subNode->class instanceof Name) {
                return null;
            }
            if (!$this->isName($subNode->class, 'parent') || !$subNode->name instanceof Identifier) {
                return null;
            }

            $method = $subNode->name->toString();
            if ($newParent !== null && $this->parentHasMethod($newParent, $method)) {
                return null;
            }
            if (!isset($aliases[$method])) {
                return null;
            }

            return new MethodCall(new Variable('this'), new Identifier($aliases[$method]), $subNode->getArgs());
        });

        return $aliases;
    }

    /**
     * Native methods only, deliberately.
     *
     * BaseObject carries phpdoc `@method int save(?PropulsionPDO $con = null)`
     * and friends to document what generated subclasses provide -- and
     * ClassReflection::hasMethod() counts a phpdoc @method as present. Asking
     * that question would report save(), delete(), toArray() and fromArray() as
     * inherited and leave their parent:: calls untouched, which is precisely
     * backwards: those are the generated methods that most need aliasing.
     * hasNativeMethod() sees only what is really declared, so preSave() (a real
     * method on BaseObject) stays and save() (annotation only) is rewritten.
     */
    private function parentHasMethod(string $parentClass, string $method): bool
    {
        if (!$this->reflectionProvider->hasClass($parentClass)) {
            return false;
        }

        return $this->reflectionProvider->getClass($parentClass)->hasNativeMethod($method);
    }

    /**
     * `save` => `generatedSave`, falling back to `generatedSave2`, ... if the
     * stub already declares that name. An alias colliding with a method the
     * class defines is silently useless -- the class's own method wins for that
     * name -- so it has to be checked rather than assumed free.
     *
     * @param array<string, true> $existingNames lowercased
     */
    private function pickAliasName(string $method, array $existingNames): string
    {
        $candidate = 'generated' . ucfirst($method);
        $suffix = 1;
        while (isset($existingNames[strtolower($candidate)])) {
            $suffix++;
            $candidate = 'generated' . ucfirst($method) . $suffix;
        }

        return $candidate;
    }

    /**
     * @return array<string, true> lowercased names the class already declares
     */
    private function collectDeclaredNames(Class_ $class): array
    {
        $names = [];
        foreach ($class->getMethods() as $classMethod) {
            $names[strtolower($classMethod->name->toString())] = true;
        }

        return $names;
    }

    /**
     * The generated trait's fully qualified name, derived from the base class it
     * replaces.
     *
     * Both are emitted into the same package, so the trait is the old parent's
     * namespace with the last segment swapped: App\Model\OM\BaseBookQuery
     * becomes App\Model\OM\BookQueryGenerated. Taking it from the stub's own
     * name instead would put it in the stub's namespace, one level too high.
     */
    private function buildTraitName(Name $baseClass, string $stubName): string
    {
        $parts = $baseClass->getParts();
        array_pop($parts);
        $parts[] = $stubName . 'Generated';

        return implode('\\', $parts);
    }

    /**
     * @param array<string, string> $aliases generated method name => alias name
     */
    private function buildTraitUse(string $traitName, array $aliases): TraitUse
    {
        $adaptations = [];
        foreach ($aliases as $method => $alias) {
            // null trait, not new Name($traitName): a stub uses exactly one
            // generated trait, so the qualifier is redundant and the printer
            // would emit `BookGenerated::save as ...` instead of `save as ...`.
            $adaptations[] = new Alias(
                null,
                new Identifier($method),
                Class_::MODIFIER_PRIVATE,
                new Identifier($alias)
            );
        }

        // Fully qualified only when there is a namespace to qualify. A global
        // class emitted as `\BookGenerated` is valid but reads as noise in a
        // project that has no namespaces at all.
        $name = str_contains($traitName, '\\')
            ? new FullyQualified($traitName)
            : new Name($traitName);

        return new TraitUse([$name], $adaptations);
    }
}
