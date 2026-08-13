<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\Pgsql\PgsqlPropulsionPDO;
use Propulsion\Connection\GenericPropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Query\Criteria;
use Propulsion\Query\VectorExpression;

/**
 * DBPostgres's dialect decisions -- the SQL-standard string functions it spells
 * differently from MySQL/T-SQL, the RETURNING builders, the ON CONFLICT upsert
 * form, and the pgvector distance operators.
 *
 * Pure string work with no server involved, so unlike the rest of the Postgres
 * coverage it also runs on the no-Docker tier and on every other platform's job.
 */
class DBPostgresDialectTest extends TestCase
{
    private DBPostgres $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DBPostgres();
    }

    public function testDefaultPdoClassIsTheDriverSpecificSubclass()
    {
        $this->assertSame(PgsqlPropulsionPDO::class, $this->adapter->getDefaultPdoClass());
    }

    public function testCaseFoldingUsesUpper()
    {
        $this->assertSame('UPPER(book.title)', $this->adapter->ignoreCase('book.title'));
        $this->assertSame('UPPER(book.title)', $this->adapter->toUpperCase('book.title'));
    }

    /**
     * The SQL-standard `||`, not MySQL's CONCAT() or T-SQL's `+`.
     */
    public function testConcatStringUsesTheStandardOperator()
    {
        $this->assertSame('(book.title || book.isbn)', $this->adapter->concatString('book.title', 'book.isbn'));
    }

    /**
     * Postgres's SUBSTRING takes its arguments as `from`/`for` keywords rather
     * than a comma list, and a negative length means "to the end", so the `for`
     * part is omitted entirely.
     */
    public function testSubStringUsesFromAndForKeywords()
    {
        $this->assertSame('substring(book.title from 2for 5)', $this->adapter->subString('book.title', 2, 5));
        $this->assertSame('substring(book.title from 2)', $this->adapter->subString('book.title', 2, -1));
    }

    public function testStrLengthUsesCharLength()
    {
        $this->assertSame('char_length(book.title)', $this->adapter->strLength('book.title'));
    }

    public function testQuoteIdentifierTableQuotesEachPartSeparately()
    {
        $this->assertSame('"book"', $this->adapter->quoteIdentifierTable('book'));
        $this->assertSame('"public"."book"', $this->adapter->quoteIdentifierTable('public.book'));
        $this->assertSame('"book" "b"', $this->adapter->quoteIdentifierTable('book b'));
    }

    public function testRandomIgnoresAnySeed()
    {
        $this->assertSame('random()', $this->adapter->random());
        $this->assertSame('random()', $this->adapter->random('42'), 'random() takes no seed argument in Postgres');
    }

    /**
     * Both formatters carry the "O" timezone offset, which is what keeps a
     * timestamptz round-trip from silently shifting.
     */
    public function testTemporalFormattersIncludeTheTimezoneOffset()
    {
        $this->assertSame('Y-m-d H:i:s O', $this->adapter->getTimestampFormatter());
        $this->assertSame('H:i:s O', $this->adapter->getTimeFormatter());
    }

    /**
     * Postgres's id method is a sequence, not an auto-increment column, which is
     * why getId() needs the sequence name rather than reading lastInsertId().
     */
    public function testGetIdRefusesToGuessASequenceName()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unable to fetch next sequence ID without sequence name.');
        $this->adapter->getId(new GenericPropulsionPDO('sqlite::memory:'), null);
    }

    public function testRowAndInsertReturningAreBothSupported()
    {
        $this->assertTrue($this->adapter->supportsInsertReturning());
        $this->assertTrue($this->adapter->supportsRowReturning());
        $this->assertTrue($this->adapter->supportsUpsert());
    }

    /**
     * RETURNING is a trailing clause here -- contrast DBMSSQL, which has to
     * splice OUTPUT into the middle of the statement.
     */
    public function testReturningClausesAreAppended()
    {
        $this->assertSame(
            'UPDATE book SET title = :p1 WHERE id = :p2 RETURNING id, title',
            $this->adapter->getUpdateReturningSql('UPDATE book SET title = :p1 WHERE id = :p2', array('id', 'title'))
        );
        $this->assertSame(
            'DELETE FROM book WHERE id = :p1 RETURNING id',
            $this->adapter->getDeleteReturningSql('DELETE FROM book WHERE id = :p1', array('id'))
        );
    }

    public function testGetUpsertSqlBuildsAnOnConflictDoUpdate()
    {
        $this->assertSame(
            'INSERT INTO book (id, title) VALUES (:p1, :p2) ON CONFLICT (id) DO UPDATE SET title = :p2',
            $this->adapter->getUpsertSql('INSERT INTO book (id, title) VALUES (:p1, :p2)', array('id'), 'title = :p2')
        );
    }

    /**
     * An empty set clause means "insert, or leave the existing row alone", which
     * ON CONFLICT spells DO NOTHING.
     */
    public function testGetUpsertSqlWithNoSetClauseDoesNothingOnConflict()
    {
        $this->assertSame(
            'INSERT INTO book (id) VALUES (:p1) ON CONFLICT (id) DO NOTHING',
            $this->adapter->getUpsertSql('INSERT INTO book (id) VALUES (:p1)', array('id'), '')
        );
    }

    /**
     * ON CONFLICT has no "any unique constraint" form -- the conflict target is
     * mandatory, so an empty list is a caller error rather than something to
     * emit and let the server reject.
     */
    public function testGetUpsertSqlRequiresAConflictTarget()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBPostgres::getUpsertSql() needs at least one conflict-target column');
        $this->adapter->getUpsertSql('INSERT INTO book (id) VALUES (:p1)', array(), 'title = :p2');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function vectorMetricProvider(): array
    {
        return array(
            'l2' => array(VectorExpression::L2, '<->'),
            'cosine' => array(VectorExpression::COSINE, '<=>'),
            'inner product' => array(VectorExpression::INNER_PRODUCT, '<#>'),
            'l1' => array(VectorExpression::L1, '<+>'),
        );
    }

    /**
     * The literal is quoted and explicitly cast to `vector`: without the cast
     * Postgres has to infer an untyped literal's type against an operator
     * overloaded across vector/halfvec/sparsevec, and picks wrong often enough
     * to matter. See the method's own comment.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('vectorMetricProvider')]
    public function testVectorDistanceSqlUsesThePgvectorOperator(string $metric, string $expectedOperator)
    {
        $this->assertSame(
            "embedding $expectedOperator '[1,2,3]'::vector",
            $this->adapter->getVectorDistanceSql('embedding', '[1,2,3]', $metric)
        );
    }

    public function testVectorDistanceSqlRejectsAnUnknownMetric()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBPostgres: unknown vector distance metric "hamming"');
        $this->adapter->getVectorDistanceSql('embedding', '[1,2,3]', 'hamming');
    }

    public function testGetDeleteFromClauseNamesTheTable()
    {
        $this->assertSame('DELETE FROM book', $this->adapter->getDeleteFromClause(new Criteria(), 'book'));
    }

    /**
     * Postgres's DELETE has no multi-table form, so an aliased delete names only
     * the real table and its alias -- no leading alias, unlike
     * DBAdapter::getDeleteFromClause()'s "DELETE b FROM book AS b".
     */
    public function testGetDeleteFromClauseResolvesAnAliasWithoutRepeatingIt()
    {
        $criteria = new Criteria();
        $criteria->addAlias('b', 'book');
        $this->assertSame('DELETE FROM book AS b', $this->adapter->getDeleteFromClause($criteria, 'b'));
    }

    public function testGetDeleteFromClauseIncludesAQueryComment()
    {
        $criteria = new Criteria();
        $criteria->setComment('audit sweep');
        $this->assertSame('DELETE /* audit sweep */ FROM book', $this->adapter->getDeleteFromClause($criteria, 'book'));
    }

    /**
     * Identifier quoting is opt-in (DBAdapter::useQuoteIdentifier() is false
     * unless the generated table map turns it on), so both branches of the
     * clause builder need an adapter that has it enabled.
     */
    public function testGetDeleteFromClauseQuotesTheTableWhenQuotingIsEnabled()
    {
        $adapter = new QuotingDBPostgres();
        $this->assertSame('DELETE FROM "book"', $adapter->getDeleteFromClause(new Criteria(), 'book'));

        $criteria = new Criteria();
        $criteria->addAlias('b', 'book');
        $this->assertSame('DELETE FROM "book" AS b', $adapter->getDeleteFromClause($criteria, 'b'));
    }
}

/**
 * DBPostgres with identifier quoting turned on, which is otherwise driven by the
 * generated table map rather than by the adapter itself.
 */
class QuotingDBPostgres extends DBPostgres
{
    public function useQuoteIdentifier()
    {
        return true;
    }
}
