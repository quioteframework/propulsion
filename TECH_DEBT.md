# Tech debt

## Open: `Column.php`/`Table.php` not clean at PHPStan `--level 9`

`generator/Lib/Model/Column.php` (115 findings) and `generator/Lib/Model/Table.php`
(81 findings) are far from clean at `--level 9` (the project's real baseline,
`phpstan.neon`, stays at `--level 6` and is unaffected). Unlike the
`Platform` classes' own version of this problem (below), this isn't
fixable file-locally with a couple of `require*()` guards -- the findings
trace back to two structural issues in the model layer itself:

1. **`XMLElement::getAttribute()` returns untyped `mixed`** (declared with no
   param/return types at all, just a docblock) -- every `$this->getAttribute(...)`
   call in `setupObject()` (which is most of `Column.php`'s and `Table.php`'s
   own findings: `assign.propertyType`, `argument.type` into
   `booleanValue()`/`strtolower()`/`ColumnDefaultValue`'s constructor/
   `DOMElement::setAttribute()`, etc.) propagates `mixed` into a typed
   property or a narrower parameter. A real fix needs typed accessor wrappers
   on `XMLElement` (e.g. `getStringAttribute()`, `getBoolAttribute()`) used
   consistently across every `Model` class's `setupObject()` -- a mechanical
   but wide-reaching change, not scoped to either file alone.
2. **`Column::$table`/`Table::$database`/`Column::$domain` are typed nullable**
   but are, by construction, always set by the time any real DDL/schema code
   runs on a fully-loaded model (same "always populated once loaded, but
   typed nullable" shape the `Platform` classes' `require*()` guards below
   already solve, but from the *producing* side here rather than the
   *consuming* side) -- accounting for most of the `method.nonObject`
   findings (`Cannot call method X() on Table|null`, etc.). Fixing this
   properly likely means either a real two-phase construct-then-attach
   invariant enforced by the type system (harder, more invasive), or pushing
   the same `require*()`-guard pattern into `Column`/`Table`'s own getters
   -- which just relocates the problem rather than removing it, since
   *something* still has to assert the invariant PHPStan can't infer.

Not attempted here: this is squarely "needs a generator-level decision", the
same bar the `setByName()` known-open item below was already held to.

Every other item ever tracked in this file is resolved. Two batches landed:

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
