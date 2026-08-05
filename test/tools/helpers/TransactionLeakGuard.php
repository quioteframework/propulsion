<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;

/**
 * Fails the run, by name, on the first test that leaves a database transaction
 * open -- and unwinds it so the rest of the suite still means something.
 *
 * Propulsion's connections are process-wide, and the bookstore fixture points
 * three datasources (bookstore, bookstore-cms, bookstore-behavior) at one
 * physical database. A transaction left open on any of them therefore holds its
 * write locks for the remainder of the run, and every later test that writes
 * the same rows through one of the *other* connections blocks on them until the
 * server's lock timeout. That turns one ordinary assertion failure into hundreds
 * of unrelated "Lock wait timeout exceeded" / "canceling statement due to lock
 * timeout" errors, and a four-minute suite into a twenty-seven-minute one --
 * with the test that actually broke buried somewhere in the middle.
 *
 * A leak is easy to introduce without noticing: a test that opens a transaction
 * and commits it at the end of the method leaks one the moment an assertion
 * before that commit fails, because the failure aborts the method. So the guard
 * belongs here, around every test, rather than in the tearDown of whichever
 * class last got it wrong.
 *
 * Rolling back is always the right unwind: a transaction still open when the
 * test that opened it has finished is by definition work nothing is waiting to
 * commit.
 */
final class TransactionLeakGuard implements Extension
{
	/** @var list<string> Tests that left a transaction open, in the order found. */
	private static array $leaks = array();

	public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
	{
		$facade->registerSubscribers(
			new class implements FinishedSubscriber {
				public function notify(Finished $event): void
				{
					TransactionLeakGuard::checkAfter($event->test()->id());
				}
			},
			new class implements ExecutionFinishedSubscriber {
				public function notify(ExecutionFinished $event): void
				{
					TransactionLeakGuard::report();
				}
			}
		);
	}

	public static function checkAfter(string $testId): void
	{
		if (!class_exists(Propulsion::class, false)) {
			return;
		}

		foreach (Propulsion::getOpenConnections() as $con) {
			if (!$con instanceof PropulsionPDO || !$con->isInTransaction()) {
				continue;
			}

			self::$leaks[] = $testId;
			try {
				// forceRollBack(), not rollBack(): the leak may be several
				// levels deep, and unwinding one level would leave the
				// outer one -- and its locks -- exactly where they were.
				$con->forceRollBack();
			} catch (\Throwable $e) {
				// Nothing useful to do here, and throwing would replace the
				// test's own result with this. The report below still names
				// the test, which is the part that matters.
			}
		}
	}

	public static function report(): void
	{
		if (self::$leaks === array()) {
			return;
		}

		$unique = array_values(array_unique(self::$leaks));
		fwrite(
			STDERR,
			PHP_EOL . 'Transaction leak: ' . count($unique) . ' test(s) finished with a database transaction still open.' . PHP_EOL
			. 'Each was rolled back so the rest of the run could proceed; every one of them is a bug in the test.' . PHP_EOL
			. '  - ' . implode(PHP_EOL . '  - ', $unique) . PHP_EOL . PHP_EOL
		);
	}
}
