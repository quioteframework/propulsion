<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBNone;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Connection\GenericPropulsionPDO;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Map\TableMap;
use Propulsion\Query\Criteria;

/**
 * DBAdapter's own defaults, rather than any one platform's overrides of them.
 *
 * Every optional capability in the adapter contract is expressed as a
 * `supportsX()` predicate paired with the hook(s) that implement it, and the
 * base class deliberately answers "no" to each while making the matching hook
 * throw -- so that a platform which forgets to override one gets a clear
 * exception naming itself, instead of silently emitting SQL its server cannot
 * run. Those defaults are only reachable through a subclass that overrides
 * nothing, which no real adapter is, hence the stub below: exercised via
 * DBPostgres or DBMySQL these paths would all be the override, not the default.
 *
 * @see DBAdapterTest for the parts of DBAdapter that need the bookstore fixtures.
 */
class DBAdapterDefaultsTest extends TestCase
{
    private BareDBAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new BareDBAdapter();
    }

    public function testFactoryBuildsTheAdapterRegisteredForADriver()
    {
        $this->assertInstanceOf(DBPostgres::class, DBAdapter::factory('pgsql'));
        $this->assertInstanceOf(DBMySQL::class, DBAdapter::factory('mysql'));
        // The empty driver key is the documented "no database installed" case.
        $this->assertInstanceOf(DBNone::class, DBAdapter::factory(''));
    }

    public function testFactoryThrowsForAnUnregisteredDriver()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unsupported Propulsion driver: nosuchdriver: Check your configuration file');
        DBAdapter::factory('nosuchdriver');
    }

    public function testInitConnectionAppliesACharsetSetting()
    {
        $con = new RecordingExecPDO();
        $this->adapter->initConnection($con, array('charset' => array('value' => 'utf8')));
        $this->assertSame(array("SET NAMES 'utf8'"), $con->executed);
    }

    /**
     * The charset setting is only honored in the nested `['charset']['value']`
     * shape the runtime configuration produces; anything else is ignored rather
     * than passed to setCharset() as a non-string.
     */
    public function testInitConnectionIgnoresAMalformedCharsetSetting()
    {
        $con = new RecordingExecPDO();
        $this->adapter->initConnection($con, array('charset' => 'utf8'));
        $this->adapter->initConnection($con, array('charset' => array('value' => array('utf8'))));
        $this->assertSame(array(), $con->executed);
    }

    public function testInitConnectionRunsConfiguredQueries()
    {
        $con = new RecordingExecPDO();
        $this->adapter->initConnection($con, array('queries' => array('SET a = 1', 'SET b = 2')));
        $this->assertSame(array('SET a = 1', 'SET b = 2'), $con->executed);
    }

    /**
     * A non-string entry is skipped instead of being handed to PDO::exec(),
     * which would be a TypeError on a value that came from user configuration.
     *
     * The inner `foreach ((array) $queries ...)` also flattens one level, so a
     * *nested* list of query strings is executed rather than skipped -- which is
     * what lets the runtime configuration express a datasource's queries as
     * either a flat list or a list of lists.
     */
    public function testInitConnectionSkipsNonStringQueriesButFlattensNestedLists()
    {
        $con = new RecordingExecPDO();
        $this->adapter->initConnection($con, array('queries' => array('SET a = 1', array('SET b = 2'), 42)));
        $this->assertSame(array('SET a = 1', 'SET b = 2'), $con->executed, 'the nested list is flattened; the non-string 42 is skipped');
    }

    public function testSetCharsetUsesTheSqlStandardStatement()
    {
        $con = new RecordingExecPDO();
        $this->adapter->setCharset($con, 'latin1');
        $this->assertSame(array("SET NAMES 'latin1'"), $con->executed);
    }

    public function testStringDelimiterIsTheSingleQuote()
    {
        $this->assertSame("'", $this->adapter->getStringDelimiter());
    }

    public function testQuoteIdentifierUsesSqlStandardDoubleQuotes()
    {
        $this->assertSame('"book"', $this->adapter->quoteIdentifier('book'));
        $this->assertSame('"book" "b"', $this->adapter->quoteIdentifierTable('book b'));
    }

    public function testGetIdReadsTheDriversLastInsertId()
    {
        $con = new GenericPropulsionPDO('sqlite::memory:');
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $con->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $con->exec("INSERT INTO t (v) VALUES ('x')");
        $this->assertEquals(1, $this->adapter->getId($con));
    }

    public function testInsertNullPkIsSupportedByDefault()
    {
        $this->assertTrue($this->adapter->supportsInsertNullPk());
    }

    public function testNoOptionalCapabilityIsClaimedByDefault()
    {
        $this->assertFalse($this->adapter->supportsInsertReturning());
        $this->assertFalse($this->adapter->supportsUpsert());
        $this->assertFalse($this->adapter->supportsRowReturning());
        $this->assertFalse($this->adapter->supportsBulkLoad());
        $this->assertFalse($this->adapter->supportsExplain());
    }

    /**
     * Locking is the one family the base class opts *into*: the plain
     * `SELECT ... FOR UPDATE/SHARE [NOWAIT|SKIP LOCKED]` suffixes applyLock()
     * emits are correct for Postgres, MySQL/MariaDB and Oracle, so only a
     * platform that expresses locking differently (MSSQL's table hints) has to
     * override them.
     *
     * The stub leaves all four unset here, so these really are DBAdapter's
     * answers -- see BareDBAdapter's own note.
     */
    public function testLockingCapabilitiesAreClaimedByDefault()
    {
        $this->assertTrue($this->adapter->supportsForUpdate());
        $this->assertTrue($this->adapter->supportsForShare());
        $this->assertTrue($this->adapter->supportsNoWait());
        $this->assertTrue($this->adapter->supportsSkipLocked());
    }

    /**
     * @return array<string, array{callable(BareDBAdapter): mixed, string}>
     */
    public static function unsupportedCapabilityHookProvider(): array
    {
        return array(
            'getInsertReturningSql' => array(
                fn(BareDBAdapter $a) => $a->getInsertReturningSql('INSERT INTO t (a) VALUES (1)', 'id'),
                'BareDBAdapter does not support folding id retrieval into INSERT',
            ),
            'extractInsertedId' => array(
                fn(BareDBAdapter $a) => $a->extractInsertedId((new PDO('sqlite::memory:'))->prepare('SELECT 1')),
                'BareDBAdapter does not support folding id retrieval into INSERT',
            ),
            'getUpsertSql' => array(
                fn(BareDBAdapter $a) => $a->getUpsertSql('INSERT INTO t (a) VALUES (1)', array('a'), 'a = 1'),
                'BareDBAdapter does not support upserts',
            ),
            'getMergeUpsertSql' => array(
                fn(BareDBAdapter $a) => $a->getMergeUpsertSql('t', array('a'), array('a'), 'a = 1'),
                'BareDBAdapter does not support upserts',
            ),
            'getUpdateReturningSql' => array(
                fn(BareDBAdapter $a) => $a->getUpdateReturningSql('UPDATE t SET a = 1', array('id')),
                'BareDBAdapter does not support RETURNING on UPDATE',
            ),
            'getDeleteReturningSql' => array(
                fn(BareDBAdapter $a) => $a->getDeleteReturningSql('DELETE FROM t', array('id')),
                'BareDBAdapter does not support RETURNING on DELETE',
            ),
            'bulkLoad' => array(
                fn(BareDBAdapter $a) => $a->bulkLoad(new GenericPropulsionPDO('sqlite::memory:'), 't', array('a'), array(array(1))),
                'BareDBAdapter does not support bulk loading',
            ),
            'getExplainSql' => array(
                fn(BareDBAdapter $a) => $a->getExplainSql('SELECT 1'),
                'BareDBAdapter does not support ->explain()',
            ),
            'releaseAdvisoryLock' => array(
                fn(BareDBAdapter $a) => $a->releaseAdvisoryLock(new GenericPropulsionPDO('sqlite::memory:'), 'lock'),
                'BareDBAdapter does not support advisory locks',
            ),
        );
    }

    /**
     * @param callable(BareDBAdapter): mixed $invoke
     */
    #[PHPUnit\Framework\Attributes\DataProvider('unsupportedCapabilityHookProvider')]
    public function testHookForAnUnclaimedCapabilityThrowsNamingTheAdapter(callable $invoke, string $expectedMessage)
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage($expectedMessage);
        $invoke($this->adapter);
    }

    public function testGetEmptyInsertSqlUsesDefaultValues()
    {
        $this->assertSame('INSERT INTO "book" DEFAULT VALUES', $this->adapter->getEmptyInsertSql('"book"', null));
    }

    /**
     * getEmptyInsertSql() is the one hook that takes the insert-returning
     * decision as an argument rather than being gated by supportsInsertReturning()
     * at the call site, so it has to refuse the id column itself.
     */
    public function testGetEmptyInsertSqlRefusesAnIdColumnItCannotReturn()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('BareDBAdapter does not support folding id retrieval into an empty INSERT');
        $this->adapter->getEmptyInsertSql('"book"', 'id');
    }

    public function testIdentityInsertToggleSqlIsAbsentByDefault()
    {
        $this->assertNull($this->adapter->getIdentityInsertOnSql('"book"'));
        $this->assertNull($this->adapter->getIdentityInsertOffSql('"book"'));
    }

    public function testApplyLockLeavesAnUnlockedQueryAlone()
    {
        $sql = 'SELECT a FROM t';
        $this->adapter->applyLock($sql, new Criteria());
        $this->assertSame('SELECT a FROM t', $sql);
    }

    public function testApplyLockAppendsTheLockModeSuffix()
    {
        $sql = 'SELECT a FROM t';
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate());
        $this->assertSame('SELECT a FROM t FOR UPDATE', $sql);

        $sql = 'SELECT a FROM t';
        $this->adapter->applyLock($sql, (new Criteria())->setLockForShare());
        $this->assertSame('SELECT a FROM t FOR SHARE', $sql);
    }

    public function testApplyLockAppendsNoWait()
    {
        $sql = 'SELECT a FROM t';
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate(false, true));
        $this->assertSame('SELECT a FROM t FOR UPDATE NOWAIT', $sql);
    }

    public function testApplyLockAppendsSkipLocked()
    {
        $sql = 'SELECT a FROM t';
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate(true));
        $this->assertSame('SELECT a FROM t FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testApplyLockThrowsWhenForUpdateIsUnsupported()
    {
        $this->adapter->supportsForUpdate = false;
        $sql = 'SELECT a FROM t';
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('BareDBAdapter does not support SELECT ... FOR UPDATE');
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate());
    }

    public function testApplyLockThrowsWhenForShareIsUnsupported()
    {
        $this->adapter->supportsForShare = false;
        $sql = 'SELECT a FROM t';
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('BareDBAdapter does not support SELECT ... FOR SHARE');
        $this->adapter->applyLock($sql, (new Criteria())->setLockForShare());
    }

    public function testApplyLockThrowsWhenNoWaitIsUnsupported()
    {
        $this->adapter->supportsNoWait = false;
        $sql = 'SELECT a FROM t';
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('BareDBAdapter does not support NOWAIT locking');
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate(false, true));
    }

    public function testApplyLockThrowsWhenSkipLockedIsUnsupported()
    {
        $this->adapter->supportsSkipLocked = false;
        $sql = 'SELECT a FROM t';
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('BareDBAdapter does not support SKIP LOCKED');
        $this->adapter->applyLock($sql, (new Criteria())->setLockForUpdate(true));
    }

    public function testAdvisoryLockKeyIsStableNonNegativeAndNameDependent()
    {
        $key = $this->adapter->callAdvisoryLockKey('propulsion.migrate');
        $this->assertSame($key, $this->adapter->callAdvisoryLockKey('propulsion.migrate'), 'the same name always derives the same key');
        $this->assertGreaterThanOrEqual(0, $key, 'masked to 63 bits so it cannot depend on how a platform reads a bigint sign bit');
        $this->assertNotSame($key, $this->adapter->callAdvisoryLockKey('propulsion.other'));
    }

    public function testFetchAdvisoryLockResultThrowsWhenPrepareReturnsFalse()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('PropulsionPDO::prepare() returned false for [SELECT pg_advisory_lock(?)]');
        $this->adapter->callFetchAdvisoryLockResult(new FailingPreparePropulsionPDO(), 'SELECT pg_advisory_lock(?)', array(1));
    }

    public function testGetDeleteFromClauseNamesTheTable()
    {
        $this->assertSame('DELETE FROM book', $this->adapter->getDeleteFromClause(new Criteria(), 'book'));
    }

    public function testGetDeleteFromClauseIncludesAQueryComment()
    {
        $criteria = new Criteria();
        $criteria->setComment('audit sweep');
        $this->assertSame('DELETE /* audit sweep */ FROM book', $this->adapter->getDeleteFromClause($criteria, 'book'));
    }

    public function testGetDeleteFromClauseQuotesTheTableWhenTheAdapterQuotes()
    {
        $this->adapter->useQuoteIdentifier = true;
        $this->assertSame('DELETE FROM "book"', $this->adapter->getDeleteFromClause(new Criteria(), 'book'));
    }

    /**
     * An aliased table has to keep both names -- "DELETE b FROM book AS b" --
     * since the WHERE clause built alongside it refers to the alias.
     */
    public function testGetDeleteFromClauseResolvesATableAlias()
    {
        $criteria = new Criteria();
        $criteria->addAlias('b', 'book');
        $this->assertSame('DELETE b FROM book AS b', $this->adapter->getDeleteFromClause($criteria, 'b'));

        $this->adapter->useQuoteIdentifier = true;
        $this->assertSame('DELETE b FROM "book" AS b', $this->adapter->getDeleteFromClause($criteria, 'b'));
    }

    public function testBindValuesBindsNullWithoutConsultingTheDatabaseMap()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->adapter->bindValues($stmt, array(array('table' => null, 'column' => null, 'value' => null)), new DatabaseMap('default'));
        $stmt->execute();
        $this->assertNull($stmt->fetchColumn());
    }

    /**
     * A param with no table (a literal in the query, not a mapped column) binds
     * by PDO's own type inference rather than a ColumnMap's -- there is no
     * column to look up.
     */
    public function testBindValuesBindsAnUnmappedValueDirectly()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->adapter->bindValues($stmt, array(array('table' => null, 'column' => null, 'value' => 'literal')), new DatabaseMap('default'));
        $stmt->execute();
        $this->assertSame('literal', $stmt->fetchColumn());
    }

    public function testBindValuesRejectsNonStringTableOrColumnNames()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBAdapter::bindValues() expected param table/column names to be strings');
        $this->adapter->bindValues($stmt, array(array('table' => 42, 'column' => 'ID', 'value' => 1)), new DatabaseMap('default'));
    }

    /**
     * An array reaching bindValue() means a caller built `WHERE col = ?` from a
     * list of values, which PDO would bind as the string "Array". The named
     * alternative in the message is the fix, so it is worth asserting.
     */
    public function testBindValueRejectsAnArrayValue()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $cMap = new ColumnMap('ID', new TableMap('book'));

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Cannot bind array value for parameter :p1. Use IN() criteria instead.');
        $this->adapter->bindValue($stmt, ':p1', array(1, 2), $cMap);
    }

    public function testHasTopLevelOrderByFindsAnUnparenthesizedClause()
    {
        $this->assertTrue($this->adapter->callHasTopLevelOrderBy('SELECT a FROM t ORDER BY a'));
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy('SELECT a FROM t'));
    }

    public function testHasTopLevelOrderByIgnoresAClauseNestedInParentheses()
    {
        $this->assertFalse(
            $this->adapter->callHasTopLevelOrderBy('SELECT a FROM (SELECT a FROM t ORDER BY a) x'),
            'a subquery/window construct puts its ORDER BY inside parens'
        );
    }

    /**
     * A quoted literal containing "order by" (a LIKE pattern, or a comment
     * routed through Criteria::setComment()) is not a clause -- and a literal
     * containing an unbalanced paren must not throw the depth count off either.
     */
    public function testHasTopLevelOrderByIgnoresStringLiterals()
    {
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy("SELECT a FROM t WHERE a LIKE 'order by%'"));
        $this->assertTrue($this->adapter->callHasTopLevelOrderBy("SELECT a FROM t WHERE a LIKE '(' ORDER BY a"));
    }

    /**
     * SQL's escape for a quote inside a literal is doubling it, so the scan has
     * to consume the pair rather than reading the second one as the literal's
     * end -- otherwise everything after it is scanned in the wrong state.
     */
    public function testHasTopLevelOrderByHandlesADoubledQuoteEscape()
    {
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy("SELECT a FROM t WHERE a LIKE 'it''s (order by)'"));
        $this->assertTrue($this->adapter->callHasTopLevelOrderBy("SELECT a FROM t WHERE a = 'it''s' ORDER BY a"));
    }

    /**
     * MSSQL-style bracketed identifiers are the other quoting style PDO-bound
     * SQL can carry, and brackets do not nest.
     */
    public function testHasTopLevelOrderByHandlesBracketedIdentifiers()
    {
        $this->assertTrue($this->adapter->callHasTopLevelOrderBy('SELECT [a] FROM [t] ORDER BY [a]'));
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy('SELECT [order by] FROM [t]'));
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy('SELECT [unclosed FROM t ORDER BY a'), 'an unclosed bracket consumes the rest of the statement');
    }

    /**
     * The Criteria overload short-circuits the string scan entirely: when
     * applyLimit() was handed one, its own ORDER BY list is authoritative.
     */
    public function testHasTopLevelOrderByPrefersTheCriteriaWhenGivenOne()
    {
        $criteria = new Criteria();
        $this->assertFalse($this->adapter->callHasTopLevelOrderBy('SELECT a FROM t ORDER BY a', $criteria));
        $criteria->addAscendingOrderByColumn('book.ID');
        $this->assertTrue($this->adapter->callHasTopLevelOrderBy('SELECT a FROM t', $criteria));
    }
}

