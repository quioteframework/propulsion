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

    public function parse(\Propulsion\Generator\Model\Database $database, mixed $task = null)
    {
        return 0;
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
}
