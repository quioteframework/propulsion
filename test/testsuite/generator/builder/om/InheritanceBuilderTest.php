<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder;
use Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder;
use Propulsion\Generator\Exception\EngineException;

/**
 * Coverage for single-table-inheritance codegen (MultiExtendObjectBuilder /
 * ExtensionQueryInheritanceBuilder), which had near-zero coverage: the bookstore
 * fixture's own inheritance table (bookstore_employee) never re-exercises these
 * builders because its generated stub files are written to disk once and never
 * regenerated afterwards ("only generated as long as it does not already exist
 * in the output directory", per both builders' docblocks).
 *
 * Builds a real Table/Inheritance model via PropulsionQuickBuilder (for fully
 * wired Database/GeneratorConfig/Platform context) and calls each builder's
 * build() directly, asserting on the generated source string -- rather than
 * eval()-ing the result into real classes. Both generated classes reference
 * each other for covariant return types (BaseXQuery::create(): XQuery, where
 * XQuery extends BaseXQuery), which PHP can only resolve through separate,
 * lazily-autoloaded files the way the real disk-based build does; eval()-ing
 * one big concatenated string (as PropulsionQuickBuilder::buildClasses() does)
 * hits an unavoidable circular-declaration fatal for this specific feature, a
 * separate pre-existing limitation of the quick-builder's eval() strategy that
 * inspecting the generated source directly sidesteps.
 */
class InheritanceBuilderTest extends TestCase
{
    private function buildInheritanceModel()
    {
        $schema = <<<EOF
<database name="inheritance_builder_test_model_only">
    <table name="inh_employee" phpName="InhEmployee">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="class_key" type="INTEGER" required="true" default="0" inheritance="single">
            <inheritance key="0" class="InhEmployee" />
            <inheritance key="1" class="InhManager" extends="InhEmployee" />
        </column>
        <column name="name" type="VARCHAR" size="32" />
    </table>
</database>
EOF;
        $quickBuilder = new PropulsionQuickBuilder();
        $quickBuilder->setSchema($schema);
        $table = $quickBuilder->getDatabase()->getTable('inh_employee');
        $child = null;
        foreach ($table->getChildrenColumn()->getChildren() as $candidate) {
            if ($candidate->getAncestor()) {
                $child = $candidate;
            }
        }

        return array($quickBuilder, $table, $child);
    }

    public function testMultiExtendObjectBuilderGeneratesChildClassExtendingParent()
    {
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'objectmultiextend');
        $this->assertInstanceOf(MultiExtendObjectBuilder::class, $builder);
        $builder->setChild($child);

        $script = $builder->build();

        $this->assertStringContainsString('class InhManager extends InhEmployee', $script);
    }

    public function testMultiExtendObjectBuilderConstructorSetsDiscriminatorToClasskeyConstant()
    {
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'objectmultiextend');
        $builder->setChild($child);
        $script = $builder->build();

        $this->assertStringContainsString('public function __construct()', $script);
        $this->assertStringContainsString('parent::__construct();', $script);
        $this->assertStringContainsString('InhEmployeePeer::CLASSKEY_1', $script);
    }

    public function testMultiExtendObjectBuilderCastsNumericDiscriminatorToIntNotString()
    {
        // The CLASSKEY_* peer constants are always declared as string literals
        // regardless of the discriminator column's real type; MultiExtendObjectBuilder
        // must cast back to the column's native PHP type (int here) in the generated
        // constructor, or persisting a new InhManager would silently write the wrong
        // (string) value into an INTEGER column.
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'objectmultiextend');
        $builder->setChild($child);
        $script = $builder->build();

        $this->assertStringContainsString('(int) InhEmployeePeer::CLASSKEY_1', $script);
    }

    public function testMultiExtendObjectBuilderGetUnprefixedClassnameUsesChildClassname()
    {
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'objectmultiextend');
        $builder->setChild($child);

        $this->assertSame('InhManager', $builder->getUnprefixedClassname());
    }

    public function testExtensionQueryInheritanceBuilderGeneratesQueryClassExtendingBaseQuery()
    {
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'queryinheritancestub');
        $this->assertInstanceOf(ExtensionQueryInheritanceBuilder::class, $builder);
        $builder->setChild($child);

        $script = $builder->build();

        $this->assertStringContainsString('class InhManagerQuery extends BaseInhManagerQuery', $script);
    }

    public function testExtensionQueryInheritanceBuilderGetUnprefixedClassnameAppendsQuery()
    {
        list($quickBuilder, $table, $child) = $this->buildInheritanceModel();

        $builder = $quickBuilder->getConfig()->getConfiguredBuilder($table, 'queryinheritancestub');
        $builder->setChild($child);

        $this->assertSame('InhManagerQuery', $builder->getUnprefixedClassname());
    }

    public function testMultiExtendObjectBuilderThrowsWhenChildNotSet()
    {
        $builder = new MultiExtendObjectBuilder(new Table('foo'));
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('needs to be told which child class to build');
        $builder->getChild();
    }

    public function testExtensionQueryInheritanceBuilderThrowsWhenChildNotSet()
    {
        $builder = new ExtensionQueryInheritanceBuilder(new Table('foo'));
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('needs to be told which child class to build');
        $builder->getChild();
    }
}
