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
 * (NodeBuilder/NodePeerBuilder/ExtensionNodeBuilder/ExtensionNodePeerBuilder).
 * No fixture project or dedicated runtime test exercises this feature anywhere
 * else in the suite, so this is the only thing standing behind it -- worth
 * knowing, since treeMode="NestedSet" was removed in 3.0 and MaterializedPath
 * is now the sole surviving treeMode.
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
