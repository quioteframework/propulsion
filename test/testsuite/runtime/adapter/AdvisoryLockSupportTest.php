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
use Propulsion\Adapter\DBNone;
use Propulsion\Adapter\DBOracle;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Exception\AdvisoryLockTimeoutException;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

/**
 * The parts of the advisory-lock feature that need no live server: which
 * platforms claim support, what an unsupported one does, and the exception a
 * contended lock produces.
 *
 * The behaviour itself -- that the lock actually excludes another session --
 * is in AdvisoryLockTest, live against Postgres, because it cannot be shown
 * any other way.
 */
class AdvisoryLockSupportTest extends TestCase
{
	private const SQLITE_DATASOURCE = 'advisory_lock_support_test_sqlite';

	protected function setUp(): void
	{
		parent::setUp();
		Propulsion::setDB(self::SQLITE_DATASOURCE, new DBSQLite());
	}

	public function testWhichPlatformsHaveAdvisoryLocks()
	{
		$this->assertTrue((new DBPostgres())->supportsAdvisoryLocks(), 'pg_advisory_lock');
		$this->assertTrue((new DBMySQL())->supportsAdvisoryLocks(), 'GET_LOCK');
		$this->assertTrue((new DBMSSQL())->supportsAdvisoryLocks(), 'sp_getapplock');
		$this->assertTrue((new DBOracle())->supportsAdvisoryLocks(), 'DBMS_LOCK');

		// SQLite locks the whole database and has no named-lock primitive.
		$this->assertFalse((new DBSQLite())->supportsAdvisoryLocks());
		$this->assertFalse((new DBNone())->supportsAdvisoryLocks());
	}

	public function testSupportsAdvisoryLocksFacadeReportsThePlatform()
	{
		$this->assertFalse(Propulsion::supportsAdvisoryLocks(self::SQLITE_DATASOURCE));
	}

	public function testAnUnsupportedPlatformThrowsRatherThanRunningUnserialised()
	{
		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('has no advisory locks');
		Propulsion::withAdvisoryLock('x', function () {
			$this->fail('the closure must not run on a platform with no advisory locks');
		}, null, self::SQLITE_DATASOURCE);
	}

	public function testTheAdapterHooksThemselvesThrowOnAnUnsupportedPlatform()
	{
		$db = new DBSQLite();
		$con = $this->createStub(\Propulsion\Connection\PropulsionPDO::class);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('does not support advisory locks');
		$db->acquireAdvisoryLock($con, 'x');
	}

	public function testTimeoutExceptionCarriesTheNameAndWait()
	{
		$e = new AdvisoryLockTimeoutException('nightly-run', 2.5);
		$this->assertSame('nightly-run', $e->getLockName());
		$this->assertSame(2.5, $e->getTimeout());
		$this->assertStringContainsString('nightly-run', $e->getMessage());
		$this->assertStringContainsString('within 2.5s', $e->getMessage());
	}

	public function testTimeoutExceptionWithoutAWaitDoesNotClaimOne()
	{
		$e = new AdvisoryLockTimeoutException('nightly-run');
		$this->assertNull($e->getTimeout());
		$this->assertStringNotContainsString('within', $e->getMessage());
	}

	/**
	 * The Postgres key derivation is the one piece of the mechanism a caller
	 * can observe indirectly (two names must not collide, and the same name
	 * must always produce the same key across processes, or two app servers
	 * would not contend at all).
	 */
	public function testPostgresLockKeyIsStableAndNonNegative()
	{
		$db = new class extends DBPostgres {
			public function keyFor(string $name): int
			{
				return $this->advisoryLockKey($name);
			}
		};

		$this->assertSame($db->keyFor('a'), $db->keyFor('a'));
		$this->assertNotSame($db->keyFor('a'), $db->keyFor('b'));
		$this->assertGreaterThanOrEqual(0, $db->keyFor('a'));
		$this->assertGreaterThanOrEqual(0, $db->keyFor(str_repeat('x', 5000)));
	}
}
