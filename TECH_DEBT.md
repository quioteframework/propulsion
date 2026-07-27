# Tech debt

Larger, systemic PHPStan level-9 findings that don't fit the "fix while you
touch the file" rule in `CLAUDE.md` — each of these is a standalone rewrite,
not a local fix. Discovered while landing the `PLATFORM_FEATURES.md` batches
(pessimistic locking, `ColumnExpression`, MSSQL `OUTPUT`, the upsert
abstraction); see that history for what *was* fixed inline in the same files
(e.g. `DBPostgres`/`DBMySQL`/`DBOracle`/`DBMSSQL`/`DBAdapter` were all brought
to a clean `--level 9` as part of these batches; `DBSQLite`/`Criteria`/
`ColumnExpression` started and stayed clean).

Baseline at time of writing: `runtime/Lib/Query/ModelCriteria.php` has 69
level-9 findings (was 66 before the upsert batch added 3 more instances of
the exact same §1/§2 pattern below -- dynamic `call_user_func` dispatch to
`$this->modelPeerName` and a `constant($this->modelPeerName.'::TABLE_NAME')`
call in the new `doUpsert()` method, mirroring the pre-existing `doUpdate()`
code it was modeled on). `runtime/Lib/Util/BasePeer.php` has 28 (unchanged --
`doUpsert()` and the `buildSetClause()` helper extracted from `doUpdate()`
came in clean). Verify current counts with:

```
vendor/bin/phpstan analyse runtime/Lib/Query/ModelCriteria.php --level 9
vendor/bin/phpstan analyse runtime/Lib/Util/BasePeer.php --level 9
```

## 1. Dynamic `call_user_func`/`call_user_func_array` dispatch to the Peer class

`ModelCriteria` holds the generated Peer class name as a plain string
(`$this->modelPeerName`, typed `string|null`) and calls static methods on it
by name at runtime instead of a direct static call, e.g.
(`runtime/Lib/Query/ModelCriteria.php`):

- `call_user_func(array($this->modelPeerName, 'doDelete'), ...)` (~line 1822)
- `call_user_func(array($this->modelPeerName, 'doDeleteAll'), ...)` (~line 1887)
- `call_user_func(array($this->modelPeerName, 'clearInstancePool'))` /
  `'clearRelatedInstancePool'` (~lines 2032-2033)
- `call_user_func(array($this->modelPeerName, 'getFieldNames'), ...)` /
  `'translateFieldName'` (`BasePeer.php` ~lines 100, 115, called with a Peer
  class name threaded in from elsewhere)
- `call_user_func_array(array($this, 'filterBy' . $relation), $args)`
  (~line 2496) and similar `$this`-based dynamic method dispatch for magic
  `filterBy*`/`joinWith` methods

PHPStan can't verify any of these — the callable shape is
`array{string|null, 'methodName'}`, which is never assignable to
`callable(): mixed` since a `null` class name isn't callable and PHPStan has
no way to confirm the named method exists on whatever class the string
names. Each call site is a separate, unverifiable finding (~15 of the 66 in
`ModelCriteria.php`).

**Why this exists:** the generated `BaseXxxQuery`/`BaseXxxPeer` classes are
produced at build time from XML schema, so `ModelCriteria` (hand-written,
shared across every model) has no compile-time way to know the concrete Peer
class — hence the string-keyed dynamic dispatch. This is architecturally the
same problem `PLATFORM_FEATURES.md`'s cross-ORM ideas section already flags:

> **PHPStan extension.** Given the level-9 goal in `CLAUDE.md`, shipping an
> extension that teaches PHPStan about generated query/Peer classes and the
> magic `__call()` filters (as Doctrine and Larastan do for their ecosystems)
> would pay off for consumers as well as this repo.

**Options, roughly in order of effort:**
1. A small first-party PHPStan extension (dynamic-return-type + method
   existence reflection against the generated classmap) — the "proper" fix,
   and reusable by every consumer of generated Propulsion models, not just
   this repo's own analysis.
2. Replace the `call_user_func`/`array($class, $method)` calls with PHP 8.1+
   first-class callable syntax where the class is knowable
   (`$this->modelPeerName::doDelete(...)` reads identically at runtime and
   at least lets PHPStan see it's a static call, even if it still can't
   verify the method exists on an unknown class string).
3. Narrow `$modelPeerName` to non-nullable `string` (see §2) so at minimum
   the "expects callable, `string|null` given" half of each finding goes
   away, leaving only the "method existence on an unverified class" half.

None of these are a small, local diff — each is its own PR.

## 2. Nullable model metadata treated as always-initialized

`ModelCriteria`'s core identity properties are declared nullable but every
real code path (any generated `BaseXxxQuery` constructor) always sets them:

```php
/** @var string|null */
protected $modelName;
/** @var string|null */
protected $modelPeerName;
/** @var TableMap|null */
protected $tableMap;
```

This is the root cause of most of the remaining ~40 findings in
`ModelCriteria.php` — every getter built on top of these
(`getModelName()`, `getModelPeerName()`, `getTableMap()`,
`getModelAliasOrName()`, `useQuery()`/`endUse()`'s stored parent-query
reference, etc.) is declared to return the non-nullable type but PHPStan
correctly infers the nullable one leaking through, e.g.:

