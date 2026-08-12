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
 * Coverage for XMLElement::getConfiguredBehavior()'s refusal of behaviors
 * removed in 3.0.
 *
 * Refusing by name matters because the fallback is actively misleading: an
 * unconfigured behavior otherwise reaches "Unknown behavior "x"; make sure you
 * configured the propulsion.behavior.x.class setting in your build.properties",
 * which sends the user after a setting that cannot bring back a class that no
 * longer exists. Same reasoning as the treeMode="NestedSet" refusal.
 */
class RemovedBehaviorRefusalTest extends TestCase
{
    private function buildWithBehavior(string $behaviorName): void
    {
        $schema = <<<XML
<database name="removed_behavior_test" defaultIdMethod="native">
    <table name="removed_behavior_child">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
        <behavior name="$behaviorName" />
    </table>
</database>
XML;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $builder->getClasses();
    }

    public function testConcreteInheritanceIsRefusedByName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "concrete_inheritance" behavior was removed in Propulsion 3.0.');

        $this->buildWithBehavior('concrete_inheritance');
    }

    /**
     * The message has to carry the replacement, not just the removal -- someone
     * hitting this is mid-upgrade with a schema that used to build.
     */
    public function testConcreteInheritanceRefusalNamesWhatToUseInstead()
    {
        try {
            $this->buildWithBehavior('concrete_inheritance');
            $this->fail('Expected the removed behavior to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('foreign key to the parent table', $e->getMessage());
            $this->assertStringContainsString('inheritance="single"', $e->getMessage());
        }
    }

    public function testConcreteInheritanceParentIsRefusedByName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "concrete_inheritance_parent" behavior was removed in Propulsion 3.0.');

        $this->buildWithBehavior('concrete_inheritance_parent');
    }

    /**
     * The refusal must not swallow the generic path: a behavior that was never
     * a thing still gets the "configure propulsion.behavior.*" advice, which is
     * correct advice for a genuinely unknown name.
     */
    public function testAnUnknownBehaviorStillGetsTheGenericMessage()
    {
        try {
            $this->buildWithBehavior('never_existed_behavior');
            $this->fail('Expected an unknown behavior to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown behavior', $e->getMessage());
            $this->assertStringNotContainsString('removed in Propulsion 3.0', $e->getMessage());
        }
    }

    /**
     * A behavior that still exists must be unaffected by the removal list --
     * nested_set in particular, since treeMode="NestedSet" was removed in the
     * same release and the two are easily conflated.
     */
    public function testASurvivingBehaviorStillBuilds()
    {
        $schema = <<<XML
<database name="removed_behavior_test" defaultIdMethod="native">
    <table name="surviving_behavior_table">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="40" />
        <behavior name="nested_set" />
    </table>
</database>
XML;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function isLeaf', $script);
        $this->assertStringContainsString('function isRoot', $script);
    }
}
