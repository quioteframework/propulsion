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
include_once dirname(__FILE__) . '/CmsDataPopulator.php';

/**
 * Base class contains some methods shared by subclass test cases.
 */
abstract class CmsTestBase extends TestCase
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

		if (!Propulsion::isInit()) {
			set_include_path(get_include_path() . PATH_SEPARATOR . realpath(IntegrationDatabase::classesDir()));
			Propulsion::init(IntegrationDatabase::confFile());
		}

		$this->con = Propulsion::getConnection(PagePeer::DATABASE_NAME);
		$this->con->beginTransaction();
		CmsDataPopulator::depopulate($this->con);
		CmsDataPopulator::populate($this->con);
	}

	/**
	 * This is run after each unit test.  It empties the database.
	 */
	protected function tearDown(): void
	{
		if ($this->con && $this->con->isCommitable()) {
			CmsDataPopulator::depopulate($this->con);
			$this->con->commit();
		} elseif ($this->con && $this->con->isInTransaction()) {
			// See BookstoreTestBase::tearDown() -- a failed test leaves Postgres
			// itself in an aborted-transaction state that persists across tests
			// sharing this process-wide connection unless explicitly rolled back.
			$this->con->forceRollBack();
		}
		parent::tearDown();
	}

}