- `getTableMap(): TableMap` actually returns `TableMap|null`
- `getModelPeerName(): string` actually returns `string|null`
- `getModelName()`/`getModelAliasOrName()`: same shape
- Every downstream caller that assumes non-null (`$this->tableMap->getColumn(...)`,
  `$this->getTableMap()->getName()`, etc.) then produces its own
  "Cannot call method X on Foo|null" finding one level further out.

**Why this is a rewrite, not a fix:** `ModelCriteria` genuinely does have a
brief nullable window (the no-arg `new ModelCriteria()` constructor exists
and is used, e.g. by `Criteria`-style raw usage in a few tests), so simply
widening these to non-nullable types would be lying to PHPStan the same way
`@phpstan-ignore` would — the constructor really can leave them unset. A
real fix needs one of:
1. A dedicated "identified" state — e.g. split into `ModelCriteria` (always
   has model identity, non-nullable properties) vs. a bare
   `Criteria`-like variant for the no-model-yet construction path, so the
   type system reflects the real invariant instead of a runtime one.
2. Or: make the no-model constructor throw/require a follow-up call that
   the type checker can see narrows the properties (awkward in PHP without
   proper flow-sensitive typing across method boundaries).
3. Or, cheapest but weakest: assert-and-throw at the top of every method
   that needs non-null identity (`getModelName()`, `doDelete()`, etc.),
   which at least turns "silently wrong at runtime" into "loud exception",
   without fully resolving the PHPStan findings (assertions don't narrow
   property types across method calls the way local variable narrowing
   does).

## 3. `BasePeer.php`: unchecked `PDO::prepare()`/`PDOStatement::execute()` returns

Three call sites in `BasePeer.php` (~lines 166-168, 211-213, 500) call
`$con->prepare($sql)` and use the result without checking for PDO's
documented `false` return on failure — `doInsert()` already got this fix in
the "fold id retrieval into INSERT" batch (see git history), but
`doSelect()`/`doCount()`/`doDelete()`'s equivalents did not. Same shape each
time:

```php
$stmt = $con->prepare($sql);
$db->bindValues($stmt, $params, $dbMap); // PDOStatement|false given
$stmt->execute();                         // Cannot call method on PDOStatement|false
```

**Fix shape** (already applied once, see `doInsert()`): add
`if ($stmt === false) { throw new PropulsionException(...); }` right after
each `prepare()` call. Mechanical, low-risk, ~3 call sites — this one is
close to a "small batch" and could reasonably be picked up on its own
without the rest of this document's larger items.

## 4. `BasePeer.php`: nullable strings flowing from `Criteria`/`Criterion` into non-nullable APIs

A cluster of findings (~lines 208, 268, 313, 667-670, 795, 902-903, 907,
915) where a value typed `string|null` somewhere upstream in `Criteria`
(table name, alias, classname for a validator, etc.) reaches a method that
declares a non-nullable `string` parameter — `quoteIdentifierTable()`,
`DatabaseMap::getTable()`, `getValidator()`, `hasSelectQuery()`,
`array_map()` over `quoteIdentifierTable`, `strpos()`/`explode()`. Same root
shape as §2 (nullable state that's realistically always set by the time
these run) but on `Criteria`'s side rather than `ModelCriteria`'s — worth
tracking separately since fixing §2 won't fix these; they'd need their own
audit of which `Criteria` properties are genuinely optional vs.
always-populated-by-the-time-BasePeer-touches-them.

## 5. `BasePeer::getValidator()`'s untyped validator registry

```php
/** @var array<string, BasicValidator> */
private static $validatorMap = [];
...
$cls = Propulsion::importClass($classname); // returns a class-string, loosely
$v = new $cls();                             // typed `object`, not `BasicValidator`
self::$validatorMap[$classname] = $v;        // assign.propertyType: array<string,object> given
```

`getValidator()` is documented to return `BasicValidator|null` but nothing
actually verifies the dynamically-loaded class extends `BasicValidator` —
this is a real (if narrow) correctness gap, not just a PHPStan annoyance: a
misconfigured validator class name would only fail once something calls an
undeclared method on it, far from the actual misconfiguration. Fix: an
`instanceof BasicValidator` check right after instantiation, throwing a
clear `PropulsionException` if it fails, mirroring the `DBAdapter::factory()`
fix already applied to `DBAdapter.php` for the exact same "dynamically
instantiated class isn't what we expected" shape.

## 6. `ModelCriteria::useQuery()`'s broken generic contract

```php
/**
 * @template T of ModelCriteria
 * @param class-string<T>|null $secondCriteria
 * @return static|T
 */
public function useQuery($relationName, $secondCriteria = null): ModelCriteria
```

PHPStan flags that the method always returns a concrete `ModelCriteria`
regardless of the `T` template parameter passed in — the `@return static|T`
contract is unenforced by the actual return statements. Callers that pass
a `class-string<T>` and expect a `T` back (a documented, presumably
intentional feature for query subclass chaining) get an object PHPStan
can't confirm matches. Needs either an actual `instanceof $secondCriteria`
narrowing return, or dropping the generic contract if it was never
actually honored at runtime — needs a decision on which behavior is
"correct" before it's fixable either way.
