<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Reverse\PgSQL\PgsqlSchemaParser;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Platform\PgsqlPlatform;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Unique;
use Propulsion\Generator\Model\Index;

require_once dirname(__DIR__, 4) . '/tools/helpers/IntegrationDatabase.php';

/**
 * End-to-end coverage for PgsqlSchemaParser against a real Postgres testcontainer,
 * seeded with a deliberately non-trivial schema: two related tables covering
 * multiple column types/defaults, a serial (auto-increment) pk, a single-column
 * unique constraint, a composite unique constraint, a plain non-unique index,
 * and a foreign key with non-default ON UPDATE/ON DELETE actions -- none of
 * which the existing schema:reverse command test (a single PK+VARCHAR table)
 * exercises. In particular this locks in the NUMERIC(p,s) precision/scale
 * decoding fix documented in processLengthScale()'s own comment (checking
 * against PropulsionTypes::DECIMAL, not the never-produced ::NUMERIC).
 */
class PgsqlSchemaParserTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_pgsql_schema_parser';

    private ?PDO $pdo = null;
    private ?Database $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $conn = IntegrationDatabase::containerConnection();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $adminDsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
        $admin = new PDO($adminDsn, 'propulsion', 'propulsion');
        $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$admin->query('SELECT 1 FROM pg_database WHERE datname = ' . $admin->quote(self::DB_NAME))->fetchColumn()) {
            $admin->exec('CREATE DATABASE ' . self::DB_NAME);
        }

        $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=" . self::DB_NAME;
        $this->pdo = new PDO($dsn, 'propulsion', 'propulsion');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('DROP TABLE IF EXISTS rev_book');
        $this->pdo->exec('DROP TABLE IF EXISTS rev_author');

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_author (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) UNIQUE,
                bio TEXT,
                rating NUMERIC(3,2) DEFAULT 0.0,
                active BOOLEAN DEFAULT true,
                created_at TIMESTAMP DEFAULT now()
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE rev_book (
                id SERIAL PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                author_id INTEGER REFERENCES rev_author(id) ON DELETE CASCADE ON UPDATE SET NULL,
                price NUMERIC(10,2),
                CONSTRAINT rev_book_title_author_uniq UNIQUE (title, author_id)
            )
        SQL);
        $this->pdo->exec('CREATE INDEX rev_book_price_idx ON rev_book (price)');

        $this->database = new Database();
        $this->database->setPlatform(new PgsqlPlatform());
        $this->database->setDefaultIdMethod(\Propulsion\Generator\Model\IDMethod::NATIVE);

        $parser = new PgsqlSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new PgsqlPlatform());
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

    public function testSerialPrimaryKeyIsDetectedAsAutoIncrement()
    {
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

    public function testNumericColumnDecodesPrecisionAndScale()
    {
        // Locks in the processLengthScale() fix: NUMERIC(3,2) must decode to
        // size=3/scale=2, not the raw packed typmod integer.
        $column = $this->database->getTable('rev_author')->getColumn('rating');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(3, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testNumericColumnWithDifferentPrecisionAndScaleOnAnotherTable()
    {
        $column = $this->database->getTable('rev_book')->getColumn('price');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(10, $column->getSize());
        $this->assertEquals(2, $column->getScale());
    }

    public function testBooleanColumnDefaultValueIsCaptured()
    {
        $column = $this->database->getTable('rev_author')->getColumn('active');
        $this->assertSame(PropulsionTypes::BOOLEAN, $column->getType());
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('true', $default->getValue());
    }

    public function testExpressionDefaultValueIsCapturedAsExpression()
    {
        $column = $this->database->getTable('rev_author')->getColumn('created_at');
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertTrue($default->isExpression());
        $this->assertSame('now()', $default->getValue());
    }

    public function testSingleColumnUniqueConstraintIsReverseEngineeredAsUniqueIndex()
    {
        $table = $this->database->getTable('rev_author');
        $uniques = array_filter($table->getIndices(), fn($idx) => $idx->isUnique());
        $this->assertCount(1, $uniques);
        $unique = array_shift($uniques);
        $this->assertInstanceOf(Unique::class, $unique);
        $columnNames = $unique->getColumns();
        $this->assertSame(array('email'), $columnNames);
    }

    public function testCompositeUniqueConstraintCoversBothColumns()
    {
        $table = $this->database->getTable('rev_book');
        $unique = null;
        foreach ($table->getIndices() as $idx) {
            if ($idx->isUnique()) {
                $unique = $idx;
            }
        }
        $this->assertNotNull($unique);
        $columnNames = $unique->getColumns();
        sort($columnNames);
        $this->assertSame(array('author_id', 'title'), $columnNames);
    }

    public function testPlainIndexIsReverseEngineeredAsNonUniqueIndex()
    {
        $table = $this->database->getTable('rev_book');
        $plain = null;
        foreach ($table->getIndices() as $idx) {
            if (!$idx->isUnique()) {
                $plain = $idx;
            }
        }
        $this->assertNotNull($plain);
        $this->assertInstanceOf(Index::class, $plain);
        $this->assertNotInstanceOf(Unique::class, $plain);
        $columnNames = $plain->getColumns();
        $this->assertSame(array('price'), $columnNames);
    }

    public function testForeignKeyIsReverseEngineeredWithCorrectActions()
    {
        $table = $this->database->getTable('rev_book');
        $fks = $table->getForeignKeys();
        $this->assertCount(1, $fks);
        $fk = $fks[0];

        $this->assertSame('rev_author', $fk->getForeignTableName());
        $this->assertSame(ForeignKey::CASCADE, $fk->getOnDelete());
        $this->assertSame(ForeignKey::SETNULL, $fk->getOnUpdate());

        $localColumns = $fk->getLocalColumns();
        $foreignColumns = $fk->getForeignColumns();
        $this->assertSame(array('author_id'), $localColumns);
        $this->assertSame(array('id'), $foreignColumns);
    }
}
