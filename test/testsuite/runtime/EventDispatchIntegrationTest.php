<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Event\ModelLifecycleEvent;
use Propulsion\Event\PostBulkDeleteEvent;
use Propulsion\Event\PostDeleteEvent;
use Propulsion\Event\PostInsertEvent;
use Propulsion\Event\PostSaveEvent;
use Propulsion\Event\PostUpdateEvent;
use Propulsion\Event\PreBulkDeleteEvent;
use Propulsion\Event\PreDeleteEvent;
use Propulsion\Event\PreInsertEvent;
use Propulsion\Event\PreSaveEvent;
use Propulsion\Event\PreUpdateEvent;
use Propulsion\Event\StoppableModelLifecycleEvent;
use Propulsion\Propulsion;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * End-to-end coverage of PSR-14 event dispatch through the real generated
 * save()/delete() code path (as opposed to BaseObjectEventDispatchTest,
 * which drives the BaseObject hook methods directly without a database).
 * Uses the plain `Author` bookstore fixture, which does not override any of
 * BaseObject's preSave()/postSave()/etc. hooks, so save()/delete() resolve
 * straight to BaseObject's dispatch-wired implementations.
 *
 * Requires the bookstore fixtures/database (see BookstoreTestBase) -- skips
 * itself if Docker/Postgres aren't available.
 *
 * Propulsion::$eventDispatcher is process-wide static state (mirroring
 * Propulsion::$logger), so every test resets it via reflection in
 * tearDown() to avoid leaking a dispatcher into unrelated tests.
 */
class EventDispatchIntegrationTest extends BookstoreTestBase
{
	protected function tearDown(): void
	{
		$prop = new ReflectionProperty(Propulsion::class, 'eventDispatcher');
		$prop->setValue(null, null);
		parent::tearDown();
	}

	public function testSaveDispatchesPreAndPostInsertEventsInOrder(): void
	{
		$dispatcher = new IntegrationRecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame(
			[PreSaveEvent::class, PreInsertEvent::class, PostInsertEvent::class, PostSaveEvent::class],
			$classes
		);
		foreach ($dispatcher->events as $event) {
			$this->assertInstanceOf(ModelLifecycleEvent::class, $event);
			$this->assertSame($author, $event->getObject());
			$this->assertSame($this->con, $event->getConnection());
		}
	}

	public function testSaveDispatchesPreAndPostUpdateEventsInOrder(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		$dispatcher = new IntegrationRecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$author->setFirstName('Lev');
		$author->save($this->con);

		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame(
			[PreSaveEvent::class, PreUpdateEvent::class, PostUpdateEvent::class, PostSaveEvent::class],
			$classes
		);
	}

	/**
	 * BaseObject::delete()'s generated code doesn't issue the row's DELETE
	 * itself -- it builds a one-row `AuthorQuery::create()->
	 * filterByPrimaryKey(...)->delete($con)` internally (see
	 * BaseAuthor::delete()) and lets ModelCriteria::delete() run the actual
	 * SQL. Since that's the same bulk delete() method
	 * ModelCriteriaBulkEventDispatchTest covers, it dispatches its own
	 * PreBulkDeleteEvent/PostBulkDeleteEvent too, sandwiched between the
	 * per-object PreDeleteEvent/PostDeleteEvent -- this is a deliberate
	 * consequence of wiring events into the real bulk call site rather than
	 * special-casing "internal, one-row" callers, not a bug: a listener
	 * that only cares about "some DELETE statement ran" can rely on the
	 * bulk events firing for both genuinely-bulk and single-object deletes.
	 */
	public function testDeleteDispatchesPreAndPostDeleteEventsAroundTheInternalBulkDeleteEvents(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		$dispatcher = new IntegrationRecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$author->delete($this->con);

		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame(
			[PreDeleteEvent::class, PreBulkDeleteEvent::class, PostBulkDeleteEvent::class, PostDeleteEvent::class],
			$classes
		);
		$this->assertTrue($author->isDeleted());
	}

