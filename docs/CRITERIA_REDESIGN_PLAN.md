# Option C: Closure-scoped sub-query API + shippable Rector migration

## Context

`docs/CRITERIA_REDESIGN.md` documents a long-standing typing gap in Propulsion's
query builder. `useQuery()` is *already* precisely typed
(`@return ($secondaryCriteriaClass is null ? static : T)`), but `endUse()` is
declared `: ModelCriteria` and cannot recover the concrete class of the
object that originally called `useQuery()` — that object is stored in an
untemplated `?ModelCriteria $primaryCriteria` property. Every chain that
crosses an `endUse()` therefore collapses to the `ModelCriteria` supertype,
which kills both PHPStan inference and IDE autocomplete.

**The confirmed, primary pain is problem (1b): hand-written, deeply nested,
multi-relation chains in consumer code**, e.g.

```php
$q->useAuthorQuery()
      ->useBookQuery()->filterBySomething('xyz')->endUse()
      ->usePublisherQuery()->filterBySomethingElse('quux')->endUse()
  ->endUse();
```

There are hundreds of such sites. After the first `endUse()`, autocomplete
and PHPStan are lost for the rest of the chain. This is the problem this
plan exists to solve.

Scope decisions (locked with the maintainer):
- **Build full Option C** — a closure-scoped sub-query API — as the fix for (1b).
- **Ship a Rector rule downstream** as a supported migration tool, so the
  hundreds of existing call sites (here and in consumer codebases) upgrade
  mechanically. This is what makes the breaking change acceptable.
- Keep `useQuery()`/`endUse()` as a deprecated escape hatch (removal is a
  later major-version decision, once downstream has migrated).

Done in the isolated worktree `worktree-criteria-redesign` (another agent is
active on `main`).

### How the fix restores typing (the mechanism that matters)

The closure form has no `endUse()` to lose the type on — the "switch back"
is the closure returning. Two independent facts make it type cleanly:

1. Generated `with<Relation>Query()` methods return `static`, so the outer
   chain keeps the caller's concrete type across the whole expression.
2. The closure parameter is typed via the generated method's
   `@param callable(<RelationQueryClass>): void` docblock. **PHPStan and
   PhpStorm both infer an untyped closure parameter's type from that
   docblock**, so the Rector output can leave `$book`/`$publisher` untyped
   and still get full inference. Rector never needs to resolve relation →
   query-class itself; the generated method carries the type.

## 1. Runtime: `ModelCriteria::withQuery()`

Add to `runtime/Lib/Query/ModelCriteria.php`, implemented on top of the
existing `useQuery()`/`endUse()` pair (no change to `$primaryCriteria`,
`setPrimaryCriteria()`, `getPrimaryCriteria()`, `$previousJoin`, or
`mergeWith()`):

```php
/**
 * @template T of ModelCriteria
 * @param string $relationName
 * @param callable(T): void $callback
 * @param class-string<T>|null $secondaryCriteriaClass
 * @return static
 */
public function withQuery(string $relationName, callable $callback, ?string $secondaryCriteriaClass = null): static
{
    $secondary = $this->useQuery($relationName, $secondaryCriteriaClass);
    $callback($secondary);
    $secondary->endUse();   // merges into $this in place; return value discarded
    return $this;           // typed static — no ModelCriteria leak
}
```

**Critical correction over the first draft:** the body must `return $this;`,
NOT `return $secondary->endUse();`. `endUse()` is declared `: ModelCriteria`,
so returning it directly would reproduce inside `withQuery()` the exact
`return.type` finding we are eliminating. This is safe because `endUse()`
merges into and returns the original caller — verified in
`ModelCriteria::endUse()` (`$primaryCriteria->mergeWith($this); return
$primaryCriteria;`, and `$primaryCriteria` was set to the caller by
`useQuery()`), so `$this` and the discarded return value are the same object.

`useQuery()`/`endUse()` get `@deprecated` docblocks pointing at
`withQuery()` / the generated `with<Relation>Query()` wrappers. Not removed.

## 2. Generator template changes (`generator/Lib/Builder/OM/QueryBuilder.php`)

Every place that today emits a `use<Relation>Query()` wrapper must gain a
`with<Relation>Query()` sibling — the wrappers are what downstream code
actually calls, and they are what carry the typed-closure docblock.

