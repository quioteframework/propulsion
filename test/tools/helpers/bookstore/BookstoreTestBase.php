<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Propel;

require_once dirname(__FILE__) . '/../IntegrationDatabase.php';

/**
 * Base class contains some methods shared by subclass test cases.
 */
abstract class BookstoreTestBase extends TestCase
{
	protected $con;

	/**
	 * This is run before each unit test; it populates the database.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		try {
			IntegrationDatabase::ensureReady();
		} catch (\RuntimeException $e) {
			$this->markTestSkipped($e->getMessage());
		}

		if (!Propel::isInit()) {
			set_include_path(get_include_path() . PATH_SEPARATOR . realpath(IntegrationDatabase::classesDir()));
			Propel::init(IntegrationDatabase::confFile());
		}

		$this->con = Propel::getConnection(BookPeer::DATABASE_NAME);
		$this->con->beginTransaction();
	}

	/**
	 * This is run after each unit test. It empties the database.
	 */
	protected function tearDown(): void
	{
		parent::tearDown();
		// Only commit if the transaction hasn't failed.
		// This is because tearDown() is also executed on a failed tests,
		// and we don't want to call PropelPDO::commit() in that case
		// since it will trigger an exception on its own
		// ('Cannot commit because a nested transaction was rolled back')
		if (!$this->con) {
			return;
		}
		if ($this->con->isCommitable()) {
			$this->con->commit();
		} elseif ($this->con->isInTransaction()) {
			// A test that threw mid-transaction (e.g. a constraint violation) leaves
			// Postgres itself in an aborted-transaction state -- unlike MySQL, every
			// subsequent statement on this connection fails with "current transaction
			// is aborted" until an explicit ROLLBACK, and Propel's connections are
			// process-wide, not per-test. Without this, one failing test silently
			// breaks every other test that runs afterward in the same process.
			$this->con->forceRollBack();
		}
	}
}
