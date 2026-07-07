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
 * Coverage for InterfaceBuilder, which generates the empty stub interface for
 * a table's interface="..." attribute. No existing test builds a schema using
 * this attribute at all (the fixture PropulsionQuickBuilder call site for it,
 * in getClassesForTable(), also had an argument-order bug fixed earlier this
 * session -- buildScriptFor('interface', $target) instead of
 * buildScriptFor($table, 'interface') -- which this test guards against
 * regressing).
 */
class InterfaceBuilderTest extends TestCase
{
    public function testGeneratesEmptyStubInterfaceForTableWithInterfaceAttribute()
    {
        $schema = <<<EOF
<database name="interface_builder_test">
    <table name="if_widget" interface="WidgetInterface">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('interface WidgetInterface', $script);
    }
}
