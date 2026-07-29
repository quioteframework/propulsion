# Tech debt

Every item ever tracked in this file is now resolved. Two batches landed:

**Batch 1** (§1-§6, one commit each): guard `PDO::prepare()` failures, verify
`BasePeer::getValidator()`'s dynamic instantiation, make `ModelCriteria`'s
model-identity properties non-nullable, replace `call_user_func` Peer
dispatch with dynamic static calls plus a first-party PHPStan extension for
`ModelCriteria`'s magic methods, audit nullable `Criteria`/`Criterion`
strings into `BasePeer`, and fix `useQuery()`'s broken generic contract.

**Batch 2** (items A-D below, one commit each, closing out what batch 1
surfaced): fixed `ModelJoin`'s `RelationMap`/`TableMap` nullability, split
`withQuery()`/`withTypedQuery()` to resolve a real PHPStan generics
limitation, fixed every `Validator/*.php` finding batch 1's `BasicValidator`
docblock fix had unmasked, and worked through the rest of `ModelCriteria.php`
down to 2 known-open findings (see below). See git history for each item's
original text and how it was fixed.

`BasePeer.php`, `Criteria.php`, `ModelJoin.php`, and every file under
`runtime/Lib/Validator/` are fully clean at `--level 9`. `ModelCriteria.php`
went from 71 findings (batch 1's baseline) to 2. The project-wide `--level 6`
baseline (`phpstan.neon`) stays clean throughout.

Verify current counts with:

```
vendor/bin/phpstan analyse runtime/Lib/Query/ModelCriteria.php --level 9
vendor/bin/phpstan analyse runtime/Lib/Util/BasePeer.php --level 9
```

## Known-open: `setByName()`'s dynamic dispatch on generated model objects

`ModelCriteria::findOneOrCreate()` (~line 1656) and `doUpdate()`'s
`$forceIndividualSaves` branch (~line 2372) both do `new $class()` (a
dynamically-instantiated generated model, e.g. `Book`) and then call
`$obj->setByName($key, $value, ...)` on it -- a real method every *writable*
generated model class emits, but not part of any shared, PHPStan-visible
contract.

Tried and rejected: narrowing `$obj` to `BaseObject` before the call (via
`instanceof`) actually made things *worse* -- it upgrades the finding from
level-9-only (`Call to an undefined method object::setByName()`, silent at
the project's real `--level 6` baseline) to visible at `--level 6` too
(`Call to an undefined method Propulsion\OM\BaseObject::setByName()`), since
PHPStan checks method existence against a known concrete class stricter than
against the generic `object` type. Declaring `setByName()` abstract on
`BaseObject` itself would fix the type-checking but break real code: verified
`BaseContestView` (a read-only, database-VIEW-backed generated model) extends
`BaseObject` and genuinely has no `setByName()` -- it's a real distinction,
not an oversight.

A real fix needs a generator-level decision: either a marker interface
(`WritableModelInterface` or similar) that only non-view generated model
classes implement, with `setByName()` declared on it, and `findOneOrCreate()`
etc. checking `instanceof` that interface instead of `BaseObject` -- or
something equivalent. Left as-is (the pre-existing, always-`mixed`-typed
dynamic dispatch, matching every other case of this shape already documented
via the PHPStan extension added in batch 1's §1) rather than force a
narrower, breaking fix under this batch's scope.
