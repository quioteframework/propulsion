<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Adapter\MSSQL\MssqlPropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\DatabaseMap;
use Propulsion\Map\TableMap;
use Propulsion\Query\Criteria;

/**
 * DBMSSQL's T-SQL dialect decisions -- identifier quoting, the string/scalar
 * function spellings, and the OUTPUT-clause splicing that stands in for
 * Postgres/MariaDB's trailing RETURNING.
 *
 * All pure string work, so it belongs in a plain TestCase rather than alongside
 * DBMSSQLTest, which extends BookstoreTestBase and therefore skips itself
 * whenever the bookstore fixtures are unavailable -- taking these paths' coverage
 * with it on the no-Docker tier.
 */
class DBMSSQLDialectTest extends TestCase
{
    private DBMSSQL $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DBMSSQL();
    }

    public function testDefaultPdoClassIsTheDblibSubclass()
    {
        $this->assertSame(MssqlPropulsionPDO::class, $this->adapter->getDefaultPdoClass());
    }

    /**
     * SQL Server has no SET NAMES; the encoding is a property of the connection
     * the driver opens, so there is nothing to issue after the fact.
     */
    public function testSetCharsetIsANoOpBecauseTsqlHasNoSetNames()
    {
        $con = new PDO('sqlite::memory:');
        $this->adapter->setCharset($con, 'utf8');
        $this->expectNotToPerformAssertions();
    }

    /**
     * toUpperCase() and ignoreCase() are the same operation here: MSSQL's
     * collation is usually case-insensitive already, so the adapter only needs
     * one spelling.
     */
    public function testCaseFoldingUsesUpper()
    {
        $this->assertSame('UPPER(book.TITLE)', $this->adapter->ignoreCase('book.TITLE'));
        $this->assertSame('UPPER(book.TITLE)', $this->adapter->toUpperCase('book.TITLE'));
    }

    /**
     * T-SQL concatenates with `+`, not the SQL-standard `||` or MySQL's
     * CONCAT() -- and the result is parenthesized so it composes safely inside a
     * larger expression.
     */
    public function testConcatStringUsesThePlusOperator()
    {
        $this->assertSame('(book.TITLE + book.ISBN)', $this->adapter->concatString('book.TITLE', 'book.ISBN'));
    }

    public function testSubStringUsesTsqlSubstring()
    {
        $this->assertSame('SUBSTRING(book.TITLE, 1, 5)', $this->adapter->subString('book.TITLE', 1, 5));
    }

    /**
     * LEN(), not the SQL-standard LENGTH() -- SQL Server has no LENGTH.
     */
    public function testStrLengthUsesLen()
    {
        $this->assertSame('LEN(book.TITLE)', $this->adapter->strLength('book.TITLE'));
    }

    public function testQuoteIdentifierUsesSquareBrackets()
    {
        $this->assertSame('[TITLE]', $this->adapter->quoteIdentifier('TITLE'));
    }

    /**
     * A table reference can carry a schema/database qualifier and an alias, and
     * each part is bracketed separately: "database.table alias" becomes
     * "[database].[table] [alias]".
     */
    public function testQuoteIdentifierTableBracketsEachPartSeparately()
    {
        $this->assertSame('[book]', $this->adapter->quoteIdentifierTable('book'));
        $this->assertSame('[dbo].[book]', $this->adapter->quoteIdentifierTable('dbo.book'));
        $this->assertSame('[book] [b]', $this->adapter->quoteIdentifierTable('book b'));
        $this->assertSame('[dbo].[book] [b]', $this->adapter->quoteIdentifierTable('dbo.book b'));
    }

    /**
     * RAND() takes the seed as an argument rather than the seedless form other
     * platforms use, and the seed is cast so it cannot carry SQL into the
     * statement.
     */
    public function testRandomSeedsRandAndCastsTheSeed()
    {
        $this->assertSame('RAND(0)', $this->adapter->random());
        $this->assertSame('RAND(42)', $this->adapter->random('42'));
        $this->assertSame('RAND(0)', $this->adapter->random('not a number'));
    }

    /**
     * T-SQL writes a recursive CTE under a plain `WITH name AS (...)` and
     * rejects the RECURSIVE keyword outright as a syntax error.
     */
    public function testRecursiveCteKeywordIsRefused()
    {
        $this->assertFalse($this->adapter->supportsRecursiveCteKeyword());
    }

    /**
     * NOWAIT has no table-hint equivalent (SET LOCK_TIMEOUT is session-level),
     * so it is the one locking capability MSSQL declines.
     */
    public function testNoWaitIsUnsupportedUnlikeTheOtherLockingModes()
    {
        $this->assertFalse($this->adapter->supportsNoWait());
        $this->assertTrue($this->adapter->supportsSkipLocked());
    }

    /**
     * applyLimit() interpolates the offset/limit straight into the statement, so
     * it validates them itself rather than trusting its untyped parameters.
     */
    public function testApplyLimitRejectsNonNumericBounds()
    {
        $sql = 'SELECT TITLE FROM book';
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBMSSQL::applyLimit() expects a number for argument 2 and 3');
        $this->adapter->applyLimit($sql, 'not a number', 10);
    }

    public function testApplyLimitWithoutAnOffsetUsesTop()
    {
        $sql = 'SELECT TITLE FROM book';
        $this->adapter->applyLimit($sql, 0, 10);
        $this->assertStringContainsString('TOP', $sql);
    }

    /**
     * Locking is expressed as a table hint appended to each FROM entry, not as a
     * trailing clause -- and SKIP LOCKED becomes the READPAST hint.
     */
    public function testApplyLockHintsAppendsTableHintsForUpdate()
    {
        $fromClause = array('book');
        $joinClause = array();
        $this->adapter->applyLockHints($fromClause, $joinClause, (new Criteria())->setLockForUpdate());
        $this->assertSame(array('book WITH (UPDLOCK, ROWLOCK)'), $fromClause);
    }

    public function testApplyLockHintsUsesHoldlockForShare()
    {
        $fromClause = array('book');
        $joinClause = array();
        $this->adapter->applyLockHints($fromClause, $joinClause, (new Criteria())->setLockForShare());
        $this->assertSame(array('book WITH (HOLDLOCK, ROWLOCK)'), $fromClause);
    }

    public function testApplyLockHintsAddsReadpastForSkipLocked()
    {
        $fromClause = array('book');
        $joinClause = array();
        $this->adapter->applyLockHints($fromClause, $joinClause, (new Criteria())->setLockForUpdate(true));
        $this->assertSame(array('book WITH (UPDLOCK, ROWLOCK, READPAST)'), $fromClause);
    }

    /**
     * A join carries its hint before the ON keyword, not at the end of the
     * clause, so the hint applies to the joined table rather than to the
     * condition.
     */
    public function testApplyLockHintsSplicesTheHintBeforeAJoinsOnKeyword()
    {
        $fromClause = array('book');
        $joinClause = array('LEFT JOIN author ON book.AUTHOR_ID = author.ID');
        $this->adapter->applyLockHints($fromClause, $joinClause, (new Criteria())->setLockForUpdate());
        $this->assertSame(
            array('LEFT JOIN author WITH (UPDLOCK, ROWLOCK) ON book.AUTHOR_ID = author.ID'),
            $joinClause
        );
    }

    public function testInsertReturningIsSupportedButNullPrimaryKeysAreNot()
    {
        $this->assertTrue($this->adapter->supportsInsertReturning());
        $this->assertTrue($this->adapter->supportsRowReturning());
        $this->assertFalse($this->adapter->supportsInsertNullPk(), 'an IDENTITY column rejects an explicit NULL');
    }

    /**
     * T-SQL puts OUTPUT between SET and FROM/WHERE, not at the end of the
     * statement where a trailing RETURNING would go -- so the clause is spliced
     * before whichever of the two comes first.
     */
    public function testGetUpdateReturningSqlSplicesOutputBeforeWhere()
    {
        $this->assertSame(
            'UPDATE book SET TITLE = :p1 OUTPUT INSERTED.ID, INSERTED.TITLE WHERE ID = :p2',
            $this->adapter->getUpdateReturningSql('UPDATE book SET TITLE = :p1 WHERE ID = :p2', array('ID', 'TITLE'))
        );
    }

    public function testGetUpdateReturningSqlSplicesOutputBeforeAnAliasedUpdatesFrom()
    {
        $this->assertSame(
            'UPDATE b SET TITLE = :p1 OUTPUT INSERTED.ID FROM book AS b WHERE b.ID = :p2',
            $this->adapter->getUpdateReturningSql('UPDATE b SET TITLE = :p1 FROM book AS b WHERE b.ID = :p2', array('ID'))
        );
    }

    /**
     * An UPDATE with neither FROM nor WHERE (a whole-table update) has nothing to
     * splice before, so the clause goes at the end.
     */
    public function testGetUpdateReturningSqlAppendsWhenThereIsNothingToSpliceBefore()
    {
        $this->assertSame(
            'UPDATE book SET TITLE = :p1 OUTPUT INSERTED.ID',
            $this->adapter->getUpdateReturningSql('UPDATE book SET TITLE = :p1', array('ID'))
        );
    }

    /**
     * DELETE's OUTPUT reads the DELETED pseudo-table, not INSERTED.
     */
    public function testGetDeleteReturningSqlSplicesOutputBeforeWhere()
    {
        $this->assertSame(
            'DELETE FROM book OUTPUT DELETED.ID, DELETED.TITLE WHERE ID = :p1',
            $this->adapter->getDeleteReturningSql('DELETE FROM book WHERE ID = :p1', array('ID', 'TITLE'))
        );
    }

    public function testGetDeleteReturningSqlAppendsForAnUnfilteredDelete()
    {
        $this->assertSame(
            'DELETE FROM book OUTPUT DELETED.ID',
            $this->adapter->getDeleteReturningSql('DELETE FROM book', array('ID'))
        );
    }

    public function testGetInsertReturningSqlThrowsWhenThereIsNoValuesClause()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBMSSQL::getInsertReturningSql() could not locate the VALUES clause in: SELECT 1');
        $this->adapter->getInsertReturningSql('SELECT 1', 'ID');
    }

    public function testCleanupSqlRejectsNonStringTableOrColumnNames()
    {
        $dbMap = new DatabaseMap('bookstore');
        $tableMap = new TableMap('book', $dbMap);
        $tableMap->addColumn('COVER', 'Cover', 'BLOB');
        $dbMap->addTableObject($tableMap);

        $sql = 'INSERT INTO book (COVER) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 42, 'value' => null));

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBMSSQL::cleanupSQL() expected param table/column names to be strings');
        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
    }

    /**
     * pdo_dblib cannot bind a blob through bindValue() -- quoting breaks the
     * statement -- so a LOB stream is hex-encoded straight into the SQL and its
     * placeholder removed from the parameter list.
     */
    public function testCleanupSqlHexEncodesABlobStreamIntoTheStatement()
    {
        $dbMap = new DatabaseMap('bookstore');
        $tableMap = new TableMap('book', $dbMap);
        $tableMap->addColumn('COVER', 'Cover', 'BLOB');
        $dbMap->addTableObject($tableMap);

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, 'AB');

        $sql = 'INSERT INTO book (COVER) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 'COVER', 'value' => $stream));

        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
        $this->assertSame('INSERT INTO book (COVER) VALUES (0x4142)', $sql, "'AB' is 0x4142");
        $this->assertSame(array(), $params, 'the hex-encoded value is no longer a bound parameter');
    }
}
