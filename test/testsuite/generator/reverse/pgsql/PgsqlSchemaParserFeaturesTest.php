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
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Platform\PgsqlPlatform;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\ForeignKey;

require_once dirname(__DIR__, 4) . '/tools/helpers/IntegrationDatabase.php';

/**
 * Postgres-specific reverse-engineering features that PgsqlSchemaParserTest's
 * single fixed two-table schema cannot reach, each needing a schema shaped for
 * it: DOMAIN-typed columns (a whole second catalog query, processDomain(), whose
 * result then feeds a different branch of addColumns() than an ordinary column
 * takes), the array-datatype rejection, the ON UPDATE/ON DELETE actions that
 * fixture's one foreign key does not use, and tables outside the `public`
 * schema.
 *
 * Its own database, not the one PgsqlSchemaParserTest uses: parse() reverse-
 * engineers every table in every non-system schema, so the two classes' fixtures
 * would otherwise show up in each other's results.
 */
class PgsqlSchemaParserFeaturesTest extends TestCase
{
    private const DB_NAME = 'propulsion_test_pgsql_parser_features';

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (IntegrationDatabase::currentPlatform() !== 'pgsql') {
            $this->markTestSkipped('Exercises PgsqlSchemaParser against a live Postgres server specifically, regardless of PROPULSION_TEST_DB.');
        }

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

