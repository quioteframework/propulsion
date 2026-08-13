<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBNone;
use Propulsion\Connection\GenericPropulsionPDO;

/**
 * DBNone is the adapter registered for the empty driver key -- "you do not have
 * a database installed". It is reachable from ordinary configuration
 * (`DBAdapter::factory('')`), so its behavior is part of the contract even
 * though no integration job runs against it: every SQL-emitting hook degrades
 * to something inert rather than throwing, which is what lets code that only
 * builds queries run with no database behind it.
 */
class DBNoneTest extends TestCase
{
    private DBNone $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DBNone();
    }

    public function testDefaultPdoClassIsTheGenericConnection()
    {
        $this->assertSame(GenericPropulsionPDO::class, $this->adapter->getDefaultPdoClass());
    }

    /**
     * Unlike DBAdapter::initConnection(), this one applies nothing at all -- not
     * even a configured charset or query list, since there is no server to
     * apply them to.
     */
    public function testInitConnectionAppliesNothing()
    {
        $con = new PDO('sqlite::memory:');
        $this->adapter->initConnection($con, array('charset' => array('value' => 'utf8'), 'queries' => array('SET a = 1')));
        $this->expectNotToPerformAssertions();
    }

    /**
     * The case-folding hooks are identity functions here: with no server to
     * normalize for, changing the caller's string would only misrepresent it.
     */
    public function testCaseFoldingHooksReturnTheInputUnchanged()
    {
        $this->assertSame('Book.Title', $this->adapter->toUpperCase('Book.Title'));
        $this->assertSame('Book.Title', $this->adapter->ignoreCase('Book.Title'));
    }

    /**
     * concatString()/subString() are the two hooks that do not merely return a
     * SQL fragment naming the operation -- they perform it in PHP, so a caller
     * gets a real answer instead of unrunnable SQL.
     */
    public function testConcatStringConcatenatesInPhp()
    {
        $this->assertSame('foobar', $this->adapter->concatString('foo', 'bar'));
    }

    public function testSubStringExtractsInPhp()
    {
        $this->assertSame('ell', $this->adapter->subString('hello', 1, 3));
    }

    public function testStrLengthEmitsTheSqlStandardFunction()
    {
        $this->assertSame('LENGTH(title)', $this->adapter->strLength('title'));
    }

    public function testApplyLimitLeavesTheSqlUntouched()
    {
        $sql = 'SELECT title FROM book';
        $this->adapter->applyLimit($sql, 10, 20);
        $this->assertSame('SELECT title FROM book', $sql);
    }

    public function testRandomHasNoSqlToOffer()
    {
        $this->assertNull($this->adapter->random());
        $this->assertNull($this->adapter->random('seed'));
    }
}
