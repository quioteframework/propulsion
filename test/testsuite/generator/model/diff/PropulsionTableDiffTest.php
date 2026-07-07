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
 * Coverage for PropulsionTableDiff, in particular getReverseDiff() (used to build
 * the DOWN half of generated migrations from the UP diff -- a bug here silently
 * produces a broken rollback) and __toString() (the human-readable summary shown
 * by the migration:status / migration CLI commands), neither of which had any
 * dedicated test before.
 */
class PropulsionTableDiffTest extends TestCase
{
    private function makeTables()
    {
        $fromTable = new Table('foo');
        $toTable = new Table('foo');
        return array($fromTable, $toTable);
    }

    public function testFromAndToTableGettersSetters()
    {
        list($fromTable, $toTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $this->assertSame($fromTable, $diff->getFromTable());
        $this->assertSame($toTable, $diff->getToTable());
    }

    public function testAddedColumnsAddAndRemove()
    {
        $diff = new PropulsionTableDiff();
        $col = new Column('bar');
        $diff->addAddedColumn('bar', $col);
        $this->assertSame($col, $diff->getAddedColumn('bar'));
        $this->assertSame(array('bar' => $col), $diff->getAddedColumns());
        $diff->removeAddedColumn('bar');
        $this->assertSame(array(), $diff->getAddedColumns());
    }

    public function testRemovedColumnsAddAndRemove()
    {
        $diff = new PropulsionTableDiff();
        $col = new Column('bar');
        $diff->addRemovedColumn('bar', $col);
        $this->assertSame($col, $diff->getRemovedColumn('bar'));
        $diff->removeRemovedColumn('bar');
        $this->assertSame(array(), $diff->getRemovedColumns());
    }

    public function testModifiedColumns()
    {
        $diff = new PropulsionTableDiff();
        $colDiff = new PropulsionColumnDiff();
        $diff->addModifiedColumn('bar', $colDiff);
        $this->assertSame(array('bar' => $colDiff), $diff->getModifiedColumns());
    }

    public function testRenamedColumns()
    {
        $diff = new PropulsionTableDiff();
        $from = new Column('bar_old');
        $to = new Column('bar_new');
        $diff->addRenamedColumn($from, $to);
        $this->assertSame(array(array($from, $to)), $diff->getRenamedColumns());
    }

    public function testAddedAndRemovedPkColumns()
    {
        $diff = new PropulsionTableDiff();
        $col = new Column('id');
        $diff->addAddedPkColumn('id', $col);
        $this->assertSame(array('id' => $col), $diff->getAddedPkColumns());
        $diff->removeAddedPkColumn('id');
        $this->assertSame(array(), $diff->getAddedPkColumns());

        $diff->addRemovedPkColumn('id', $col);
        $this->assertSame(array('id' => $col), $diff->getRemovedPkColumns());
        $diff->removeRemovedPkColumn('id');
        $this->assertSame(array(), $diff->getRemovedPkColumns());
    }

    public function testHasModifiedPkIsFalseWhenNothingChanged()
    {
        $diff = new PropulsionTableDiff();
        $this->assertFalse($diff->hasModifiedPk());
    }

    public function testHasModifiedPkIsTrueWhenPkAdded()
    {
        $diff = new PropulsionTableDiff();
        $diff->addAddedPkColumn('id', new Column('id'));
        $this->assertTrue($diff->hasModifiedPk());
    }

    public function testHasModifiedPkIsTrueWhenPkRemoved()
    {
        $diff = new PropulsionTableDiff();
        $diff->addRemovedPkColumn('id', new Column('id'));
        $this->assertTrue($diff->hasModifiedPk());
    }

    public function testHasModifiedPkIsTrueWhenPkRenamed()
    {
        $diff = new PropulsionTableDiff();
        $diff->addRenamedPkColumn(new Column('id_old'), new Column('id_new'));
        $this->assertTrue($diff->hasModifiedPk());
    }

    public function testAddedRemovedAndModifiedIndices()
    {
        $diff = new PropulsionTableDiff();
        $idx = new Index('idx_foo');
        $diff->addAddedIndex('idx_foo', $idx);
        $this->assertSame(array('idx_foo' => $idx), $diff->getAddedIndices());

        $diff2 = new PropulsionTableDiff();
        $diff2->addRemovedIndex('idx_foo', $idx);
        $this->assertSame(array('idx_foo' => $idx), $diff2->getRemovedIndices());

        $fromIdx = new Index('idx_bar');
        $toIdx = new Index('idx_bar');
        $diff3 = new PropulsionTableDiff();
        $diff3->addModifiedIndex('idx_bar', $fromIdx, $toIdx);
        $this->assertSame(array('idx_bar' => array($fromIdx, $toIdx)), $diff3->getModifiedIndices());
    }

    private function makeForeignKey($name, $localCol, $foreignCol)
    {
        $fk = new ForeignKey($name);
        $fk->addReference(new Column($localCol), new Column($foreignCol));
        return $fk;
    }

    public function testAddedRemovedFksAndRemoval()
    {
        $diff = new PropulsionTableDiff();
        $fk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $diff->addAddedFk('fk_foo', $fk);
        $this->assertSame($fk, $diff->getAddedFks()['fk_foo']);
        $diff->removeAddedFk('fk_foo');
        $this->assertSame(array(), $diff->getAddedFks());

        $diff->addRemovedFk('fk_foo', $fk);
        $this->assertSame($fk, $diff->getRemovedFks()['fk_foo']);
        $diff->removeRemovedFk('fk_foo');
        $this->assertSame(array(), $diff->getRemovedFks());
    }

    public function testAddRemovedFkWithNullNameFallsBackToEmptyString()
    {
        $diff = new PropulsionTableDiff();
        $fk = $this->makeForeignKey('', 'bar_id', 'id');
        $diff->addRemovedFk(null, $fk);
        $this->assertSame($fk, $diff->getRemovedFks()['']);
    }

    public function testModifiedFks()
    {
        $diff = new PropulsionTableDiff();
        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);
        $this->assertSame(array('fk_foo' => array($fromFk, $toFk)), $diff->getModifiedFks());
    }

