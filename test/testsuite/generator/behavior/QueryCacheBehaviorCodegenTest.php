<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * QueryCacheBehavior has no existing test at all (no fixture table uses it).
 * Builds a schema with the behavior applied and checks the generated query
 * class gets the expected caching methods.
 */
class QueryCacheBehaviorCodegenTest extends TestCase
{
    public function testDefaultBackend()
    {
        $schema = <<<EOF
<database name="query_cache_codegen_test_1">
    <table name="qc_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="query_cache" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('queryKey', $script);
        $this->assertStringContainsString('function setQueryKey', $script);
        $this->assertStringContainsString('function getQueryKey', $script);
        $this->assertStringContainsString('function cacheContains', $script);
        $this->assertStringContainsString('function cacheStore', $script);
        $this->assertStringContainsString('function cacheFetch', $script);
        $this->assertStringContainsString('function getSelectStatement', $script);
        $this->assertStringContainsString('function getCountStatement', $script);
        $this->assertStringContainsString('apc_fetch', $script);
    }

    public function testArrayBackendAndCustomLifetime()
    {
        $schema = <<<EOF
<database name="query_cache_codegen_test_2">
    <table name="qc_array">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="query_cache">
            <parameter name="backend" value="array" />
            <parameter name="lifetime" value="60" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('protected static $cacheBackend;', $script);
        $this->assertStringContainsString('self::$cacheBackend[$key] = $value;', $script);
        $this->assertStringContainsString('function cacheStore($key, $value, $lifetime = 60)', $script);
    }

    public function testCustomBackendGeneratesOverrideException()
    {
        $schema = <<<EOF
<database name="query_cache_codegen_test_3">
    <table name="qc_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="query_cache">
            <parameter name="backend" value="custom" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('You must override the cacheContains(), cacheStore(), and cacheFetch()', $script);
    }
}
