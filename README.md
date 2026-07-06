# Propulsion

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
(PostgreSQL 15+; see `KNOWN_ISSUES.md` for the version-support note). It's
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
