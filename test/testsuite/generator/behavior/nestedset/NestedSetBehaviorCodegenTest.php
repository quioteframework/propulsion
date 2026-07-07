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
 * Triggers the nested_set behavior's own codegen (NestedSetBehaviorObjectBuilderModifier
 * / PeerBuilderModifier / QueryBuilderModifier) freshly inside a test body.
 *
 * NestedSetBehaviorRuntimeTest already proves the *generated* code behaves
 * correctly at runtime (against a real Postgres-backed Table9), but that
 * generated code comes from the shared bookstore fixture, built once in
 * test/bootstrap.php *before* PHPUnit's coverage collector attaches -- so the
 * behavior classes that produce it never register as covered no matter how
 * thoroughly the runtime output is tested. Rebuilding the same schema shapes
 * here, inside an actual test method, exercises that codegen within the
 * coverage window instead.
 */
class NestedSetBehaviorCodegenTest extends TestCase
{
    public function testDefaultColumnNamesWithoutScope()
    {
        $schema = <<<EOF
<database name="nested_set_codegen_test_1">
    <table name="ns_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="nested_set" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function getLeftValue', $script);
        $this->assertStringContainsString('function getRightValue', $script);
        $this->assertStringContainsString('function getLevel', $script);
        $this->assertStringContainsString('function insertAsFirstChildOf', $script);
        $this->assertStringContainsString('function moveToFirstChildOf', $script);
        $this->assertStringContainsString('function deleteDescendants', $script);
        $this->assertStringContainsString('function findRoot', $script);
        $this->assertStringContainsString('function findTree', $script);
        // No scope column/parameter configured for this table.
        $this->assertStringNotContainsString('function getScopeValue', $script);
    }

    public function testCustomColumnNamesWithScopeAndMethodProxies()
    {
        $schema = <<<EOF
<database name="nested_set_codegen_test_2">
    <table name="ns_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <column name="my_left_column" type="INTEGER" required="false"/>
        <column name="my_right_column" type="INTEGER" required="false"/>
        <column name="my_level_column" type="INTEGER" required="false"/>
        <column name="my_scope_column" type="INTEGER" required="false"/>
        <behavior name="nested_set">
            <parameter name="left_column" value="my_left_column" />
            <parameter name="right_column" value="my_right_column" />
            <parameter name="level_column" value="my_level_column" />
            <parameter name="use_scope" value="true" />
            <parameter name="scope_column" value="my_scope_column" />
            <parameter name="method_proxies" value="true" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function getScopeValue', $script);
        $this->assertStringContainsString('function setScopeValue', $script);
        $this->assertStringContainsString('MyLeftColumn', $script);
        $this->assertStringContainsString('MyRightColumn', $script);
        $this->assertStringContainsString('MyLevelColumn', $script);
        $this->assertStringContainsString('MyScopeColumn', $script);
        // method_proxies=true adds the short getLeftValue()/getRightValue()/getLevel() aliases.
        $this->assertStringContainsString('function getLeftValue', $script);
    }
}
