<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBMSSQL;
use Propulsion\Adapter\DBMySQL;
use Propulsion\Adapter\DBOracle;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Adapter\Pgsql\PgsqlPropulsionPDO;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\AdvisoryLockTimeoutException;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

require_once dirname(__FILE__) . '/../../../tools/helpers/IntegrationDatabase.php';

/**
 * Named/advisory locks: {@see Propulsion::withAdvisoryLock()} and the adapter
 * hooks under it.
 *
 * The mutual-exclusion tests are live against Postgres, with a *second, real
 * connection* standing in for the other process. That second connection is the
 * whole point: an advisory lock is scoped to a session, so nothing about this
 * feature can be demonstrated -- or broken -- within one connection, and a
 * mocked PDO would only prove that the SQL string was assembled.
 */
class AdvisoryLockTest extends TestCase
{
	private const DATASOURCE = 'advisory_lock_test';

	private ?PropulsionPDO $primary = null;
	private ?PDO $other = null;

	protected function setUp(): void
	{
		parent::setUp();

		if (IntegrationDatabase::currentPlatform() !== 'pgsql') {
			$this->markTestSkipped('Exercises Postgres advisory locks specifically; the other platforms have their own primitives and no container-backed coverage here.');
		}

		try {
			$conn = IntegrationDatabase::containerConnection();
		} catch (\RuntimeException $e) {
			$this->markTestSkipped($e->getMessage());
		}

		$dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname=propulsion_test";
		$this->primary = new PgsqlPropulsionPDO($dsn, 'propulsion', 'propulsion');
		$this->primary->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
		$this->other = new PDO($dsn, 'propulsion', 'propulsion');
		$this->other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		Propulsion::setDB(self::DATASOURCE, new DBPostgres());
		Propulsion::setConnection(self::DATASOURCE, $this->primary, Propulsion::CONNECTION_WRITE);
	}

	protected function tearDown(): void
	{
		if ($this->primary !== null) {
			Propulsion::discardConnection($this->primary);
			$this->primary = null;
		}
		$this->other = null;
		parent::tearDown();
	}

	/** The 63-bit key DBPostgres derives from a lock name, so the other session can contend for the same one. */
	private function keyFor(string $name): int
	{
		$unpacked = unpack('J', substr(hash('sha256', $name, true), 0, 8));
		$this->assertIsArray($unpacked);

		return $unpacked[1] & 0x7FFFFFFFFFFFFFFF;
	}

	private function otherSessionTakes(string $name): bool
	{
		return (bool) $this->other->query('SELECT pg_try_advisory_lock(' . $this->keyFor($name) . ')')->fetchColumn();
	}

	private function otherSessionReleases(string $name): void
	{
		$this->other->query('SELECT pg_advisory_unlock(' . $this->keyFor($name) . ')')->fetchColumn();
	}

	public function testTheClosureRunsWithTheLockHeld()
	{
		$name = 'propulsion-test-' . uniqid();
		$observed = null;

		$result = Propulsion::withAdvisoryLock($name, function ($con) use ($name, &$observed) {
			// While we are inside, nobody else can take it.
			$observed = $this->otherSessionTakes($name);

			return 'done';
		}, null, self::DATASOURCE);

		$this->assertSame('done', $result);
		$this->assertFalse($observed, 'the other session must not be able to take the lock inside the closure');
		$this->assertTrue($this->otherSessionTakes($name), 'and must be able to once the closure has returned');
		$this->otherSessionReleases($name);
	}

