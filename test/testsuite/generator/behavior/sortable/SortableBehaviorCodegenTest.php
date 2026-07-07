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
 * Triggers the sortable behavior's own codegen freshly inside a test body.
 * SortableBehaviorRuntimeTest already proves the generated code behaves
 * correctly at runtime (against a real Postgres-backed Table11), but that
 * generated code comes from the shared bookstore fixture, built once in
 * test/bootstrap.php before PHPUnit's coverage collector attaches -- so the
 * behavior classes producing it never register as covered. Rebuilding the
 * same schema shapes here, inside an actual test method, fixes that.
 */
class SortableBehaviorCodegenTest extends TestCase
{
    public function testDefaultRankColumnWithoutScope()
    {
        $schema = <<<EOF
<database name="sortable_codegen_test_1">
    <table name="sort_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="sortable" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function getRank', $script);
        $this->assertStringContainsString('function insertAtRank', $script);
        $this->assertStringContainsString('function insertAtBottom', $script);
        $this->assertStringContainsString('function insertAtTop', $script);
        $this->assertStringContainsString('function moveToRank', $script);
        $this->assertStringContainsString('function swapWith', $script);
        $this->assertStringContainsString('function moveUp', $script);
        $this->assertStringContainsString('function moveDown', $script);
        $this->assertStringContainsString('function removeFromList', $script);
        $this->assertStringContainsString('function getMaxRank', $script);
        $this->assertStringContainsString('function reorder', $script);
        // No scope column configured for this table.
        $this->assertStringNotContainsString('function getScopeValue', $script);
    }

    public function testCustomRankColumnWithScope()
    {
        $schema = <<<EOF
<database name="sortable_codegen_test_2">
    <table name="sort_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <column name="position" type="INTEGER" />
        <column name="my_scope_column" type="INTEGER" required="false" />
        <behavior name="sortable">
            <parameter name="rank_column" value="position" />
            <parameter name="use_scope" value="true" />
            <parameter name="scope_column" value="my_scope_column" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('Position', $script);
        $this->assertStringContainsString('function getScopeValue', $script);
        $this->assertStringContainsString('function setScopeValue', $script);
    }
}
