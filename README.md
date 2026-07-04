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
`generator/default.php`'s `propel.database` is `pgsql` out of the box, and
`PgsqlPlatform` gets the most feature-parity attention of the bundled
platforms. MySQL, SQLite, Oracle, and MSSQL/SQL Server are also supported and
exercised by the test suite, and remain a simple per-project override — set
`propel.database` in your own `build.php` (a plain PHP file returning an
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
