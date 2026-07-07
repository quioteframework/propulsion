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
 * Triggers NestedSetBuilder/NestedSetPeerBuilder's own codegen freshly inside a
 * test body. This is the legacy treeMode="NestedSet" attribute-based tree API
 * (distinct from the "nested_set" *behavior*, see
 * NestedSetBehaviorCodegenTest) -- GeneratedNestedSetPeerTest already proves
 * the generated code works at runtime against the CMS fixture's PagePeer, but
 * that fixture is built once in test/bootstrap.php, before PHPUnit's coverage
 * collector attaches, so NestedSetBuilder/NestedSetPeerBuilder never register
 * as covered no matter how thoroughly that runtime behavior is tested.
 */
class NestedSetBuilderCodegenTest extends TestCase
{
    public function testTreeModeAttributeGeneratesNestedSetClasses()
    {
        $schema = <<<EOF
<database name="nested_set_builder_codegen_test">
    <table name="ns_node" treeMode="NestedSet">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="left" required="true" nestedSetLeftKey="true" type="INTEGER" />
        <column name="right" required="true" nestedSetRightKey="true" type="INTEGER" />
        <column name="label" required="true" type="VARCHAR" size="10" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('class NsNode', $script);
        $this->assertStringContainsString('class NsNodePeer', $script);
        $this->assertStringContainsString('function retrieveRoot', $script);
        $this->assertStringContainsString('function isRoot', $script);
        $this->assertStringContainsString('function isLeaf', $script);
        $this->assertStringContainsString('function moveToFirstChildOf', $script);
        $this->assertStringContainsString('function getChildren', $script);
    }
}
