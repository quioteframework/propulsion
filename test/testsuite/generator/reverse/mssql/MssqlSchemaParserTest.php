<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Reverse\MSSQL\MssqlSchemaParser;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Platform\MssqlPlatform;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Unique;

require_once dirname(__DIR__, 4) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end coverage for MssqlSchemaParser against a real MSSQL testcontainer,
 * seeded with the same non-trivial schema shape as PgsqlSchemaParserTest (two
 * related tables, multiple column types/defaults, an identity pk, a
 * single-column unique constraint, an explicit unique index, a plain index,
 * and a foreign key with non-default ON DELETE/ON UPDATE actions).
 *
 * Several assertions here lock in genuine limitations/quirks of the current
 * parser (confirmed against a live azure-sql-edge container, not assumed)
 * rather than idealized Postgres-parity behavior -- see each test's own
 * docblock.
 */
class MssqlSchemaParserTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_mssql_schema_parser';

    private ?PDO $pdo = null;
    private ?Database $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (IntegrationDatabase::currentPlatform() !== 'mssql') {
            $this->markTestSkipped('Exercises MssqlSchemaParser against a live MSSQL server specifically, regardless of PROPULSION_TEST_DB.');
        }

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $dsn = IntegrationDatabase::pdoDsn($conn['host'], $conn['port'], 'propulsion_test');
        [$user, $password] = IntegrationDatabase::pdoCredentials();
        $admin = new PDO($dsn, $user, $password);
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $admin->exec("IF DB_ID('" . self::DB_NAME . "') IS NOT NULL DROP DATABASE " . self::DB_NAME);
        $admin->exec('CREATE DATABASE ' . self::DB_NAME);

        $scratchDsn = IntegrationDatabase::pdoDsn($conn['host'], $conn['port'], self::DB_NAME);
        $this->pdo = new PDO($scratchDsn, $user, $password);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_author (
                id INT IDENTITY PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) UNIQUE,
                bio TEXT,
                rating DECIMAL(3,2) DEFAULT 0.0,
                active BIT DEFAULT 1
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_book (
                id INT IDENTITY PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                author_id INT,
                price DECIMAL(10,2),
                CONSTRAINT rev_book_author_fk FOREIGN KEY (author_id) REFERENCES rev_author(id) ON DELETE CASCADE
            )
        SQL);
        $this->pdo->exec('CREATE INDEX rev_book_price_idx ON rev_book (price)');
        $this->pdo->exec('CREATE UNIQUE INDEX rev_book_title_idx ON rev_book (title)');

        $this->database = new Database();
        $this->database->setPlatform(new MssqlPlatform());
        $this->database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = new MssqlSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new MssqlPlatform());
        $parser->parse($this->database);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS rev_book');
            $this->pdo->exec('DROP TABLE IF EXISTS rev_author');
        }
        parent::tearDown();
    }

    public function testParseFindsBothTables()
    {
        $names = array_map(fn($t) => $t->getName(), $this->database->getTables());
        sort($names);
        $this->assertSame(array('rev_author', 'rev_book'), $names);
    }

    public function testIdentityPrimaryKeyIsDetectedAsAutoIncrement()
    {
        $column = $this->database->getTable('rev_author')->getColumn('id');
        $this->assertTrue($column->isPrimaryKey());
        $this->assertTrue($column->isAutoIncrement());
        $this->assertSame(PropulsionTypes::INTEGER, $column->getType());
    }

    /**
     * addColumns()'s auto-increment check is `strtolower($type) == "int
     * identity"` -- an exact-string match against sp_columns' TYPE_NAME,
     * which only ever equals that for a plain INT IDENTITY column. A BIGINT
     * IDENTITY column reports TYPE_NAME "bigint identity" (confirmed against
     * a live server), which the type map still correctly resolves to BIGINT,
     * but the narrower auto-increment check never matches it -- so it's
     * never flagged as auto-increment, unlike every other reverse-engineered
     * platform in this suite.
     */
    public function testBigintIdentityColumnIsNotDetectedAsAutoIncrement()
    {
        $this->pdo->exec('CREATE TABLE rev_bigid (id BIGINT IDENTITY PRIMARY KEY, val VARCHAR(10))');
        try {
            $database = new Database();
            $database->setPlatform(new MssqlPlatform());
            $database->setDefaultIdMethod(IDMethod::NATIVE);
            $parser = new MssqlSchemaParser($this->pdo);
            $parser->setGeneratorConfig(new QuickGeneratorConfig());
            $parser->setPlatform(new MssqlPlatform());
            $parser->parse($database);

            $column = $database->getTable('rev_bigid')->getColumn('id');
            $this->assertSame(PropulsionTypes::BIGINT, $column->getType());
            $this->assertFalse($column->isAutoIncrement(), 'documents a parser limitation, not desired behavior -- see this test\'s docblock');
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS rev_bigid');
        }
    }

    public function testVarcharColumnCapturesSizeAndNotNull()
    {
        $column = $this->database->getTable('rev_author')->getColumn('name');
        $this->assertSame(PropulsionTypes::VARCHAR, $column->getType());
        $this->assertEquals(100, $column->getSize());
        $this->assertTrue($column->isNotNull());
    }

    /**
     * Confirmed against a live server: pdo_dblib's sp_columns reports
     * NULLABLE=0 for every single column here, including email/bio (neither
     * declared NOT NULL) -- addColumns() reads that value as-is
     * (`setNotNull(!$is_nullable)`), so every reverse-engineered MSSQL column
     * comes back marked NOT NULL regardless of its real nullability. This
     * documents that limitation rather than the nullable=true one would
     * expect from Postgres/MySQL.
     */
    public function testNullableColumnIsNeverthelessMarkedNotNull()
    {
        $column = $this->database->getTable('rev_author')->getColumn('email');
        $this->assertTrue($column->isNotNull(), 'documents a parser/driver limitation, not desired behavior -- see this test\'s docblock');
    }

    public function testTextColumnMapsToLongVarChar()
    {
        $column = $this->database->getTable('rev_author')->getColumn('bio');
        $this->assertSame(PropulsionTypes::LONGVARCHAR, $column->getType());
    }

    /**
     * Confirmed against a live server: addColumns() sets the column's size
     * from sp_columns' LENGTH field, not its PRECISION field. For a
     * DECIMAL(3,2) column, PRECISION correctly reports 3, but LENGTH reports
     * 5 (T-SQL's display-width byte count for the value, i.e. precision + 2
     * for the sign and decimal point) -- so the reverse-engineered column's
     * size ends up 2 higher than the column's real declared precision.
     */
    public function testDecimalColumnSizeIsPrecisionPlusTwoNotRawPrecision()
    {
        $column = $this->database->getTable('rev_author')->getColumn('rating');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(5, $column->getSize(), 'documents a parser limitation (LENGTH used instead of PRECISION), not desired behavior -- see this test\'s docblock');
        $this->assertEquals(2, $column->getScale());
    }

    public function testDecimalColumnWithDifferentPrecisionOnAnotherTable()
    {
        $column = $this->database->getTable('rev_book')->getColumn('price');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(12, $column->getSize(), 'DECIMAL(10,2) -> LENGTH 12, same +2 offset as rev_author.rating');
        $this->assertEquals(2, $column->getScale());
    }

    public function testBitColumnMapsToBooleanAndCapturesDefault()
    {
        $column = $this->database->getTable('rev_author')->getColumn('active');
        $this->assertSame(PropulsionTypes::BOOLEAN, $column->getType());
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        // T-SQL wraps a column default constraint's expression in parens (and
        // sp_columns' COLUMN_DEF returns it as-is, unprocessed) -- "1", not "((1))".
        $this->assertSame('((1))', $default->getValue());
    }

    public function testNumericDefaultValueIsCapturedWithTsqlParens()
    {
        $column = $this->database->getTable('rev_author')->getColumn('rating');
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('((0.0))', $default->getValue());
    }

    /**
     * addIndexes() has a "FIXME -- Add UNIQUE support" comment and never
     * reads any uniqueness information at all -- every index, including
     * SQL Server's own auto-generated indexes backing the primary key and
     * the UNIQUE column constraint, reverse-engineers as a plain Index.
     */
    public function testNoIndexIsEverReverseEngineeredAsUnique()
    {
        foreach ($this->database->getTable('rev_author')->getIndices() as $index) {
            $this->assertNotInstanceOf(Unique::class, $index, $index->getName() . ' should not be a Unique instance');
        }
        foreach ($this->database->getTable('rev_book')->getIndices() as $index) {
            $this->assertNotInstanceOf(Unique::class, $index, $index->getName() . ' should not be a Unique instance');
        }
    }

    public function testPlainIndexIsReverseEngineered()
    {
        $table = $this->database->getTable('rev_book');
        $index = null;
        foreach ($table->getIndices() as $idx) {
            if ($idx->getName() === 'rev_book_price_idx') {
                $index = $idx;
            }
        }
        $this->assertNotNull($index);
        $this->assertSame(array('price'), $index->getColumns());
    }

    public function testForeignKeyIsReverseEngineeredWithoutActions()
    {
        $table = $this->database->getTable('rev_book');
        $fks = $table->getForeignKeys();
        $this->assertCount(1, $fks);
        $fk = $fks[0];

        $this->assertSame('rev_author', $fk->getForeignTableName());
        $localColumns = $fk->getLocalColumns();
        $foreignColumns = $fk->getForeignColumns();
        $this->assertSame(array('author_id'), $localColumns);
        $this->assertSame(array('id'), $foreignColumns);

        // addForeignKeys() has both setOnDelete()/setOnUpdate() calls commented
        // out -- confirmed against a live server, even though this table's
        // FK genuinely has "ON DELETE CASCADE", neither action is ever
        // reverse-engineered.
        $this->assertFalse($fk->hasOnDelete(), 'documents a parser limitation, not desired behavior -- see this test\'s docblock');
        $this->assertFalse($fk->hasOnUpdate());
    }
}
