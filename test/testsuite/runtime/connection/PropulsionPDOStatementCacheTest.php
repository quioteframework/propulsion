<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Connection\PropulsionPDO;

/**
 * PropulsionPDO's opt-in prepared-statement cache
 * (PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES).
 *
 * Runs against a live `sqlite::memory:` connection through the real driver
 * subclass -- no mocking of PDO, and no Docker: everything here is about what
 * prepare() does with its own cache, which needs nothing but a working
 * connection.
 */
class PropulsionPDOStatementCacheTest extends TestCase
{
	private PropulsionPDO $pdo;

	protected function setUp(): void
	{
		parent::setUp();
		$this->pdo = new SqlitePropulsionPDO('sqlite::memory:');
		$this->pdo->setConfiguration(new PropulsionConfiguration(array()));
		$this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
	}

	/**
	 * The regression this guards: a prepare() that failed was memoised, and every
	 * later prepare() of the same SQL handed the failure straight back without
	 * ever retrying.
	 *
	 * PDO::prepare() returns false rather than throwing when the error mode is
	 * ERRMODE_SILENT or ERRMODE_WARNING, and `isset()` is true for a stored false
	 * (it is only false for null), so the cache both stored the failure and
	 * reported a hit for it. A single transient failure -- a table not yet created
	 * by a migration running concurrently, as here, or a connection that dropped
	 * mid-request -- therefore poisoned that one SQL string for the whole life of
	 * the connection, which for a pooled or worker-mode connection is far longer
	 * than the condition that caused it.
	 */
	public function testAFailedPrepareIsNotCached(): void
	{
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		$this->pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

		$sql = 'SELECT id FROM arrives_later';
		$this->assertFalse($this->pdo->prepare($sql), 'the table does not exist yet, so this prepare fails');

		$this->pdo->exec('CREATE TABLE arrives_later (id INTEGER PRIMARY KEY)');

		$this->assertInstanceOf(
			PDOStatement::class,
			$this->pdo->prepare($sql),
			'once the condition clears, the same SQL must prepare successfully rather than returning the cached failure'
		);
	}

	/**
	 * The happy path the cache exists for, asserted so the fix above is pinned as
	 * not having simply disabled it: a successful prepare is cached, and the same
	 * SQL comes back as the very same statement object.
	 */
	public function testASuccessfulPrepareIsCached(): void
	{
		$this->pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

		$first = $this->pdo->prepare('SELECT id FROM widgets');
		$second = $this->pdo->prepare('SELECT id FROM widgets');

		$this->assertInstanceOf(PDOStatement::class, $first);
		$this->assertSame($first, $second, 'a cache hit returns the same statement instance');
	}

	public function testDistinctSqlIsCachedSeparately(): void
	{
		$this->pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

		$byId = $this->pdo->prepare('SELECT id FROM widgets');
		$byName = $this->pdo->prepare('SELECT name FROM widgets');

		$this->assertNotSame($byId, $byName);
	}

	public function testCachingOffReturnsAFreshStatementEachTime(): void
	{
		// Off is the default; asserted explicitly because the whole cache path --
		// including the bug above -- is unreachable unless a caller opts in.
		$this->assertFalse($this->pdo->getAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES));

		$first = $this->pdo->prepare('SELECT id FROM widgets');
		$second = $this->pdo->prepare('SELECT id FROM widgets');

		$this->assertInstanceOf(PDOStatement::class, $first);
		$this->assertNotSame($first, $second);
	}

	public function testClearStatementCacheDropsCachedStatements(): void
	{
		$this->pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);

		$first = $this->pdo->prepare('SELECT id FROM widgets');
		$this->pdo->clearStatementCache();
		$second = $this->pdo->prepare('SELECT id FROM widgets');

		$this->assertNotSame(
			$first,
			$second,
			'clearing the cache must force a fresh prepare -- handleDroppedConnection() relies on this, '
			. 'since every handle in the cache belongs to the dead connection'
		);
	}

	/**
	 * A cached statement is still a usable one -- the cache must not hand back
	 * something exhausted or half-consumed by a previous execution.
	 */
	public function testACachedStatementStillExecutes(): void
	{
		$this->pdo->setAttribute(PropulsionPDO::PROPEL_ATTR_CACHE_PREPARES, true);
		$this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");

		$sql = 'SELECT name FROM widgets ORDER BY name';

		$first = $this->pdo->prepare($sql);
		$this->assertNotFalse($first);
		$first->execute();
		$this->assertSame(array('A'), $first->fetchAll(PDO::FETCH_COLUMN, 0));

		$second = $this->pdo->prepare($sql);
		$this->assertNotFalse($second);
		$second->execute();
		$this->assertSame(array('A'), $second->fetchAll(PDO::FETCH_COLUMN, 0));
	}
}