- **`addUseRelatedQuery()`** (~1424-1445): keep `use<Relation>Query()`,
  add sibling
  `with<Relation>Query(callable $callback, $relationAlias = null, $joinType = ...): static`
  emitting
  `$this->join<Relation>($relationAlias, $joinType)->withQuery('<Relation>', $callback, '<QueryClass>'); return $this;`
  (or delegate through `withQuery()` which already returns `static`).
  Docblock: `@param callable(<RelationQueryClass>): void $callback`. Pilot here first.
- **I18n template** (`generator/Lib/Behavior/I18n/templates/queryUseI18nQuery.php`):
  add `withI18nQuery()` sibling alongside `useI18nQuery()`.
- **`addFilterByCrossFK()`** (~1456-1510) and the composite-FK block
  (~1266-1289): rewrite bodies (external `filterBy<X>()` signatures
  unchanged) to call `with<Relation>Query(closure)` internally instead of
  `use<Relation>Query()->...->endUse()`. This also incidentally clears the
  generated-code `return.type` findings (problem 1a) as a free side effect.
- **`__call()` magic dispatcher** (~1098-1114): leave as-is for now — dynamic
  relation name, no concrete type to gain, and it is generator-authored
  string-building code Rector cannot target. Optional future cleanup.

None of this touches `Criteria.php`/`Criterion.php`/`ModelCriterion.php`.

Coverage check before rollout: grep every template site that emits
`useQuery` or a `use<X>Query` wrapper and confirm each has a `with<X>Query`
counterpart — a downstream chain the Rector rule rewrites will fail to
compile if any relation lacks its `with` wrapper.

## 3. Rector rule (the primary deliverable, shipped downstream)

Confirmed feasible for **fluent single-statement chains**, including the
hard real-world shape: **sibling pairs nested inside an outer pair, to
unbounded depth** (N nested `use<X>Query()` layers, not just two or three).
Non-fluent / variable-split chains are detected and skipped (left for
manual review), never mis-rewritten.

The depth-generality is structural: step 2's balanced-depth matching builds
a full pair *tree*, and step 3 recurses into every segment with no depth
cap. The only things that must scale with depth are (a) the generated
parameter names and (b) variable capture — both handled below.

### Why the fluent case is tractable

A fluent chain parses as one linear "spine" of nested `MethodCall` nodes —
each node's `->var` is exactly the previous call, `endUse()` outermost,
`useQuery()`/`use<X>Query()` innermost. No branching.

### Algorithm

Rector `AbstractRector` visiting `MethodCall`, firing only on a *terminal*
`endUse()` (one not itself the `->var` of another `MethodCall`).

1. **Flatten the spine** from the terminal node inward via `->var` into an
   ordered list of calls (root receiver first, terminal `endUse` last).
   Abort (leave untouched) if any element is not a plain `MethodCall` whose
   `->var` is the previous element or the root receiver — this rejects
   ternaries, `array_map` callbacks, and anything branching.
2. **Match pairs by balanced depth.** Walk the flattened list tracking
   depth: `use*Query`/`useQuery` opens (+1 on entry), `endUse` closes
   (−1). Each opener's matching closer is where depth returns to its
   pre-open level. This yields a *tree* of pairs — correctly capturing both
   nesting (Book inside Author) **and siblings** (Book then Publisher, both
   children of Author).
3. **Rewrite bottom-up (innermost pairs first).** For each pair, the calls
   strictly between opener and closer become the closure body, rooted at a
   fresh parameter variable. Sibling pairs within the same parent segment
   each become their own `with<Relation>Query(...)` call, chained on the
   parent's closure parameter; non-pair calls in that segment (e.g. a direct
   `->filterByX()` on the parent context) stay as direct calls on the
   parameter, in original order. Because a segment is one chained
   expression, **emit an arrow function** `fn($q) => $q->...` rather than
   `function ($q) { ... }`:
   - **Depth scaling**: name parameters by nesting depth (`$q0`, `$q1`, …)
     so an N-deep rewrite never shadows an enclosing parameter.
   - **Variable capture**: arrow functions auto-capture by value, so a value
     variable used at any depth (`->filterByX($name)`) needs **no `use`
     clause at any level** — this is the whole reason to prefer arrow
     functions and is what makes deep nesting safe. By-value capture is
     semantically identical here because the closures execute synchronously
     inside `withQuery()` (nothing mutates the captured vars between
     definition and call), and PHP objects capture by handle regardless.
   - Fall back to `function () use (...) {}` only if a segment somehow isn't
     a single expression — which cannot occur for a pure fluent spine, so in
     practice arrow functions always apply. `$this` references inside the
     body remain valid (arrow functions bind `$this` automatically).
