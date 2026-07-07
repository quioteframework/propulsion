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
 * Triggers the sluggable behavior's own codegen freshly inside a test body.
 * SluggableBehaviorTest already proves the generated code behaves correctly
 * at runtime (against the shared bookstore fixture's Table13/Table14), but
 * that generated code is produced once in test/bootstrap.php before
 * PHPUnit's coverage collector attaches, so SluggableBehavior never
 * registers as covered. Rebuilding the same schema shapes here, inside an
 * actual test method, fixes that.
 */
class SluggableBehaviorCodegenTest extends TestCase
{
    public function testDefaultSlugColumn()
    {
        $schema = <<<EOF
<database name="sluggable_codegen_test_1">
    <table name="slug_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="sluggable" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function getSlug', $script);
        $this->assertStringContainsString('function setSlug', $script);
        $this->assertStringContainsString('function createSlug', $script);
        $this->assertStringContainsString('function createRawSlug', $script);
        $this->assertStringContainsString('function makeSlugUnique', $script);
        $this->assertStringContainsString('function limitSlugSize', $script);
        $this->assertStringContainsString('function cleanupSlugPart', $script);
        $this->assertStringContainsString('function findOneBySlug', $script);
    }

    public function testCustomSlugColumnWithPatternAndPermanentFlag()
    {
        $schema = <<<EOF
<database name="sluggable_codegen_test_2">
    <table name="slug_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <column name="url" type="VARCHAR" size="100" />
        <behavior name="sluggable">
            <parameter name="slug_column" value="url" />
            <parameter name="slug_pattern" value="/foo/{Title}/bar" />
            <parameter name="replace_pattern" value="/[^\w\/]+/" />
            <parameter name="separator" value="/" />
            <parameter name="permanent" value="true" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function getUrl', $script);
        $this->assertStringContainsString('function createSlug', $script);
    }
}
