# Tech debt

All six items originally tracked in this file (§1-§6 below, as landed one
commit each: guard PDO::prepare() failures, verify BasePeer::getValidator()'s
dynamic instantiation, make ModelCriteria's model-identity properties
non-nullable, replace call_user_func Peer dispatch with dynamic static calls
plus a first-party PHPStan extension for ModelCriteria's magic methods, audit
nullable Criteria/Criterion strings into BasePeer, and fix useQuery()'s broken
generic contract) are now resolved. `BasePeer.php` and `Criteria.php` are
fully clean at `--level 9`; `ModelCriteria.php` went from 71 findings to 39.
See git history for the original text of each item and how it was fixed.

Verify current counts with:

```
vendor/bin/phpstan analyse runtime/Lib/Query/ModelCriteria.php --level 9
vendor/bin/phpstan analyse runtime/Lib/Util/BasePeer.php --level 9
```

What follows is the debt discovered *while* closing out those six items --
either genuinely new (revealed once a wrong nullable/non-nullable annotation
elsewhere was corrected) or pre-existing but outside this batch's scope.

## A. `ModelCriteria.php`: `RelationMap|null` treated as always-set

`ModelJoin::getRelationMap()`/`getTableMap()` are declared nullable (only set
via `setRelationMap()`/`setTableMap()`), but call sites in `with()` (~line
921-923) and `useQuery()` (~line 1039) call methods on the result unchecked.
Same shape as the original §2 (`ModelCriteria`) and §4 (`Criteria`) items --
needs the same audit: is a `ModelJoin` returned by `getNamedModelJoin()` ever
missing its `RelationMap`/`TableMap` in practice, or is this another
"declared nullable, always populated by construction" case that can be
tightened at the source?

## B. `withQuery()`'s callback type hits a PHPStan generics limitation

Fixing `useQuery()`'s null-branch return type from (wrong) `static` to
(correct) `ModelCriteria` -- the actual fix this file's old §6 called for --
forces the same correction onto `withQuery()`'s `$callback` parameter type
(`($secondaryCriteriaClass is null ? callable(static): void : callable(T):
void)` was equally wrong, for the same reason). Once corrected to
`callable(ModelCriteria): void` for the null branch, the single remaining
finding (`Parameter #1 of callable callable(T): void expects T of
ModelCriteria, ModelCriteria given`) is a verified, unavoidable PHPStan
soundness limitation, not a real bug -- confirmed by reproducing the
identical error in a minimal, unrelated class hierarchy where the annotated
type and the actual runtime type are provably identical. PHPStan does not
narrow a conditionally-typed callable parameter per branch inside the
implementing method's own body (the conditional type is a caller-facing
contract only); reverting to `static` just trades this finding for a
different, equally real one (`static` no longer matches `useQuery()`'s now-
honest `ModelCriteria` return). Tried and rejected: inlining the branch
logic, extracting private per-branch helpers, dispatching via
`call_user_func()` -- all reproduce the same error. A real fix needs an API
decision (split `withQuery()` into a class-string-required generic method
and a separate non-generic no-class method, accepting a small public API
change) rather than a docblock tweak.

## C. `Validator/*.php`: real findings unmasked by fixing `BasicValidator`'s docblock

`BasicValidator::isValid()`'s `@param string $str` was simply wrong --
validators receive arbitrary column values, not just strings, as
`MaxValueValidator`/`MinValueValidator`'s own comparison logic already
assumed. Correcting it to `@param mixed $str` (needed to close a `BasePeer.php`
finding) surfaced pre-existing, previously-hidden level-9 findings in the
validator implementations themselves (each was being silently checked against
the wrong inherited `string` type before): `MatchValidator`/`NotMatchValidator`
`prepareRegexp()` return/param nullability, `TypeValidator::isValid()` passing
a mixed value to `function_exists()`, `UniqueValidator`'s own
`call_user_func()`-based Peer dispatch (same shape as the old §1), and
`ValidValuesValidator`'s `preg_split()`/`in_array()` typing. Not fixed here --
these files weren't touched by this batch, so CLAUDE.md's
"clean the file you edit" rule didn't apply -- but now visible and worth a
pass.

## D. `ModelCriteria.php`: assorted `mixed`-typed findings, not part of the original six

Independent of the above, `--level 9` on `ModelCriteria.php` still shows:

- `find()`/`findOne()`/`findOneOrCreate()` (~1471, 1514-1529, 1559) declare
  concrete return types but return whatever `PropulsionFormatter::format()`/
  `formatRecord()` gives back, typed `mixed` -- same "declared type doesn't
  match what actually flows through" shape as the old §1/§2, just on the
  formatter dispatch path instead of the Peer dispatch path.
- `getCriterionForConditions()`'s `$operator` parameter (~347, 408) and a
  handful of `string|null`-into-non-nullable-`string` call sites (~439, 442,
  466, 656-659, 1336, 2722) -- likely more instances of the same
  always-set-in-practice pattern already fixed elsewhere in this file; not
  yet audited.
- `Criterion|null` at `addAnd()`/`addOr()` (~815) and a few `mixed` array-key/
  `sprintf` findings (~843-846, 2459-2461) in the pseudo-SQL clause parser --
  not yet looked at.

None of these were part of the original six items, so they weren't in scope
for this batch; listed here so they're not lost.
