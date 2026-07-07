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
 * Triggers the timestampable behavior's own codegen freshly inside a test
 * body. TimestampableBehaviorTest already proves the generated code behaves
 * correctly at runtime (against the shared bookstore fixture's Table1/Table2),
 * but that generated code is produced once in test/bootstrap.php before
 * PHPUnit's coverage collector attaches, so TimestampableBehavior never
 * registers as covered. Rebuilding the same schema shapes here, inside an
 * actual test method, fixes that.
 */
class TimestampableBehaviorCodegenTest extends TestCase
{
    public function testCustomColumnNames()
    {
        $schema = <<<EOF
<database name="timestampable_codegen_test_1">
    <table name="ts_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <column name="created_on" type="TIMESTAMP" />
        <column name="updated_on" type="TIMESTAMP" />
        <behavior name="timestampable">
            <parameter name="create_column" value="created_on" />
            <parameter name="update_column" value="updated_on" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function keepUpdateDateUnchanged', $script);
        $this->assertStringContainsString('function recentlyUpdated', $script);
        $this->assertStringContainsString('function recentlyCreated', $script);
        $this->assertStringContainsString('function lastUpdatedFirst', $script);
        $this->assertStringContainsString('function firstUpdatedFirst', $script);
        $this->assertStringContainsString('function lastCreatedFirst', $script);
        $this->assertStringContainsString('function firstCreatedFirst', $script);
        $this->assertStringContainsString('CreatedOn', $script);
        $this->assertStringContainsString('UpdatedOn', $script);
    }

    public function testDefaultColumnNames()
    {
        $schema = <<<EOF
<database name="timestampable_codegen_test_2">
    <table name="ts_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="timestampable" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('CreatedAt', $script);
        $this->assertStringContainsString('UpdatedAt', $script);
    }
}
