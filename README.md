# Propulsion

[![Tests](https://github.com/quioteframework/propulsion/actions/workflows/tests.yml/badge.svg)](https://github.com/quioteframework/propulsion/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/quioteframework/propulsion/graph/badge.svg)](https://codecov.io/gh/quioteframework/propulsion)
[![Latest release](https://img.shields.io/github/v/release/quioteframework/propulsion)](https://github.com/quioteframework/propulsion/releases)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.5-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/github/license/quioteframework/propulsion)](LICENSE)

Propulsion is an object-relational mapper (ORM) for PHP, forked from
[Propel 1](https://github.com/propelorm/Propel1) and modernized to target
PHP 8.5+.

Propel 1 development had wound down and the project was effectively
unmaintained; Propulsion picks up that codebase, renames it, and carries it
forward — modern PHP syntax and types throughout, Phing replaced by a plain
console app, PostgreSQL promoted to the default/recommended database, and
ongoing bug fixes. See `NOTICE.md` for attribution details and
`KNOWN_ISSUES.md` for a running log of what's changed and what's still in
progress.

## Database support

**PostgreSQL is the recommended and default database for new projects**
(PostgreSQL 16+; see `KNOWN_ISSUES.md` for the version-support note). It's
what this codebase's own test suite, CI, and code generator default to —
`generator/default.php`'s `propulsion.database` is `pgsql` out of the box, and
`PgsqlPlatform` gets the most feature-parity attention of the bundled
platforms. MySQL, SQLite, Oracle, and MSSQL/SQL Server are also supported and
exercised by the test suite, and remain a simple per-project override — set
`propulsion.database` in your own `build.php` (a plain PHP file returning an
array; `--config`, repeatable, on the console commands — a legacy
`build.properties` text file is also still accepted), or pass `--database`
directly, if you need a different target.

## Logging

Propulsion logs through [PSR-3](https://www.php-fig.org/psr/psr-3/)
(`Psr\Log\LoggerInterface`). It does not bundle a concrete logger
implementation — bring your own (e.g. [Monolog](https://github.com/Seldaek/monolog),
or any other PSR-3 implementation) and register it once, typically right
after `Propulsion::init()`:

```php
use Propulsion\Propulsion;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

Propulsion::init('/path/to/runtime-conf.php');

$logger = new Logger('propulsion');
$logger->pushHandler(new StreamHandler('/path/to/propulsion.log'));
Propulsion::setLogger($logger);
```

If no logger is registered, `Propulsion::log()` is a no-op and nothing is
written anywhere — there is no implicit fallback to `error_log()` or a file
on disk.

`Propulsion::LOG_EMERG` .. `Propulsion::LOG_DEBUG` are aliases for the corresponding
`Psr\Log\LogLevel::*` string constants, so existing call sites like
`Propulsion::log($message, Propulsion::LOG_ERR)` keep working unchanged.

A `PropulsionPDO` connection can also be given its own logger, overriding the
globally-registered one for just that connection:

```php
$con->setLogger($logger);
```

## Caching

Propulsion caches query results in two tiers: a request-scoped one, and an
optional global tier shared across requests, processes and hosts — which is what
makes caching pay off in a worker-mode deployment, where the process outlives
the request.

Both are off by default and opt-in per query:

```php
// config: 'cache' => ['query' => ['enabled' => true, 'driver' => 'apcu']]
$books = BookQuery::create()->filterByPublished(true)->setQueryCache(true)->find();
```

The global tier is backed by any [PSR-16](https://www.php-fig.org/psr/psr-16/)
pool — Propulsion ships thin `array`, `apcu` and `file` drivers and no Redis or
Memcached client of its own, so bring one via
`Propulsion::setQueryCachePool()`. Invalidation is automatic for writes made
through the ORM. Hand-written SQL can join in via `Propulsion::rawQuery()`.

See **[docs/CACHING.md](docs/CACHING.md)** for driver trade-offs (they differ far
more in what they share than in how fast they are), invalidation, overload
protection, and the correctness caveats worth reading before switching it on.

## Upgrading to 3.0: generated code is a trait, not a base class

Propulsion 3.0 emits generated object, query and node code as a **trait** that
your model class uses, rather than a base class it extends:

```php
// 2.x -- om/BaseBook.php held the generated code
class Book extends BaseBook {}

// 3.0 -- om/BookGenerated.php holds it
class Book extends BaseObject implements Persistent, Poolable, WritableModelInterface
{
    use BookGenerated;
}
```

**Why.** Every relation call the generator emits passes `$this` — for instance
`$review->setBook($this)`, where `setBook()` takes a `Book`. Written into
`BaseBook`, `$this` is a `BaseBook`, so the call only held at runtime because
the stub happened to be the base's only subclass, which nothing stated or
enforced. A hand-written `class Rogue extends BaseBook` got a `TypeError`.
PHPStan analyses a trait body once per using class, so inside `BookGenerated`,
`$this` *is* `Book`, and the premise stops being true rather than being
suppressed. It removed 275 of 550 level 9 findings in generated code.

Peers still extend a generated base — `BookPeer extends BaseBookPeer` is
unchanged, because peer methods are all static and never had a `$this` to
mistype.

### Automated migration

Your stubs are generated once and then owned by you, so regenerating will not
update them. A second Rector rule,
`Propulsion\Generator\Rector\StubBaseClassToGeneratedTraitRector`, does:

```php
<?php
// rector.php

use Propulsion\Generator\Rector\StubBaseClassToGeneratedTraitRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])   // wherever your model stubs live
    ->withRules([StubBaseClassToGeneratedTraitRector::class]);
```

Upgrade the library and regenerate **first**, so the traits exist, then:

```bash
vendor/bin/rector process --dry-run
vendor/bin/rector process
```

### `parent::` is the one thing that changes shape

`parent::` used to reach the generated base. Now it reaches `BaseObject`, so a
call to a *generated* method no longer resolves and needs the trait aliased:

```php
-class Book extends BaseBook
-{
-    public function save(?PropulsionPDO $con = null): int { return parent::save($con); }
+class Book extends BaseObject implements Persistent, Poolable, WritableModelInterface
+{
+    use BookGenerated { save as private generatedSave; }
+
+    public function save(?PropulsionPDO $con = null): int { return $this->generatedSave($con); }
 }
```

The rule does this for you, and only where it is actually needed. Calls to
methods that are really declared on `BaseObject` — `preSave()`, `postSave()`,
`preInsert()`, and the other lifecycle hooks — keep working through `parent::`
and are left untouched. That distinction matters: PHP rejects an alias for a
method the trait does not define ("An alias (x) was defined for method foo(),
but this method does not exist"), so aliasing a hook would be a hard fatal.

**What it leaves alone, by design:** peer stubs; any class that is not a stub
sitting directly on its own generated base (`class X extends BaseX`); stubs
whose new parent cannot be resolved, since classifying `parent::` calls without
the parent's method list would be guesswork and a wrong guess fails at compile
time; and `parent::` inside a closure or nested anonymous class, which binds to
a different scope.

### Also removed in 3.0

- **The `concrete_inheritance` behavior.** It copied every parent column into
  the child table and chained the generated classes through your stubs. Model
  the relationship with a foreign key to the parent table, or use single-table
  inheritance (`<column ... inheritance="single">`). A schema still declaring it
  is refused at build time with that guidance rather than failing obscurely.
- **`treeMode="NestedSet"`**, superseded by the `nested_set` behavior. The
  behavior itself is unaffected and still supported.

## Migrating `useQuery()`/`endUse()` to `withQuery()` with Rector

`useQuery()`/`endUse()` (and the generated `use<Relation>Query()` wrappers) are
still fully supported, but are `@deprecated` in favor of a closure-scoped
replacement: `withQuery()` on `ModelCriteria`, and a generated
`with<Relation>Query()` sibling next to every `use<Relation>Query()`. The
reason: `endUse()` can't statically know which concrete query class originally
called `useQuery()` (that information is only tracked at runtime), so it's
typed to return the generic `ModelCriteria` base class — which collapses the
type of every chained call after it, breaking IDE autocomplete and PHPStan
inference for the rest of the chain. The closure form doesn't have this
problem: there's no `endUse()` to mistype, since "switching back" is just the
callback returning.

```php
// before
$books = BookQuery::create()
    ->useAuthorQuery()
        ->filterByFirstName('Jane')
    ->endUse()
    ->find();

// after
$books = BookQuery::create()
    ->withAuthorQuery(fn ($q) => $q->filterByFirstName('Jane'))
    ->find();
```

This also works for relations nested inside other relations, to any depth —
including several sibling relations queried inside the same outer relation:

```php
$q->withAuthorQuery(fn ($author) => $author
    ->withBookQuery(fn ($book) => $book->filterByTitle('War And Peace'))
    ->withPublisherQuery(fn ($publisher) => $publisher->filterByName('Penguin')));
```

### Automated migration

Propulsion ships a [Rector](https://github.com/rectorphp/rector) rule,
`Propulsion\Generator\Rector\UseQueryToWithQueryRector`, that mechanically
rewrites `useQuery()->...->endUse()` chains (including the generated
`use<Relation>Query()` form, and nested/sibling chains at any depth) into the
`withQuery()`/`with<Relation>Query()` form shown above. It ships as part of
this package's own source, so it's available as soon as you
`composer require quioteframework/propulsion` — you just need Rector itself
installed to run it:

```bash
composer require --dev rector/rector
```

Then point your own `rector.php` at the rule:

```php
<?php
// rector.php

use Propulsion\Generator\Rector\UseQueryToWithQueryRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        // ...any other directories containing your query-building code
    ])
    ->withRules([UseQueryToWithQueryRector::class]);
```

Regenerate your models first (`propulsion model:build` or your project's
equivalent), so the `with<Relation>Query()` wrapper methods the rewritten code
calls actually exist — the rule doesn't check this for you, it's a purely
syntactic rewrite. Then, as with any Rector rule, review before applying:

```bash
vendor/bin/rector process --dry-run
vendor/bin/rector process
```

**What it rewrites:** any fluent (single-expression) chain built directly off
a `useQuery()`/`use<Relation>Query()` call and closed by a matching `endUse()`,
including chains with other relations nested or sequenced inside them, and
plain method calls (`where()`, `_or()`, `filterBy*()`, `add()`, ...) mixed in
between — those pass through into the closure body untouched.

**What it leaves alone, by design:** chains split across variables instead of
one fluent expression (e.g. `$sub = $q->useQuery('x'); ...; $sub->endUse();`)
— rewriting those safely would need flow analysis the rule doesn't attempt,
so it's conservative and skips them rather than risk an incorrect rewrite.
Anything left unconverted keeps working exactly as before, since
`useQuery()`/`endUse()` are deprecated, not removed.
