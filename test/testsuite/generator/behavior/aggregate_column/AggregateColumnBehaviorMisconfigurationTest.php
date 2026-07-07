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
 * Coverage for AggregateColumnBehavior's three misconfiguration guards, none of
 * which the fixture-based AggregateColumnBehaviorTest can exercise (its schema
 * is, by construction, always correctly configured). Each of these guards is
 * only reachable via a deliberately broken schema.
 */
class AggregateColumnBehaviorMisconfigurationTest extends TestCase
{
    public function testThrowsWhenNameParameterIsMissing()
    {
        $schema = <<<EOF
<database name="agg_misconfig_test_1">
    <table name="agg_post">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <behavior name="aggregate_column">
            <parameter name="expression" value="COUNT(*)" />
            <parameter name="foreign_table" value="agg_comment" />
        </behavior>
    </table>
    <table name="agg_comment">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="post_id" type="INTEGER" />
        <foreign-key foreignTable="agg_post">
            <reference local="post_id" foreign="id" />
        </foreign-key>
    </table>
</database>
EOF;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("You must define a 'name' parameter for the 'aggregate_column' behavior in the 'agg_post' table");

        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $builder->getDatabase();
    }

    public function testThrowsWhenForeignTableParameterIsMissing()
    {
        // modifyTable() calls getForeignTable() before objectMethods() ever runs
        // its own 'foreign_table' guard -- with foreign_table unset,
        // getForeignTable() used to resolve to no table at all, and modifyTable()
        // fataled dereferencing null ("Call to a member function hasBehavior() on
        // null") instead of a clean, actionable error. modifyTable() now checks
        // 'foreign_table' up front, the same way it already checks 'name'.
        $schema = <<<EOF
<database name="agg_misconfig_test_2">
    <table name="agg_post">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <behavior name="aggregate_column">
            <parameter name="name" value="nb_comments" />
            <parameter name="expression" value="COUNT(*)" />
        </behavior>
    </table>
</database>
EOF;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("You must define a 'foreign_table' parameter for the 'aggregate_column' behavior in the 'agg_post' table");

        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $builder->getDatabase();
    }

    public function testThrowsWhenNoForeignKeyPointsBackToTheAggregateTable()
    {
        $schema = <<<EOF
<database name="agg_misconfig_test_3">
    <table name="agg_post">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <behavior name="aggregate_column">
            <parameter name="name" value="nb_comments" />
            <parameter name="expression" value="COUNT(*)" />
            <parameter name="foreign_table" value="agg_comment" />
        </behavior>
    </table>
    <table name="agg_comment">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
    </table>
</database>
EOF;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("You must define a foreign key to the 'agg_post' table in the 'agg_comment' table to enable the 'aggregate_column' behavior");

        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);
        $builder->getDatabase();
    }
}
