<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\Oracle\OraclePropulsionPDO;
use Propulsion\Connection\GenericPropulsionPDO;
use Propulsion\Connection\PropulsionPDO;

/**
 * OraclePropulsionPDO's one behavioural override is its liveness-probe SQL, and
 * it read as 0% covered even on the Oracle job -- the class is instantiated
 * there, but a `class OraclePropulsionPDO extends ...` line is not an executable
 * statement, so the only tracked line is getPingSql()'s body, which
 * PropulsionPDOTrait::ping() only reaches on a connection it actually has to
 * probe.
 *
 * getPingSql() is protected (ping() is its only caller), so this reads it through
 * a subclass rather than reaching into the class with reflection -- the same
 * pattern the adapter tests use for protected hooks.
 */
class OraclePropulsionPDOTest extends TestCase
{
    /**
     * Oracle requires a FROM clause on every SELECT, so the bare `SELECT 1` the
     * trait pings every other platform with is a syntax error there -- hence
     * `FROM dual`. Getting this wrong turns every liveness check into a failed
     * query, which is exactly the path that decides whether a pooled connection
     * is handed out or discarded.
     */
    public function testPingSqlCarriesTheFromDualOracleRequires()
    {
        $this->assertSame('SELECT 1 FROM dual', PingSqlProbe::of(new OraclePropulsionPDO('sqlite::memory:')));
    }

    /**
     * Contrast with the trait's default, which every other platform keeps.
     */
    public function testTheGenericConnectionStillPingsWithABareSelect()
    {
        $this->assertSame('SELECT 1', PingSqlProbe::of(new GenericPropulsionPDO('sqlite::memory:')));
    }

    public function testItIsAPropulsionPdo()
    {
        $this->assertInstanceOf(PropulsionPDO::class, new OraclePropulsionPDO('sqlite::memory:'));
    }
}

/**
 * Exposes the protected getPingSql() of whichever PropulsionPDO it is handed, by
 * binding a closure to that object's scope -- so the class under test is the real
 * one, not a subclass that might diverge from it.
 */
final class PingSqlProbe
{
    public static function of(PropulsionPDO $con): string
    {
        // Scope (not $this) is what grants access to a protected method, so a
        // static closure rebound to the object's class can call it on $con.
        $read = \Closure::bind(
            static fn (PropulsionPDO $c): string => $c->getPingSql(),
            null,
            $con
        );
        if ($read === null) {
            throw new \RuntimeException('Could not rebind a reader for ' . $con::class . '::getPingSql()');
        }

        return $read($con);
    }
}
