<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\EntityState;
use Propulsion\Event\PostFlushEvent;
use Propulsion\Event\PreFlushEvent;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\UnitOfWork;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Coverage for the core Propulsion\UnitOfWork class -- see UNIT_OF_WORK.md
 * for the design. Uses the plain bookstore Author/Book fixtures.
 */
class UnitOfWorkTest extends BookstoreTestBase
{
	protected function tearDown(): void
	{
		$prop = new ReflectionProperty(Propulsion::class, 'eventDispatcher');
		$prop->setValue(null, null);
		parent::tearDown();
	}

	public function testFlushInsertsTrackedFkParentBeforeChild(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$book = new Book();
		$book->setTitle('War And Peace (UnitOfWork)');
		$book->setISBN('UOW-0000000001');
		$book->setAuthor($author);

		$uow = new UnitOfWork($this->con);
		// Tracked in child-before-parent order deliberately -- flush() must
		// still insert the author first (it's referenced by the book's FK),
		// regardless of the order entities were tracked in.
		$uow->track($book);
		$uow->track($author);

		$affected = $uow->flush();

		$this->assertGreaterThanOrEqual(2, $affected, 'both the author and the book were inserted');
		$this->assertFalse($author->isNew());
		$this->assertFalse($book->isNew());
		$this->assertNotNull($author->getId());

		// The re-sync half of the FK-parent cascade (see
		// ObjectBuilder::addDoSave()'s doc comment) must still have run even
		// though the recursive save() half was suppressed -- otherwise the
		// book's author_id would still hold whatever it was before the
		// author had a real primary key (null, here).
		$this->assertSame($author->getId(), $book->getAuthorId());

		$reloadedBook = BookQuery::create()->findPk($book->getId(), $this->con);
		$this->assertSame($author->getId(), $reloadedBook->getAuthorId());
	}

	public function testFlushWithoutTrackingFkParentLeavesForeignKeyUnset(): void
	{
		// Documents the known limitation from UnitOfWork's own doc comment:
		// an entity reachable only via another tracked entity's FK setter,
		// and not itself tracked, is never persisted by flush() -- cascade
		// suppression is the mechanism that replaces the automatic cascade,
		// not an optimization layered on top of it.
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$book = new Book();
		$book->setTitle('War And Peace (untracked author)');
		$book->setISBN('UOW-0000000002');
		$book->setAuthor($author);

		$uow = new UnitOfWork($this->con);
		$uow->track($book);
		// $author deliberately not tracked.

		$uow->flush();

		$this->assertTrue($author->isNew(), 'the untracked author was never saved');
		$this->assertFalse($book->isNew());
		$this->assertNull($book->getAuthorId(), 'the FK re-sync picked up the still-unsaved author\'s null id');
	}

	public function testFlushUpdatesModifiedEntity(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		$author->setFirstName('Lev');

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$affected = $uow->flush();

		$this->assertSame(1, $affected);
		$this->assertFalse($author->isModified());

		$reloaded = AuthorQuery::create()->findPk($author->getId(), $this->con);
		$this->assertSame('Lev', $reloaded->getFirstName());
	}

	public function testFlushDeletesMarkedEntity(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);
		$id = $author->getId();

		$uow = new UnitOfWork($this->con);
		$uow->markDeleted($author);
		$affected = $uow->flush();

