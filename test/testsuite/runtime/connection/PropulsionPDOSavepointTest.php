<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\Session;

/**
 * Exercises PropulsionPDO's real SAVEPOINT-based nested transactions directly
 * against a live SQLite connection (`sqlite::memory:`) -- SQLite is one of the
 * three drivers (alongside pgsql and mysql, see
 * PropulsionPDO::$savepointCapableDrivers) real savepoint support is implemented
 * for, and, unlike pgsql/mysql, needs no testcontainers/Docker to exercise for
 * real (no mocking of PDO itself -- every assertion here is the result of an
 * actual SQL round-trip). This deliberately complements (not replaces)
 * PropulsionPDOTest's Postgres/MySQL-backed nested-transaction tests, which
 * cover the same contract against those other two capable drivers.
 */
class PropulsionPDOSavepointTest extends TestCase
{
    private PropulsionPDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PropulsionPDO('sqlite::memory:');
        $this->pdo->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
    }

    /**
     * @return array<int, string>
     */
    private function names(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM widgets ORDER BY name');
        if ($stmt === false) {
            throw new \RuntimeException('Query failed');
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * The central happy path this whole feature exists for: rolling back a
     * nested transaction must undo only the work done since its own
     * beginTransaction(), not poison the outer transaction -- which must then
     * go on to commit normally, persisting the outer work.
     */
    public function testNestedRollbackUndoesOnlyInnerWorkAndOuterCommitSucceeds(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");

        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('B')");
        $this->assertSame(['A', 'B'], $this->names(), 'both inserts visible before the nested rollback');

        $this->pdo->rollBack();
        $this->assertSame(['A'], $this->names(), 'nested rollback undid only B, A is still visible mid-transaction');
        $this->assertTrue($this->pdo->isCommitable(), 'outer transaction must not be poisoned by a savepoint rollback');

        $this->assertTrue($this->pdo->commit());
        $this->assertSame(['A'], $this->names(), 'A was persisted, B was not');
        $this->assertFalse($this->pdo->isInTransaction());
    }

    /**
     * Work done in the outer transaction *after* a nested rollback (but before
     * the outer commit) must survive -- proving the outer transaction really
     * continues normally rather than merely being allowed to commit as a no-op.
     */
    public function testWorkAfterNestedRollbackIsPersistedOnOuterCommit(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");

        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('B')");
        $this->pdo->rollBack();

        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('C')");
        $this->pdo->commit();

        $this->assertSame(['A', 'C'], $this->names());
    }

    /**
     * Deep nesting: each depth gets its own savepoint name
     * (PROPULSION_SAVEPOINT_LEVEL<depth>), so rolling back the innermost level
     * must undo only that level's work, leaving every shallower level's work
     * intact and committable.
     */
    public function testDeeplyNestedRollbackOnlyUndoesInnermostLevel(): void
    {
        $this->pdo->beginTransaction(); // depth 1
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('L1')");

        $this->pdo->beginTransaction(); // depth 2
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('L2')");

        $this->pdo->beginTransaction(); // depth 3
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('L3')");

        $this->pdo->beginTransaction(); // depth 4
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('L4')");
        $this->pdo->rollBack(); // undo only L4, back to depth 3

        $this->assertSame(['L1', 'L2', 'L3'], $this->names());

        $this->pdo->commit(); // depth 3 -> 2
        $this->pdo->commit(); // depth 2 -> 1
        $this->pdo->commit(); // depth 1 -> 0, real commit

        $this->assertSame(['L1', 'L2', 'L3'], $this->names());
        $this->assertFalse($this->pdo->isInTransaction());
    }

    /**
     * Sibling nested transactions opened one after another at the *same*
     * nesting depth reuse the same savepoint name (PropulsionPDO::getSavepointName()
     * is depth-keyed, not call-keyed). This must not collide: SAVEPOINT with an
     * already-used name replaces the earlier one on every capable driver, so
     * reusing the name across siblings is safe by construction. This is the
     * "savepoint name collision across deep nesting" case called out for
     * explicit coverage.
     */
    public function testSiblingNestedTransactionsAtSameDepthReuseSavepointNameSafely(): void
    {
        $this->pdo->beginTransaction(); // depth 1

        $this->pdo->beginTransaction(); // depth 2, savepoint LEVEL2
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('sibling1')");
        $this->pdo->rollBack(); // undoes sibling1, releases back to depth 1

        $this->pdo->beginTransaction(); // depth 2 again, re-declares savepoint LEVEL2
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('sibling2')");
        $this->pdo->commit(); // depth 2 -> 1, keeps sibling2

        $this->pdo->commit(); // depth 1 -> 0

        $this->assertSame(['sibling2'], $this->names());
    }

    /**
     * Calling rollBack()/commit() with no open transaction (nestedTransactionCount
     * already 0) must be a safe no-op -- mismatched call ordering (e.g. an extra
     * rollBack() in a finally block after the transaction was already closed)
     * shouldn't throw or touch the real connection.
     */
    public function testCommitAndRollBackAreNoOpsWithoutAnOpenTransaction(): void
    {
        $this->assertFalse($this->pdo->isInTransaction());
        $this->assertTrue($this->pdo->commit(), 'commit() without an open transaction is a no-op returning true');
        $this->assertTrue($this->pdo->rollBack(), 'rollBack() without an open transaction is a no-op returning true');
        $this->assertSame(0, $this->pdo->getNestedTransactionCount());
    }

    /**
     * One commit() call per beginTransaction() call is the expected contract;
     * an extra, unmatched commit() beyond the outermost one must likewise be a
     * harmless no-op rather than erroring against a connection that is no
     * longer in a transaction.
     */
    public function testExtraUnmatchedCommitIsANoOp(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");
        $this->pdo->commit();
        $this->assertFalse($this->pdo->isInTransaction());

        $this->assertTrue($this->pdo->commit(), 'extra unmatched commit() is a no-op');
        $this->assertSame(['A'], $this->names());
    }

    /**
     * forceRollBack() must discard every open savepoint level in one go (a
     * plain ROLLBACK on the real connection discards the base transaction and
     * everything savepointed on top of it), reset the nesting counter to 0,
     * and leave the connection immediately reusable for a fresh transaction --
     * this is exactly what Session::reset() relies on to fully unwind a
     * dangling multi-level transaction at a worker request boundary.
     */
    public function testForceRollBackDiscardsEveryOpenSavepointLevel(): void
    {
        $this->pdo->beginTransaction(); // depth 1
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");
        $this->pdo->beginTransaction(); // depth 2
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('B')");
        $this->pdo->beginTransaction(); // depth 3
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('C')");

        $this->assertTrue($this->pdo->forceRollBack());

        $this->assertSame(0, $this->pdo->getNestedTransactionCount());
        $this->assertFalse($this->pdo->isInTransaction());
        $this->assertSame([], $this->names(), 'every level (A, B, C) was rolled back');

        // The connection, and the savepoint names it used, must be fully
        // reusable afterwards -- a leftover savepoint from before forceRollBack()
        // must not linger and collide with a fresh nested transaction.
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('D')");
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO widgets (name) VALUES ('E')");
        $this->pdo->commit();
        $this->pdo->commit();

        $this->assertSame(['D', 'E'], $this->names());
    }

    /**
     * On a driver PropulsionPDO doesn't consider savepoint-capable, nested
     * transactions must fall back exactly to the pre-existing depth-counter/
     * poison-flag emulation: a nested rollBack() undoes nothing by itself, and
     * the outer commit() then throws instead of silently discarding the rolled
     * back nested work. Forcing this via an overridden supportsSavepoints()
     * (rather than needing a real non-capable driver like Oracle/MSSQL) keeps
     * this test Docker-free while still exercising the real fallback code path
     * in PropulsionPDO::beginTransaction()/commit()/rollBack().
     */
    public function testFallsBackToPoisonFlagEmulationWhenSavepointsAreNotSupported(): void
    {
        $con = new class ('sqlite::memory:') extends PropulsionPDO {
            protected function supportsSavepoints(): bool
            {
                return false;
            }
        };
        $con->setConfiguration(new \Propulsion\Config\PropulsionConfiguration(array()));
        $con->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        $con->beginTransaction();
        $con->exec("INSERT INTO widgets (name) VALUES ('A')");

        $con->beginTransaction();
        $con->exec("INSERT INTO widgets (name) VALUES ('B')");

        $con->rollBack();
        // Old emulation: nothing is undone by the nested rollBack() itself --
        // B is still visible mid-transaction, unlike the savepoint-capable path.
        $stmt = $con->query('SELECT name FROM widgets ORDER BY name');
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $this->assertSame(['A', 'B'], $rows);
        $this->assertFalse($con->isCommitable(), 'outer transaction is poisoned, as before savepoint support existed');

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Cannot commit because a nested transaction was rolled back');
        $con->commit();
    }

    /**
     * Session::reset() (the worker-request-boundary safety net) must force-roll-
     * back a connection sitting several savepoint levels deep just as completely
     * as a single-level (pre-savepoints) dangling transaction -- otherwise a
     * leaked multi-level transaction would poison the next request's reuse of
     * the same connection. Uses a throwaway Session instance (not
     * Propulsion::getSession()) and a manually-registered datasource name so this
     * doesn't disturb any other test's connections/session state.
     */
    public function testSessionResetUnwindsAFullStackOfOpenSavepoints(): void
    {
        Propulsion::setConnection('propulsion_pdo_savepoint_test', $this->pdo);
        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec("INSERT INTO widgets (name) VALUES ('A')");
            $this->pdo->beginTransaction();
            $this->pdo->exec("INSERT INTO widgets (name) VALUES ('B')");
            $this->pdo->beginTransaction();
            $this->pdo->exec("INSERT INTO widgets (name) VALUES ('C')");
            $this->assertTrue($this->pdo->isInTransaction());

            (new Session())->reset();

            $this->assertFalse(
                $this->pdo->isInTransaction(),
                'Session::reset() must fully unwind every open savepoint level, not just the innermost one'
            );
            $this->assertSame(0, $this->pdo->getNestedTransactionCount());
            $this->assertSame([], $this->names());
        } finally {
            Propulsion::forceReconnect('propulsion_pdo_savepoint_test');
        }
    }
}
