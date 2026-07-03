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
		if ($this->con && $this->con->isCommitable()) {
			$this->con->commit();
		}
	}
}
