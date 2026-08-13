<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Reverse\BaseSchemaParser;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Exception\EngineException;

class TestableBaseSchemaParser extends BaseSchemaParser
{
    protected function getTypeMapping(): array
    {
        return array(
            'INT' => 'INTEGER',
            'VARCHAR' => 'VARCHAR',
        );
    }

    public function callGetMappedPropulsionType($nativeType)
    {
        return $this->getMappedPropulsionType($nativeType);
    }

    public function callGetMappedNativeType($propelType)
    {
        return $this->getMappedNativeType($propelType);
    }

    public function callGetNewVendorInfoObject(array $params)
    {
        return $this->getNewVendorInfoObject($params);
    }

    public function callWarn(string $msg): void
    {
        $this->warn($msg);
    }

    public function callRequireConnection(): \PDO
    {
        return $this->requireConnection();
    }

    public function callQueryOrFail(string $sql): \PDOStatement
    {
        return $this->queryOrFail($sql);
    }

    public function callPrepareOrFail(string $sql): \PDOStatement
    {
        return $this->prepareOrFail($sql);
    }

    public function callLogTask(mixed $task, string $msg, int $level = 4): void
    {
        $this->logTask($task, $msg, $level);
    }

    public function callRowValueToString(mixed $value): string
    {
        return self::rowValueToString($value);
    }

    public function callRowValueIsPresent(mixed $value): bool
    {
        return self::rowValueIsPresent($value);
    }

    public function callRowValueIsPgTrue(mixed $value): bool
    {
        return self::rowValueIsPgTrue($value);
    }

    public function callRowValueToIntStringOrNull(mixed $value): int|string|null
    {
        return self::rowValueToIntStringOrNull($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function callFetchAssoc(\PDOStatement $stmt): ?array
    {
        return $this->fetchAssoc($stmt);
    }

    /**
     * @return array<int, mixed>|null
     */
    public function callFetchNum(\PDOStatement $stmt): ?array
    {
        return $this->fetchNum($stmt);
    }

    public function callRequireStatement(\PDOStatement|false $stmt, string $query): \PDOStatement
    {
        return $this->requireStatement($stmt, $query);
    }

    public function parse(\Propulsion\Generator\Model\Database $database, mixed $task = null)
    {
        return 0;
    }
}

/**
 * A PDO whose query()/prepare() report failure through the `false` return value
 * rather than by throwing, which is what queryOrFail()/prepareOrFail() exist to
 * translate into an exception. A real connection cannot stand in here: this
 * project runs with PDO::ERRMODE_EXCEPTION, under which those methods raise
 * PDOException instead of ever returning false -- so the guards' own failure
 * branches are unreachable through any real driver, which is precisely why they
 * are documented as indicating a driver inconsistency.
 */
class FailingQueryPDO extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }

    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        return false;
    }
}

/**
 * Returns a row whose keys are not the string keys PDO::FETCH_ASSOC promises (and
 * not an array at all once exhausted), so fetchAssoc()/fetchNum()'s shape
 * validation -- which exists because PDOStatement::fetch() is typed `mixed` and
 * cannot be verified statically -- can be exercised for real instead of being
 * assumed correct. Instantiated by PDO via ATTR_STATEMENT_CLASS, since
 * PDOStatement cannot be constructed directly.
 */
class MalformedRowStatement extends PDOStatement
{
    /** @var array<int, mixed> */
    public static array $rows = array();

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (self::$rows === array()) {
            return false;
        }
        return array_shift(self::$rows);
    }
}

/**
 * Test class for BaseSchemaParser.
 */
class BaseSchemaParserTest extends TestCase
{
    public function testConstructorSetsConnection()
    {
        $pdo = new PDO('sqlite::memory:');
        $parser = new TestableBaseSchemaParser($pdo);
        $this->assertSame($pdo, $parser->getConnection());
    }

