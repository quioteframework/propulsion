<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\EntityState;
use Propulsion\Exception\ConcurrencyException;
use Propulsion\UnitOfWork;

/**
 * Tests for OptimisticLockBehavior, against the shared bookstore fixture's
 * table15 (default "version" column) and table16 (custom "lock_version"
 * column) -- see behavior-optimistic-lock-schema.xml.
 */
class OptimisticLockBehaviorTest extends BookstoreTestBase
{
	public function testModifyTableAddsVersionColumn(): void
	{
		$table = Table15Peer::getTableMap();
		$this->assertTrue($table->hasColumn('version'));
		$this->assertTrue(method_exists('Table15', 'getVersion'));
	}

	public function testModifyTableRespectsCustomColumnName(): void
	{
		$table = Table16Peer::getTableMap();
		$this->assertTrue($table->hasColumn('lock_version'));
		$this->assertTrue(method_exists('Table16', 'getLockVersion'));
		// The behavior's generic getOptimisticLockPreviousVersion()/preUpdate()
		// wiring works off whichever column the version_column parameter
		// names -- not hardcoded to "version".
		$this->assertTrue(method_exists('Table16', 'getOptimisticLockPreviousVersion'));
	}

	public function testNewRowStartsAtVersionZero(): void
	{
		$t = new Table15();
		$t->setTitle('Original');
		$t->save($this->con);

		$this->assertSame(0, $t->getVersion());
	}

	public function testSaveBumpsVersionOnUpdate(): void
	{
		$t = new Table15();
		$t->setTitle('Original');
		$t->save($this->con);

		$t->setTitle('Changed');
		$t->save($this->con);

		$this->assertSame(1, $t->getVersion());

		$reloaded = Table15Query::create()->findPk($t->getId(), $this->con);
		$this->assertSame(1, $reloaded->getVersion());
		$this->assertSame('Changed', $reloaded->getTitle());
	}

	public function testNoOpSaveDoesNotBumpVersion(): void
	{
		$t = new Table15();
		$t->setTitle('Original');
		$t->save($this->con);

		// Nothing changed -- isModified() is false, so this must be a
		// complete no-op: no UPDATE at all, and in particular no version
		// bump (setVersion() would itself mark the object modified, turning
		// every no-op save() into a real UPDATE if this weren't guarded).
		$affected = $t->save($this->con);

		$this->assertSame(0, $affected);
		$this->assertSame(0, $t->getVersion());
	}

	public function testConcurrentUpdateThrowsConcurrencyException(): void
	{
		$t = new Table15();
		$t->setTitle('Original');
		$t->save($this->con);
		$id = $t->getId();

		// Two independent copies of the same row, as if loaded by two
		// different requests/processes -- clearing the instance pool between
		// the two findPk() calls is what makes them genuinely distinct PHP
		// objects instead of both resolving to the same pooled instance
		// (Propulsion's identity map, on by default, would otherwise make
		// this test simulate updating the *same* object twice, never a real
		// conflict).
		$copyA = Table15Query::create()->findPk($id, $this->con);
		Table15Peer::clearInstancePool();
		$copyB = Table15Query::create()->findPk($id, $this->con);

		$copyA->setTitle('Changed by A');
		$copyA->save($this->con);
		$this->assertSame(1, $copyA->getVersion());

		// $copyB still thinks the row is at version 0 -- its UPDATE's WHERE
		// clause (version = 0) no longer matches anything.
		$copyB->setTitle('Changed by B');
		try {
			$copyB->save($this->con);
			$this->fail('expected a ConcurrencyException');
		} catch (ConcurrencyException $e) {
			$this->assertSame($copyB, $e->getEntity());
		}

		// $copyA's change won; $copyB's was rejected, not silently applied.
		$reloaded = Table15Query::create()->findPk($id, $this->con);
		$this->assertSame('Changed by A', $reloaded->getTitle());
		$this->assertSame(1, $reloaded->getVersion());
	}

	public function testUnitOfWorkFlushRollsBackOnStaleRow(): void
	{
		$t = new Table15();
		$t->setTitle('Original');
		$t->save($this->con);
		$id = $t->getId();

		$fresh = new Table15();
		$fresh->setTitle('Fresh Row');

		$stale = Table15Query::create()->findPk($id, $this->con);
		// See testConcurrentUpdateThrowsConcurrencyException()'s comment on
		// why the instance pool must be cleared for this to simulate a real
		// two-process conflict rather than updating the same object twice.
		Table15Peer::clearInstancePool();
		// Made stale by an update that happens between loading $stale and
		// flushing the UnitOfWork below.
		$viaOtherProcess = Table15Query::create()->findPk($id, $this->con);
		$viaOtherProcess->setTitle('Changed elsewhere');
		$viaOtherProcess->save($this->con);

		$stale->setTitle('Changed via stale copy');

		$uow = new UnitOfWork($this->con);
		$uow->track($fresh);
		$uow->track($stale);

		try {
			$uow->flush();
			$this->fail('expected a ConcurrencyException');
		} catch (ConcurrencyException $e) {
			// expected
		}

		if ($this->con->isCommitable()) {
			// Savepoint-capable platform (Postgres/MySQL/SQLite/Oracle):
			// flush()'s own rollBack() -- itself nested inside
			// BookstoreTestBase's outer per-test transaction -- issued a real
			// ROLLBACK TO SAVEPOINT, so $fresh's otherwise-valid insert,
			// ordered before $stale's failing update, is undone immediately
			// and the outer transaction is left healthy.
			$this->assertNull(Table15Query::create()->findOneByTitle('Fresh Row', $this->con));
			$reloaded = Table15Query::create()->findPk($id, $this->con);
			$this->assertSame('Changed elsewhere', $reloaded->getTitle());
		} else {
			// MSSQL has no savepoints (see PropulsionPDO::$savepointCapableDrivers)
			// -- confirmed live, this is the one platform among the five this
			// project supports where this branch actually runs. A rollBack()
			// nested this deep can't undo anything immediately (there's no
			// mid-transaction ROLLBACK TO SAVEPOINT to issue); it can only
			// mark the *outer* transaction uncommitable (see
			// PropulsionPDO::rollBack()'s own doc comment), which is exactly
			// what just got asserted via isCommitable() above being false.
			// $fresh's insert is genuinely still visible on this connection
			// right now -- it's undone only once whichever caller owns the
			// outer transaction rolls it back (BookstoreTestBase::tearDown()
			// does, via forceRollBack(), since isCommitable() is false here).
			// A caller driving UnitOfWork::flush() directly at the top level
			// (no pre-existing outer transaction, the normal case outside
			// this test's own transaction-wrapping harness) doesn't hit this
			// caveat at all -- flush()'s rollBack() would be the outermost
			// one, which always does a real ROLLBACK TRANSACTION on every
			// platform, MSSQL included (see PropulsionPDO::rollBack()'s
			// opcount === 1 branch).
			$this->assertFalse($this->con->isCommitable(), 'the outer transaction was poisoned, even though nothing could be undone immediately');
		}
	}
}
