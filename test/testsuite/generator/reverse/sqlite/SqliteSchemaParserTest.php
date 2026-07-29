<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Reverse\SQLite\SqliteSchemaParser;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Platform\SqlitePlatform;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Unique;

/**
 * End-to-end coverage for SqliteSchemaParser, seeded with the same
 * non-trivial two-table schema shape as PgsqlSchemaParserTest (multiple
 * column types/defaults, a primary key, a single-column unique constraint
 * expressed via a UNIQUE column, an explicit unique index, a plain index,
 * and a REFERENCES clause) -- no testcontainer needed, SQLite is file/memory
 * based, so this just uses a plain in-memory PDO connection.
 *
 * Several assertions here lock in genuine limitations/quirks of the current
 * parser rather than idealized behavior -- see each test's own docblock.
 */
class SqliteSchemaParserTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Deliberately a plain "INTEGER PRIMARY KEY", not "INTEGER PRIMARY KEY
        // AUTOINCREMENT" -- the latter makes SQLite create an internal
        // sqlite_sequence bookkeeping table, which the parser doesn't filter
        // out (only the migration ledger table name is excluded), so it would
        // show up as a spurious extra "table" in the reverse-engineered result.
        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_author (
                id INTEGER PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) UNIQUE,
                bio TEXT,
                rating DECIMAL(3,2) DEFAULT 0.0,
                active BOOLEAN DEFAULT 1
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_book (
                id INTEGER PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                author_id INTEGER REFERENCES rev_author(id),
                price DECIMAL(10,2)
            )
        SQL);
        $this->pdo->exec('CREATE INDEX rev_book_price_idx ON rev_book (price)');
        $this->pdo->exec('CREATE UNIQUE INDEX rev_book_title_idx ON rev_book (title)');

        $this->database = new Database();
        $this->database->setPlatform(new SqlitePlatform());
        $this->database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = new SqliteSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new SqlitePlatform());
        $parser->parse($this->database);
    }

    public function testParseFindsBothTables()
    {
        $names = array_map(fn($t) => $t->getName(), $this->database->getTables());
        sort($names);
        $this->assertSame(array('rev_author', 'rev_book'), $names);
    }

    public function testIntegerPrimaryKeyIsDetectedAsAutoIncrement()
    {
        // Any "INTEGER PRIMARY KEY" column is a rowid alias in SQLite and
        // behaves as auto-increment even without the AUTOINCREMENT keyword
        // (see the parser's own comment, and https://sqlite.org/autoinc.html).
        $column = $this->database->getTable('rev_author')->getColumn('id');
        $this->assertTrue($column->isPrimaryKey());
        $this->assertTrue($column->isAutoIncrement());
        $this->assertSame(PropulsionTypes::INTEGER, $column->getType());
    }

    public function testVarcharColumnCapturesSizeAndNotNull()
    {
        $column = $this->database->getTable('rev_author')->getColumn('name');
        $this->assertSame(PropulsionTypes::VARCHAR, $column->getType());
        $this->assertEquals(100, $column->getSize());
        $this->assertTrue($column->isNotNull());
    }

    public function testNullableColumnIsNotMarkedNotNull()
    {
        $column = $this->database->getTable('rev_author')->getColumn('bio');
        $this->assertFalse($column->isNotNull());
    }

    public function testTextColumnMapsToLongVarChar()
    {
        $column = $this->database->getTable('rev_author')->getColumn('bio');
        $this->assertSame(PropulsionTypes::LONGVARCHAR, $column->getType());
    }

    /**
     * Locks in a genuine parser bug: addColumns()'s "TYPE(precision,scale)"
     * regex branch captures $precision into a local variable that is never
     * actually applied to the column (only $scale reaches
     * getDomain()->replaceSize()/replaceScale() -- replaceSize() is called
     * with the still-null $size, not $precision). So a DECIMAL(3,2) column
     * reverse-engineers with size=null, not size=3.
     */
    public function testDecimalColumnPrecisionIsLostButScaleIsCaptured()
    {
        $column = $this->database->getTable('rev_author')->getColumn('rating');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertNull($column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testDecimalColumnOnAnotherTableAlsoLosesPrecision()
    {
        $column = $this->database->getTable('rev_book')->getColumn('price');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertNull($column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    /**
     * SqliteSchemaParser's type map has no "boolean" entry at all (SQLite has
     * no native boolean type, and the map otherwise mirrors MySQL's type
     * names) -- an unrecognized type falls back to Column::DEFAULT_TYPE
     * (VARCHAR), same as any other unsupported column type.
     */
    public function testBooleanColumnFallsBackToDefaultVarcharType()
    {
        $column = $this->database->getTable('rev_author')->getColumn('active');
        $this->assertSame(Column::DEFAULT_TYPE, $column->getType());
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('1', $default->getValue());
    }

    public function testNumericDefaultValueIsCapturedAsRawText()
    {
        $column = $this->database->getTable('rev_author')->getColumn('rating');
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('0.0', $default->getValue());
    }

    /**
     * Locks in a second genuine parser bug: addColumns() marks a column
     * primary key if EITHER PRAGMA table_info() says pk=1 OR the column's
     * type is "integer" -- the second condition is unconditional, so any
     * plain INTEGER column (author_id here, a foreign key, not the table's
     * actual primary key) is incorrectly reported as a primary key too.
     */
    public function testAnyIntegerColumnIsIncorrectlyMarkedPrimaryKey()
    {
        $column = $this->database->getTable('rev_book')->getColumn('author_id');
        $this->assertSame(PropulsionTypes::INTEGER, $column->getType());
        $this->assertTrue($column->isPrimaryKey(), 'documents a parser bug, not desired behavior -- see this test\'s docblock');
        $this->assertFalse($column->isAutoIncrement(), 'only the pk=1 column, not every integer column, is treated as auto-increment');
    }

    /**
     * addIndexes() never reads PRAGMA index_list()'s own "unique" column, so
     * no index -- whether from a UNIQUE column constraint, an explicit
     * CREATE UNIQUE INDEX, or a plain CREATE INDEX -- is ever reverse-engineered
     * as a Unique instance; everything becomes a plain Index.
     */
    public function testNoIndexIsEverReverseEngineeredAsUnique()
    {
        $authorIndices = $this->database->getTable('rev_author')->getIndices();
        $this->assertCount(1, $authorIndices, 'the UNIQUE column constraint on email creates one implicit index');
        $this->assertNotInstanceOf(Unique::class, $authorIndices[0]);
        $this->assertSame(array('email'), $authorIndices[0]->getColumns());

        $bookIndices = $this->database->getTable('rev_book')->getIndices();
        foreach ($bookIndices as $index) {
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

    /**
     * SqliteSchemaParser has no addForeignKeys() at all -- parse() only ever
     * calls addColumns()/addIndexes() -- so a REFERENCES clause is completely
     * invisible to reverse engineering; it doesn't even show up as a plain
     * index the way it might on another platform.
     */
    public function testForeignKeysAreNotReverseEngineeredAtAll()
    {
        $table = $this->database->getTable('rev_book');
        $this->assertCount(0, $table->getForeignKeys());
    }
}