    public function testSetAndGetConnection()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertNull($parser->getConnection());
        $pdo = new PDO('sqlite::memory:');
        $parser->setConnection($pdo);
        $this->assertSame($pdo, $parser->getConnection());
    }

    public function testMigrationTableDefaultAndSetter()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('propulsion_migration', $parser->getMigrationTable());
        $parser->setMigrationTable('my_migrations');
        $this->assertSame('my_migrations', $parser->getMigrationTable());
    }

    public function testWarnAccumulatesWarnings()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame(array(), $parser->getWarnings());
        $parser->callWarn('first warning');
        $parser->callWarn('second warning');
        $this->assertSame(array('first warning', 'second warning'), $parser->getWarnings());
    }

    public function testGetBuildPropertyReturnsNullWithoutGeneratorConfig()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertNull($parser->getBuildProperty('foo'));
    }

    public function testSetAndGetGeneratorConfig()
    {
        $parser = new TestableBaseSchemaParser();
        $config = GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.php',
            null,
            array('propulsion.database' => 'mysql')
        );
        $parser->setGeneratorConfig($config);
        $this->assertSame($config, $parser->getGeneratorConfig());
    }

    public function testGetMappedPropulsionTypeReturnsMappedType()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('INTEGER', $parser->callGetMappedPropulsionType('INT'));
    }

    public function testGetMappedPropulsionTypeReturnsNullForUnknownType()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertNull($parser->callGetMappedPropulsionType('UNKNOWN_TYPE'));
    }

    public function testGetMappedNativeTypeReturnsReverseMappedType()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('INT', $parser->callGetMappedNativeType('INTEGER'));
    }

    public function testGetMappedNativeTypeReturnsNullForUnknownType()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertNull($parser->callGetMappedNativeType('UNKNOWN_TYPE'));
    }

    public function testGetNewVendorInfoObjectUsesPlatformDatabaseType()
    {
        $parser = new TestableBaseSchemaParser();
        $parser->setPlatform(new MysqlPlatform());
        $vi = $parser->callGetNewVendorInfoObject(array('foo' => 'bar'));
        $this->assertSame('mysql', $vi->getType());
        $this->assertSame('bar', $vi->getParameter('foo'));
    }

    public function testSetAndGetPlatform()
    {
        $parser = new TestableBaseSchemaParser();
        $platform = new MysqlPlatform();
        $parser->setPlatform($platform);
        $this->assertSame($platform, $parser->getPlatform());
    }

    public function testGetBuildPropertyDelegatesToGeneratorConfig()
    {
        $parser = new TestableBaseSchemaParser();
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        // Any property the default build properties define; the point is that the
        // call is forwarded rather than short-circuiting to null as it does with
        // no config attached (see testGetBuildPropertyReturnsNullWithoutGeneratorConfig).
        $this->assertNotNull($parser->getBuildProperty('targetPackage'));
    }

    public function testRequireConnectionReturnsTheAttachedConnection()
    {
        $pdo = new PDO('sqlite::memory:');
        $parser = new TestableBaseSchemaParser($pdo);
        $this->assertSame($pdo, $parser->callRequireConnection());
    }

    public function testRequireConnectionThrowsWhenNoConnectionWasSet()
    {
        $parser = new TestableBaseSchemaParser();
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('TestableBaseSchemaParser has no database connection; call setConnection() before parsing.');
        $parser->callRequireConnection();
    }

    public function testQueryOrFailReturnsTheStatementOnSuccess()
    {
        $parser = new TestableBaseSchemaParser(new PDO('sqlite::memory:'));
        $this->assertInstanceOf(PDOStatement::class, $parser->callQueryOrFail('SELECT 1'));
    }

    public function testQueryOrFailThrowsWhenTheDriverReturnsFalse()
    {
        $parser = new TestableBaseSchemaParser(new FailingQueryPDO());
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Query failed: SELECT 1');
        $parser->callQueryOrFail('SELECT 1');
    }

    public function testPrepareOrFailReturnsTheStatementOnSuccess()
    {
        $parser = new TestableBaseSchemaParser(new PDO('sqlite::memory:'));
        $this->assertInstanceOf(PDOStatement::class, $parser->callPrepareOrFail('SELECT 1'));
    }

    public function testPrepareOrFailThrowsWhenTheDriverReturnsFalse()
    {
        $parser = new TestableBaseSchemaParser(new FailingQueryPDO());
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Failed to prepare statement: SELECT 1');
        $parser->callPrepareOrFail('SELECT 1');
    }

    public function testRequireStatementPassesARealStatementThrough()
    {
        $parser = new TestableBaseSchemaParser();
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->query('SELECT 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame($stmt, $parser->callRequireStatement($stmt, 'SELECT 1'));
    }

    public function testRequireStatementThrowsOnTheFalseSentinel()
    {
        $parser = new TestableBaseSchemaParser();
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('Query failed: SELECT bogus');
        $parser->callRequireStatement(false, 'SELECT bogus');
    }

    public function testLogTaskCallsLogOnAnObjectThatHasIt()
    {
        $parser = new TestableBaseSchemaParser();
        $task = new class {
            /** @var array<int, array{string, int}> */
            public array $logged = array();

            public function log(string $msg, int $level): void
            {
                $this->logged[] = array($msg, $level);
            }
        };
        $parser->callLogTask($task, 'reverse engineering', 2);
        $this->assertSame(array(array('reverse engineering', 2)), $task->logged);
    }

    /**
     * parse()'s only real caller passes null, so this is the path taken on every
     * ordinary run -- it must not attempt the call.
     */
    public function testLogTaskIsANoOpForNullAndForAnObjectWithoutLog()
    {
        $parser = new TestableBaseSchemaParser();
        $parser->callLogTask(null, 'ignored');
        $parser->callLogTask(new stdClass(), 'ignored');
        $this->expectNotToPerformAssertions();
    }

    public function testRowValueToStringStringifiesScalars()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('abc', $parser->callRowValueToString('abc'));
        $this->assertSame('42', $parser->callRowValueToString(42));
        $this->assertSame('1', $parser->callRowValueToString(true));
        $this->assertSame('0.5', $parser->callRowValueToString(0.5));
    }

    /**
     * The empty-string fallback is the whole point of the helper: an unguarded
     * (string) cast on a non-scalar would raise a TypeError instead.
     */
    public function testRowValueToStringFallsBackToEmptyStringForNullAndNonScalars()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('', $parser->callRowValueToString(null));
        $this->assertSame('', $parser->callRowValueToString(array('a')));
        $this->assertSame('', $parser->callRowValueToString(new stdClass()));
    }

    public function testRowValueIsPresentTreatsNullAndEmptyStringAsAbsent()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertFalse($parser->callRowValueIsPresent(null));
        $this->assertFalse($parser->callRowValueIsPresent(''));
        $this->assertFalse($parser->callRowValueIsPresent(array('a')), 'a non-scalar stringifies to "", so it is absent too');
    }

    /**
     * "0" and " " are present: the helper's contract is emptiness of the
     * stringified value, not truthiness -- a "0" default value or a
     * single-space CHAR default is real information a parser must keep.
     */
    public function testRowValueIsPresentTreatsZeroAndWhitespaceAsPresent()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertTrue($parser->callRowValueIsPresent('0'));
        $this->assertTrue($parser->callRowValueIsPresent(0));
        $this->assertTrue($parser->callRowValueIsPresent(' '));
        $this->assertTrue($parser->callRowValueIsPresent('x'));
    }

    public function testRowValueIsPgTrueReadsNativeBooleans()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertTrue($parser->callRowValueIsPgTrue(true));
        $this->assertFalse($parser->callRowValueIsPgTrue(false));
    }

    public function testRowValueIsPgTrueReadsIntegers()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertTrue($parser->callRowValueIsPgTrue(1));
        $this->assertFalse($parser->callRowValueIsPgTrue(0));
    }

    /**
     * The three string spellings a driver/configuration can hand back for a
     * Postgres `boolean`, plus the false spellings. 't' is the wire-protocol
     * form; '1' is what an already-stringified true becomes, which the plain
     * `$value == 't'` comparison the docblock warns about would get wrong.
     */
    public function testRowValueIsPgTrueReadsTheStringSpellings()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertTrue($parser->callRowValueIsPgTrue('t'));
        $this->assertTrue($parser->callRowValueIsPgTrue('true'));
        $this->assertTrue($parser->callRowValueIsPgTrue('1'));
        $this->assertFalse($parser->callRowValueIsPgTrue('f'));
        $this->assertFalse($parser->callRowValueIsPgTrue('false'));
        $this->assertFalse($parser->callRowValueIsPgTrue('0'));
        $this->assertFalse($parser->callRowValueIsPgTrue(''));
    }

    public function testRowValueIsPgTrueFallsBackToACastForOtherTypes()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertTrue($parser->callRowValueIsPgTrue(1.5));
        $this->assertFalse($parser->callRowValueIsPgTrue(0.0));
        $this->assertFalse($parser->callRowValueIsPgTrue(null));
    }

    public function testRowValueToIntStringOrNullPassesThroughItsTargetShape()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertNull($parser->callRowValueToIntStringOrNull(null));
        $this->assertSame(10, $parser->callRowValueToIntStringOrNull(10));
        $this->assertSame('10', $parser->callRowValueToIntStringOrNull('10'));
    }

    public function testRowValueToIntStringOrNullStringifiesOtherScalarsAndDropsTheRest()
    {
        $parser = new TestableBaseSchemaParser();
        $this->assertSame('2.5', $parser->callRowValueToIntStringOrNull(2.5));
        $this->assertSame('1', $parser->callRowValueToIntStringOrNull(true));
        $this->assertNull($parser->callRowValueToIntStringOrNull(array('a')));
        $this->assertNull($parser->callRowValueToIntStringOrNull(new stdClass()));
    }

    public function testFetchAssocReturnsAStringKeyedRowAndThenNull()
    {
        $parser = new TestableBaseSchemaParser();
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->query("SELECT 'x' AS name, 1 AS num");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(array('name' => 'x', 'num' => 1), $parser->callFetchAssoc($stmt));
        $this->assertNull($parser->callFetchAssoc($stmt), 'null once the result set is exhausted');
    }

    public function testFetchNumReturnsAnIntKeyedRowAndThenNull()
    {
        $parser = new TestableBaseSchemaParser();
        $pdo = new PDO('sqlite::memory:');
        $stmt = $pdo->query("SELECT 'x', 1");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(array(0 => 'x', 1 => 1), $parser->callFetchNum($stmt));
        $this->assertNull($parser->callFetchNum($stmt), 'null once the result set is exhausted');
    }

    /**
     * The shape validation is not decoration: each helper keeps only the keys of
     * the type it promises its callers, so a row that mixes them cannot leak an
     * int-keyed entry into an array<string, mixed> (or vice versa).
     */
    public function testFetchHelpersDropKeysOfTheWrongType()
    {
        $parser = new TestableBaseSchemaParser();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(MalformedRowStatement::class));

        MalformedRowStatement::$rows = array(array('name' => 'x', 0 => 'positional'));
        $stmt = $pdo->query('SELECT 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(array('name' => 'x'), $parser->callFetchAssoc($stmt));

        MalformedRowStatement::$rows = array(array('name' => 'x', 0 => 'positional'));
        $this->assertSame(array(0 => 'positional'), $parser->callFetchNum($stmt));
    }

    public function testFetchHelpersReturnNullWhenTheDriverReturnsANonArray()
    {
        $parser = new TestableBaseSchemaParser();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(MalformedRowStatement::class));
        $stmt = $pdo->query('SELECT 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);

        MalformedRowStatement::$rows = array();
        $this->assertNull($parser->callFetchAssoc($stmt));
        $this->assertNull($parser->callFetchNum($stmt));
    }

    /**
     * getPlatform() auto-configures from the GeneratorConfig when none was set
     * explicitly, which is how SchemaReverseManager drives a parser.
     */
    public function testGetPlatformAutoConfiguresFromTheGeneratorConfig()
    {
        $parser = new TestableBaseSchemaParser();
        $parser->setGeneratorConfig(GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.php',
            null,
            array('propulsion.database' => 'mysql')
        ));
        $platform = $parser->getPlatform();
        $this->assertInstanceOf(MysqlPlatform::class, $platform);
        $this->assertSame($platform, $parser->getPlatform(), 'the auto-configured platform is memoized');
    }

    public function testGetPlatformThrowsWithNoPlatformAndNoGeneratorConfig()
    {
        $parser = new TestableBaseSchemaParser();
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('the configured GeneratorConfig (none) does not support getConfiguredPlatform()');
        $parser->getPlatform();
    }

    /**
     * QuickGeneratorConfig implements GeneratorConfigInterface but is not a
     * GeneratorConfig, so it has no getConfiguredPlatform() to auto-configure
     * from -- the parser must say so rather than fail later on a null platform.
     */
    public function testGetPlatformThrowsWhenTheGeneratorConfigCannotConfigureOne()
    {
        $parser = new TestableBaseSchemaParser();
        $parser->setGeneratorConfig(new QuickGeneratorConfig());
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage(QuickGeneratorConfig::class . ') does not support getConfiguredPlatform()');
        $parser->getPlatform();
    }
}
