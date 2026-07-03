# Propulsion

Propulsion is an object-relational mapper (ORM) for PHP, forked from Propel 1
and modernized to target PHP 8.5+.

## Logging

Propulsion logs through [PSR-3](https://www.php-fig.org/psr/psr-3/)
(`Psr\Log\LoggerInterface`). It does not bundle a concrete logger
implementation — bring your own (e.g. [Monolog](https://github.com/Seldaek/monolog),
or any other PSR-3 implementation) and register it once, typically right
after `Propel::init()`:

```php
use Propulsion\Propel;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

Propel::init('/path/to/runtime-conf.php');

$logger = new Logger('propulsion');
$logger->pushHandler(new StreamHandler('/path/to/propulsion.log'));
Propel::setLogger($logger);
```

If no logger is registered, `Propel::log()` is a no-op and nothing is
written anywhere — there is no implicit fallback to `error_log()` or a file
on disk.

`Propel::LOG_EMERG` .. `Propel::LOG_DEBUG` are aliases for the corresponding
`Psr\Log\LogLevel::*` string constants, so existing call sites like
`Propel::log($message, Propel::LOG_ERR)` keep working unchanged.

A `PropelPDO` connection can also be given its own logger, overriding the
globally-registered one for just that connection:

```php
$con->setLogger($logger);
```
