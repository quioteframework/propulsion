# Tech debt

**Nothing in this file is open any more.** `phpstan.neon` is at `level: 9` and
the whole project (`generator`, `runtime`, `bin`) analyses clean at it, including
the `Column.php`/`Table.php` item below that was the last thing left. Verify with:

```
vendor/bin/phpstan analyse          # uses phpstan.neon: level 9, whole project
```

Everything below is kept as the history of how each item was resolved, and as the
reasoning behind decisions (the `require*()`-guard pattern, `WritableModelInterface`)
that new code is still expected to follow. Note that several passages describe the
baseline as `--level 6` or `--level 7`: those were accurate when written and have
been left as-is rather than retconned, since the surrounding argument only makes
sense against the level in force at the time.

## Resolved: `Column.php`/`Table.php` not clean at PHPStan `--level 9`

`generator/Lib/Model/Column.php` (115 findings) and `generator/Lib/Model/Table.php`
(81 findings) were far from clean at `--level 9` (the project's baseline at the
time, `phpstan.neon`, was `--level 6` and unaffected). Unlike the
`Platform` classes' own version of this problem (below), this wasn't
fixable file-locally with a couple of `require*()` guards -- the findings
traced back to two structural issues in the model layer itself:

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

Both were resolved on the way to making `phpstan.neon` itself `level: 9`; the
two structural issues above are the reasoning that shaped how, and remain the
reference for anything new in the model layer.

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
went from 71 findings (batch 1's baseline) to 2, and subsequently to 0 --
`phpstan.neon` is now `level: 9` project-wide, so the whole tree is verified by
the single `vendor/bin/phpstan analyse` at the top of this file rather than by
per-file spot checks.

## Resolved: `setByName()`'s dynamic dispatch on generated model objects

`ModelCriteria::findOneOrCreate()` (~line 1646) and `doUpdate()`'s
`$forceIndividualSaves` branch (~line 2372) both do `new $class()` (a
dynamically-instantiated generated model, e.g. `Book`) and then call
`$obj->setByName($key, $value, ...)` on it -- a real method every *writable*
generated model class emits, but that wasn't part of any shared,
PHPStan-visible contract.

Narrowing `$obj` to `BaseObject` before the call (via `instanceof`) doesn't
work: it upgrades the finding from level-9-only (`Call to an undefined method
object::setByName()`, silent at the project's real `--level 6` baseline) to
visible at `--level 6` too, since PHPStan checks method existence against a
known concrete class stricter than against the generic `object` type.
Declaring `setByName()` abstract on `BaseObject` itself would fix the
type-checking but break real code: `BaseContestView` (a read-only,
database-VIEW-backed generated model) extends `BaseObject` and genuinely has
no `setByName()` -- a real distinction, not an oversight.

**Fix**: a new marker interface, `Propulsion\OM\WritableModelInterface`
(`runtime/Lib/OM/WritableModelInterface.php`), declaring `setByName()`,
`setByPosition()`, and `fromArray()` -- the three methods
`ObjectBuilder::addClassBody()` only ever emits together, all gated by the
identical `AbstractObjectBuilder::isAddGenericMutators()` condition (false
for read-only/VIEW-backed tables, alias tables, or a schema that opts out via
the `propulsion.addGenericMutators` build property). `ObjectBuilder::addClassOpen()`
now has every generated model class implement it exactly when
`isAddGenericMutators()` is true -- in lockstep with whether those methods
actually exist, by construction, rather than by inferring it from
`isReadOnly()` alone (which would have missed the alias/build-property
opt-out cases). `ModelCriteria::findOneOrCreate()` checks
`instanceof WritableModelInterface` before its `setByName()` calls (the
existing `instanceof BaseObject` check stays too, further down, for the
`formatRecord(?BaseObject $record)` call -- `WritableModelInterface` doesn't
extend `BaseObject`, so both checks are needed for their own reasons). The
`doUpdate()` call site needed no change: its `$object` comes from iterating a
`PropulsionObjectCollection` (an untyped `\ArrayObject`), so PHPStan already
saw it as `mixed`, not `object` -- never actually flagged.

Bare (unnamespaced) generated fixture/legacy code references runtime classes
by their bare global name (see `runtime/Lib/legacy-class-map.php`'s own
docblock) -- `WritableModelInterface` needed an entry there too, alongside
`Persistent`'s, or `class_exists('WritableModelInterface')` fails for
non-namespaced generated classes.

This was the last `--level 7` finding project-wide (182 -> 0 over the course
of the `--level 7` push); `phpstan.neon` is now at `level: 7`.