		$this->assertSame(1, $affected);
		$this->assertTrue($author->isDeleted());
		$this->assertNull(AuthorQuery::create()->findPk($id, $this->con));
	}

	public function testFlushSkipsUnchangedEntity(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		// Not modified, not new, not marked deleted -- tracking it should be
		// a complete no-op.
		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$affected = $uow->flush();

		$this->assertSame(0, $affected);
	}

	public function testAttachAddedForcesInsertRegardlessOfIsNew(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);
		$originalId = $author->getId();

		// Simulate a detached object that thinks it's new (the BaseObject
		// default) but should actually be treated as new for this flush --
		// contrived, since it's already real, but exercises the same
		// isNew()-forcing path attach(Added) uses for a genuinely detached
		// entity (e.g. one hydrated from a deserialized request body).
		$fresh = new Author();
		$fresh->setFirstName('Anton');
		$fresh->setLastName('Chekhov');

		$uow = new UnitOfWork($this->con);
		$uow->attach($fresh, EntityState::Added);
		$this->assertTrue($fresh->isNew());
		$uow->flush();

		$this->assertFalse($fresh->isNew());
		$this->assertNotSame($originalId, $fresh->getId());
	}

	public function testAttachModifiedForcesUpdatePath(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		// A freshly-hydrated object defaults isNew() === true; attach()ing
		// it as Modified must flip that to false so flush() takes the
		// update path instead of trying to insert a duplicate row.
		$detached = new Author();
		$detached->setId($author->getId());
		$detached->setFirstName('Leo');
		$detached->setLastName('Tolstoy'); // corrected spelling
		$this->assertTrue($detached->isNew(), 'a freshly-hydrated object defaults to isNew()');

		$uow = new UnitOfWork($this->con);
		$uow->attach($detached, EntityState::Modified);
		$this->assertFalse($detached->isNew());
		$affected = $uow->flush();

		$this->assertSame(1, $affected);
		$reloaded = AuthorQuery::create()->findPk($author->getId(), $this->con);
		$this->assertSame('Tolstoy', $reloaded->getLastName());
	}

	public function testDetachRemovesEntityFromNextFlush(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$uow->detach($author);

		$this->assertSame(array(), $uow->getTrackedEntities());
		$uow->flush();
		$this->assertTrue($author->isNew(), 'detached before flush(), so never saved');
	}

	public function testPreFlushEventCanAbortFlush(): void
	{
		Propulsion::setEventDispatcher(new class implements EventDispatcherInterface {
			public function dispatch(object $event): object
			{
				if ($event instanceof PreFlushEvent) {
					$event->stopPropagation();
				}
				return $event;
			}
		});

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$affected = $uow->flush();

		$this->assertSame(0, $affected);
		$this->assertTrue($author->isNew(), 'the flush was vetoed before anything was persisted');
	}

	public function testPostFlushEventCarriesAffectedRows(): void
	{
		$dispatcher = new class implements EventDispatcherInterface {
			public array $events = array();
			public function dispatch(object $event): object
			{
				$this->events[] = $event;
				return $event;
			}
		};
		Propulsion::setEventDispatcher($dispatcher);

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$affected = $uow->flush();

		$postEvents = array_values(array_filter($dispatcher->events, fn ($e) => $e instanceof PostFlushEvent));
		$this->assertCount(1, $postEvents);
		$this->assertSame($affected, $postEvents[0]->getAffectedRows());
		$this->assertSame($uow, $postEvents[0]->getUnitOfWork());
	}

	public function testFlushRollsBackWholeBatchOnFailure(): void
	{
		// Distinctive name -- other tests in this file also create a plain
		// "Leo Tolstoi", and this shared bookstore fixture DB persists across
		// the whole PHPUnit run, so a generic name here couldn't distinguish
		// "this test's row was rolled back" from "some earlier test's row is
		// still sitting there".
		$author = new Author();
		$author->setFirstName('UnitOfWorkRollbackTest');
		$author->setLastName('ShouldNotPersist');

		// The failure is triggered via an UPDATE (an existing author's
		// last_name set to null violates the NOT NULL constraint), not an
		// INSERT -- confirmed live against MSSQL: pdo_dblib's OUTPUT-clause
		// id-fold (DBMSSQL::getInsertReturningSql(), the same one every
		// INSERT uses since DBAdapter::supportsInsertReturning() went
		// unconditional) silently swallows a failed INSERT's constraint
		// violation instead of throwing -- execute() returns true and the
		// OUTPUT clause's result set is simply empty, so extractInsertedId()
		// gets back false/null with no exception at all. A real,
		// independently-worth-fixing platform gap (see KNOWN_ISSUES.md), but
		// orthogonal to what this test is actually about, so the failure
		// here is engineered to avoid it rather than gated around it.
		$existingAuthor = new Author();
		$existingAuthor->setFirstName('ExistingAuthorToCorrupt');
		$existingAuthor->setLastName('Fine For Now');
		$existingAuthor->save($this->con);
		$existingAuthor->setLastName(null);

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$uow->track($existingAuthor);

		try {
			$uow->flush();
			$this->fail('expected a PropulsionException from the NOT NULL violation');
		} catch (PropulsionException $e) {
			// expected
		}

		if ($this->con->isCommitable()) {
			// Savepoint-capable platform: see
			// OptimisticLockBehaviorTest::testUnitOfWorkFlushRollsBackOnStaleRow()'s
			// matching comment -- flush()'s own rollBack() (nested inside
			// BookstoreTestBase's outer per-test transaction) issued a real
			// ROLLBACK TO SAVEPOINT here, so $author's otherwise-valid
			// insert, ordered before $existingAuthor's failing update, is
			// undone immediately. $author's own in-memory isNew()/
			// modifiedColumns state was already mutated by its (now-undone)
			// INSERT before $existingAuthor's failure was even discovered --
			// a real, if orthogonal, "in-memory state can outlive a rolled-
			// back transaction" characteristic, not something UnitOfWork
			// introduces or could paper over without snapshotting every
			// tracked entity's state up front.
			$this->assertNull(AuthorQuery::create()->findOneByFirstName('UnitOfWorkRollbackTest', $this->con));
		} else {
			// MSSQL: see the same matching comment -- a rollBack() nested
			// this deep can't undo anything immediately (no mid-transaction
			// ROLLBACK TO SAVEPOINT to issue); it only poisons the *outer*
			// transaction, confirmed here via isCommitable() being false.
			$this->assertFalse($this->con->isCommitable(), 'the outer transaction was poisoned, even though nothing could be undone immediately');
		}
	}

	public function testFlushRollsBackWhenAListenerThrowsANonPropulsionException(): void
	{
		// flush() used to catch only PropulsionException, so anything else --
		// a PSR-14 listener's own exception, a TypeError, a raw PDOException
		// escaping commit() -- propagated out with the transaction flush()
		// opened still open. On Postgres that poisons the connection for
		// whatever reuses it next ("current transaction is aborted" until an
		// explicit ROLLBACK), and the only thing that eventually cleans it up
		// is Session::reset()'s dangling-transaction sweep at the *next
		// request boundary* -- long after the damage.
		//
		// Asserted via the nesting depth rather than isInTransaction(),
		// because BookstoreTestBase::setUp() already holds an outer
		// per-test transaction: the point is that flush()'s own nesting
		// level was unwound, not that the connection left transactions
		// altogether.
		Propulsion::setEventDispatcher(new class implements EventDispatcherInterface {
			public function dispatch(object $event): object
			{
				if ($event instanceof \Propulsion\Event\PreSaveEvent) {
					throw new RuntimeException('listener says no');
				}
				return $event;
			}
		});

		$depthBefore = $this->con->getNestedTransactionCount();

		$author = new Author();
		$author->setFirstName('UnitOfWorkThrowableRollbackTest');
		$author->setLastName('ShouldNotPersist');

		$uow = new UnitOfWork($this->con);
		$uow->track($author);

		try {
			$uow->flush();
			$this->fail('expected the listener RuntimeException to propagate out of flush()');
		} catch (RuntimeException $e) {
			$this->assertSame('listener says no', $e->getMessage(), 'the original throwable is rethrown unwrapped');
		}

		$this->assertSame(
			$depthBefore,
			$this->con->getNestedTransactionCount(),
			'flush() must roll back its own transaction before letting a non-PropulsionException escape'
		);

		// Tracked entities are deliberately left tracked on failure, so a
		// caller can inspect and retry -- same contract as the
		// PropulsionException path.
		$this->assertSame(array($author), $uow->getTrackedEntities());
	}

	public function testGetTrackedEntities(): void
	{
		$author = new Author();
		$book = new Book();

		$uow = new UnitOfWork($this->con);
		$uow->track($author);
		$uow->track($book);
		// Tracking the same instance twice must not duplicate it.
		$uow->track($author);

		$tracked = $uow->getTrackedEntities();
		$this->assertCount(2, $tracked);
		$this->assertTrue(in_array($author, $tracked, true));
		$this->assertTrue(in_array($book, $tracked, true));
	}
}
