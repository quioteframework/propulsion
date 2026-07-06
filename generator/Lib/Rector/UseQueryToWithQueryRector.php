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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;
use Rector\Exception\ShouldNotHappenException;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites `->useQuery('x')->...->endUse()` / generated `->use<Relation>Query()->...->endUse()`
 * chains into the closure-scoped `->withQuery('x', fn ($q) => ...)` /
 * `->with<Relation>Query(fn ($q) => ...)` form.
 *
 * This is a mechanical, single-level rewrite: it only ever transforms a "leaf" pair
 * (a useQuery/endUse span with no other useQuery/endUse call inside it). Rector re-parses
 * and re-traverses each file until no rule reports a change (see
 * Rector\Application\FileProcessor::processFile()'s do/while loop), so nested and sibling
 * pairs at any depth resolve automatically, one level per pass, without this rule needing
 * to implement recursion or tree-building itself.
 *
 * Deliberately left alone (not matched, so untouched by design):
 * - Non-fluent / variable-split chains, e.g. `$sub = $q->useQuery('x'); ...; $sub->endUse();`
 *   -- this rule only ever inspects a single fluent MethodCall spine.
 * - Chains where the segment between opener and closer isn't a plain linear MethodCall
 *   chain (e.g. embedded in a ternary or callback) -- the spine walk bails out.
 */
final class UseQueryToWithQueryRector extends AbstractRector
{
    private const OPENER_PATTERN = '/^use([A-Z][A-Za-z0-9]*)Query$/';

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof MethodCall || !$this->isName($node->name, 'endUse')) {
            return null;
        }

        $opener = $this->findMatchingOpener($node);
        if ($opener === null) {
            return null;
        }

        // Only rewrite leaf pairs -- if the segment between opener and closer still
        // contains a nested useQuery/endUse pair, skip it this pass. Rector's fixpoint
        // loop will revisit this same $node on a later pass once the inner pair has
        // already been rewritten into a with*Query() call and the segment is a leaf.
        if ($this->segmentHasNestedPair($opener, $node)) {
            return null;
        }

        $paramName = $this->pickParamName($opener, $node);
        $closureBody = $this->buildClosureBody($opener, $node, $paramName);

        $arrowFunction = new ArrowFunction([
            'params' => [new Param(new Variable($paramName))],
            'expr' => $closureBody,
        ]);

        return $this->buildReplacementCall($opener, $arrowFunction);
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rewrites useQuery()/use<Relation>Query() ... endUse() chains into closure-scoped withQuery()/with<Relation>Query() calls, so PHPStan and IDEs can type the sub-query.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$books = BookQuery::create()
    ->useAuthorQuery()
        ->filterByFirstName('Jane')
    ->endUse()
    ->find();
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$books = BookQuery::create()
    ->withAuthorQuery(fn ($q) => $q->filterByFirstName('Jane'))
    ->find();
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * Walks inward from the closing endUse() via ->var, tracking nesting depth, to find
     * the useQuery()/use<Relation>Query() call that balances it. Bails (returns null) if
     * the chain isn't a plain linear MethodCall spine all the way down.
     */
    private function findMatchingOpener(MethodCall $closer): ?MethodCall
    {
        $depth = 1;
        $current = $closer->var;

        while ($current instanceof MethodCall) {
            if ($this->isName($current->name, 'endUse')) {
                $depth++;
            } elseif ($this->isOpenerCall($current)) {
                $depth--;
                if ($depth === 0) {
                    return $current;
                }
            }

            $current = $current->var;
        }

        return null;
    }

    private function isOpenerCall(MethodCall $methodCall): bool
    {
        if ($this->isName($methodCall->name, 'useQuery')) {
            return true;
        }

        $name = $this->getName($methodCall->name);
        return $name !== null && preg_match(self::OPENER_PATTERN, $name) === 1;
    }

    /**
     * True if any MethodCall strictly between $opener and $closer (exclusive on both ends)
     * is itself an opener or a closer -- i.e. this pair still has an unresolved nested pair
     * and must wait for a later pass.
     */
    private function segmentHasNestedPair(MethodCall $opener, MethodCall $closer): bool
    {
        $current = $closer->var;

        while ($current instanceof MethodCall && $current !== $opener) {
            if ($this->isName($current->name, 'endUse') || $this->isOpenerCall($current)) {
                return true;
            }

            $current = $current->var;
        }

        return false;
    }

    /**
     * Rebuilds the segment between $opener and $closer (exclusive) as a standalone
     * expression rooted at a fresh Variable($paramName) in place of $opener -- this becomes
     * the arrow function body. If the segment is empty (useQuery()->endUse() with nothing
     * in between), the body is just the bare parameter.
     */
    private function buildClosureBody(MethodCall $opener, MethodCall $closer, string $paramName): Expr
    {
        $segmentRoot = $closer->var;

        if ($segmentRoot === $opener) {
            return new Variable($paramName);
        }

        if (!$segmentRoot instanceof MethodCall) {
            // Guaranteed not to happen: findMatchingOpener()/segmentHasNestedPair() only
            // ever let a plain linear MethodCall spine reach this point. Guard defensively.
            return new Variable($paramName);
        }

        $clonedRoot = clone $segmentRoot;
        $this->rebaseSegment($clonedRoot, $opener, $paramName);

        return $clonedRoot;
    }

    /**
     * Walks the cloned segment chain until it finds the (also cloned) node that used to
     * point at $originalOpener, and replaces that ->var with a fresh Variable($paramName).
     */
    private function rebaseSegment(MethodCall $clonedNode, MethodCall $originalOpener, string $paramName): void
    {
        if ($clonedNode->var === $originalOpener) {
            $clonedNode->var = new Variable($paramName);
            return;
        }

        if (!$clonedNode->var instanceof MethodCall) {
            // Shouldn't happen -- segmentHasNestedPair()/findMatchingOpener() already
            // guarantee a plain linear spine down to $opener. Guard defensively anyway.
            return;
        }

        $clonedNode->var = clone $clonedNode->var;
        $this->rebaseSegment($clonedNode->var, $originalOpener, $paramName);
    }

    private function buildReplacementCall(MethodCall $opener, ArrowFunction $arrowFunction): MethodCall
    {
        $openerName = $this->getName($opener->name);
        $openerArgs = $opener->getArgs();

        // $opener only ever reaches here via isOpenerCall(), which already required a
        // resolvable, pattern-matching name -- null is unreachable, this is a type guard.
        if ($openerName === null) {
            throw new ShouldNotHappenException();
        }

        if ($openerName === 'useQuery') {
            // withQuery(string $relationName, callable $callback, ?string $secondaryCriteriaClass = null)
            $relationNameArg = $openerArgs[0] ?? null;
            $secondaryClassArg = $openerArgs[1] ?? null;

            $newArgs = [];
            if ($relationNameArg !== null) {
                $newArgs[] = $relationNameArg;
            }
            $newArgs[] = new Arg($arrowFunction);
            if ($secondaryClassArg !== null) {
                $newArgs[] = $secondaryClassArg;
            }

            return new MethodCall($opener->var, new Identifier('withQuery'), $newArgs);
        }

        // with<Relation>Query(callable $callback, ...same trailing args as use<Relation>Query())
        $relation = preg_replace(self::OPENER_PATTERN, '$1', $openerName);
        $newArgs = array_merge([new Arg($arrowFunction)], $openerArgs);

        return new MethodCall($opener->var, new Identifier('with' . $relation . 'Query'), $newArgs);
    }

    /**
     * Defaults to `$q`; falls back to `$q2`, `$q3`, ... only if the segment already
     * references a variable literally named that, to avoid shadowing user code.
     */
    private function pickParamName(MethodCall $opener, MethodCall $closer): string
    {
        $usedNames = [];
        $current = $closer->var;
        $nodeFinder = new NodeFinder();

        while ($current instanceof MethodCall && $current !== $opener) {
            foreach ($nodeFinder->findInstanceOf($current->args, Variable::class) as $variable) {
                if (is_string($variable->name)) {
                    $usedNames[$variable->name] = true;
                }
            }
            $current = $current->var;
        }

        $candidate = 'q';
        $suffix = 1;
        while (isset($usedNames[$candidate])) {
            $suffix++;
            $candidate = 'q' . $suffix;
        }

        return $candidate;
    }
}
