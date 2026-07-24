<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Event\BulkModelEvent;
use Propulsion\Event\PostBulkDeleteEvent;
use Propulsion\Event\PostBulkUpdateEvent;
use Propulsion\Event\PreBulkDeleteEvent;
use Propulsion\Event\PreBulkUpdateEvent;
use Propulsion\Event\StoppableBulkModelEvent;
use Propulsion\Propulsion;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the PSR-14 event dispatching wired into the bulk Query-level path:
 * {@see \Propulsion\Query\ModelCriteria::update()}, ::delete(), and
 * ::deleteAll() -- as opposed to BaseObjectEventDispatchTest/
 * EventDispatchIntegrationTest, which cover the per-object save()/delete()
 * path on a single loaded BaseObject instance.
 *
 * Unlike the per-object events, ModelCriteria can't be meaningfully
 * exercised without a real database and a real generated model/tablemap --
 * its constructor resolves `constant($modelName . '::PEER')` and looks the
 * model up in Propulsion's live DatabaseMap, so there is no "bare subclass,
 * no DB" unit-testing seam here the way BaseObjectEventDispatchTest has for
 * BaseObject. This is therefore entirely an integration-level test (see
 * BookstoreTestBase), following the style of the existing
 * ModelCriteriaHooksTest, which already exercises plain
 * `new ModelCriteria('bookstore', 'Book')` against the real bookstore
 * fixtures without needing a generated *Query subclass.
 *
 * Propulsion::$eventDispatcher is process-wide static state (mirroring
 * Propulsion::$logger), so every test resets it via reflection in
 * tearDown() to avoid leaking a dispatcher into unrelated tests.
 */
class ModelCriteriaBulkEventDispatchTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::depopulate();
		BookstoreDataPopulator::populate();
	}

	protected function tearDown(): void
	{
		$prop = new ReflectionProperty(Propulsion::class, 'eventDispatcher');
		$prop->setValue(null, null);
		parent::tearDown();
	}

	public function testDeleteDispatchesPreAndPostBulkDeleteEventsWithCriteriaConnectionAndRowCount(): void
	{
		$dispatcher = new RecordingBulkEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$affectedRows = $c->delete($this->con);

		$this->assertSame(1, $affectedRows);
		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame([PreBulkDeleteEvent::class, PostBulkDeleteEvent::class], $classes);

		foreach ($dispatcher->events as $event) {
			$this->assertInstanceOf(BulkModelEvent::class, $event);
			$this->assertInstanceOf(ModelCriteria::class, $event->getCriteria());
			$this->assertSame($this->con, $event->getConnection());
		}
		$this->assertSame(1, $dispatcher->events[1]->getAffectedRowCount());
	}

	public function testDeleteWithMultiRowFilterDispatchesPostEventWithCorrectRowCount(): void
	{
		$dispatcher = new RecordingBulkEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Price > ?', 11);
		$affectedRows = $c->delete($this->con);

		$this->assertSame(3, $affectedRows, 'Quicksilver (11.99), Don Juan (12.99) and The Tin Drum (13.99) all match Price > 11');
		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame([PreBulkDeleteEvent::class, PostBulkDeleteEvent::class], $classes);
		$this->assertSame(3, $dispatcher->events[1]->getAffectedRowCount());
	}

	/**
	 * deleteAll() (unlike delete()) ignores any filters set on the Criteria
	 * -- it always issues an unconditional DELETE FROM against the whole
	 * table (see BasePeer::doDeleteAll()) -- so the PostBulkDeleteEvent it
	 * dispatches reports every row in the table, irrespective of any
	 * where() call.
	 */
	public function testDeleteAllDispatchesPreAndPostBulkDeleteEventsWithFullTableRowCount(): void
	{
		$dispatcher = new RecordingBulkEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$c = new ModelCriteria('bookstore', 'Book');
		$affectedRows = $c->deleteAll($this->con);

		$this->assertSame(4, $affectedRows, 'deleteAll() ignores filters and empties the whole table');
		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame([PreBulkDeleteEvent::class, PostBulkDeleteEvent::class], $classes);
		$this->assertSame(4, $dispatcher->events[1]->getAffectedRowCount());
	}

	public function testUpdateDispatchesPreAndPostBulkUpdateEventsWithValuesAndRowCount(): void
	{
		$dispatcher = new RecordingBulkEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$affectedRows = $c->update(['ISBN' => '9999'], $this->con);

		$this->assertSame(1, $affectedRows);
		$classes = array_map(fn (object $e) => $e::class, $dispatcher->events);
		$this->assertSame([PreBulkUpdateEvent::class, PostBulkUpdateEvent::class], $classes);

		$preEvent = $dispatcher->events[0];
		$this->assertInstanceOf(PreBulkUpdateEvent::class, $preEvent);
		$this->assertSame(['ISBN' => '9999'], $preEvent->getValues());
		$this->assertFalse($preEvent->isForceIndividualSaves());

		$postEvent = $dispatcher->events[1];
		$this->assertInstanceOf(PostBulkUpdateEvent::class, $postEvent);
		$this->assertSame(1, $postEvent->getAffectedRowCount());
		$this->assertSame(['ISBN' => '9999'], $postEvent->getValues());
	}

	public function testListenerCanRewriteValuesViaPreBulkUpdateEvent(): void
	{
		Propulsion::setEventDispatcher(new RewritingUpdateEventDispatcher());

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$c->update(['ISBN' => 'ignored'], $this->con);

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$book = $c->findOne();
		$this->assertSame('rewritten-by-listener', $book->getISBN(), 'a PreBulkUpdateEvent listener calling setValues() replaces what actually gets applied');
	}

	public function testListenerVetoingPreBulkDeleteEventAbortsDeleteWithoutOpeningATransaction(): void
	{
		Propulsion::setEventDispatcher(new VetoingBulkEventDispatcher(PreBulkDeleteEvent::class));

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$affectedRows = $c->delete($this->con);

		$this->assertSame(0, $affectedRows, 'a vetoed delete() reports 0 affected rows');

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$this->assertSame(1, $c->count(), 'the row was never actually deleted');
	}

	public function testListenerVetoingPreBulkDeleteEventAbortsDeleteAll(): void
	{
		Propulsion::setEventDispatcher(new VetoingBulkEventDispatcher(PreBulkDeleteEvent::class));

		$c = new ModelCriteria('bookstore', 'Book');
		$affectedRows = $c->deleteAll($this->con);

		$this->assertSame(0, $affectedRows);
		$this->assertSame(4, (new ModelCriteria('bookstore', 'Book'))->count(), 'no row was actually deleted');
	}

	public function testListenerVetoingPreBulkUpdateEventAbortsUpdate(): void
	{
		Propulsion::setEventDispatcher(new VetoingBulkEventDispatcher(PreBulkUpdateEvent::class));

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$affectedRows = $c->update(['ISBN' => '9999'], $this->con);

		$this->assertSame(0, $affectedRows, 'a vetoed update() reports 0 affected rows');

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$book = $c->findOne();
		$this->assertSame('0140422161', $book->getISBN(), 'the row was never actually updated');
	}

	public function testNoDispatcherRegisteredBulkOpsStillWorkNormally(): void
	{
		$this->assertFalse(Propulsion::hasEventDispatcher());

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$this->assertSame(1, $c->update(['ISBN' => '9999'], $this->con));

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');
		$this->assertSame(1, $c->delete($this->con));

		$c = new ModelCriteria('bookstore', 'Book');
		$this->assertSame(3, $c->deleteAll($this->con));
	}

	public function testPostEventsAreNotStoppable(): void
	{
		$implements = class_implements(PostBulkDeleteEvent::class);
		$this->assertIsArray($implements);
		$this->assertNotContains(\Psr\EventDispatcher\StoppableEventInterface::class, $implements);

		$implements = class_implements(PostBulkUpdateEvent::class);
		$this->assertIsArray($implements);
		$this->assertNotContains(\Psr\EventDispatcher\StoppableEventInterface::class, $implements);
	}

	/**
	 * A listener that throws while dispatching a PreBulkDeleteEvent runs
	 * before delete() opens its transaction, so nothing needs rolling back --
	 * the exception just propagates straight out of delete().
	 */
	public function testThrowingListenerOnPreEventPropagatesBeforeAnyTransaction(): void
	{
		Propulsion::setEventDispatcher(new ThrowingBulkEventDispatcher());

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');

		$nestedCountBefore = $this->con->getNestedTransactionCount();
		try {
			$c->delete($this->con);
			$this->fail('Expected the listener exception to propagate out of delete()');
		} catch (RuntimeException $e) {
			$this->assertSame('listener boom', $e->getMessage());
		}
		$this->assertSame($nestedCountBefore, $this->con->getNestedTransactionCount(), 'no transaction was opened since the pre-event dispatch happens before beginTransaction()');
	}

	/**
	 * A listener that throws while dispatching the PostBulkDeleteEvent runs
	 * inside delete()'s try block, before commit() -- like the per-object
	 * postSave() case (see EventDispatchIntegrationTest), only
	 * PropulsionException is caught there, so a plain RuntimeException
	 * propagates out with the transaction left open.
	 */
	public function testThrowingListenerOnPostEventPropagatesAndLeavesTransactionOpen(): void
	{
		Propulsion::setEventDispatcher(new ThrowingOnPostBulkEventDispatcher());

		$c = new ModelCriteria('bookstore', 'Book', 'b');
		$c->where('b.Title = ?', 'Don Juan');

		$nestedCountBefore = $this->con->getNestedTransactionCount();
		try {
			$c->delete($this->con);
			$this->fail('Expected the listener exception to propagate out of delete()');
		} catch (RuntimeException $e) {
			$this->assertSame('listener boom', $e->getMessage());
		}
		$this->assertSame($nestedCountBefore + 1, $this->con->getNestedTransactionCount());
		$this->con->forceRollBack();
		$this->con->beginTransaction();
	}
}

class RecordingBulkEventDispatcher implements EventDispatcherInterface
{
	/** @var array<int, object> */
	public array $events = [];

	public function dispatch(object $event): object
	{
		$this->events[] = $event;
		return $event;
	}
}

class VetoingBulkEventDispatcher implements EventDispatcherInterface
{
	/**
	 * @param class-string $eventClassToVeto
	 */
	public function __construct(private readonly string $eventClassToVeto)
	{
	}

	public function dispatch(object $event): object
	{
		if ($event instanceof StoppableBulkModelEvent && $event::class === $this->eventClassToVeto) {
			$event->stopPropagation();
		}
		return $event;
	}
}

class RewritingUpdateEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		if ($event instanceof PreBulkUpdateEvent) {
			$event->setValues(['ISBN' => 'rewritten-by-listener']);
		}
		return $event;
	}
}

class ThrowingBulkEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		throw new RuntimeException('listener boom');
	}
}

class ThrowingOnPostBulkEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		if ($event instanceof PostBulkDeleteEvent || $event instanceof PostBulkUpdateEvent) {
			throw new RuntimeException('listener boom');
		}
		return $event;
	}
}