        $this->dropFixtures();
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
        // Tables before the domains they use, and CASCADE on the schema since it
        // may hold a table of its own.
        $this->pdo->exec('DROP TABLE IF EXISTS feat_child');
        $this->pdo->exec('DROP TABLE IF EXISTS feat_parent');
        $this->pdo->exec('DROP TABLE IF EXISTS feat_arr');
        $this->pdo->exec('DROP SCHEMA IF EXISTS feat_other CASCADE');
        $this->pdo->exec('DROP DOMAIN IF EXISTS feat_email');
        $this->pdo->exec('DROP DOMAIN IF EXISTS feat_amount');
    }

    /**
     * Reverse-engineers the current contents of the scratch database.
     */
    private function parse(): Database
    {
        $database = new Database();
        $database->setPlatform(new PgsqlPlatform());
        $database->setDefaultIdMethod(IDMethod::NATIVE);

        $parser = new PgsqlSchemaParser($this->pdo);
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $parser->setPlatform(new PgsqlPlatform());
        $parser->parse($database);

        return $database;
    }

    /**
     * A DOMAIN column reports typtype='d', which sends addColumns() down its
     * domain branch: the column's real type, length and scale come from
     * processDomain()'s own lookup of the domain's base type rather than from
     * the column's own atttypmod.
     */
    public function testDomainColumnResolvesToItsBaseTypeAndLength()
    {
        $this->pdo->exec('CREATE DOMAIN feat_email AS VARCHAR(120)');
        $this->pdo->exec('CREATE TABLE feat_parent (id SERIAL PRIMARY KEY, email feat_email)');

        $column = $this->parse()->getTable('feat_parent')->getColumn('email');
        $this->assertSame(PropulsionTypes::VARCHAR, $column->getType());
        $this->assertEquals(120, $column->getSize());
    }

    /**
     * The precision/scale of a NUMERIC domain travels the same path -- decoded
     * from the *domain's* typtypmod by processLengthScale(), not the column's.
     */
    public function testNumericDomainColumnResolvesPrecisionAndScale()
    {
        $this->pdo->exec('CREATE DOMAIN feat_amount AS NUMERIC(9,3)');
        $this->pdo->exec('CREATE TABLE feat_parent (id SERIAL PRIMARY KEY, amount feat_amount)');

        $column = $this->parse()->getTable('feat_parent')->getColumn('amount');
        $this->assertSame(PropulsionTypes::DECIMAL, $column->getType());
        $this->assertEquals(9, $column->getSize());
        $this->assertEquals(3, $column->getScale());
    }

    /**
     * A domain can carry its own NOT NULL and DEFAULT, which the column
     * inherits: the domain branch falls back to the domain's typnotnull/
     * typdefault when the column itself declares neither.
     */
    public function testDomainColumnInheritsTheDomainsNotNullAndDefault()
    {
        $this->pdo->exec("CREATE DOMAIN feat_email AS VARCHAR(120) NOT NULL DEFAULT 'nobody@example.com'");
        $this->pdo->exec('CREATE TABLE feat_parent (id SERIAL PRIMARY KEY, email feat_email)');

        $column = $this->parse()->getTable('feat_parent')->getColumn('email');
        $this->assertTrue($column->isNotNull());
        $default = $column->getDefaultValue();
        $this->assertNotNull($default);
        $this->assertSame('nobody@example.com', $default->getValue());
    }

    /**
     * Array columns are rejected outright rather than reverse-engineered as
     * their element type, which would silently produce a schema that does not
     * describe the database.
     */
    public function testArrayColumnIsRejectedWithANamedError()
    {
        $this->pdo->exec('CREATE TABLE feat_arr (id SERIAL PRIMARY KEY, tags VARCHAR(20)[])');

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Array datatypes are not currently supported [feat_arr.tags]');
        $this->parse();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function foreignKeyActionProvider(): array
    {
        // Postgres stores each action as a single confupdtype/confdeltype char;
        // these are the spellings the parser's two switch statements decode.
        return array(
            'cascade' => array('ON UPDATE CASCADE', ForeignKey::CASCADE, ForeignKey::NONE),
            'restrict' => array('ON UPDATE RESTRICT', ForeignKey::RESTRICT, ForeignKey::NONE),
            'set null' => array('ON UPDATE SET NULL', ForeignKey::SETNULL, ForeignKey::NONE),
            'set default' => array('ON DELETE SET DEFAULT', ForeignKey::NONE, ForeignKey::SETDEFAULT),
            'delete restrict' => array('ON DELETE RESTRICT', ForeignKey::NONE, ForeignKey::RESTRICT),
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('foreignKeyActionProvider')]
    public function testForeignKeyActionsAreDecoded(string $clause, string $expectedOnUpdate, string $expectedOnDelete)
    {
        $this->pdo->exec('CREATE TABLE feat_parent (id INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE feat_child (id SERIAL PRIMARY KEY, parent_id INTEGER DEFAULT 0 REFERENCES feat_parent(id) ' . $clause . ')'
        );

        $fks = $this->parse()->getTable('feat_child')->getForeignKeys();
        $this->assertCount(1, $fks);
        $this->assertSame($expectedOnUpdate, $fks[0]->getOnUpdate());
        $this->assertSame($expectedOnDelete, $fks[0]->getOnDelete());
    }

    /**
     * NO ACTION is Postgres's default and the parser maps it to "no action
     * recorded", so an unqualified REFERENCES clause must leave both actions
     * empty rather than inventing one.
     */
    public function testForeignKeyWithoutActionsRecordsNone()
    {
        $this->pdo->exec('CREATE TABLE feat_parent (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE feat_child (id SERIAL PRIMARY KEY, parent_id INTEGER REFERENCES feat_parent(id))');

        $fks = $this->parse()->getTable('feat_child')->getForeignKeys();
        $this->assertCount(1, $fks);
        $this->assertSame(ForeignKey::NONE, $fks[0]->getOnUpdate());
        $this->assertSame(ForeignKey::NONE, $fks[0]->getOnDelete());
    }

    /**
     * Tables in the `public` schema are left unqualified -- that is the default
     * search path, so recording it would put a redundant schema on every table
     * of an ordinary single-schema database. Any other schema is recorded.
     */
    public function testTableOutsideThePublicSchemaRecordsItsSchema()
    {
        $this->pdo->exec('CREATE SCHEMA feat_other');
        $this->pdo->exec('CREATE TABLE feat_other.feat_scoped (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE feat_parent (id INTEGER PRIMARY KEY)');

        $database = $this->parse();
        // Postgres supports schemas, so a table carrying one is keyed (and named)
        // by its qualified name -- see Table::getName().
        $scoped = $database->getTable('feat_other.feat_scoped');
        $this->assertNotNull($scoped);
        $this->assertSame('feat_other', $scoped->getSchema());
        $this->assertSame('feat_scoped', $scoped->getCommonName());

        $public = $database->getTable('feat_parent');
        $this->assertNotNull($public);
        $this->assertNull($public->getSchema(), 'a public-schema table stays unqualified');
    }
}
