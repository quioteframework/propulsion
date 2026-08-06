<?php


use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

require_once dirname(__FILE__) . '/../IntegrationDatabase.php';

/**
 * Base class for tests on the schemas schema
 */
abstract class SchemasTestBase extends TestCase
{
	private ?PropulsionStateSnapshot $state = null;

	protected function setUp(): void
	{
		parent::setUp();

		try {
			IntegrationDatabase::ensureSchemasReady();
		} catch (\RuntimeException $e) {
			$this->markTestSkipped($e->getMessage());
		}

		// Captured before init(), which drops the entire adapter map on its way
		// to installing the schemas configuration. Re-initialising back to the
		// bookstore conf in tearDown() -- what this used to do -- restores the
		// configuration but not the adapters registered with setDB(), which no
		// configuration can rebuild. Those belong to the QuickBuilder-based
		// tests, so the cost landed on tests with no connection to this one.
		$this->state = PropulsionStateSnapshot::capture();

		Propulsion::init(IntegrationDatabase::schemasConfFile());
	}

	protected function tearDown(): void
	{
		// Null when setUp() skipped before capturing, which is also the case
		// where there is nothing to put back.
		$this->state?->restore();
		$this->state = null;

		parent::tearDown();
	}
}