	public function testTheLockIsReleasedEvenWhenTheClosureThrows()
	{
		$name = 'propulsion-test-' . uniqid();

		try {
			Propulsion::withAdvisoryLock($name, function () {
				throw new \RuntimeException('boom');
			}, null, self::DATASOURCE);
			$this->fail('the closure\'s exception should propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertTrue($this->otherSessionTakes($name), 'the lock must not survive a throwing closure');
		$this->otherSessionReleases($name);
	}

	public function testAZeroTimeoutGivesUpImmediatelyWhenTheLockIsHeldElsewhere()
	{
		$name = 'propulsion-test-' . uniqid();
		$this->assertTrue($this->otherSessionTakes($name));

		$started = microtime(true);
		try {
			Propulsion::withAdvisoryLock($name, function () {
				$this->fail('the closure must not run when the lock was not acquired');
			}, 0.0, self::DATASOURCE);
			$this->fail('expected an AdvisoryLockTimeoutException');
		} catch (AdvisoryLockTimeoutException $e) {
			$this->assertSame($name, $e->getLockName());
			$this->assertSame(0.0, $e->getTimeout());
		}
		$this->assertLessThan(1.0, microtime(true) - $started, 'a zero timeout must not block');

		$this->otherSessionReleases($name);
	}

	public function testAFiniteTimeoutWaitsAndThenGivesUp()
	{
		$name = 'propulsion-test-' . uniqid();
		$this->assertTrue($this->otherSessionTakes($name));

		$started = microtime(true);
		try {
			Propulsion::withAdvisoryLock($name, function () {
				$this->fail('the closure must not run when the lock was not acquired');
			}, 0.5, self::DATASOURCE);
			$this->fail('expected an AdvisoryLockTimeoutException');
		} catch (AdvisoryLockTimeoutException $e) {
			$this->assertSame(0.5, $e->getTimeout());
		}
		$elapsed = microtime(true) - $started;
		$this->assertGreaterThanOrEqual(0.4, $elapsed, 'it should actually have waited');
		$this->assertLessThan(5.0, $elapsed, 'and then stopped waiting');

		$this->otherSessionReleases($name);
	}

	public function testAFailedAcquisitionRestoresTheSessionLockTimeout()
	{
		// The finite-timeout path sets lock_timeout around the wait. It must put
		// back whatever the session had, not reset it to the default -- a
		// deployment that sets its own would otherwise silently lose it.
		$this->primary->exec("SET lock_timeout = '7s'");
		$name = 'propulsion-test-' . uniqid();
		$this->assertTrue($this->otherSessionTakes($name));

		try {
			Propulsion::withAdvisoryLock($name, fn () => null, 0.3, self::DATASOURCE);
		} catch (AdvisoryLockTimeoutException) {
			// expected
		}

		$this->assertSame('7s', $this->primary->query('SHOW lock_timeout')->fetchColumn());
		$this->primary->exec('SET lock_timeout = DEFAULT');
		$this->otherSessionReleases($name);
	}

	public function testWaitingSucceedsOnceTheOtherSessionReleases()
	{
		$name = 'propulsion-test-' . uniqid();
		$this->assertTrue($this->otherSessionTakes($name));
		$this->otherSessionReleases($name);

		$this->assertTrue(
			Propulsion::withAdvisoryLock($name, fn () => true, 2.0, self::DATASOURCE)
		);
	}

	public function testExplicitAcquireAndRelease()
	{
		$name = 'propulsion-test-' . uniqid();

		$this->assertTrue(Propulsion::acquireAdvisoryLock($name, 0.0, self::DATASOURCE));
		$this->assertFalse($this->otherSessionTakes($name));
		$this->assertTrue(Propulsion::releaseAdvisoryLock($name, self::DATASOURCE));
		$this->assertFalse(
			Propulsion::releaseAdvisoryLock($name, self::DATASOURCE),
			'releasing a lock this session does not hold reports false rather than throwing'
		);
		$this->assertTrue($this->otherSessionTakes($name));
		$this->otherSessionReleases($name);
	}

	public function testTheLockSurvivesACommitInsideTheClosure()
	{
		// Session-scoped, not transaction-scoped: pg_advisory_lock rather than
		// pg_advisory_xact_lock. A lock that evaporated at the next commit would
		// be useless for the job-queue case this exists for.
		$name = 'propulsion-test-' . uniqid();

		Propulsion::withAdvisoryLock($name, function (PropulsionPDO $con) use ($name) {
			$con->beginTransaction();
			$con->commit();
			$this->assertFalse($this->otherSessionTakes($name), 'the commit must not have released it');
		}, null, self::DATASOURCE);

		$this->assertTrue($this->otherSessionTakes($name));
		$this->otherSessionReleases($name);
	}
}
