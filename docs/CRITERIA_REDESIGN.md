# Redesigning Criteria / ModelCriteria

This document exists to capture a long-standing, recurring source of friction
in Propulsion's query-building layer (`runtime/Lib/Query/Criteria.php`,
`Criterion.php`, `ModelCriteria.php`, `ModelCriterion.php`, and the
generated `Base*Query` classes built on top of them) so it can be planned
properly instead of re-discovered piecemeal every time it bites. None of
this is new — it's the same handful of shapes that keep resurfacing whenever
this code is touched, most recently while chasing PHPStan level 8/9 findings
in generated code.

It is **not** a plan to implement immediately. It's a record of the problem,
why it's structural rather than a bug, and the realistic options for
actually fixing it, so that decision can be made deliberately later.

## The core complaint

`Criteria`/`ModelCriteria` is a ~25-year-old design (inherited essentially
unchanged from Propel 1, which itself inherited it from Torque). It predates
PHP generics-via-docblocks, predates `static` return types, predates
PHP having a type system worth statically checking at all. Two decades of
"one shared mutable object represents both the accumulating query state and,
transiently, whatever sub-query you're currently building" has produced an
API that is *flexible* but essentially untypeable in its current shape.
Concretely, three separate problems, each independent of the others:

### 1. `useQuery()`/`endUse()` can't round-trip a type through two calls

```php
$books = BookQuery::create()
    ->useAuthorQuery()           // switches "current" context to an AuthorQuery
    ->filterByFirstName('Jane')  // ...build conditions against Author's columns...
    ->endUse();                  // ...then switch back to the original BookQuery
```

`useQuery()` (and the generated `use<Relation>Query()` wrappers) can be
correctly typed today — it's a `@template T of ModelCriteria` /
`class-string<T>` generic keyed off the class name argument, so PHPStan
*can* tell you `useAuthorQuery()` returns an `AuthorQuery`. But `endUse()`
returns "whatever object called `useQuery()` in the first place," and that
information is thrown away: it's stored in a plain `?ModelCriteria
$primaryCriteria` property with no memory of which concrete subclass it
was. There is no way to express "the return type of this method depends on
the argument to a *different* method call that happened earlier, on a
*different* object, and was stored in a property" using PHP's generics —
that would need instance-level generics with a mutable type parameter,
which doesn't exist in PHP or in PHPStan's docblock generics. Every
generated `filterBy<CrossReference>()` method whose implementation chains
through `useQuery()->...->endUse()` inherits this: PHPStan sees the return
type collapse to the generic `ModelCriteria` supertype, even though the
runtime value is always correct.

### 2. Fluent methods can't be typed as "the same concrete class as the caller," only "the base class"

Every generated `Base*Query`/`Base*` class defines fluent methods
(`filterByX()`, `joinY()`, `setZ()`, `addRefFK()`, ...) that `return $this;`
or reciprocally accept `$this` from a peer `Base*`-class method. Two
different shapes of the same underlying limitation:

- **Return position**: solvable — PHP's `static` return type means "whatever
  class was actually instantiated," so `@return static` (or a native
  `: static`) is exactly right and was already applied broadly this session.
- **Parameter position**: *not* solvable the same way. `static` cannot be
  used as a parameter type in PHP. So when a `Base*` class method is called
  reciprocally with `$this` from another `Base*`-class method (e.g. a
  foreign-key setter calling the referrer's adder with itself as the
  argument), the only types available are (a) the concrete stub subclass —
  accurate in practice (the abstract base is never directly instantiated)
  but not provable to PHPStan from inside the base class itself — or (b) the
  abstract base class, which is provably correct but degrades the *public*
  API: every consumer-facing getter/setter for a relation would need to
  return/accept the never-instantiated abstract class instead of the real
  model class, for every relation, everywhere. This session explicitly chose
  (a) — real usability over a clean PHPStan run — and left the resulting
  ~30 `argument.type` findings as a deliberate, accepted gap. See
  `KNOWN_ISSUES.md` and the PHPStan level 8 pass commit for the specifics.

### 3. The query API itself is stringly-typed at its core

Independent of the two generics problems above, `Criteria::add()`,
`addCond()`, `addUsingAlias()`, and friends take column identifiers as
plain `string`s (qualified names like `'book.TITLE'`, resolved at runtime
against a `TableMap`) and comparison operators as string class constants
(`Criteria::EQUAL`, `Criteria::IN`, ...). There's no static link between
"this column belongs to this table" or "this comparison is valid for this
column's type" — everything is checked at runtime, if at all. The
*generated* per-column `filterByX()` methods layer real types on top of
this (that's what all the PHPStan work this session was tightening), but
the underlying `Criteria` object they delegate to has no memory of any of
those types once the call crosses into it. This is what makes problems #1
and #2 hard to fix locally: any fix has to either stay entirely within the
generated layer (papering over, not fixing) or reach into `Criteria`
itself, which the entire ORM depends on and which has none of the type
information needed to do this properly.

## Why this is hard, not just unfinished

All three problems trace back to one design decision made once, decades
ago: **the query builder is a single mutable class hierarchy shared across
every table**, with per-table behavior injected via generated subclasses
rather than the query object itself carrying compile-time knowledge of
"which table/model this instance is for." Modern equivalents (Doctrine's
`QueryBuilder`, most TypeScript query builders) solve this with real
generics parameterized by the entity type, threaded through every method
signature and every stored reference. PHP's generics are docblock-only,
erased at runtime, and (critically for problem #1) can't be attached to an
*instance* the way TypeScript's can — there's no way to write "this
object's type parameter is whatever it was constructed with" and have a
*different* object read that parameter back out of a stored reference to
it.

## Options, roughly ordered by invasiveness

### A. Do nothing (status quo)

Keep documenting the gaps as they're found (as `KNOWN_ISSUES.md` already
does). Zero cost, zero benefit beyond the status quo. Reasonable if nobody
is actually blocked by this in practice — the runtime behavior is correct,
only the static types are imprecise.

### B. Patch `endUse()` with a best-effort generic, accept it's incomplete

Give `ModelCriteria` a class-level `@template TPrimary of ModelCriteria`
and have `useQuery()` return `self<TPrimary>`-style self-referential
generics, with `$primaryCriteria` typed `TPrimary|null` and `endUse()`
returning `TPrimary`. This is the smallest change that could plausibly
close gap #1, but it requires adding a class-level template parameter to
`ModelCriteria` itself, which means *every* existing docblock reference to
`ModelCriteria` in the entire codebase (and in every consumer's code)
either needs updating to `ModelCriteria<SomeClass>` or falls back to an
implicit `ModelCriteria<*>`/`mixed`, which may or may not fully close the
gap depending on how PHPStan resolves the erased case. Worth prototyping in
isolation before committing to it — there's a real risk it trades one
category of finding for another (unresolved template parameters instead of
`ModelCriteria` supertype leaks) without being simpler to reason about.

### C. Replace the `useQuery()`/`endUse()` pattern with an explicit callback-scoped sub-query API

Instead of mutating "current context" and switching back, take a page from
Doctrine/Eloquent-style closures:

```php
$books = BookQuery::create()
    ->withRelation(NamespacedAuthorQuery::class, function (NamespacedAuthorQuery $q) {
        $q->filterByFirstName('Jane');
    })
    ->find();