/**
 * A concrete DBAdapter that overrides nothing but the one abstract method, so
 * every other method under test here is DBAdapter's own default. The
 * `supportsX` properties exist because applyLock()'s refusal paths are only
 * reachable for a platform that declines a locking capability the base class
 * claims -- MSSQL is the real such adapter, and it overrides applyLock() itself
 * rather than inheriting the paths being tested.
 */
class BareDBAdapter extends DBAdapter
{
    // Null means "defer to DBAdapter's own answer", so a test that does not
    // deliberately decline a capability still exercises the real default rather
    // than a hardcoded copy of it -- setting these to plain `true` made
    // testLockingCapabilitiesAreClaimedByDefault assert this stub's overrides
    // and leave DBAdapter's own methods uncovered.
    public ?bool $supportsForUpdate = null;
    public ?bool $supportsForShare = null;
    public ?bool $supportsNoWait = null;
    public ?bool $supportsSkipLocked = null;
    public bool $useQuoteIdentifier = false;

    public function getDefaultPdoClass(): string
    {
        return GenericPropulsionPDO::class;
    }

    // DBAdapter's abstract interface -- what a platform *must* supply, as
    // opposed to the defaults under test. Kept as trivial as DBNone's.

    public function toUpperCase($in)
    {
        return strtoupper($in);
    }