    // --- getReverseDiff() -----------------------------------------------

    public function testGetReverseDiffSwapsFromAndToTable()
    {
        list($fromTable, $toTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);

        $reverse = $diff->getReverseDiff();
        $this->assertSame($toTable, $reverse->getFromTable());
        $this->assertSame($fromTable, $reverse->getToTable());
    }

    public function testGetReverseDiffSwapsAddedAndRemovedColumns()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $added = new Column('added');
        $removed = new Column('removed');
        $diff->addAddedColumn('added', $added);
        $diff->addRemovedColumn('removed', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('removed' => $removed), $reverse->getAddedColumns());
        $this->assertSame(array('added' => $added), $reverse->getRemovedColumns());
    }

    public function testGetReverseDiffReversesRenamedColumnPairs()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $from = new Column('bar_old');
        $to = new Column('bar_new');
        $diff->addRenamedColumn($from, $to);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array(array($to, $from)), $reverse->getRenamedColumns());
    }

    public function testGetReverseDiffReversesModifiedColumnDiffs()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $colDiff = new PropulsionColumnDiff();
        $fromCol = new Column('bar');
        $toCol = new Column('bar');
        $colDiff->setFromColumn($fromCol);
        $colDiff->setToColumn($toCol);
        $colDiff->setChangedProperties(array('size' => array(10, 20)));
        $diff->addModifiedColumn('bar', $colDiff);

        $reverse = $diff->getReverseDiff();
        $reversedColDiff = $reverse->getModifiedColumns()['bar'];
        $this->assertSame($toCol, $reversedColDiff->getFromColumn());
        $this->assertSame($fromCol, $reversedColDiff->getToColumn());
        $this->assertSame(array('size' => array(20, 10)), $reversedColDiff->getChangedProperties());
    }

    public function testGetReverseDiffSwapsAddedAndRemovedPkColumns()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $added = new Column('added_pk');
        $removed = new Column('removed_pk');
        $diff->addAddedPkColumn('added_pk', $added);
        $diff->addRemovedPkColumn('removed_pk', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('removed_pk' => $removed), $reverse->getAddedPkColumns());
        $this->assertSame(array('added_pk' => $added), $reverse->getRemovedPkColumns());
    }

    public function testGetReverseDiffReversesRenamedPkColumnPairs()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $from = new Column('id_old');
        $to = new Column('id_new');
        $diff->addRenamedPkColumn($from, $to);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array(array($to, $from)), $reverse->getRenamedPkColumns());
    }

    public function testGetReverseDiffSwapsAddedAndRemovedIndices()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $added = new Index('idx_added');
        $removed = new Index('idx_removed');
        $diff->addAddedIndex('idx_added', $added);
        $diff->addRemovedIndex('idx_removed', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('idx_removed' => $removed), $reverse->getAddedIndices());
        $this->assertSame(array('idx_added' => $added), $reverse->getRemovedIndices());
    }

    public function testGetReverseDiffReversesModifiedIndexPairs()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $fromIdx = new Index('idx_foo');
        $toIdx = new Index('idx_foo');
        $diff->addModifiedIndex('idx_foo', $fromIdx, $toIdx);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('idx_foo' => array($toIdx, $fromIdx)), $reverse->getModifiedIndices());
    }

    public function testGetReverseDiffSwapsAddedAndRemovedFks()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $added = $this->makeForeignKey('fk_added', 'a_id', 'id');
        $removed = $this->makeForeignKey('fk_removed', 'r_id', 'id');
        $diff->addAddedFk('fk_added', $added);
        $diff->addRemovedFk('fk_removed', $removed);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('fk_removed' => $removed), $reverse->getAddedFks());
        $this->assertSame(array('fk_added' => $added), $reverse->getRemovedFks());
    }

    public function testGetReverseDiffReversesModifiedFkPairs()
    {
        $diff = new PropulsionTableDiff();
        list($fromTable, $toTable) = $this->makeTables();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $reverse = $diff->getReverseDiff();
        $this->assertSame(array('fk_foo' => array($toFk, $fromFk)), $reverse->getModifiedFks());
    }

    public function testGetReverseDiffIsFullyInvertible()
    {
        // Reversing a reverse diff should reproduce the original diff's shape.
        list($fromTable, $toTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->setToTable($toTable);
        $diff->addAddedColumn('added', new Column('added'));
        $diff->addRemovedColumn('removed', new Column('removed'));

        $roundTripped = $diff->getReverseDiff()->getReverseDiff();
        $this->assertSame($fromTable, $roundTripped->getFromTable());
        $this->assertSame($toTable, $roundTripped->getToTable());
        $this->assertArrayHasKey('added', $roundTripped->getAddedColumns());
        $this->assertArrayHasKey('removed', $roundTripped->getRemovedColumns());
    }

    // --- __toString() -----------------------------------------------

    public function testToStringWithNoChangesOnlyPrintsHeader()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $this->assertSame("  foo:\n", (string) $diff);
    }

    public function testToStringListsAddedAndRemovedColumns()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->addAddedColumn('bar', new Column('bar'));
        $diff->addRemovedColumn('baz', new Column('baz'));

        $str = (string) $diff;
        $this->assertStringContainsString("addedColumns:\n      - bar\n", $str);
        $this->assertStringContainsString("removedColumns:\n      - baz\n", $str);
    }

    public function testToStringIncludesModifiedColumnDetail()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $colDiff = new PropulsionColumnDiff();
        $col = new Column('bar');
        $col->setTable($fromTable);
        $colDiff->setFromColumn($col);
        $colDiff->setToColumn($col);
        $colDiff->setChangedProperties(array('size' => array(10, 20)));
        $diff->addModifiedColumn('bar', $colDiff);

        $str = (string) $diff;
        $this->assertStringContainsString('modifiedColumns:', $str);
        $this->assertStringContainsString('size', $str);
    }

    public function testToStringListsRenamedColumns()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->addRenamedColumn(new Column('bar_old'), new Column('bar_new'));

        $str = (string) $diff;
        $this->assertStringContainsString("renamedColumns:\n      bar_old: bar_new\n", $str);
    }

    public function testToStringListsIndicesAndFks()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);
        $diff->addAddedIndex('idx_added', new Index('idx_added'));
        $diff->addRemovedIndex('idx_removed', new Index('idx_removed'));
        $diff->addModifiedIndex('idx_mod', new Index('idx_mod'), new Index('idx_mod'));
        $diff->addAddedFk('fk_added', $this->makeForeignKey('fk_added', 'a_id', 'id'));
        $diff->addRemovedFk('fk_removed', $this->makeForeignKey('fk_removed', 'r_id', 'id'));

        $str = (string) $diff;
        $this->assertStringContainsString("addedIndices:\n      - idx_added\n", $str);
        $this->assertStringContainsString("removedIndices:\n      - idx_removed\n", $str);
        $this->assertStringContainsString("modifiedIndices:\n      - idx_mod\n", $str);
        $this->assertStringContainsString("addedFks:\n      - fk_added\n", $str);
        $this->assertStringContainsString("removedFks:\n      - fk_removed\n", $str);
    }

    public function testToStringModifiedFkOnlyReportsChangedAspects()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        // Nothing actually differs between fromFk/toFk here.
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $str = (string) $diff;
        $this->assertStringContainsString("modifiedFks:\n      fk_foo:\n", $str);
        $this->assertStringNotContainsString('localColumns:', $str);
        $this->assertStringNotContainsString('foreignColumns:', $str);
        $this->assertStringNotContainsString('onUpdate:', $str);
        $this->assertStringNotContainsString('onDelete:', $str);
    }

    public function testToStringModifiedFkReportsLocalColumnChange()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk = $this->makeForeignKey('fk_foo', 'other_id', 'id');
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $str = (string) $diff;
        $this->assertStringContainsString('localColumns: from ["bar_id"] to ["other_id"]', $str);
    }

    public function testToStringModifiedFkReportsForeignColumnChange()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'other_id');
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $str = (string) $diff;
        $this->assertStringContainsString('foreignColumns: from ["id"] to ["other_id"]', $str);
    }

    public function testToStringModifiedFkReportsOnUpdateChange()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $fromFk->setOnUpdate(ForeignKey::SETNULL);
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk->setOnUpdate(ForeignKey::CASCADE);
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $str = (string) $diff;
        $this->assertStringContainsString('onUpdate: from SET NULL to CASCADE', $str);
    }

    public function testToStringModifiedFkReportsOnDeleteChange()
    {
        list($fromTable) = $this->makeTables();
        $diff = new PropulsionTableDiff();
        $diff->setFromTable($fromTable);

        $fromFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $fromFk->setOnDelete(ForeignKey::RESTRICT);
        $toFk = $this->makeForeignKey('fk_foo', 'bar_id', 'id');
        $toFk->setOnDelete(ForeignKey::CASCADE);
        $diff->addModifiedFk('fk_foo', $fromFk, $toFk);

        $str = (string) $diff;
        $this->assertStringContainsString('onDelete: from RESTRICT to CASCADE', $str);
    }
}
