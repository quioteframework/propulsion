<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Propulsion;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;

/**
 * The generated save()/delete() rollback guard (see
 * ObjectBuilder::addSave()/addDelete()).
 *
 * Both wrap their work in `catch (\Throwable $e) { $con->rollBack(); throw $e; }`.
 * That rollback used to be unguarded, so when it *also* failed -- which is
 * exactly what happens when the original throwable came from commit(), since the
 * transaction is already resolved and PDO then raises "There is no active
 * transaction" -- the rollback's own exception superseded the one being handled.
 * An exception thrown from inside a catch block replaces the one it is handling,
 * so the constraint violation, deadlock or hook refusal that actually failed the
 * write was discarded in favour of a message about rollback: the one piece of
 * information the caller could not do without. Every ActiveRecord write goes
 * through this path, which is what makes it worth its own coverage.
 *
 * The connection here is a throwaway `sqlite::memory:` one and no SQL is ever
 * issued against it: the write fails in its PreSave/PreDelete listener, before
 * anything reaches the database, so only the transaction methods are exercised
 * and no bookstore schema is needed on it. (The Author model itself still comes
 * from the built bookstore fixture, hence BookstoreTestBase.)
 */
class BaseObjectSaveRollbackTest extends BookstoreTestBase
{
	protected function tearDown(): void
	{
		$dispatcher = new ReflectionProperty(Propulsion::class, 'eventDispatcher');
		$dispatcher->setValue(null, null);
		// Propulsion::setLogger() has no unset counterpart, and a logger left
		// registered here would keep collecting from every later test in the run.
		$logger = new ReflectionProperty(Propulsion::class, 'logger');
		$logger->setValue(null, null);
		parent::tearDown();
	}

	/**
	 * A connection on which every rollback fails, standing in for "the
	 * transaction was already resolved when we tried to unwind it".
	 */
	private function connectionWhoseRollbackFails(): SqlitePropulsionPDO
	{
		$con = new class ('sqlite::memory:') extends SqlitePropulsionPDO {
			public function rollBack(): bool
			{
				throw new \PDOException('ROLLBACK_EXPLODED');
			}
		};
		$con->setConfiguration(new PropulsionConfiguration(array()));

		return $con;
	}

	/**
	 * @return AbstractLogger&object{messages: list<string>}
	 */
	private function captureLog(): AbstractLogger
	{
		$logger = new class extends AbstractLogger {
			/** @var list<string> */
			public array $messages = array();

			public function log($level, $message, array $context = array()): void
			{
				$this->messages[] = (string) $message;
			}
		};
		Propulsion::setLogger($logger);

		return $logger;
	}

	private function refuseOn(string $eventClass): void
	{
		Propulsion::setEventDispatcher(new class ($eventClass) implements EventDispatcherInterface {
			public function __construct(private readonly string $eventClass)
			{
			}

			public function dispatch(object $event): object
			{
				if ($event instanceof $this->eventClass) {
					throw new RuntimeException('the real cause');
				}

				return $event;
			}
		});
	}

	public function testSaveRethrowsTheOriginalFailureWhenRollbackAlsoFails(): void
	{
		$this->refuseOn(\Propulsion\Event\PreSaveEvent::class);
		$logger = $this->captureLog();

		$author = new Author();
		$author->setFirstName('SaveRollbackGuard');
		$author->setLastName('ShouldNotPersist');

		try {
			$author->save($this->connectionWhoseRollbackFails());
			$this->fail('expected the listener refusal to propagate out of save()');
		} catch (\Throwable $e) {
			$this->assertStringNotContainsString(
				'ROLLBACK_EXPLODED',
				$e->getMessage(),
				"save()'s own rollback failure must not displace the failure it was cleaning up after"
			);
			$this->assertSame('the real cause', $e->getMessage(), 'the original failure propagates, unwrapped');
			$this->assertInstanceOf(RuntimeException::class, $e, 'and with its original type intact');
		}

		$this->assertStringContainsString(
			'ROLLBACK_EXPLODED',
			implode("\n", $logger->messages),
			'the rollback failure is still recorded -- it means the connection may be left dirty'
		);
	}

	public function testDeleteRethrowsTheOriginalFailureWhenRollbackAlsoFails(): void
	{
		// delete() carries its own copy of the same catch block, so it needs its
		// own assertion rather than riding on save()'s.
		$author = new Author();
		$author->setFirstName('DeleteRollbackGuard');
		$author->setLastName('ExistsForNow');
		$author->save($this->con);

		$this->refuseOn(\Propulsion\Event\PreDeleteEvent::class);
		$logger = $this->captureLog();

		try {
			$author->delete($this->connectionWhoseRollbackFails());
			$this->fail('expected the listener refusal to propagate out of delete()');
		} catch (\Throwable $e) {
			$this->assertStringNotContainsString('ROLLBACK_EXPLODED', $e->getMessage());
			$this->assertSame('the real cause', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e);
		}

		$this->assertStringContainsString('ROLLBACK_EXPLODED', implode("\n", $logger->messages));
	}

	/**
	 * The other side of the guard: when the rollback succeeds -- the ordinary
	 * case -- the original throwable must come out completely unwrapped and
	 * nothing must be logged. Wrapping or logging unconditionally would break
	 * callers that catch a specific type from save(), and would add noise to
	 * every ordinary failed write.
	 */
	public function testSaveRethrowsTheOriginalUnwrappedAndLogsNothingWhenRollbackSucceeds(): void
	{
		$this->refuseOn(\Propulsion\Event\PreSaveEvent::class);
		$logger = $this->captureLog();

		$author = new Author();
		$author->setFirstName('SaveRollbackGuardUnwrapped');
		$author->setLastName('ShouldNotPersist');

		try {
			$author->save($this->con);
			$this->fail('expected the listener refusal to propagate out of save()');
		} catch (RuntimeException $e) {
			$this->assertSame('the real cause', $e->getMessage());
		}

		$this->assertSame(
			array(),
			$logger->messages,
			'a rollback that worked is not an event worth logging'
		);
	}

	/**
	 * Happy path, so the guard is pinned as being genuinely out of the way when
	 * nothing fails: an ordinary save still commits and persists.
	 */
	public function testOrdinarySaveStillCommits(): void
	{
		$author = new Author();
		$author->setFirstName('SaveRollbackGuardHappyPath');
		$author->setLastName('ShouldPersist');
		$author->save($this->con);

		$this->assertFalse($author->isNew());
		$this->assertNotNull(
			AuthorQuery::create()->findOneByFirstName('SaveRollbackGuardHappyPath', $this->con)
		);
	}
}
