<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Platform\MysqlPlatform;
use Propulsion\Generator\Reverse\MySQL\MysqlSchemaParser;

require_once dirname(__DIR__, 4) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end coverage for MysqlSchemaParser against a real MySQL/MariaDB
 * testcontainer, modelled on PgsqlSchemaParserTest.
 *
 * The pre-existing MysqlSchemaParserTest could never run: it builds its
 * connection from a hand-written `runtime-conf.xml` fixture whose DSN is
 * `mysql:dbname=reverse_bookstore` with no host at all, so PDO tries a local
 * Unix socket that exists on no machine this suite runs on -- including the
 * integration-mysql CI job, which provisions a testcontainer on a mapped TCP
 * port that fixture knows nothing about. It skipped itself unconditionally,
 * everywhere, which is why the whole parser sat at 0% coverage while looking
 * tested.
 *
 * The schema below is deliberately awkward in the ways this parser has to
 * cope with: MySQL's own integer display widths, an unsigned column, an ENUM
 * and a SET (both of which collapse to CHAR), a TEXT/LONGTEXT pair that map to
 * different Propulsion types, a DECIMAL with precision and scale, defaults of
 * three different kinds, a composite unique, a plain index, a composite
 * primary key on the join table, and a foreign key with non-default
 * ON DELETE/ON UPDATE actions parsed out of SHOW CREATE TABLE text.
 */
class MysqlSchemaParserLiveTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_mysql_schema_parser';

    private ?PDO $pdo = null;
    private ?Database $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array(IntegrationDatabase::currentPlatform(), array('mysql', 'mariadb'), true)) {
            $this->markTestSkipped('Exercises MysqlSchemaParser against a live MySQL/MariaDB server; run with PROPULSION_TEST_DB=mysql or mariadb.');
        }

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        // A database of its own, created as root (both the MySQL and MariaDB
        // testcontainers set root/root -- see IntegrationDatabase). The shared
        // propulsion_test schema already holds ~57 bookstore fixture tables,
        // and this parser reverse-engineers whatever database it is pointed
        // at, so asserting on the table list there would be asserting on the
        // rest of the suite's fixtures.
        $admin = new PDO("mysql:host={$conn['host']};port={$conn['port']}", 'root', 'root');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $admin->exec('CREATE DATABASE IF NOT EXISTS ' . self::DB_NAME);

        $this->pdo = new PDO(
            "mysql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME,
            'root',
            'root'
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->dropFixtures();

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_my_author (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NULL,
                bio TEXT NULL,
                notes LONGTEXT NULL,
                rating DECIMAL(3,2) NULL DEFAULT 0.00,
                visits INT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('draft','live') NOT NULL DEFAULT 'draft',
                flags SET('a','b') NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY rev_my_author_email_uniq (email)
            ) ENGINE=InnoDB
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_my_book (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                author_id INT NULL,
                price DECIMAL(10,2) NULL,
                UNIQUE KEY rev_my_book_title_author_uniq (title, author_id),
                KEY rev_my_book_price_idx (price),
                CONSTRAINT rev_my_book_author_fk FOREIGN KEY (author_id)
                    REFERENCES rev_my_author (id) ON DELETE CASCADE ON UPDATE SET NULL
            ) ENGINE=InnoDB
        SQL);

        // A composite primary key, which the single-column tables above cannot
        // exercise: addPrimaryKey() has to mark both columns, in order.
        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_my_book_tag (
                book_id INT NOT NULL,
                tag VARCHAR(50) NOT NULL,
                PRIMARY KEY (book_id, tag)
            ) ENGINE=InnoDB
        SQL);

        // Views must be skipped: parse() only counts BASE TABLE rows.
        $this->pdo->exec('CREATE VIEW rev_my_book_view AS SELECT id, title FROM rev_my_book');

        $this->database = new Database();
        $this->database->setPlatform(new MysqlPlatform());
        $this->database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = new MysqlSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new MysqlPlatform());
        $parser->parse($this->database);
    }

    protected function tearDown(): void
    {
        $this->dropFixtures();
        parent::tearDown();
    }

    private function dropFixtures(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->pdo->exec('DROP VIEW IF EXISTS rev_my_book_view');
        $this->pdo->exec('DROP TABLE IF EXISTS rev_my_book_tag');
        $this->pdo->exec('DROP TABLE IF EXISTS rev_my_book');
        $this->pdo->exec('DROP TABLE IF EXISTS rev_my_author');
    }

    private function table(string $name): \Propulsion\Generator\Model\Table
    {
        $this->assertNotNull($this->database);
        $table = $this->database->getTable($name);
        $this->assertNotNull($table, "expected the parser to have found $name");

        return $table;
    }

    // ---- tables and views -------------------------------------------------

    public function testEveryBaseTableIsFound()
    {
        $this->assertNotNull($this->database);
        $names = array_map(fn($t) => $t->getName(), $this->database->getTables());
        sort($names);

        $this->assertSame(array('rev_my_author', 'rev_my_book', 'rev_my_book_tag'), $names);
    }

    public function testViewsAreExcluded()
    {
        // SHOW FULL TABLES reports a view as type VIEW; reverse-engineering one
        // into a <table> would generate a model that cannot be written to.
        $this->assertNotNull($this->database);
        $names = array_map(fn($t) => $t->getName(), $this->database->getTables());

        $this->assertNotContains('rev_my_book_view', $names);
    }

    // ---- columns ----------------------------------------------------------

    public function testAutoIncrementPrimaryKeyIsDetected()
    {
        $column = $this->table('rev_my_author')->getColumn('id');
        $this->assertTrue($column->isPrimaryKey());
        $this->assertTrue($column->isAutoIncrement());
        $this->assertSame(PropulsionTypes::INTEGER, $column->getType());
    }

    public function testVarcharCapturesSizeAndNotNull()
    {
        $column = $this->table('rev_my_author')->getColumn('name');
        $this->assertSame(PropulsionTypes::VARCHAR, $column->getType());
        $this->assertEquals(100, $column->getSize());
        $this->assertTrue($column->isNotNull());
    }

    public function testNullableColumnIsNotMarkedNotNull()
    {
        $this->assertFalse($this->table('rev_my_author')->getColumn('bio')->isNotNull());
    }

    public function testTextAndLongtextMapToDifferentTypes()
    {
        // Both are "text" to a careless reader; the parser distinguishes them.
        $this->assertSame(PropulsionTypes::LONGVARCHAR, $this->table('rev_my_author')->getColumn('bio')->getType());
        $this->assertSame(PropulsionTypes::CLOB, $this->table('rev_my_author')->getColumn('notes')->getType());
    }

    public function testDecimalDecodesPrecisionAndScale()
    {
        $column = $this->table('rev_my_author')->getColumn('rating');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(3, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testDecimalOnAnotherTableKeepsItsOwnPrecision()
    {
        $column = $this->table('rev_my_book')->getColumn('price');
        $this->assertEquals(10, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testEnumAndSetCollapseToChar()
    {
        // MySQL's ENUM/SET have no Propulsion equivalent at reverse-engineering
        // time (the value set is not carried across), so both land on CHAR
        // rather than being dropped or guessed at.
        $this->assertSame(PropulsionTypes::CHAR, $this->table('rev_my_author')->getColumn('status')->getType());
        $this->assertSame(PropulsionTypes::CHAR, $this->table('rev_my_author')->getColumn('flags')->getType());
    }

    public function testLiteralDefaultIsCaptured()
    {
        $default = $this->table('rev_my_author')->getColumn('visits')->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertEquals(0, $default->getValue());
    }

    public function testStringDefaultIsCaptured()
    {
        $default = $this->table('rev_my_author')->getColumn('status')->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('draft', $default->getValue());
    }

    public function testCurrentTimestampDefaultIsCapturedAsAnExpression()
    {
        // Not a literal. MariaDB reports this default as `current_timestamp()`
        // and MySQL as `CURRENT_TIMESTAMP`; recording either as a value rather
        // than an expression regenerates DEFAULT '<those characters>', a
        // string column default that stores the words instead of the time.
        $default = $this->table('rev_my_author')->getColumn('created_at')->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertTrue($default->isExpression(), 'a function default must not be regenerated quoted');
    }

    public function testTimestampDefaultSurvivesRegeneration()
    {
        // The consequence, rather than the internal flag: the DDL this
        // reverse-engineered column produces must still call the function.
        $ddl = (new MysqlPlatform())->getColumnDDL($this->table('rev_my_author')->getColumn('created_at'));

        $this->assertMatchesRegularExpression('/DEFAULT\s+current_timestamp/i', $ddl);
        $this->assertDoesNotMatchRegularExpression("/DEFAULT\s+'/i", $ddl, 'the default must not be quoted');
    }

    public function testDecimalPrecisionAndScaleSurviveRegeneration()
    {
        // The round trip this locks in: DECIMAL's "default size" is 10, so
        // DECIMAL(10,2) used to have its precision stripped while keeping the
        // scale -- and printSize() emits nothing unless both are set, so the
        // column regenerated as a bare DECIMAL, which MySQL reads as
        // DECIMAL(10,0). A price column came back with no decimal places.
        $ddl = (new MysqlPlatform())->getColumnDDL($this->table('rev_my_book')->getColumn('price'));

        $this->assertStringContainsString('DECIMAL(10,2)', str_replace(' ', '', $ddl));
    }

    public function testARedundantDefaultSizeIsStillDropped()
    {
        // The behaviour the fix above must not have broken: INT(11) is MySQL's
        // own display-width default and carries no information, so it is not
        // written into the generated schema as though it had been asked for.
        $column = $this->table('rev_my_book')->getColumn('author_id');

        $this->assertNull($column->getSize(), 'INT(11) is just INT');
    }

    // ---- keys and indexes -------------------------------------------------

    public function testCompositePrimaryKeyKeepsBothColumnsInOrder()
    {
        $table = $this->table('rev_my_book_tag');
        $pk = array_map(fn($c) => $c->getName(), $table->getPrimaryKey());

        $this->assertSame(array('book_id', 'tag'), $pk);
    }

    public function testSingleColumnUniqueIsReverseEngineeredAsUnique()
    {
        $table = $this->table('rev_my_author');
        $uniques = array_values(array_filter($table->getIndices(), fn($idx) => $idx->isUnique()));

        $this->assertCount(1, $uniques);
        $this->assertInstanceOf(Unique::class, $uniques[0]);
        $this->assertSame(array('email'), $uniques[0]->getColumns());
    }

    public function testCompositeUniqueKeepsBothColumns()
    {
        $table = $this->table('rev_my_book');
        $uniques = array_values(array_filter($table->getIndices(), fn($idx) => $idx->isUnique()));

        $this->assertCount(1, $uniques);
        $this->assertSame(array('title', 'author_id'), $uniques[0]->getColumns());
    }

    public function testPlainIndexIsNotReportedAsUnique()
    {
        $table = $this->table('rev_my_book');
        $plain = array_values(array_filter($table->getIndices(), fn($idx) => !$idx->isUnique()));

        $names = array_map(fn($idx) => $idx->getName(), $plain);
        $this->assertContains('rev_my_book_price_idx', $names);
    }

    public function testPrimaryKeyIsNotReportedAsAnIndex()
    {
        // SHOW INDEX lists PRIMARY alongside the real indexes; treating it as
        // one would emit a duplicate <index> next to the <primary-key>.
        $names = array_map(fn($idx) => $idx->getName(), $this->table('rev_my_book')->getIndices());

        $this->assertNotContains('PRIMARY', $names);
    }

    // ---- foreign keys -----------------------------------------------------

    public function testForeignKeyIsParsedOutOfShowCreateTable()
    {
        $fks = $this->table('rev_my_book')->getForeignKeys();
        $this->assertCount(1, $fks);

        $fk = $fks[0];
        $this->assertSame('rev_my_author', $fk->getForeignTableName());
        $this->assertSame(array('author_id'), $fk->getLocalColumns());
        $this->assertSame(array('id'), $fk->getForeignColumns());
    }

    public function testForeignKeyReferentialActionsAreCaptured()
    {
        // These come out of SHOW CREATE TABLE's text rather than a catalog
        // table, so they are the part most likely to be silently dropped.
        $fk = $this->table('rev_my_book')->getForeignKeys()[0];

        $this->assertSame(ForeignKey::CASCADE, $fk->getOnDelete());
        $this->assertSame(ForeignKey::SETNULL, $fk->getOnUpdate());
    }

    public function testATableWithNoForeignKeysHasNone()
    {
        $this->assertCount(0, $this->table('rev_my_author')->getForeignKeys());
    }

    // ---- the return value -------------------------------------------------

    public function testParseReturnsTheNumberOfTablesItAdded()
    {
        $database = new Database();
        $database->setPlatform(new MysqlPlatform());
        $database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = new MysqlSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new MysqlPlatform());

        $this->assertSame(3, $parser->parse($database), 'three base tables, the view excluded');
    }
}
