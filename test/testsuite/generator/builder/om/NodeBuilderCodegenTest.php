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

    /**
     * The node classes emit as traits used by their stubs, like the rest of the
     * generated model (docs/GENERATED_TRAITS_PLAN.md).
     *
     * Pinned explicitly because the assertions above cannot tell the difference:
     * 'class MpNodeNode' matches both `class MpNodeNode extends BaseMpNodeNode`
     * and `class MpNodeNode implements \IteratorAggregate`, so the whole
     * conversion could regress without a single failure.
     */
    public function testNodeCodeIsEmittedAsTraitsUsedByTheStubs()
    {
        $script = $this->buildMaterializedPathSchema();

        $this->assertStringContainsString('trait MpNodeNodeGenerated', $script);
        $this->assertStringContainsString('trait MpNodeNodePeerGenerated', $script);
        $this->assertStringNotContainsString('abstract class BaseMpNodeNode', $script);
        $this->assertStringNotContainsString('abstract class BaseMpNodeNodePeer', $script);

        // IteratorAggregate moves to the stub with the rest of the class header --
        // a trait cannot declare `implements`.
        $this->assertMatchesRegularExpression(
            '/class MpNodeNode implements \\\\IteratorAggregate\s*\{\s*use MpNodeNodeGenerated;/',
            $script
        );
        $this->assertMatchesRegularExpression(
            '/class MpNodeNodePeer\s*\{\s*use MpNodeNodePeerGenerated;/',
            $script
        );
    }

    /**
     * getIterator() has to survive the move: it is the one method the
     * IteratorAggregate contract on the stub actually requires, and a trait
     * method satisfying an interface declared by the using class is the exact
     * arrangement that would break if the trait were not applied.
     */
    public function testGeneratedNodeSatisfiesIteratorAggregate()
    {
        $script = $this->buildMaterializedPathSchema();

        $this->assertStringContainsString('function getIterator', $script);
        // The method must land in the trait, not the stub, or regenerating would
        // not pick up changes to it.
        $traitStart = strpos($script, 'trait MpNodeNodeGenerated');
        $this->assertNotFalse($traitStart);
        $this->assertGreaterThan(
            $traitStart,
            strpos($script, 'function getIterator', $traitStart),
            'getIterator() should be emitted inside the generated trait'
        );
    }

    private function buildMaterializedPathSchema(): string
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

        return $builder->getClasses();
    }
}
