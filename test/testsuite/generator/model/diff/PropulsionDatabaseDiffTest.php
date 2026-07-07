<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Generator\Model\IDMethod;

/**
 * Coverage for PropulsionDatabaseDiff -- in particular getReverseDiff() (drives
 * the DOWN half of a generated migration; includes a special-case idMethod
 * fix-up for tables coming from reverse-engineering that's easy to silently
 * regress) and __toString()/getDescription() (the CLI-facing summaries), none
 * of which had a dedicated test before.
 */
class PropulsionDatabaseDiffTest extends TestCase
{
    public function testAddedTablesAddGetRemoveAndCount()
    {
        $diff = new PropulsionDatabaseDiff();
        $table = new Table('foo');
        $diff->addAddedTable('foo', $table);
        $this->assertSame($table, $diff->getAddedTable('foo'));
        $this->assertSame(array('foo' => $table), $diff->getAddedTables());
        $this->assertSame(1, $diff->countAddedTables());
        $diff->removeAddedTable('foo');
        $this->assertSame(array(), $diff->getAddedTables());
        $this->assertSame(0, $diff->countAddedTables());
    }

    public function testRemovedTablesAddGetRemoveAndCount()
    {
        $diff = new PropulsionDatabaseDiff();
        $table = new Table('foo');
        $diff->addRemovedTable('foo', $table);
        $this->assertSame($table, $diff->getRemovedTable('foo'));
        $this->assertSame(1, $diff->countRemovedTables());
        $diff->removeRemovedTable('foo');
        $this->assertSame(0, $diff->countRemovedTables());
    }

    public function testModifiedTablesAddAndCount()
    {
        $diff = new PropulsionDatabaseDiff();
        $tableDiff = new PropulsionTableDiff();
        $diff->addModifiedTable('foo', $tableDiff);
        $this->assertSame(array('foo' => $tableDiff), $diff->getModifiedTables());
        $this->assertSame(1, $diff->countModifiedTables());
    }

    public function testRenamedTablesAddAndCount()
    {
        $diff = new PropulsionDatabaseDiff();
        $diff->addRenamedTable('foo_old', 'foo_new');
        $this->assertSame(array('foo_old' => 'foo_new'), $diff->getRenamedTables());
        $this->assertSame(1, $diff->countRenamedTables());
    }

    // --- getDescription() -----------------------------------------------

    public function testGetDescriptionIsEmptyWhenNothingChanged()
    {
        $diff = new PropulsionDatabaseDiff();
        $this->assertSame('', $diff->getDescription());
    }

    public function testGetDescriptionSummarizesEachKindOfChange()
    {
        $diff = new PropulsionDatabaseDiff();
        $diff->addAddedTable('a', new Table('a'));
        $diff->addRemovedTable('b', new Table('b'));
        $diff->addModifiedTable('c', new PropulsionTableDiff());
        $diff->addRenamedTable('d_old', 'd_new');

        $description = $diff->getDescription();
        $this->assertSame('1 added tables, 1 removed tables, 1 modified tables, 1 renamed tables', $description);
    }

    public function testGetDescriptionOnlyMentionsNonZeroCounts()
    {
        $diff = new PropulsionDatabaseDiff();
        $diff->addAddedTable('a', new Table('a'));
        $this->assertSame('1 added tables', $diff->getDescription());
    }

    // --- getReverseDiff() -----------------------------------------------

    public function testGetReverseDiffSwapsAddedAndRemovedTables()
    {
        $diff = new PropulsionDatabaseDiff();
        $added = new Table('added');
        $added->setIdMethod(IDMethod::NATIVE);
        $removed = new Table('removed');
        $removed->setIdMethod(IDMethod::NATIVE);
        $diff->addAddedTable('added', $added);
        $diff->addRemovedTable('removed', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('removed' => $removed), $reverse->getAddedTables());
        $this->assertSame(array('added' => $added), $reverse->getRemovedTables());
    }

    public function testGetReverseDiffFixesUpNoIdMethodToNativeOnAddedTables()
    {
        // Tables produced by reverse-engineering an existing database don't carry
        // an explicit idMethod; without this fix-up, reversing such a diff (to
        // build a rollback migration) would try to re-create the table with
        // idMethod=none, silently losing its auto-increment/native id generation.
        $diff = new PropulsionDatabaseDiff();
        $removed = new Table('removed');
        $removed->setIdMethod(IDMethod::NO_ID_METHOD);
        $diff->addRemovedTable('removed', $removed);

        $reverse = $diff->getReverseDiff();
        $reAdded = $reverse->getAddedTable('removed');
        $this->assertSame(IDMethod::NATIVE, $reAdded->getIdMethod());
    }

    public function testGetReverseDiffLeavesExplicitIdMethodOnAddedTablesUnchanged()
    {
        $diff = new PropulsionDatabaseDiff();
        $removed = new Table('removed');
        $removed->setIdMethod(IDMethod::NATIVE);
        $diff->addRemovedTable('removed', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(IDMethod::NATIVE, $reverse->getAddedTable('removed')->getIdMethod());
    }

    public function testGetReverseDiffFlipsRenamedTables()
    {
        $diff = new PropulsionDatabaseDiff();
        $diff->addRenamedTable('old_name', 'new_name');

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('new_name' => 'old_name'), $reverse->getRenamedTables());
    }

    public function testGetReverseDiffReversesEachModifiedTableDiff()
    {
        $diff = new PropulsionDatabaseDiff();
        $tableDiff = new PropulsionTableDiff();
        $fromTable = new Table('foo');
        $toTable = new Table('foo');
        $tableDiff->setFromTable($fromTable);
        $tableDiff->setToTable($toTable);
        $diff->addModifiedTable('foo', $tableDiff);

        $reverse = $diff->getReverseDiff();
        $reversedTableDiff = $reverse->getModifiedTables()['foo'];
        $this->assertSame($toTable, $reversedTableDiff->getFromTable());
        $this->assertSame($fromTable, $reversedTableDiff->getToTable());
    }

    // --- __toString() -----------------------------------------------

    public function testToStringIsEmptyWhenNothingChanged()
    {
        $diff = new PropulsionDatabaseDiff();
        $this->assertSame('', (string) $diff);
    }

    public function testToStringListsAddedRemovedAndRenamedTables()
    {
        $diff = new PropulsionDatabaseDiff();
        $diff->addAddedTable('added', new Table('added'));
        $diff->addRemovedTable('removed', new Table('removed'));
        $diff->addRenamedTable('old_name', 'new_name');

        $str = (string) $diff;
        $this->assertStringContainsString("addedTables:\n  - added\n", $str);
        $this->assertStringContainsString("removedTables:\n  - removed\n", $str);
        $this->assertStringContainsString("renamedTables:\n  old_name: new_name\n", $str);
    }

    public function testToStringIncludesModifiedTableDetail()
    {
        $diff = new PropulsionDatabaseDiff();
        $tableDiff = new PropulsionTableDiff();
        $tableDiff->setFromTable(new Table('foo'));
        $tableDiff->addAddedColumn('bar', new Column('bar'));
        $diff->addModifiedTable('foo', $tableDiff);

        $str = (string) $diff;
        $this->assertStringContainsString("modifiedTables:\n", $str);
        $this->assertStringContainsString('foo:', $str);
        $this->assertStringContainsString('addedColumns:', $str);
        $this->assertStringContainsString('- bar', $str);
    }
}