```

The closure parameter can be natively, exactly typed (it's just a normal
function parameter, no cross-call generics needed), and there's no
`endUse()` to mistype because the "switch back" is just the closure
returning. This is a real, clean fix to gap #1 with no PHP type-system
workarounds needed — but it's a breaking API change for anyone using
`useQuery()`/`endUse()` directly (not just via generated `use<X>Query()`
wrappers), and the generated `filterBy<CrossReference>()` methods that
currently chain through `useQuery()` internally would need rewriting (their
*external* signature wouldn't need to change, only their *implementation*,
since they're generated and don't currently expose `useQuery()`/`endUse()`
to their own callers — this is likely lower-risk than it first sounds, but
needs verifying method-by-method).

### D. Break up the single shared hierarchy: generic `Criteria<TModel>`

The "real" fix, matching how modern statically-typed query builders do it:
give `Criteria`/`ModelCriteria` a class-level `@template TModel of
BaseObject`, thread it through every method that currently returns
`static`/`self`/`ModelCriteria` and every property that stores a reference
to another criteria object, and have the generated `Base*Query` classes
extend `ModelCriteria<ConcreteModelClass>` instead of plain `ModelCriteria`.
This closes gaps #1 and #2 simultaneously and gives real column-level
typing a path forward (`TModel`'s `TableMap` could drive column-name
autocompletion/validation). It is also the largest option by a wide margin:
it touches `Criteria.php`, `Criterion.php`, `ModelCriteria.php`,
`ModelCriterion.php`, every builder template that emits a `Base*Query`
class declaration, and every existing call site (in this codebase's own
`runtime/`/`generator/` and in every downstream user's code) that names
`ModelCriteria` without a type parameter. This is a multi-week redesign,
not a follow-up commit, and should not be started without a clear sense of
who it's for and what "done" looks like (a design spike/prototype against
one or two fixtures before committing to the full migration).

### E. Column-string-safety independent of A-D

Regardless of which of the above (if any) gets picked, problem #3 (stringly
typed column names/comparisons) could be improved independently and
incrementally: PHPStan supports `literal-string`-constrained parameters and
enum-backed constants could replace the `Criteria::EQUAL`-style string
class constants with real backed enums, giving IDE autocomplete and a
narrower argument type without touching the generics problems at all. This
is the only option here that's genuinely low-risk and could be done as a
normal follow-up commit rather than a redesign.

## Recommendation

Not making one here — this document's job is to lay out the shape of the
problem and the options, not to pre-decide the outcome of what should be a
deliberate call. If/when this gets picked up: start with **E** (independent,
low-risk, real value on its own), and treat **C** as the most promising
option for actually closing the `useQuery()`/`endUse()` gap without
committing to the full **D** rewrite — but prototype **C** against the
`namespaced` and `generator-parity` fixtures (the two that most heavily
exercise cross-reference filtering) before touching any generated-code
templates for real, since the migration cost for existing users directly
calling `useQuery()`/`endUse()` needs to be weighed honestly, not assumed
away.
