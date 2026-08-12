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
 * Coverage for the 3.0 contract that generated object and query code is a trait
 * the stub uses, rather than a base class it extends
 * (docs/GENERATED_TRAITS_PLAN.md).
 *
 * The point of the change is that PHPStan analyses a trait body once per using
 * class, so `$this` inside generated code is provably the model. Nothing here
 * can assert that directly -- it is a static-analysis property, measured
 * separately against the fixture builds -- so what these tests pin is the
 * structure it depends on: the trait exists, the stub uses it, and the class
 * header the trait can no longer carry (parent, interfaces) is on the stub.
 *
 * Without that, the conversion could silently revert: assertions like
 * `assertStringContainsString('class Book')` match both shapes, which is exactly
 * how the node builders' only test failed to notice.
 */
class GeneratedTraitEmissionTest extends TestCase
{
    private function build(string $tableXml, string $databaseAttrs = ''): string
    {
        $schema = <<<XML
<database name="trait_emission_test" defaultIdMethod="native" $databaseAttrs>
$tableXml
</database>
XML;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);

        return $builder->getClasses();
    }

    private function simpleTable(string $attrs = ''): string
    {
        return <<<XML
    <table name="trait_widget" $attrs>
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
    </table>
XML;
    }

    public function testObjectCodeIsATraitAndTheStubUsesIt()
    {
        $script = $this->build($this->simpleTable());

        $this->assertStringContainsString('trait TraitWidgetGenerated', $script);
        // Anchored: the peer legitimately still emits `abstract class
        // BaseTraitWidgetPeer`, which a plain substring check would match.
        $this->assertDoesNotMatchRegularExpression('/abstract class BaseTraitWidget(?![A-Za-z])/', $script);
        $this->assertMatchesRegularExpression(
            '/class TraitWidget extends BaseObject implements [^{]*\{\s*use TraitWidgetGenerated;/s',
            $script
        );
    }

    public function testQueryCodeIsATraitAndTheStubExtendsModelCriteria()
    {
        $script = $this->build($this->simpleTable());

        $this->assertStringContainsString('trait TraitWidgetQueryGenerated', $script);
        $this->assertStringNotContainsString('abstract class BaseTraitWidgetQuery', $script);
        $this->assertMatchesRegularExpression(
            '/class TraitWidgetQuery extends ModelCriteria \{\s*\n\s*use TraitWidgetQueryGenerated;/',
            $script
        );
    }

    /**
     * Peers deliberately did not move: every peer method is static, so there was
     * never a `$this` to be mistyped, and converting them fixes nothing.
     */
    public function testPeerCodeStaysABaseClass()
    {
        $script = $this->build($this->simpleTable());

        $this->assertStringContainsString('abstract class BaseTraitWidgetPeer', $script);
        $this->assertStringContainsString('class TraitWidgetPeer extends BaseTraitWidgetPeer', $script);
        $this->assertStringNotContainsString('trait TraitWidgetPeerGenerated', $script);
    }

    /**
     * The interface list moved from the generated base to the stub, so the
     * conditions that vary it have to keep varying it there.
     *
     * A read-only table is emitted without save()/delete() and so cannot be
     * Persistent -- but it is still hydrated and still pooled, which is why
     * Poolable is unconditional.
     */
    public function testReadOnlyTableStubIsPoolableButNotPersistent()
    {
        $script = $this->build($this->simpleTable('readOnly="true"'));

        $this->assertMatchesRegularExpression('/class TraitWidget extends BaseObject implements [^\n]*Poolable/', $script);
        $this->assertDoesNotMatchRegularExpression('/class TraitWidget extends BaseObject implements [^\n]*Persistent/', $script);
    }

    /**
     * WritableModelInterface is only implemented in lockstep with whether
     * setByName()/setByPosition()/fromArray() are actually emitted.
     */
    public function testWritableModelInterfaceTracksGenericMutators()
    {
        $withMutators = $this->build($this->simpleTable());
        $this->assertMatchesRegularExpression(
            '/class TraitWidget extends BaseObject implements [^\n]*WritableModelInterface/',
            $withMutators
        );

        $builder = new PropulsionQuickBuilder();
        $builder->setSchema(<<<XML
<database name="trait_emission_test" defaultIdMethod="native">
{$this->simpleTable()}
</database>
XML);
        $config = $builder->getConfig();
        $config->setBuildProperty('addGenericMutators', 'false');
        $builder->setConfig($config);

        $without = $builder->getClasses();
        $this->assertStringContainsString('class TraitWidget extends BaseObject', $without);
        $this->assertDoesNotMatchRegularExpression(
            '/class TraitWidget extends BaseObject implements [^\n]*WritableModelInterface/',
            $without
        );
    }

    /**
     * copy() builds its clone with `new static()`, which needs the using class
     * to promise a consistent constructor -- and that annotation has to sit on
     * the stub, because PHPStan does not honour it on the trait.
     */
    public function testStubCarriesConsistentConstructorAnnotation()
    {
        $script = $this->build($this->simpleTable());

        $this->assertStringContainsString('@phpstan-consistent-constructor', $script);
        $this->assertStringContainsString('new static()', $script);
        $this->assertStringNotContainsString('$clazz = get_class($this)', $script);
    }

    /**
     * Fluent methods emitted into the trait must name the stub class, never the
     * trait: a trait name in a return type is a type nothing can satisfy, and
     * under inheritance PHP rejects it outright.
     */
    public function testFluentMutatorsReturnTheStubNotTheTrait()
    {
        $script = $this->build($this->simpleTable());

        $this->assertStringContainsString('public function setLabel(?string $value = null): TraitWidget', $script);
        $this->assertStringNotContainsString(': TraitWidgetGenerated', $script);
    }
}
