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
 * Coverage for the treeMode="MaterializedPath" codegen path
 * (NodeBuilder/NodePeerBuilder/ExtensionNodeBuilder/ExtensionNodePeerBuilder),
 * which had no test at all -- unlike treeMode="NestedSet" (see
 * NestedSetBuilderCodegenTest), no fixture project or dedicated runtime test
 * exercises this feature anywhere in the suite.
 */
class NodeBuilderCodegenTest extends TestCase
{
    public function testTreeModeAttributeGeneratesMaterializedPathClasses()
    {
        $schema = <<<EOF
<database name="node_builder_codegen_test" defaultIdMethod="none">
    <table name="mp_node" treeMode="MaterializedPath">
        <column name="npath" required="true" nodeKey="true" nodeKeySep="." primaryKey="true" type="VARCHAR" size="80" />
        <column name="label" required="true" type="VARCHAR" size="10" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('class MpNode', $script);
        $this->assertStringContainsString('class MpNodePeer', $script);
        $this->assertStringContainsString('function isLeaf', $script);
        $this->assertStringContainsString('function isRoot', $script);
        $this->assertStringContainsString('function getLevel', $script);
        $this->assertStringContainsString('function setLevel', $script);
        $this->assertStringContainsString('function getIterator', $script);
    }
}
