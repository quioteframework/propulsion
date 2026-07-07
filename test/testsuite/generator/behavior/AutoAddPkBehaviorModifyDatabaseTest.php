<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests AutoAddPkBehavior::modifyDatabase(), the database-level attachment path
 * (behavior declared on <database> rather than on individual <table> elements) --
 * AutoAddPkBehaviorTest only exercises modifyTable() via bookstore fixture tables
 * that already have the behavior applied per-table.
 */
class AutoAddPkBehaviorModifyDatabaseTest extends \PHPUnit\Framework\TestCase
{
    public function testDatabaseLevelBehaviorAddsPkOnlyToTablesWithoutOne()
    {
        $schema = <<<EOF
<database name="auto_add_pk_database_test">
    <behavior name="auto_add_pk" />
    <table name="auto_add_pk_no_pk">
        <column name="title" type="VARCHAR" size="100" />
    </table>
    <table name="auto_add_pk_has_pk">
        <column name="foo" type="INTEGER" primaryKey="true" />
    </table>
</database>
EOF;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $database = $builder->getDatabase();

        $noPkTable = $database->getTable('auto_add_pk_no_pk');
        $this->assertTrue($noPkTable->hasPrimaryKey(), 'auto_add_pk at the database level adds a pk to tables without one');
        $pks = $noPkTable->getPrimaryKey();
        $pk = array_pop($pks);
        $this->assertSame('id', $pk->getName());

        $hasPkTable = $database->getTable('auto_add_pk_has_pk');
        $pks = $hasPkTable->getPrimaryKey();
        $this->assertCount(1, $pks, 'auto_add_pk at the database level leaves tables with an existing pk untouched');
        $pk = array_pop($pks);
        $this->assertSame('foo', $pk->getName());
    }
}
