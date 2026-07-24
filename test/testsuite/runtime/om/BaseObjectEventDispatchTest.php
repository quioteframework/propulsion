<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Event\ModelLifecycleEvent;
use Propulsion\Event\PostDeleteEvent;
use Propulsion\Event\PostInsertEvent;
use Propulsion\Event\PostSaveEvent;
use Propulsion\Event\PostUpdateEvent;
use Propulsion\Event\PreDeleteEvent;
use Propulsion\Event\PreInsertEvent;
use Propulsion\Event\PreSaveEvent;
use Propulsion\Event\PreUpdateEvent;
use Propulsion\Event\StoppableModelLifecycleEvent;
use Propulsion\Propulsion;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the PSR-14 event dispatching wired into BaseObject's preSave()/
 * postSave()/preInsert()/postInsert()/preUpdate()/postUpdate()/preDelete()/
 * postDelete() hook methods.
 *
 * This deliberately drives the hooks directly on a bare TestableEventObject
 * (see BaseObjectTest.php's TestableBaseObject for precedent) rather than
 * through a generated Object's save()/delete() -- those require the bookstore
 * fixtures and a live database (see GeneratedObjectTest for that level of
 * coverage), whereas the dispatch wiring itself is exercised the same way
 * either way: save()/delete() just call $this->preSave($con) etc., which
 * resolve to these BaseObject methods for any class that doesn't override
 * them.
 *
 * Propulsion::$eventDispatcher is process-wide static state (mirroring
 * Propulsion::$logger), so every test resets it via reflection in
 * tearDown() to avoid leaking a dispatcher into unrelated tests.
 */
class BaseObjectEventDispatchTest extends TestCase
{
	protected function tearDown(): void
	{
		parent::tearDown();
		$prop = new ReflectionProperty(Propulsion::class, 'eventDispatcher');
		$prop->setValue(null, null);
	}

	public function testNoDispatcherRegisteredIsANoOp(): void
	{
		$this->assertFalse(Propulsion::hasEventDispatcher());

		$object = new TestableEventObject();
		$this->assertTrue($object->preSave(), 'preSave() still returns true (no veto) with no dispatcher registered');
		$object->postSave();
		$this->assertTrue($object->preInsert());
		$object->postInsert();
		$this->assertTrue($object->preUpdate());
		$object->postUpdate();
		$this->assertTrue($object->preDelete());
		$object->postDelete();

		// Propulsion::dispatch() itself is a no-op too: it returns the event unchanged.
		$event = new PostSaveEvent($object);
		$this->assertSame($event, Propulsion::dispatch($event));
	}

	/**
	 * @return array<string, array{0: string, 1: class-string}>
	 */
	public static function hookProvider(): array
	{
		return [
			'preSave' => ['preSave', PreSaveEvent::class],
			'preInsert' => ['preInsert', PreInsertEvent::class],
			'preUpdate' => ['preUpdate', PreUpdateEvent::class],
			'preDelete' => ['preDelete', PreDeleteEvent::class],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function preHookMethodProvider(): array
	{
		return [
			'preSave' => ['preSave'],
			'preInsert' => ['preInsert'],
			'preUpdate' => ['preUpdate'],
			'preDelete' => ['preDelete'],
		];
	}

	/**
	 * @dataProvider hookProvider
	 * @param class-string $eventClass
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('hookProvider')]
	public function testPreHookDispatchesCorrectEventWithObjectAndConnection(string $preMethod, string $eventClass): void
	{
		$dispatcher = new RecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$object = new TestableEventObject();
		$con = $this->createStub(PropulsionPDO::class);
		$result = $object->$preMethod($con);

		$this->assertTrue($result, 'no listener vetoed, so the hook returns true');
		$this->assertCount(1, $dispatcher->events);
		$this->assertInstanceOf($eventClass, $dispatcher->events[0]);
		$this->assertInstanceOf(ModelLifecycleEvent::class, $dispatcher->events[0]);
		$this->assertSame($object, $dispatcher->events[0]->getObject());
		$this->assertSame($con, $dispatcher->events[0]->getConnection());
	}

	public function testPostSaveDispatchesPostSaveEventWithObjectAndConnection(): void
	{
		$dispatcher = new RecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$object = new TestableEventObject();
		$con = $this->createStub(PropulsionPDO::class);
		$object->postSave($con);

		$this->assertCount(1, $dispatcher->events);
		$this->assertInstanceOf(PostSaveEvent::class, $dispatcher->events[0]);
		$this->assertSame($object, $dispatcher->events[0]->getObject());
		$this->assertSame($con, $dispatcher->events[0]->getConnection());
	}

	public function testPostInsertDispatchesPostInsertEvent(): void
	{
		$dispatcher = new RecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$object = new TestableEventObject();
		$object->postInsert();

		$this->assertCount(1, $dispatcher->events);
		$this->assertInstanceOf(PostInsertEvent::class, $dispatcher->events[0]);
	}

	public function testPostUpdateDispatchesPostUpdateEvent(): void
	{
		$dispatcher = new RecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$object = new TestableEventObject();
		$object->postUpdate();

		$this->assertCount(1, $dispatcher->events);
		$this->assertInstanceOf(PostUpdateEvent::class, $dispatcher->events[0]);
	}

	public function testPostDeleteDispatchesPostDeleteEvent(): void
	{
		$dispatcher = new RecordingEventDispatcher();
		Propulsion::setEventDispatcher($dispatcher);

		$object = new TestableEventObject();
		$object->postDelete();

		$this->assertCount(1, $dispatcher->events);
		$this->assertInstanceOf(PostDeleteEvent::class, $dispatcher->events[0]);
	}

	/**
	 * @dataProvider preHookMethodProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('preHookMethodProvider')]
	public function testListenerCanVetoViaStopPropagation(string $preMethod): void
	{
		Propulsion::setEventDispatcher(new VetoingEventDispatcher());

		$object = new TestableEventObject();
		$this->assertFalse($object->$preMethod(), 'a listener calling stopPropagation() vetoes the operation, same as an overridden hook returning false');
	}

	public function testPostEventsAreNotStoppable(): void
	{
		// A plain instanceof/assertNotInstanceOf check here is a tautology PHPStan
		// flags at level 9 (PostSaveEvent statically never implements
		// StoppableEventInterface) -- go through class_implements() instead so the
		// invariant is still enforced at test time (protects against someone later
		// making PostSaveEvent extend StoppableModelLifecycleEvent by mistake)
		// without asserting something the type system already guarantees.
		$implements = class_implements(PostSaveEvent::class);
		$this->assertIsArray($implements);
		$this->assertNotContains(\Psr\EventDispatcher\StoppableEventInterface::class, $implements, 'post-events represent something that already happened, so vetoing them makes no sense');
	}

	/**
	 * A listener that throws is not caught by Propulsion::dispatch() -- the
	 * exception propagates out of dispatch(), and therefore out of whichever
	 * hook triggered the dispatch. This is a deliberate choice: Propulsion
	 * has no way to know whether a listener's exception represents a bug the
	 * caller needs to see immediately or something safe to ignore, so it
	 * does not swallow or log-and-continue on the caller's behalf.
	 */
	public function testThrowingListenerExceptionPropagatesOutOfDispatch(): void
	{
		Propulsion::setEventDispatcher(new ThrowingEventDispatcher());

		$object = new TestableEventObject();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('listener boom');
		$object->preSave();
	}

	public function testThrowingListenerExceptionPropagatesOutOfPostHookToo(): void
	{
		Propulsion::setEventDispatcher(new ThrowingEventDispatcher());

		$object = new TestableEventObject();
		$this->expectException(RuntimeException::class);
		$object->postSave();
	}
}

class TestableEventObject extends \Propulsion\OM\BaseObject
{
	public function getPrimaryKey()
	{
		return null;
	}

	public function clearAllReferences(bool $deep = false): void
	{
	}
}

class RecordingEventDispatcher implements EventDispatcherInterface
{
	/** @var array<int, object> */
	public array $events = [];

	public function dispatch(object $event): object
	{
		$this->events[] = $event;
		return $event;
	}
}

class VetoingEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		if ($event instanceof StoppableModelLifecycleEvent) {
			$event->stopPropagation();
		}
		return $event;
	}
}

class ThrowingEventDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		throw new RuntimeException('listener boom');
	}
}