4. **Emit the replacement call:**
   - Opener is a generated `use<Relation>Query($alias?, $joinType?)` →
     `->with<Relation>Query(function ($q) { ... }, $alias?, $joinType?)`.
   - Opener is raw `useQuery($relationName, $secondaryClass?)` →
     `->withQuery($relationName, function ($q) { ... }, $secondaryClass?)`.
5. **Closure param typing: leave untyped.** Do not resolve relation →
   query-class in Rector. Typing flows from the generated
   `with<Relation>Query()` docblock (see Context). For the raw-`useQuery`
   string form there is no static type anyway, so untyped is correct there
   too.

### Canonical test fixture (the user's real shape)

Input:
```php
$q->useAuthorQuery()
      ->useBookQuery()->filterBySomething('xyz')->endUse()
      ->usePublisherQuery()->filterBySomethingElse('quux')->endUse()
  ->endUse();
```
Expected output:
```php
$q->withAuthorQuery(fn($author) => $author
    ->withBookQuery(fn($book) => $book->filterBySomething('xyz'))
    ->withPublisherQuery(fn($publisher) => $publisher->filterBySomethingElse('quux')));
```
(Parameter names are `$q0`/`$q1`… mechanically; the readable names above are
illustrative.) A deeper chain simply nests further —
`withAuthorQuery(fn($q0) => $q0->withBookQuery(fn($q1) => $q1->withReviewQuery(fn($q2) => …)))`
— with no `use` clauses at any level.

### Explicitly skipped (manual review, never rewritten)

