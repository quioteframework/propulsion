<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Cache\Driver\ArrayCache;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\ServiceContainer;
use Propulsion\Session;

/**
 * Hand-written SQL run through the same cache the fluent API uses
 * ({@see \Propulsion\Query\RawQuery}, {@see Propulsion::rawQuery()}).
 *
 * Runs in autocommit for the same reason GlobalQueryResultCacheTest does: the
 * shared tier is deliberately inert inside a transaction.
 */
class RawQueryCacheTest extends TestCase
{
    /** @var mixed */
    private $con;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            IntegrationDatabase::ensureReady();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        if (!Propulsion::isInit()) {
            set_include_path(get_include_path() . PATH_SEPARATOR . realpath(IntegrationDatabase::classesDir()));
            Propulsion::init(IntegrationDatabase::confFile());
        }

        $this->con = Propulsion::getConnection(BookPeer::DATABASE_NAME);
        BookstoreDataPopulator::depopulate();
        BookstoreDataPopulator::populate();

        $container = new ServiceContainer();
        $container->setQueryCacheConfig(new QueryCacheConfig(
            enabled: true,
            driver: 'array',
            ttl: 300,
            namespace: 'test',
            minSightings: 1,
            beta: 0.0,
        ));
        $container->setQueryCachePool(new ArrayCache(10000));
        Propulsion::setServiceContainer($container);
        Propulsion::setSession(new Session());
    }

    protected function tearDown(): void
    {
        if ($this->con !== null && $this->con->isInTransaction()) {
            $this->con->forceRollBack();
        }
        BookstoreDataPopulator::depopulate();
        Propulsion::setServiceContainer(new ServiceContainer());
        Propulsion::setSession(new Session());
        parent::tearDown();
    }

    private const SQL = 'SELECT COUNT(*) FROM book';

    public function testUncachedRawQueryReturnsRows()
    {
        $rows = Propulsion::rawQuery(self::SQL)->rows();

        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, (int) $rows[0][0]);
    }

    public function testOneReturnsTheFirstRow()
    {
        $row = Propulsion::rawQuery(self::SQL)->one();

        $this->assertIsArray($row);
        $this->assertGreaterThan(0, (int) $row[0]);
    }

    public function testOneReturnsNullWhenNothingMatches()
    {
        $row = Propulsion::rawQuery('SELECT id FROM book WHERE 1 = 0')->one();

        $this->assertNull($row);
    }

    public function testPositionalParametersAreBound()
    {
        $rows = Propulsion::rawQuery('SELECT COUNT(*) FROM book WHERE title = ?', ['Harry Potter and the Order of the Phoenix'])->rows();

        $this->assertSame(1, (int) $rows[0][0]);
    }

    public function testNamedParametersAreBound()
    {
        $rows = Propulsion::rawQuery(
            'SELECT COUNT(*) FROM book WHERE title = :title',
            ['title' => 'Harry Potter and the Order of the Phoenix']
        )->rows();

        $this->assertSame(1, (int) $rows[0][0]);
    }

    public function testCachedRawQuerySurvivesARequestBoundary()
    {
        $before = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];

        Propulsion::getSession()->reset();

        // Bypasses the ORM, so nothing bumps the book version.
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Raw Cached', '000-0-000-00001-0')");

        $after = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        $this->assertSame($before, $after, 'a cached raw query must be served across the request boundary');

        $uncached = (int) Propulsion::rawQuery(self::SQL)->rows()[0][0];
        $this->assertSame($before + 1, $uncached, 'sanity: the direct insert really landed');
    }

    public function testOrmWriteInvalidatesACachedRawQuery()
    {
        $before = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        Propulsion::getSession()->reset();

        $book = new Book();
        $book->setTitle('Invalidates Raw');
        $book->setISBN('000-0-000-00001-1');
        $book->save($this->con);

        Propulsion::getSession()->reset();

        $after = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        $this->assertSame($before + 1, $after, 'an ORM write to a declared table must evict the raw query too');
    }

    public function testWriteToAnUndeclaredTableDoesNotInvalidate()
    {
        $before = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        Propulsion::getSession()->reset();

        $author = new Author();
        $author->setFirstName('Raw');
        $author->setLastName('Unrelated');
        $author->save($this->con);

        Propulsion::getSession()->reset();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Still Hidden', '000-0-000-00001-2')");

        $after = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        $this->assertSame($before, $after);
    }

    public function testHydrateBuildsModelObjects()
    {
        $books = Propulsion::rawQuery('SELECT ' . BookPeer::TABLE_NAME . '.* FROM ' . BookPeer::TABLE_NAME . ' ORDER BY id')
            ->dependsOn('book')
            ->cache()
            ->hydrate(BookPeer::class);

        $this->assertNotEmpty($books);
        $this->assertInstanceOf(Book::class, $books[0]);
        $this->assertNotSame('', (string) $books[0]->getTitle());
    }

    public function testHydratedResultsSurviveARequestBoundaryAndAreFreshObjects()
    {
        $sql = 'SELECT ' . BookPeer::TABLE_NAME . '.* FROM ' . BookPeer::TABLE_NAME . ' ORDER BY id';
        $first = Propulsion::rawQuery($sql)->dependsOn('book')->cache()->hydrate(BookPeer::class);

        Propulsion::getSession()->reset();

        $second = Propulsion::rawQuery($sql)->dependsOn('book')->cache()->hydrate(BookPeer::class);

        $this->assertSame(count($first), count($second));
        $this->assertSame($first[0]->getTitle(), $second[0]->getTitle());
        $this->assertNotSame($first[0], $second[0], 'a cached hit must re-hydrate rather than share the previous request\'s object');
    }

    /**
     * Propulsion will not parse SQL to guess the tables: a parser would be
     * wrong about CTEs, views and aliases, and a cache that is silently wrong
     * about invalidation is worse than one that insists you say.
     */
    public function testCachingWithoutDeclaredTablesThrows()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('dependsOn');

        Propulsion::rawQuery(self::SQL)->cache()->rows();
    }

    public function testUnknownTableInDependsOnThrows()
    {
        // A misspelled table would silently never invalidate, so it is caught
        // at declaration time instead.
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Unknown table "bok"');

        Propulsion::rawQuery(self::SQL)->dependsOn('bok');
    }

    public function testUncachedRawQueryNeedsNoDeclaredTables()
    {
        $rows = Propulsion::rawQuery(self::SQL)->rows();

        $this->assertCount(1, $rows);
    }

    public function testSharedOptOutKeepsResultsRequestScoped()
    {
        $before = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache(shared: false)->rows()[0][0];

        Propulsion::getSession()->reset();
        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Raw Opt Out', '000-0-000-00001-3')");

        $after = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache(shared: false)->rows()[0][0];
        $this->assertSame($before + 1, $after);
    }

    public function testInvalidateQueryCacheForTablesEvictsRawEntries()
    {
        // The escape hatch for writes Propulsion cannot see.
        $before = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        Propulsion::getSession()->reset();

        $this->con->exec("INSERT INTO book (title, isbn) VALUES ('Manual Invalidate', '000-0-000-00001-4')");
        Propulsion::invalidateQueryCacheForTables(['book']);
        Propulsion::getSession()->reset();

        $after = (int) Propulsion::rawQuery(self::SQL)->dependsOn('book')->cache()->rows()[0][0];
        $this->assertSame($before + 1, $after, 'invalidateQueryCacheForTables() must evict raw query entries');
    }
}
