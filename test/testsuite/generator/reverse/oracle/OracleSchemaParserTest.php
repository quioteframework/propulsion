<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Reverse\Oracle\OracleSchemaParser;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Platform\OraclePlatform;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Unique;

require_once dirname(__DIR__, 4) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end coverage for OracleSchemaParser against a real Oracle
 * testcontainer, seeded with the same non-trivial schema shape as
 * PgsqlSchemaParserTest (two related tables, multiple column types/defaults,
 * a sequence-backed pk, a single-column unique constraint, an explicit
 * unique index, a plain index, and a foreign key with a non-default
 * ON DELETE action).
 *
 * Unlike this suite's other reverse-engineering tests, the fixture schema is
 * built once in setUpBeforeClass()/torn down once in tearDownAfterClass(),
 * not per test method: every Oracle DDL statement is an implicit commit that
 * forces a redo-log fsync, and each test method would otherwise also pay for
 * a fresh OCI session logon (itself much heavier than a Postgres/MySQL/MSSQL
 * connect) -- doing that 13 times per run (once per test method, the pattern
 * every other platform's parser test uses) made this file alone take north
 * of ten minutes against a single container. Building the schema once cuts
 * that by roughly the number of test methods.
 *
 * Unquoted Oracle identifiers come back UPPERCASE from the data dictionary
 * (USER_TAB_COLS/USER_OBJECTS/etc.), unlike every other platform in this
 * suite -- every table/column name below is asserted uppercase accordingly.
 *
 * Several assertions here lock in genuine limitations/quirks of the current
 * parser (confirmed against a live oracle-free container, not assumed)
 * rather than idealized behavior -- see each test's own docblock.
 */
class OracleSchemaParserTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?Database $database = null;
    private static ?int $parsedTableCount = null;
    private static ?string $skipReason = null;

    public static function setUpBeforeClass(): void
    {
        if (IntegrationDatabase::currentPlatform() !== 'oracle') {
            self::$skipReason = 'Exercises OracleSchemaParser against a live Oracle server specifically, regardless of PROPULSION_TEST_DB.';
            return;
        }

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            self::$skipReason = $e->getMessage();
            return;
        }

        $dsn = IntegrationDatabase::pdoDsn($conn['host'], $conn['port'], 'FREEPDB1');
        [$user, $password] = IntegrationDatabase::pdoCredentials();
        self::$pdo = new PDO($dsn, $user, $password);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::dropFixtures();

        self::$pdo->exec(<<<SQL
            CREATE TABLE rev_author (
                id NUMBER NOT NULL PRIMARY KEY,
                name VARCHAR2(100) NOT NULL,
                email VARCHAR2(255) UNIQUE,
                bio CLOB,
                rating NUMBER(3,2) DEFAULT 0.0,
                active NUMBER(1) DEFAULT 1
            )
        SQL);
        self::$pdo->exec('CREATE SEQUENCE rev_author_SEQ INCREMENT BY 1 START WITH 1 NOMAXVALUE NOCYCLE NOCACHE ORDER');
        self::$pdo->exec(<<<SQL
            CREATE OR REPLACE TRIGGER rev_author_TRG
            BEFORE INSERT ON rev_author
            FOR EACH ROW
            WHEN (new.id IS NULL)
            BEGIN
                SELECT rev_author_SEQ.NEXTVAL INTO :new.id FROM dual;
            END;
        SQL);

        self::$pdo->exec(<<<SQL
            CREATE TABLE rev_book (
                id NUMBER NOT NULL PRIMARY KEY,
                title VARCHAR2(200) NOT NULL,
                author_id NUMBER,
                price NUMBER(10,2),
                CONSTRAINT rev_book_author_fk FOREIGN KEY (author_id) REFERENCES rev_author(id) ON DELETE CASCADE
            )
        SQL);
        // rev_book deliberately has no matching rev_book_SEQ sequence, so its
        // id is not detected as auto-increment (see
        // testTableWithNoMatchingSequenceIsNotAutoIncrement()).
        self::$pdo->exec('CREATE INDEX rev_book_price_idx ON rev_book (price)');
        self::$pdo->exec('CREATE UNIQUE INDEX rev_book_title_idx ON rev_book (title)');

    }

    /**
     * The parsed schema, built on first use and shared by every test here.
     *
     * Deliberately *not* built in setUpBeforeClass(), where the fixture DDL
     * above still is. PHPUnit collects coverage per test *method*, so anything
     * run in setUpBeforeClass() falls outside every collection window: while
     * the parse lived there, this parser measured 0% coverage despite being
     * thoroughly tested, and was indistinguishable in the coverage report from
     * a parser with no working test at all -- which is exactly what the MySQL
     * one turned out to be. Parsing lazily from the first test method that
     * asks moves the work inside a window at no extra cost; it still happens
     * exactly once per class, which is the point of the shared-fixture design
     * (13 Oracle DDL round trips took over ten minutes).
     */
    private static function database(): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }

        $database = new Database();
        $database->setPlatform(new OraclePlatform());
        $database->setDefaultIdMethod(IDMethod::NATIVE);

        $config = new QuickGeneratorConfig();
        // Not configured by default anywhere QuickGeneratorConfig would pick
        // up automatically (generator/default.php's own
        // propulsion.oracle.autoincrementSequencePattern isn't loaded here) --
        // has to be set explicitly for OracleSchemaParser::parse() to even
        // look for a matching sequence at all.
        $config->setBuildProperty('oracleAutoincrementSequencePattern', '${table}_SEQ');

        $parser = new OracleSchemaParser(self::$pdo);
        $parser->setGeneratorConfig($config);
        $parser->setPlatform(new OraclePlatform());
        self::$parsedTableCount = $parser->parse($database);

        return self::$database = $database;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo !== null) {
            self::dropFixtures();
        }
        self::$pdo = null;
        self::$database = null;
        self::$parsedTableCount = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    private static function dropFixtures(): void
    {
        foreach (array('rev_book', 'rev_author') as $t) {
            try {
                self::$pdo->exec('DROP TABLE ' . $t . ' CASCADE CONSTRAINTS');
            } catch (\Throwable $e) {
                // doesn't exist yet
            }
        }
        try {
            self::$pdo->exec('DROP SEQUENCE rev_author_SEQ');
        } catch (\Throwable $e) {
            // doesn't exist yet
        }
    }

    /**
     * Unlike Postgres/MySQL/MSSQL (which each get their own fresh scratch
     * database per test), Oracle's single FREEPDB1 pluggable database is
     * shared by the same app user as every other Oracle-run test in this
     * suite (see IntegrationDatabase::pdoDsn()'s own docblock) -- parse()
     * picks up every table already in that schema, not just this test's own,
     * so this only checks that both of ours are present among them, not that
     * they're the only two.
     */
    public function testParseFindsBothTablesUppercased()
    {
        $names = array_map(fn($t) => $t->getName(), self::database()->getTables());
        $this->assertContains('REV_AUTHOR', $names, 'unquoted Oracle identifiers come back uppercase');
        $this->assertContains('REV_BOOK', $names);
    }

    public function testSequenceBackedPrimaryKeyIsDetectedAsAutoIncrement()
    {
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('ID');
        $this->assertTrue($column->isPrimaryKey());
        $this->assertTrue($column->isAutoIncrement());
    }

    /**
     * rev_book's id has no matching REV_BOOK_SEQ sequence (deliberately not
     * created in setUpBeforeClass()) -- OracleSchemaParser::parse() only
     * flags a single-column primary key auto-increment when USER_SEQUENCES
     * actually has a row matching oracleAutoincrementSequencePattern, so
     * this one is left plain despite being the table's primary key, exactly
     * like rev_author's would be if its trigger/sequence pair didn't exist.
     */
    public function testTableWithNoMatchingSequenceIsNotAutoIncrement()
    {
        $column = self::database()->getTable('REV_BOOK')->getColumn('ID');
        $this->assertTrue($column->isPrimaryKey());
        $this->assertFalse($column->isAutoIncrement());
    }

    /**
     * Confirmed against a live server: addColumns() only reads DATA_PRECISION
     * for a column's size, falling back to DATA_LENGTH (Oracle's raw internal
     * storage byte count for NUMBER, always 22) whenever DATA_PRECISION is
     * NULL -- which it is for an unscoped "NUMBER" column with no precision
     * declared at all. That pushes the ">9 digits -> BIGINT" heuristic below
     * to fire even though no explicit precision was ever declared, so every
     * unscoped NUMBER column (id here) reverse-engineers as BIGINT with
     * size=22, not as PropulsionTypes::NUMBER's mapped INTEGER.
     */
    public function testUnscopedNumberColumnBecomesBigintViaLengthFallback()
    {
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('ID');
        $this->assertSame(PropulsionTypes::BIGINT, $column->getType(), 'documents a parser quirk, not desired behavior -- see this test\'s docblock');
        $this->assertEquals(22, $column->getSize());
    }

    public function testVarcharColumnCapturesSizeAndNotNull()
    {
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('NAME');
        $this->assertSame(PropulsionTypes::VARCHAR, $column->getType());
        $this->assertEquals(100, $column->getSize());
        $this->assertTrue($column->isNotNull());
    }

    public function testNullableColumnIsCorrectlyNotMarkedNotNull()
    {
        // Unlike MssqlSchemaParser (which always reports every column NOT
        // NULL regardless of its real nullability, a confirmed pdo_dblib
        // limitation), OracleSchemaParser reads USER_TAB_COLS.NULLABLE
        // correctly.
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('EMAIL');
        $this->assertFalse($column->isNotNull());
    }

    /**
     * CLOB reverse-engineers as PropulsionTypes::CLOB_EMU, not ::CLOB --
     * OraclePlatform emulates CLOB via a native string domain (see
     * PropulsionTypes::CLOB_EMU_NATIVE_TYPE's own docblock), and
     * Column::setDomainForType() resolves to that platform-specific domain
     * once the column's table's database has an OraclePlatform set.
     */
    public function testClobColumnMapsToClobEmu()
    {
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('BIO');
        $this->assertSame(PropulsionTypes::CLOB_EMU, $column->getType());
    }

    public function testNumberWithScaleDecodesPrecisionAndScaleCorrectly()
    {
        // Unlike MssqlSchemaParser's LENGTH-vs-PRECISION size bug,
        // OracleSchemaParser correctly captures both DATA_PRECISION and
        // DATA_SCALE for a scoped NUMBER(p,s) column.
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('RATING');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(3, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testNumberWithDifferentScaleOnAnotherTable()
    {
        $column = self::database()->getTable('REV_BOOK')->getColumn('PRICE');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(10, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testSmallScopedNumberMapsToInteger()
    {
        $column = self::database()->getTable('REV_AUTHOR')->getColumn('ACTIVE');
        $this->assertSame(PropulsionTypes::INTEGER, $column->getType());
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        // USER_TAB_COLS.DATA_DEFAULT (a LONG column) comes back from oci8
        // padded with trailing whitespace (confirmed against a live server --
        // the exact padding isn't pinned here since it's an oci8 buffering
        // artifact, not a meaningful value) -- addColumns() stores it
        // completely unprocessed, so the reverse-engineered default is
        // never a clean "1".
        $this->assertSame('1', trim($default->getValue()));
        $this->assertNotSame('1', $default->getValue(), 'documents a driver/parser quirk (trailing whitespace), not desired behavior -- see this test\'s docblock');
    }

    /**
     * addIndexes() never reads any uniqueness information from
     * USER_IND_COLUMNS at all -- every index, including Oracle's own
     * implicit indexes backing the primary key and the UNIQUE column
     * constraint, reverse-engineers as a plain Index, same limitation as
     * MssqlSchemaParser/SqliteSchemaParser.
     */
    public function testNoIndexIsEverReverseEngineeredAsUnique()
    {
        foreach (self::database()->getTable('REV_AUTHOR')->getIndices() as $index) {
            $this->assertNotInstanceOf(Unique::class, $index, $index->getName() . ' should not be a Unique instance');
        }
        foreach (self::database()->getTable('REV_BOOK')->getIndices() as $index) {
            $this->assertNotInstanceOf(Unique::class, $index, $index->getName() . ' should not be a Unique instance');
        }
    }

    public function testPlainIndexIsReverseEngineered()
    {
        $table = self::database()->getTable('REV_BOOK');
        $index = null;
        foreach ($table->getIndices() as $idx) {
            if ($idx->getName() === 'REV_BOOK_PRICE_IDX') {
                $index = $idx;
            }
        }
        $this->assertNotNull($index);
        $this->assertSame(array('PRICE'), $index->getColumns());
    }

    /**
     * Oracle foreign keys have no separate "ON UPDATE" concept at all (T-SQL/
     * Postgres do, Oracle doesn't) -- addForeignKeys() approximates this by
     * copying the constraint's real DELETE_RULE into both onDelete and
     * onUpdate, so both come back CASCADE here even though only
     * "ON DELETE CASCADE" was ever declared.
     */
    public function testForeignKeyOnDeleteActionIsAlsoCopiedToOnUpdate()
    {
        $table = self::database()->getTable('REV_BOOK');
        $fks = $table->getForeignKeys();
        $this->assertCount(1, $fks);
        $fk = $fks[0];

        $this->assertSame('REV_AUTHOR', $fk->getForeignTableName());
        $this->assertSame(array('AUTHOR_ID'), $fk->getLocalColumns());
        $this->assertSame(array('ID'), $fk->getForeignColumns());
        $this->assertSame('CASCADE', $fk->getOnDelete());
        $this->assertSame('CASCADE', $fk->getOnUpdate(), 'documents a parser approximation, not a real Oracle ON UPDATE action -- see this test\'s docblock');
    }

    /**
     * parse()'s return value: the number of tables it added.
     *
     * Nothing else here checks it, and it is the one thing the shared
     * `database()` accessor above would otherwise throw away.
     */
    public function testParseReportsTheNumberOfTablesFound()
    {
        $database = self::database();

        $this->assertNotNull(self::$parsedTableCount);
        $this->assertGreaterThanOrEqual(2, self::$parsedTableCount, 'at least the two fixture tables');
        $this->assertSame(
            self::$parsedTableCount,
            count($database->getTables()),
            'the return value must match what was actually added'
        );
    }
}