- **Variable-split / non-fluent chains** — `$sub = $q->useX(); …; $sub->endUse();`.
  Detecting these is safe (the spine flatten in step 1 bails when the chain
  doesn't reach a `use*Query` opener within one statement); rewriting them
  is not, without flow analysis. Emit a Rector skip note so downstream users
  see what needs hand-migration. This is also the shape of the core
  `useQuery()`/`endUse()` unit tests in
  `test/testsuite/runtime/query/ModelCriteriaTest.php` (~2112-2227), which
  should stay as-is (they test the deprecated-but-supported API).
- **Dynamic relation names** in raw `useQuery($var)` — rewrite to
  `withQuery($var, closure)` is mechanically valid; just never attempt to
  type the closure param.
- **The `__call()` dispatch path** — not a source call site.

### Packaging as a shippable rule

- Add `rector/rector` to `require-dev`, create `rector.php`.
- Place the rule where downstream can consume it (e.g. a
  `Propulsion\Rector\` namespace shipped in the package, plus a documented
  set-list / config snippet users add to their own `rector.php`).
- Document the **regeneration prerequisite**: a downstream user must
  regenerate their models with the new generator (so `with<Relation>Query()`
  exists) *before* running the rule, or the rewritten calls won't resolve.
- Test the rule with Rector's own fixture harness (input → expected-output
  `.php.inc` fixtures): the canonical shape above, simple single nesting,
  siblings-only, **deep nesting (5+ layers)** to prove depth-generality and
  correct parameter naming, mixed direct-filter-between-pairs, a case where a
  filter argument is an enclosing-scope variable (proves no `use` clause is
  needed), raw-`useQuery('string')` form, and negative fixtures
  (variable-split, ternary-embedded) that must be left untouched.

## 4. Sequencing and verification

No golden-file tests exist for generated ORM code; ORM verification is
behavioral (`test/tools/helpers/IntegrationDatabase.php` regenerates real
classes once per process via `test/bootstrap.php`). The Rector rule is
verified separately by its own input→output fixtures.

1. **Runtime**: add `ModelCriteria::withQuery()` (with `return $this;`),
   deprecate `useQuery`/`endUse` docblocks. Add unit tests for `withQuery()`
   covering single, nested, and sibling-nested cases. `ModelCriteriaTest.php`
   stays green untouched.
2. **Generator pilot**: `with<Relation>Query()` in `addUseRelatedQuery()`
   only. Regenerate fixtures; `NamespaceTest.php`/`QueryBuilderTest.php`
   still pass unchanged (coexistence, no regression to `use` wrappers).
3. **Generator full rollout**: I18n template, `addFilterByCrossFK()`,
   composite-FK block. Verify via `I18nBehaviorQueryBuilderModifierTest.php`
   and cross-reference tests in `NamespaceTest.php` /
   `test/fixtures/namespaced/` — SQL/behavior must be identical (internal
   wiring only). Confirm the coverage grep from §2.
4. **Rector rule**: build against the fixture corpus in §3 first (this is
   the real correctness gate). Then run it self-hosted over this repo's own
   fluent call sites and confirm the behavioral suite still passes with the
   rewritten code — end-to-end proof that closure semantics equal the old
   chain semantics.
5. Leave non-fluent `ModelCriteriaTest.php` cases and `__call()` untouched.
6. Do not remove `useQuery()`/`endUse()` in this pass.

## Critical files

- `runtime/Lib/Query/ModelCriteria.php` — add `withQuery()`; deprecate
  `useQuery()`/`endUse()`.
- `generator/Lib/Builder/OM/QueryBuilder.php` — `addUseRelatedQuery()`,
  `addFilterByCrossFK()`, composite-FK block (~1266-1289).
- `generator/Lib/Behavior/I18n/templates/queryUseI18nQuery.php` — add
  `withI18nQuery()`.
- New: `rector.php`, a shippable rule (e.g.
  `generator/Lib/Rector/UseQueryToWithQueryRector.php` or a dedicated
  package path) + fixture corpus, `rector/rector` in `require-dev`.
- Verify against (unchanged assertions):
  `test/testsuite/runtime/query/ModelCriteriaTest.php`,
  `test/testsuite/generator/builder/om/QueryBuilderTest.php`,
  `test/testsuite/generator/builder/NamespaceTest.php`,
  `test/testsuite/generator/behavior/i18n/I18nBehaviorQueryBuilderModifierTest.php`.

## Risk callouts

- **Rewriter correctness on siblings-in-nesting is the main risk.** The pair
  tree (step 2) plus bottom-up emission (step 3) handles it, but this is
  where bugs will live — the fixture corpus must exercise it hard before the
  rule ships.
- **Generator coverage completeness**: any relation lacking a
  `with<Relation>Query()` wrapper makes downstream rewrites fail to resolve.
  The §2 grep is a hard gate, not optional.
- **Downstream regeneration ordering** must be documented prominently.
- **Non-goal, but cheap and adjacent**: the generator body rewrites in §2
  also clear the (1a) generated-code `return.type` findings. Worth noting in
  the changelog even though it isn't the motivation.

## Option E — stringly-typed columns/comparisons (independent track)

The original design doc recommends E as the *starting* point because it's
orthogonal and low-risk. It does not interact with the useQuery/endUse work
at all — it can proceed in parallel or later, and should not block or be
blocked by C. My honest read of its value:

- **Column names** (`->add('book.TITLE', …)`, `addUsingAlias()`,
  `addCond()`): lower value than it first appears, because the generated
  per-column `filterByTitle()` / `orderByTitle()` methods already give typed,
  autocompleted column access on the common path. Raw string column
  identifiers are the escape hatch, used far less than the `filterBy*`
  surface. A `literal-string` constraint on those parameters is cheap and
  worth doing, but it mostly tightens the escape hatch rather than fixing a
  daily pain.
- **Comparison operators** (`Criteria::EQUAL`, `Criteria::IN`, … — currently
  string class constants like `'='`): the higher-value half. Replacing them
  with a backed enum gives real autocomplete and a narrow argument type
  everywhere a comparison is passed (including every generated
  `filterByX($val, $comparison)`). This is the piece most worth doing.

**Recommended shape for E, mirroring C's playbook** (so it's non-breaking and
migratable): introduce a backed `enum` for comparisons additively; have
signatures accept `Comparison|string`; keep the `Criteria::EQUAL` string
constants as `@deprecated` aliases of the enum cases; and — since we are
already shipping a Rector rule for C — add a second, much simpler Rector rule
that rewrites `Criteria::EQUAL` → `Comparison::Equal` downstream. Column-name
`literal-string` tightening can ride along as a low-priority follow-up.

**Recommendation: keep E as a separate workstream, sequenced after C's core
lands** (not before, despite the doc's ordering — the confirmed pain is 1b,
so C earns priority). Fold it in here only if you want a single coordinated
"query-layer typing" release. It is called out in this plan for completeness,
not scoped in detail; if picked up, it warrants its own short plan covering
the full list of comparison constants and the enum's backing values.
