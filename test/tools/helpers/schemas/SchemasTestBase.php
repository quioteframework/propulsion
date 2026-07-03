<?php


use PHPUnit\Framework\TestCase;
use Propulsion\Propel;
/**
 * This file is part of the Propel package.
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

	protected function setUp(): void
	{
		parent::setUp();

		try {
			IntegrationDatabase::ensureSchemasReady();
		} catch (\RuntimeException $e) {
			$this->markTestSkipped($e->getMessage());
		}

		Propel::init(IntegrationDatabase::schemasConfFile());
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		try {
			IntegrationDatabase::ensureReady();
			Propel::init(IntegrationDatabase::confFile());
		} catch (\RuntimeException $e) {
			// Bookstore fixtures unavailable (e.g. Docker missing) -- nothing to
			// reset back to, and the schemas test above will already have been
			// skipped in that case.
		}
	}
}
