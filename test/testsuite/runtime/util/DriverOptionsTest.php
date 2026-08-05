<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

/**
 * How a runtime configuration's `options`/`attributes` entries and its
 * `classname` are resolved -- the two places where Propulsion's rename of
 * `PropelPDO` (a class) to `Propulsion\Connection\PropulsionPDO` (an interface)
 * broke configurations that Propel's own `convert-conf` had generated.
 *
 * Both are on the connect path, so getting either wrong means the datasource
 * cannot be opened at all rather than merely behaving oddly.
 */
class DriverOptionsTest extends TestCase
{
    private const DATASOURCE = 'driver_options_test';

    protected function setUp(): void
    {
        parent::setUp();
        // initConnection() resolves the adapter for the datasource before it
        // ever looks at `classname`, so the test datasource needs one.
        Propulsion::setDB(self::DATASOURCE, new \Propulsion\Adapter\DBSQLite());
    }

    /**
     * @param  array<string, mixed> $source
     * @return array<int|string, mixed>
     */
    private function process(array $source): array
    {
        $method = new ReflectionMethod(Propulsion::class, 'processDriverOptions');
        $written = [];
        $method->invokeArgs(null, [$source, &$written]);

        return $written;
    }

    public function testBareNameResolvesAgainstPdo(): void
    {
        // The regression: PropelPDO was a class extending PDO, so a bare
        // ATTR_PERSISTENT resolved through inheritance. PropulsionPDO is an
        // interface and inherits nothing, so every PDO constant name became
        // undefined and every configuration carrying one -- which is what
        // convert-conf emitted, `<option id="ATTR_PERSISTENT">` -- failed to
        // connect with "Invalid PDO option/attribute name specified".
        $this->assertSame(
            [PDO::ATTR_PERSISTENT => true],
            $this->process(['ATTR_PERSISTENT' => ['value' => true]])
        );
    }

    public function testBareNameStillResolvesPropulsionsOwnAttribute(): void
    {
        // The interface is tried first, so the one attribute that lives only
        // there keeps working. The two sets do not overlap, so the ordering is
        // unambiguous either way.
        $this->assertSame(
            [PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES => true],
            $this->process(['PROPEL_ATTR_CACHE_PREPARES' => ['value' => true]])
        );
    }

    public function testAnExplicitlyQualifiedNameIsUnchanged(): void
    {
        $this->assertSame(
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            $this->process(['PDO::ATTR_ERRMODE' => ['value' => 'PDO::ERRMODE_EXCEPTION']])
        );
    }

    public function testAQualifiedDriverSpecificNameResolves(): void
    {
        if (!class_exists('Pdo\\Mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded');
        }

        // The `::` branch takes any class, which is what makes the modern
        // driver-specific spellings usable. That matters on PHP 8.5, where the
        // bolted-on `PDO::MYSQL_ATTR_*` aliases are deprecated in favour of
        // `Pdo\Mysql::ATTR_*`: a bare `MYSQL_ATTR_LOCAL_INFILE` still resolves
        // through the PDO fallback (that is the whole point of the fallback --
        // legacy configs spell it that way), but it resolves to a deprecated
        // constant and PHP says so. Qualifying it is the way out, and it has to
        // keep working.
        $this->assertSame(
            [\Pdo\Mysql::ATTR_LOCAL_INFILE => true],
            $this->process(['Pdo\\Mysql::ATTR_LOCAL_INFILE' => ['value' => true]])
        );
    }

    public function testAnUnknownBareNameNamesBothPrefixesItTried(): void
    {
        // "not a constant on X" with only one X named sent people looking in the
        // wrong place, which is how the original bug survived: the message
        // pointed at an interface that could never have carried the name.
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/ATTR_NOT_A_REAL_THING.*PropulsionPDO.*PDO/s');
        $this->process(['ATTR_NOT_A_REAL_THING' => ['value' => 1]]);
    }

    public function testAnUnknownQualifiedNameStillThrows(): void
    {
        $this->expectException(PropulsionException::class);
        $this->process(['PDO::ATTR_NOT_A_REAL_THING' => ['value' => 1]]);
    }

    public function testTheInterfaceNameIsAcceptedAsAClassnameAndSubstituted(): void
    {
        // `<classname>PropelPDO</classname>` is what convert-conf emitted. It
        // means "use Propulsion's PDO", and the adapter's own default class is
        // exactly that -- it only fails class_exists() because the name became
        // an interface. Rejecting it would reject essentially every migrated
        // configuration over a rename that was never the operator's business.
        foreach (['PropelPDO', 'PropulsionPDO', PropulsionPDO::class] as $configured) {
            $this->assertTrue(
                interface_exists($configured),
                'sanity: ' . $configured . ' really is an interface, not a class'
            );

            $con = Propulsion::initConnection(
                ['dsn' => 'sqlite::memory:', 'classname' => $configured],
                self::DATASOURCE,
                \Propulsion\Adapter\Sqlite\SqlitePropulsionPDO::class
            );

            $this->assertInstanceOf(
                \Propulsion\Adapter\Sqlite\SqlitePropulsionPDO::class,
                $con,
                $configured . ' must resolve to a concrete driver-specific connection'
            );
        }
    }

    public function testAnUnusableClassnameStillThrowsAndSaysWhy(): void
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessageMatches('/is not a class.*omit `classname`/s');
        Propulsion::initConnection(
            ['dsn' => 'sqlite::memory:', 'classname' => 'NoSuchPdoClassAnywhere'],
            self::DATASOURCE
        );
    }
}