    public function ignoreCase($in)
    {
        return strtoupper($in);
    }

    public function concatString($s1, $s2)
    {
        return "CONCAT($s1, $s2)";
    }

    public function subString($s, $pos, $len)
    {
        return "SUBSTRING($s, $pos, $len)";
    }

    public function strLength($s)
    {
        return "LENGTH($s)";
    }

    public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
    {
    }

    public function random($seed = null): ?string
    {
        return 'RANDOM()';
    }

    public function supportsForUpdate(): bool
    {
        return $this->supportsForUpdate ?? parent::supportsForUpdate();
    }

    public function supportsForShare(): bool
    {
        return $this->supportsForShare ?? parent::supportsForShare();
    }

    public function supportsNoWait(): bool
    {
        return $this->supportsNoWait ?? parent::supportsNoWait();
    }

    public function supportsSkipLocked(): bool
    {
        return $this->supportsSkipLocked ?? parent::supportsSkipLocked();
    }

    public function useQuoteIdentifier()
    {
        return $this->useQuoteIdentifier;
    }

    public function callAdvisoryLockKey(string $name): int
    {
        return $this->advisoryLockKey($name);
    }

    /**
     * @param array<int, mixed> $params
     */
    public function callFetchAdvisoryLockResult(PropulsionPDO $con, string $sql, array $params = array()): mixed
    {
        return $this->fetchAdvisoryLockResult($con, $sql, $params);
    }

    public function callHasTopLevelOrderBy(string $sql, mixed $criteria = null): bool
    {
        return $this->hasTopLevelOrderBy($sql, $criteria);
    }
}

/**
 * Records what initConnection()/setCharset() execute instead of needing a server
 * that understands `SET NAMES` (SQLite does not).
 */
class RecordingExecPDO extends PDO
{
    /** @var array<int, string> */
    public array $executed = array();

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        return 0;
    }
}

/**
 * Reports prepare() failure through the `false` return value rather than by
 * throwing, which is the sentinel fetchAdvisoryLockResult()'s guard translates
 * into an exception. Unreachable through a real connection, which runs with
 * PDO::ERRMODE_EXCEPTION.
 */
class FailingPreparePropulsionPDO extends GenericPropulsionPDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function prepare($sql, $driver_options = array()): PDOStatement|false
    {
        return false;
    }
}
