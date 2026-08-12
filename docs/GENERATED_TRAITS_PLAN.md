# 3.0: generated code moves from base classes into traits

## Context

Generated model code lives in `Base<Model>`, and the user's `<Model>` extends it.
Every relation call the generator emits is therefore written *in the base* and
passes `$this`:

```php
// emitted into BaseBook
$review->setBook($this);                  // setBook(?Book $v) is on BaseReview
$query->filterByBook($this)->find($con);  // filterByBook(Book|PropulsionObjectCollection<Book>)
```

`$this` there is a `BaseBook`; the parameters say `Book`. It holds at runtime
only because the generated stub is the sole subclass of the base -- an invariant
nothing states or enforces. That is 253 of the ~550 PHPStan level 9 findings left
in generated code, and a real `TypeError` for anyone who hand-writes
`class Rogue extends BaseBook`.

`KNOWN_ISSUES.md` ("Open: generated relation parameters name the stub class, not
the base") records the problem, the approaches that do not work, and a
workaround. **This document supersedes the workaround.** The workaround --
widening the parameters to `BaseBook` -- was attempted and abandoned: widening a
parameter forces widening the property it assigns to, which forces widening the
accessor that returns it, and `getBook(): ?BaseBook` breaks the entire point of
the stub, because `$review->getBook()->myCustomMethod()` stops resolving.
Measured: 253 findings removed, 56 new ones of exactly that shape created.

## The fix

Emit generated code as a **trait**, and make the user's class the real class:

```php
// om/BookGenerated.php -- regenerated every build, never edited
trait BookGenerated {
    protected ?Review $aReview = null;
    public function link(Review $r): void { $r->setBook($this); }
}

// Book.php -- generated once if absent, then owned by the user
class Book extends BaseObject implements Persistent, Poolable {
    use BookGenerated;
    public function custom(): string { return 'mine'; }
}
```

PHPStan analyses a trait body **once per using class**, with `$this` typed as
that class. Inside `BookGenerated`, `$this` *is* `Book`. The 253 findings do not
get suppressed or widened away; the premise that generated code might not be
running in `Book` stops being true.

Nothing downstream has to move: parameters keep naming `Book`, properties keep
`?Book`, `getBook(): ?Book` keeps returning the stub. The cascade that killed the
workaround never starts.

### Verified before writing this

Checked against PHPStan 2.2.5 and executed under PHP 8.5, in
`/tmp/.../scratchpad/traittest` (scratch, not committed -- rebuild if needed):

- `$this` inside a trait satisfies a parameter typed as the using class.
- The user's own method still resolves through the accessor
  (`$r->getBook()->custom()`).
- `parent::` from inside a trait resolves to the *using class's* parent, so
  `BaseObject` stays an ordinary hand-written abstract class.
- Concrete inheritance works: `ConcreteArticle extends ConcreteContent`, each
  using its own trait. Trait methods override *inherited* ones, so the child's
  generated method wins over the parent's -- the precedence we want.
- The using class's own methods override the trait, so users can still override
  generated behaviour.
- `static` returns resolve to the concrete class.

Precedence, confirmed by execution: **class's own method > trait method >
inherited method.**

### What it also unblocks

Several existing compromises exist *only* because `$this` was not provably the
model, and can be revisited once this lands:

- `<Model>Peer::addInstanceToPool(Poolable $obj)` takes an interface for this
  reason (`runtime/Lib/OM/Poolable.php`).
- `<Model>Peer::doValidateThis($obj)` is untyped with a runtime `instanceof` for
  this reason.
- The `Base<Model>` section of `KNOWN_ISSUES.md` goes away.

## 1. Builders that emit a base class

Five emit `abstract class Base<X>` and become trait emitters:

| Builder | Emits today | Emits after |
| --- | --- | --- |
| `Builder/OM/ObjectBuilder.php` | `abstract class BaseBook` | `trait BookGenerated` |
| `Builder/OM/PeerBuilder.php` | `abstract class BaseBookPeer` | `trait BookPeerGenerated` |
| `Builder/OM/QueryBuilder.php` | `abstract class BaseBookQuery` | `trait BookQueryGenerated` |
| `Builder/OM/NodeBuilder.php` | `abstract class BaseTestNode` | `trait TestNodeGenerated` |
| `Builder/OM/NodePeerBuilder.php` | `abstract class BaseTestNodePeer` | `trait TestNodePeerGenerated` |

`Builder/OM/TableMapBuilder.php` emits `class <X>TableMap extends TableMap` and
has no stub, so it is unaffected.

The five stub builders change what they emit from `extends Base<X>` to
`use <X>Generated;`, and gain the real parent/interfaces the base used to carry:

- `ExtensionObjectBuilder`, `ExtensionPeerBuilder`, `ExtensionQueryBuilder`,
  `ExtensionNodeBuilder`, `ExtensionNodePeerBuilder`,
  `ExtensionQueryInheritanceBuilder`.

`MultiExtendObjectBuilder` (single-table inheritance) and
`QueryInheritanceBuilder` produce classes that extend the *stub*, not the base,
so they should need only their `getParentClassName()` reviewed.

## 2. Naming and layout

`OMBuilder::getClassname()` is `prefixClassname(getUnprefixedClassname())`, and
`ObjectBuilder::getUnprefixedClassname()` prepends the `propulsion.basePrefix`
build property (`'Base'`, `generator/default.php:116`).

Decide once and apply everywhere:

- **Name**: `BookGenerated` (suffix) reads better than `BaseBook` for a trait and
  avoids colliding with anything a project already has called `BaseBook` during
  migration. A `propulsion.generatedSuffix` property replacing `basePrefix`
  keeps it configurable.
- **Path**: keep the `om/` sub-package, so `om/BaseBook.php` becomes
  `om/BookGenerated.php`. `getClassFilePath()` follows the classname, so this
  should fall out.

Both names appear in `generator/default.php` builder config, `ClassTools`, and
`test/tools/helpers/generator-legacy-class-map.php`.

## 3. Risk: property and constant collisions

**This is where genuine surprises are expected.** A trait property that also
exists on the parent class is a fatal unless declared identically, and PHP 8.2+
applies the same rule to trait constants. Today the base *inherits* from the
parent and may silently redeclare; a trait sits alongside it and may not.

Exposed by concrete inheritance and STI, where a parent model class and a
generated trait meet on one object. Audit:

- `ObjectBuilder::addProperties()` against `runtime/Lib/OM/BaseObject.php`.
- `PeerBuilder`'s column constants against a parent peer's, for concrete
  inheritance chains (`BaseConcreteArticlePeer extends ConcreteContentPeer`).
- Every behavior that emits `objectAttributes()` / `queryAttributes()`.

Do this audit *first* -- it can invalidate the naming decision (a per-behavior
trait with `insteadof` resolution, rather than one trait per class).

### Audit result: clear, naming decision stands

Scanned all 744 classes across the three fixture builds plus `runtime/Lib`,
comparing each `Base<X>`'s declared properties and constants against its
resolved parent chain. Every collision found is a **byte-identical
redeclaration** -- same visibility, same type:

| Name | Against | Count |
| --- | --- | --- |
| `modifiedColumns` | `BaseObject` | 76 |
| `alreadyInSave`, `alreadyInValidation`, `new`, `deleted` | concrete-inheritance parent | 5 each |
| `Id`, `Title`, `CategoryId`, `Body`, `AuthorId`, `DescendantClass` | concrete-inheritance parent | 1-5 each |
| `aConcreteAuthor`, `aConcreteContent`, `aConcreteCategory`, `singleConcreteNews` | concrete-inheritance parent | 1-4 each |
| `ISBN`, `Price` | `BaseBookstoreSchemasBook` | 1 each |
| `PEER` (constant) | concrete-inheritance parent | 5 |

Executed against PHP 8.5.8 to establish what actually fatals, rather than
reasoning from the manual:

- Property from a trait vs. the same property on a **parent class**: legal when
  visibility and type match. **A differing default is fine** -- the plan's worry
  about defaults was wrong; only type and visibility mismatches fatal
  (`Type of C::$x must be ?string (as in class P)` /
  `Access level to C::$x must be public`).
- A `private` property on the parent does not collide at all -- separate slot.
- Constant from a trait vs. the same constant on a **parent class**: legal
  *even when the value differs* -- the trait's value wins, which is exactly the
  `const PEER` override that concrete inheritance needs.
- Constant from a trait vs. one declared on **the using class itself**: fatal if
  the value differs. This is the only live hazard, and it is a downstream one --
  a user stub that declares `const PEER` will break. Nothing in-tree does.
- `parent::__construct()` from inside a trait reaches the using class's parent.
- A trait method satisfies an inherited `abstract` declaration.

Nothing here blocks the plan and nothing forces per-behavior traits. Proceeding
with one trait per class, named `<X>Generated`.

## 4. Migration for existing projects

Every stub in every downstream project changes shape:

```php
-class Book extends BaseBook {}
+class Book extends BaseObject implements Persistent, Poolable { use BookGenerated; }
```

Ship a Rector rule, following `generator/Lib/Rector/UseQueryToWithQueryRector.php`
(already in-tree and already shipped downstream for the `useQuery()` migration --
this is an established pattern here, not a new one).

The rule must also handle **`parent::` calls to generated methods**, which is the
one ergonomic regression:

```php
-    public function save(?PropulsionPDO $con = null): int { return parent::save($con); }
+    use BookGenerated { save as private generatedSave; }
+    public function save(?PropulsionPDO $con = null): int { return $this->generatedSave($con); }
```

`parent::` no longer reaches the generated method -- it reaches `BaseObject`.
Rector can rewrite this because the generator knows which methods it emits.

## 5. Sequencing

Each step ends green. Do not batch them.

1. **Collision audit** (section 3). Output: a list of properties/constants that
   clash, and the naming decision confirmed or changed.
2. **ObjectBuilder + ExtensionObjectBuilder only.** Object classes are where the
   253 findings are. Everything else keeps its base class for now -- mixing is
   fine, they are independent hierarchies.
3. Measure: the 253 should be gone, with no new `assign.propertyType` or
   `return.type`.
4. **PeerBuilder + ExtensionPeerBuilder.** Then check whether the concrete
   inheritance peer chain still forces `doValidateThis()` to be untyped, and
   whether `addInstanceToPool()` can go back to the model type.
5. **QueryBuilder + ExtensionQueryBuilder.**
6. **Node/NodePeer** (`treeMode="MaterializedPath"`, the only surviving
   treeMode). Its sole coverage is
   `test/testsuite/generator/builder/om/NodeBuilderCodegenTest.php`.
7. **Rector rule** + `README.md` migration section.
8. Delete the superseded section from `KNOWN_ISSUES.md`.

## 6. Verification

Per step:

```
vendor/bin/phpstan analyse                          # generator+runtime+bin, must stay clean
vendor/bin/phpunit -c test/phpunit.xml              # full tier, expect 99 skips
cd test && PROPULSION_SKIP_INTEGRATION=1 ../vendor/bin/phpunit -c phpunit.xml
php -dpcov.enabled=0 bench/correctness_check.php    # 35 assertions, hydration/pooling/save
```

Generated-code level 9 count (the number this plan is measured against, 550 at
the time of writing) needs a config that analyses the fixture output, which
`phpstan.neon` deliberately does not:

```neon
includes:
    - /path/to/propulsion/phpstan.neon
parameters:
    paths:
        - test/fixtures/bookstore/build/classes
        - test/fixtures/schemas/build/classes
        - test/fixtures/namespaced/build/classes
    tmpDir: /tmp/phpstan-propulsion-gen
```

Two traps that cost time in the session that produced this plan:

- **Stale fixtures.** `test/fixtures/*/build/classes` is regenerated by the test
  run but *not* pruned, so classes the generator no longer emits linger and get
  analysed. `rm -rf test/fixtures/*/build/classes` before measuring anything
  after a change that removes or renames generated files.
- **Leaked testcontainer.** A container stuck in `Created` makes the suite skip
  ~1400 integration tests and report green in ~10s instead of ~60s. Check the
  skip count (99 is correct, 1475 is not) and run
  `composer test:cleanup-containers`.

Benchmark only if a hot path changes; traits are flattened at compile time, so
no runtime cost is expected. `bench/hydration_bench.php 5000 25` with
`-dpcov.enabled=0 -dopcache.enable_cli=1 -dopcache.jit=tracing`.

## 7. Open questions

Neither is settled; both want an experiment before step 4.

- **Peer inheritance chain.** `BaseConcreteArticlePeer extends ConcreteContentPeer`
  is what forces `doValidateThis()` to be untyped for LSP reasons. Whether the
  peer chain can be flattened into traits, or must stay because peers genuinely
  inherit behaviour, decides whether that compromise can be reverted.
- **Memory.** Trait methods are copied into each using class rather than shared
  through inheritance. Expected to be a wash here, since base classes are already
  one per table -- but measure rather than assume.

## Critical files

```
generator/Lib/Builder/OM/ObjectBuilder.php           emits the object base
generator/Lib/Builder/OM/PeerBuilder.php             emits the peer base
generator/Lib/Builder/OM/QueryBuilder.php            emits the query base
generator/Lib/Builder/OM/NodeBuilder.php             MaterializedPath object base
generator/Lib/Builder/OM/NodePeerBuilder.php         MaterializedPath peer base
generator/Lib/Builder/OM/Extension*Builder.php       the five stubs (extends -> use)
generator/Lib/Builder/OM/OMBuilder.php               getClassname()/prefixClassname()
generator/Lib/Builder/OM/ClassTools.php              classname helpers
generator/default.php                                basePrefix (116), builder classes (244+)
generator/Lib/Rector/UseQueryToWithQueryRector.php   the pattern for the migration rule
runtime/Lib/OM/BaseObject.php                        stays a hand-written parent class
runtime/Lib/OM/Poolable.php                          may become unnecessary (step 4)
test/tools/helpers/generator-legacy-class-map.php    bare-name aliases
test/tools/helpers/IntegrationDatabase.php           fixture classmap autoloader
KNOWN_ISSUES.md                                      section this plan supersedes
```
