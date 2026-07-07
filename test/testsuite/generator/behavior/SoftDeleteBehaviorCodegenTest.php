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
 * Triggers the soft_delete behavior's own codegen freshly inside a test body.
 * SoftDeleteBehaviorTest already proves the generated code behaves correctly
 * at runtime (against the shared bookstore fixture's Table4/Table5), but that
 * generated code is produced once in test/bootstrap.php before PHPUnit's
 * coverage collector attaches, so SoftDeleteBehavior never registers as
 * covered. Rebuilding the same schema shapes here, inside an actual test
 * method, fixes that.
 */
class SoftDeleteBehaviorCodegenTest extends TestCase
{
    public function testDefaultDeletedColumn()
    {
        $schema = <<<EOF
<database name="soft_delete_codegen_test_1">
    <table name="sd_default">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <behavior name="soft_delete" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('function forceDelete', $script);
        $this->assertStringContainsString('function unDelete', $script);
        $this->assertStringContainsString('function isSoftDeleteEnabled', $script);
        $this->assertStringContainsString('function disableSoftDelete', $script);
        $this->assertStringContainsString('function enableSoftDelete', $script);
        $this->assertStringContainsString('function includeDeleted', $script);
    }

    public function testCustomDeletedColumn()
    {
        $schema = <<<EOF
<database name="soft_delete_codegen_test_2">
    <table name="sd_custom">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="title" type="VARCHAR" size="100" />
        <column name="deleted_on" type="TIMESTAMP" />
        <behavior name="soft_delete">
            <parameter name="deleted_column" value="deleted_on" />
        </behavior>
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $script = $builder->getClasses();

        $this->assertStringContainsString('DeletedOn', $script);
        $this->assertStringContainsString('function forceDelete', $script);
    }
}