	/**
	 * Mirrors GeneratedObjectTest::testPreSaveFalse() (TestAuthorSaveFalse
	 * overriding preSave() to return false) but vetoes via a PSR-14 listener
	 * calling stopPropagation() instead of a hook-method override.
	 */
	public function testListenerVetoingPreSaveEventAbortsSave(): void
	{
		Propulsion::setEventDispatcher(new IntegrationVetoingEventDispatcher(PreSaveEvent::class));

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$res = $author->save($this->con);

		$this->assertSame(0, $res, 'a vetoed save() reports 0 affected rows, just like an overridden preSave() returning false');
		$this->assertTrue($author->isNew(), 'the object was never actually inserted');
	}

	/**
	 * Mirrors GeneratedObjectTest::testPreDeleteFalse() (TestAuthorDeleteFalse
	 * overriding preDelete() to return false) but vetoes via a PSR-14 listener
	 * calling stopPropagation() instead of a hook-method override.
	 */
	public function testListenerVetoingPreDeleteEventAbortsDelete(): void
	{
		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$author->save($this->con);

		Propulsion::setEventDispatcher(new IntegrationVetoingEventDispatcher(PreDeleteEvent::class));
		$author->delete($this->con);

		$this->assertFalse($author->isDeleted(), 'a vetoed delete() leaves the object (and its row) alone, just like an overridden preDelete() returning false');
	}

	public function testNoDispatcherRegisteredSaveAndDeleteStillWorkNormally(): void
	{
		$this->assertFalse(Propulsion::hasEventDispatcher());

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');
		$res = $author->save($this->con);
		$this->assertGreaterThan(0, $res);
		$this->assertFalse($author->isNew());

		$author->delete($this->con);
		$this->assertTrue($author->isDeleted());
	}

	/**
	 * A listener that throws during save() is not caught by the generated
	 * save() method's `catch (PropulsionException $e)` block (a plain
	 * RuntimeException isn't a PropulsionException), so it propagates all
	 * the way out of save() uncaught -- and, notably, the transaction that
	 * save() began is left neither committed nor rolled back in that case
	 * (see Propulsion::dispatch()'s docblock). Callers registering listeners
	 * that might throw should either keep them exception-safe or throw
	 * PropulsionException specifically if they want save()'s existing
	 * rollback-on-PropulsionException behavior.
	 */
	public function testThrowingListenerDuringSavePropagatesAndLeavesTransactionOpen(): void
	{
		Propulsion::setEventDispatcher(new IntegrationThrowingEventDispatcher());

		$author = new Author();
		$author->setFirstName('Leo');
		$author->setLastName('Tolstoi');

		$nestedCountBefore = $this->con->getNestedTransactionCount();
		try {
			$author->save($this->con);
			$this->fail('Expected the listener exception to propagate out of save()');
		} catch (RuntimeException $e) {
			$this->assertSame('listener boom', $e->getMessage());
		}

		// save()'s own beginTransaction() was never matched by a commit/rollback
		// since only PropulsionException is caught -- clean up explicitly so
		// tearDown() (which expects a single outer transaction) doesn't choke.
		$this->assertSame($nestedCountBefore + 1, $this->con->getNestedTransactionCount());
		$this->con->forceRollBack();
		$this->con->beginTransaction();
	}
}

class IntegrationRecordingEventDispatcher implements EventDispatcherInterface
{
	/** @var array<int, object> */
	public array $events = [];

	public function dispatch(object $event): object
	{
		$this->events[] = $event;
		return $event;
	}
}

class IntegrationVetoingEventDispatcher implements EventDispatcherInterface
{
	/**
	 * @param class-string $eventClassToVeto Only stop propagation for this
	 *        specific event class, so tests can veto e.g. only PreDeleteEvent
	 *        without also silently vetoing the PreSaveEvent that precedes it.
	 */
	public function __construct(private readonly string $eventClassToVeto)
	{
	}

	public function dispatch(object $event): object
	{
		if ($event instanceof StoppableModelLifecycleEvent && $event::class === $this->eventClassToVeto) {
			$event->stopPropagation();
		}
		return $event;
	}
}

class IntegrationThrowingEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		throw new RuntimeException('listener boom');
	}
}
