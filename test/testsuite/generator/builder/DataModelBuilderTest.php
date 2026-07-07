<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Builder\OM\AbstractObjectBuilder;
use Propulsion\Generator\Builder\OM\QueryBuilder;
use Propulsion\Generator\Builder\SQL\DataSQLBuilder;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;

/**
 * Coverage for DataModelBuilder's public convenience methods
 * (getInterfaceBuilder(), getDataSQLBuilder(), getNewQueryBuilder(), setTable(),
 * warn()/getWarnings(), quoteIdentifier()'s disableIdentifierQuoting branch, and
 * configureBuilder()'s misconfiguration guard). None of these are called by any
 * other code in the generator itself -- they're public API surface for
 * consumers of this library rather than internally-used helpers -- so nothing
 * else in the test suite exercises them as a side effect.
 */
class DataModelBuilderTest extends TestCase
{
    private function buildTableWithConfig(): Table
    {
        $config = GeneratorConfig::createFromPropertiesFile(
            dirname(__DIR__, 4) . '/generator/default.php',
            null,
            ['propulsion.database' => 'mysql']
        );
        $appData = new AppData($config->getConfiguredPlatform());
        $appData->setGeneratorConfig($config);
        $database = new Database('foodb');
        $database->setPlatform($config->getConfiguredPlatform());
        $appData->addDatabase($database);
        $table = new Table('foo');
        $database->addTable($table);
        $table->addColumn(array('name' => 'id', 'type' => 'INTEGER', 'primaryKey' => 'true'));

        return $table;
    }

    public function testGetInterfaceBuilderReturnsConfiguredBuilder()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        $builder->setGeneratorConfig($table->getGeneratorConfig());

        $interfaceBuilder = $builder->getInterfaceBuilder();
        $this->assertInstanceOf(AbstractObjectBuilder::class, $interfaceBuilder);
        // Cached: calling again returns the same instance.
        $this->assertSame($interfaceBuilder, $builder->getInterfaceBuilder());
    }

    public function testGetDataSQLBuilderReturnsConfiguredBuilder()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        $builder->setGeneratorConfig($table->getGeneratorConfig());

        $dataSqlBuilder = $builder->getDataSQLBuilder();
        $this->assertInstanceOf(DataSQLBuilder::class, $dataSqlBuilder);
        $this->assertSame($dataSqlBuilder, $builder->getDataSQLBuilder());
    }

    public function testGetNewQueryBuilderReturnsQueryBuilderForGivenTable()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        $builder->setGeneratorConfig($table->getGeneratorConfig());

        $queryBuilder = $builder->getNewQueryBuilder($table);
        $this->assertInstanceOf(QueryBuilder::class, $queryBuilder);
        $this->assertSame($table, $queryBuilder->getTable());
    }

    public function testConfigureBuilderThrowsWhenConfiguredClassDoesNotMatchExpectedType()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        // QueryBuilder doesn't extend DataSQLBuilder, so requesting the
        // 'datasql' target with that mismatched expectation should be rejected.
        $config = $table->getGeneratorConfig();
        $config->setBuildProperty('builderDatasqlClass', QueryBuilder::class);
        $builder->setGeneratorConfig($config);

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage("does not extend");
        $builder->getDataSQLBuilder();
    }

    public function testSetTableChangesTheCurrentTable()
    {
        $table = $this->buildTableWithConfig();
        $otherTable = new Table('bar');
        $table->getDatabase()->addTable($otherTable);

        $builder = new ObjectBuilder($table);
        $builder->setTable($otherTable);
        $this->assertSame($otherTable, $builder->getTable());
    }

    public function testWarnAccumulatesWarnings()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        $this->assertSame(array(), $builder->getWarnings());

        $r = new ReflectionMethod($builder, 'warn');
        $r->invoke($builder, 'first warning');
        $r->invoke($builder, 'second warning');

        $this->assertSame(array('first warning', 'second warning'), $builder->getWarnings());
    }

    public function testQuoteIdentifierQuotesByDefault()
    {
        $table = $this->buildTableWithConfig();
        $builder = new ObjectBuilder($table);
        $builder->setGeneratorConfig($table->getGeneratorConfig());

        $this->assertNotSame('foo', $builder->quoteIdentifier('foo'));
    }

    public function testQuoteIdentifierReturnsTextUnchangedWhenDisabled()
    {
        $table = $this->buildTableWithConfig();
        $config = $table->getGeneratorConfig();
        $config->setBuildProperty('disableIdentifierQuoting', true);
        $builder = new ObjectBuilder($table);
        $builder->setGeneratorConfig($config);

        $this->assertSame('foo', $builder->quoteIdentifier('foo'));
    }
}
