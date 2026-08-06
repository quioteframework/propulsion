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
use Propulsion\Propulsion;

/**
 * Names any test that unregisters a database adapter it did not register --
 * and puts it back, so the rest of the suite still means something.
 *
 * The sibling of {@see TransactionLeakGuard}, for the same reason and in the
 * same shape. `Propulsion::setConfiguration()` does not just replace the
 * configuration: it drops the entire adapter map. Adapters described in the
 * configuration are rebuilt on demand by `getDB()`, so they come back;
 * adapters registered with `Propulsion::setDB()` -- which is how
 * `PropulsionQuickBuilder` hands every schema it builds to the runtime, and
 * therefore how most of this suite's non-bookstore fixtures work -- cannot be
 * rebuilt from anything and are gone for the rest of the process.
 *
 * The damage is invisible where it happens. The reconfiguring test passes;
 * some later, entirely unrelated test dies with "Unable to find adapter for
 * datasource [...]" naming a datasource it never touched. That has cost this
 * project a red CI twice, and whether it bites at all depends on execution
 * order, so it reproduces locally only with a cleared `.phpunit.cache`.
 * {@see PropulsionStateSnapshot} is the fix for a test that means to
 * reconfigure; this is what notices when one forgot.
 *
 * **Reports and repairs, but does not fail the run**, matching
 * {@see TransactionLeakGuard}. Putting the adapters back before the next test
 * starts is what stops a leak travelling, and is the part that would have
 * prevented the failures this was written for. The backlog of pre-existing
 * leakers this was written against is now empty, so a report here means a new
 * leak: fix the test rather than adding it to a list.
 *
 * **Only adapter *loss* is treated as a leak.** Registering a new adapter is
 * ordinary (every QuickBuilder-based test does it), and the configuration
 * object being replaced is not by itself a fault -- a test whose subject is
 * reconfiguration has to replace it. Losing an adapter someone else
 * registered has no legitimate case: a test that drops the map on purpose is
 * still responsible for putting back what was not its to drop.
 */
final class GlobalStateLeakGuard implements Extension
{
	/** @var array<string, \Propulsion\Adapter\DBAdapter> */
	private static array $adapters = array();

	private static bool $primed = false;

	/** @var list<string> Tests that lost an adapter, in the order found. */
	private static array $leaks = array();

	public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
	{
		$facade->registerSubscribers(
			new class implements FinishedSubscriber {
				public function notify(Finished $event): void
				{
					GlobalStateLeakGuard::checkAfter($event->test()->id());
				}
			},
			new class implements ExecutionFinishedSubscriber {
				public function notify(ExecutionFinished $event): void
				{
					GlobalStateLeakGuard::report();
				}
			}
		);
	}

	public static function checkAfter(string $testId): void
	{
		if (!class_exists(Propulsion::class, false)) {
			return;
		}

		$current = array();
		foreach (Propulsion::getRegisteredAdapterNames() as $name) {
			$current[$name] = Propulsion::getDB($name);
		}

		if (self::$primed) {
			$lost = array_diff_key(self::$adapters, $current);
			if ($lost !== array()) {
				self::$leaks[] = sprintf(
					'%s unregistered %d adapter(s) it did not register: %s',
					$testId,
					count($lost),
					implode(', ', array_slice(array_keys($lost), 0, 8))
						. (count($lost) > 8 ? ', ...' : '')
				);

				// Put them back, so the tests after this one are testing
				// themselves rather than the fallout.
				foreach ($lost as $name => $adapter) {
					Propulsion::setDB($name, $adapter);
					$current[$name] = $adapter;
				}
			}
		}

		self::$adapters = $current;
		self::$primed = true;
	}

	public static function report(): void
	{
		if (self::$leaks === array()) {
			return;
		}

		fwrite(STDERR, sprintf(
			"\n%d test(s) unregistered database adapters belonging to other tests.\n"
			. "Use PropulsionStateSnapshot::capture()/restore() around anything that calls\n"
			. "Propulsion::setConfiguration() -- it drops the whole adapter map, and an adapter\n"
			. "registered with setDB() cannot be rebuilt from the configuration.\n\n%s\n",
			count(self::$leaks),
			implode("\n", array_map(static fn (string $l): string => '  ' . $l, self::$leaks))
		));

		// Deliberately does *not* fail the run, the same choice TransactionLeakGuard
		// makes. Restoring above is what actually protects the suite -- the
		// adapters are back before the next test starts, so a leak can no longer
		// travel -- and a report is enough to get the offending test fixed.
	}
}
