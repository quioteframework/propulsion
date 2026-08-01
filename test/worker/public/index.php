<?php

/**
 * FrankenPHP worker-mode entrypoint used by the worker-safety test matrix
 * (see test/worker/driver.php, KNOWN_ISSUES.md "Phase 4 -- Worker-safety
 * rework").
 *
 * This boots Propulsion exactly once, then serves many requests in a loop
 * the way a real persistent-worker deployment (FrankenPHP, RoadRunner,
 * Swoole, ...) would -- calling Propulsion::getSession()->reset() at each
 * request boundary, which is the wiring this whole rework exists to prove
 * actually prevents state bleeding between requests that share a process.
 *
 * It deliberately does NOT use generated Propel/Propulsion model classes --
 * spinning up the code generator inside a from-scratch Docker image would
 * add a lot of moving parts without adding certainty, since the actual
 * worker-safety contract lives entirely in Session/ServiceContainer/
 * PropulsionPDO. Instead it drives those classes directly (the same calls a
 * generated Peer's addInstanceToPool()/getInstanceFromPool()/etc. make under
 * the hood -- see runtime/Lib/Session.php), plus a real PropulsionPDO
 * connection (SQLite or Postgres, selected by WORKER_DB_ADAPTER -- see
 * test/worker/driver.php) for the transaction-cleanup property.
 *
 * The Caddyfile this runs under (test/worker/Caddyfile) starts this script
 * as one or more FrankenPHP worker threads -- WORKER_THREAD_COUNT lets the
 * driver run the exact same entrypoint under >1 thread to exercise
 * cross-thread behavior, not just cross-request behavior within a single
 * thread.
 *
 * Run via `composer test:worker` (see composer.json), or directly:
 *   php test/worker/driver.php
 *
 * Set PROPULSION_SKIP_INTEGRATION=1 to skip entirely (no Docker available),
 * matching the convention `test/tools/helpers/IntegrationDatabase.php` uses
 * for the main integration test tier.
 */

declare(strict_types=1);

require '/app/vendor/autoload.php';

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;

$dbAdapter = getenv('WORKER_DB_ADAPTER') ?: 'sqlite';

if ($dbAdapter === 'pgsql') {
    $pgHost = getenv('WORKER_PG_HOST') ?: 'localhost';
    $pgPort = getenv('WORKER_PG_PORT') ?: '5432';
    $pgDatabase = getenv('WORKER_PG_DATABASE') ?: 'propulsion_worker_test';
    $pgUser = getenv('WORKER_PG_USER') ?: 'propulsion';
    $pgPassword = getenv('WORKER_PG_PASSWORD') ?: 'propulsion';

    Propulsion::setConfiguration([
        'datasources' => [
            'default' => 'workertest',
            'workertest' => [
                'adapter' => 'pgsql',
                'connection' => [
                    'dsn' => "pgsql:host=$pgHost;port=$pgPort;dbname=$pgDatabase",
                    'user' => $pgUser,
                    'password' => $pgPassword,
                ],
            ],
        ],
    ]);
} else {
    $dbFile = getenv('WORKER_SQLITE_FILE') ?: '/tmp/propulsion-worker-test.sqlite';

    Propulsion::setConfiguration([
        'datasources' => [
            'default' => 'workertest',
            'workertest' => [
                'adapter' => 'sqlite',
                'connection' => [
                    'dsn' => 'sqlite:' . $dbFile,
                ],
            ],
        ],
    ]);
}

// Boot Propulsion once, at worker start -- this is the process-scoped setup
// that must survive across every request this worker process ever handles.
Propulsion::initialize();

// Register the global (L2) query cache pool at boot, exactly as a real
// deployment would. The pool is process-scoped, so proving it survives
// Session::reset() -- while the request-scoped tier does not -- is the whole
// point of the l2-* actions below.
$cacheDriver = getenv('WORKER_CACHE_DRIVER') ?: '';
if ($cacheDriver !== '') {
    $cacheOptions = [];
    if ($cacheDriver === 'file') {
        $cacheOptions['directory'] = getenv('WORKER_CACHE_DIR') ?: '/tmp/propulsion-worker-cache';
    }
    if ($cacheDriver === 'array') {
        // Deliberately small: the driver's bounded-growth guard is one of the
        // things the matrix asserts, and an unbounded array in a process that
        // never exits is an out-of-memory condition waiting to happen.
        $cacheOptions['max_entries'] = (int) (getenv('WORKER_CACHE_MAX_ENTRIES') ?: '100');
    }

    Propulsion::setQueryCachePool(
        \Propulsion\Cache\CacheDriverFactory::factory($cacheDriver, $cacheOptions, 300)
    );
}

// Create the table used by the transaction-cleanup test, and reset it to a
// known state. With WORKER_THREAD_COUNT > 1, this bootstrap runs once per
// worker *thread*, all racing against the same underlying database at
// container start -- CREATE TABLE IF NOT EXISTS (rather than DROP+CREATE)
// keeps that idempotent instead of one thread's CREATE colliding with
// another's, and the DELETE (rather than DROP+CREATE) resets rows without
// reopening a race window on the table itself.
$bootstrapCon = Propulsion::getConnection();
$bootstrapCon->exec('CREATE TABLE IF NOT EXISTS worker_rows (id ' . ($dbAdapter === 'pgsql' ? 'SERIAL' : 'INTEGER') . ' PRIMARY KEY, label TEXT)');
$bootstrapCon->exec('DELETE FROM worker_rows');

/**
 * Fake peer class used only to exercise Session's instance-pool API the same
 * way a generated Peer's addInstanceToPool()/getInstanceFromPool() would --
 * this worker harness deliberately avoids running the code generator (see
 * this file's top docblock), so there is no real generated Peer to point at.
 */
final class WorkerTestPeer
{
}

/**
 * A TableVersionRegistry over the process's cache pool.
 *
 * Built fresh per call rather than memoised, so each request observes the
 * tokens as actually published to the shared backend rather than through a
 * per-request memo -- which is what makes the cross-request and cross-thread
 * assertions in driver.php meaningful.
 */
function workerVersionRegistry(): \Propulsion\Cache\TableVersionRegistry
{
    $config = new \Propulsion\Cache\SharedQueryCacheConfig(namespace: 'worker', ttl: 300);

    return new \Propulsion\Cache\TableVersionRegistry(Propulsion::queryCachePool(), $config);
}

/**
 * Reads a string query parameter, falling back to $default for anything
 * that isn't actually a string (missing, or a repeated-param array).
 */
function queryParam(string $name, string $default): string
{
    $value = $_GET[$name] ?? null;
    return is_string($value) ? $value : $default;
}

/**
 * Sleeps ~150ms, incrementing/decrementing an unsynchronized in-process
 * counter around the sleep. Used by the cross-thread matrix (test/worker/
 * driver.php) to give concurrently-dispatched requests something to overlap
 * on. The driver proves concurrent dispatch by wall-clock timing (many of
 * these fired at once completing in ~one sleep's worth of time, not N of
 * them serialized) rather than by reading after_increment/before_decrement
 * back above 1 -- empirically that counter never does, even when timing
 * proves the requests really did run concurrently: whatever internal
 * scheduling FrankenPHP uses to interleave PHP execution across "worker"
 * threads serializes access to shared PHP-level state (this counter,
 * Propulsion::$session, connection objects -- all a single instance shared
 * by every worker thread in the process, see runtime/Lib/Propulsion.php)
 * tightly enough that plain, unsynchronized reads/writes to it are never
 * observably interleaved. The fields are still returned for that
 * cross-check.
 *
 * @return array{after_increment: int, before_decrement: int}
 */
function concurrencyProbe(): array
{
    static $inFlight = 0;
    $inFlight++;
    $afterIncrement = $inFlight;
    usleep(150_000);
    $beforeDecrement = $inFlight;
    $inFlight--;
    return ['after_increment' => $afterIncrement, 'before_decrement' => $beforeDecrement];
}

/**
 * Handles exactly one HTTP request, returning a JSON-serializable array.
 * Fresh $_GET/$_SERVER globals are populated by frankenphp_handle_request()
 * before this runs on each iteration of the loop below.
 *
 * @return array<string, mixed>
 */
$handleOneRequest = static function (): array {
    $action = queryParam('action', 'noop');
    $con = Propulsion::getConnection();

    $common = [
        'pid' => getmypid(),
        'action' => $action,
        'memory_bytes' => memory_get_usage(),
        'session_object_id' => spl_object_id(Propulsion::getSession()),
        // Carried on every response so the contrast with session_object_id is
        // visible everywhere: the session is replaced/reset per request, the
        // cache pool is not.
        'l2_pool_object_id' => spl_object_id(Propulsion::queryCachePool()),
    ];

    switch ($action) {
        case 'pool-add':
            // Simulates a request loading a model object and populating the
            // instance pool -- exactly what a generated Peer's
            // addInstanceToPool() does under the hood.
            $key = queryParam('key', '1');
            Propulsion::getSession()->addPooledInstance(WorkerTestPeer::class, $key, (object) ['key' => $key]);
            return $common + ['pooled' => true, 'key' => $key];

        case 'pool-check':
            // Simulates a later, unrelated request checking whether an
            // object from a *previous* request is still sitting in the
            // pool. If Session::reset() ran at the boundary, this must
            // come back null for every key request A used.
            $key = queryParam('key', '1');
            $instance = Propulsion::getSession()->getPooledInstance(WorkerTestPeer::class, $key);
            return $common + ['pooled_instance_present' => $instance !== null];

        case 'pool-size':
            return $common + ['pool_size' => count(Propulsion::getSession()->getPool(WorkerTestPeer::class))];

        case 'qcache-add':
            // Simulates a query-cache-enabled ModelCriteria caching a
            // formatted result -- exactly what ModelCriteria::find()/
            // findOne()/count() and the generated Peer's doSelect()/
            // doCountThis() do under the hood (see runtime/Lib/Query/
            // ModelCriteria.php, generator/Lib/Builder/OM/PeerBuilder.php).
            $key = queryParam('key', '1');
            Propulsion::getSession()->getQueryResultCache()->set($key, ['cached' => 'result-for-' . $key], ['worker_rows']);
            return $common + ['cached' => true, 'key' => $key];

        case 'qcache-check':
            // Simulates a later, unrelated request checking whether a query
            // result cached by a *previous* request is still present. If
            // Session::reset() ran at the boundary, this must come back
            // false for every key request A used.
            $key = queryParam('key', '1');
            return $common + ['cached_entry_present' => Propulsion::getSession()->getQueryResultCache()->has($key)];

        case 'qcache-size':
            return $common + ['qcache_size' => Propulsion::getSession()->getQueryResultCache()->count()];

        case 'l2-set':
            // The global tier's counterpart to qcache-add. Unlike that one,
            // this entry must still be there after the request boundary.
            $key = queryParam('key', '1');
            Propulsion::queryCachePool()->set($key, 'l2-value-for-' . $key, 300);
            return $common + ['stored' => true, 'key' => $key];

        case 'l2-get':
            $key = queryParam('key', '1');
            $value = Propulsion::queryCachePool()->get($key, null);
            return $common + ['l2_hit' => $value !== null, 'l2_value' => $value];

        case 'l2-delete':
            Propulsion::queryCachePool()->delete(queryParam('key', '1'));
            return $common + ['deleted' => true];

        case 'l2-driver':
            $pool = Propulsion::queryCachePool();
            return $common + [
                'driver' => getenv('WORKER_CACHE_DRIVER') ?: '',
                'pool_class' => $pool::class,
            ];

        case 'l2-size':
            // Only the array driver can report a live count; the bounded-growth
            // guard in the matrix relies on it.
            $pool = Propulsion::queryCachePool();
            return $common + [
                'l2_size' => $pool instanceof \Propulsion\Cache\Driver\ArrayCache ? $pool->count() : -1,
            ];

        case 'l2-version-read':
            // Drives the table-version-token protocol by hand: this harness has
            // no generated Peers to invalidate through.
            $registry = workerVersionRegistry();
            return $common + ['token' => $registry->tokensFor('workertest', [queryParam('table', 'worker_rows')])[0]];

        case 'l2-version-bump':
            $table = queryParam('table', 'worker_rows');
            workerVersionRegistry()->publish('workertest', [$table]);
            return $common + ['bumped' => $table];

        case 'txn-open-dangling':
            // Simulates a bug/timeout in application code: opens a
            // transaction, writes a row, and returns *without* committing
            // or rolling back. Session::reset() at the request boundary is
            // what's supposed to clean this up before the next request.
            $con->beginTransaction();
            $con->exec("INSERT INTO worker_rows (label) VALUES ('dangling-from-pid-" . getmypid() . "')");
            return $common + [
                'opened_transaction' => true,
                'in_transaction_at_end_of_request' => $con instanceof PropulsionPDO && $con->isInTransaction(),
            ];

        case 'txn-check':
            // A later request checking that it did not inherit an open
            // transaction, and that the uncommitted insert from the
            // dangling transaction above was actually rolled back (not
            // left committed or half-applied).
            $stmt = $con->query('SELECT COUNT(*) FROM worker_rows');
            $rowCount = $stmt === false ? -1 : (int) $stmt->fetchColumn();
            return $common + [
                'in_transaction' => $con instanceof PropulsionPDO && $con->isInTransaction(),
                'row_count' => $rowCount,
            ];

        case 'txn-commit-row':
            // A control case: a *properly* committed row must survive
            // Session::reset() (only dangling/uncommitted state is rolled
            // back) -- otherwise this whole test matrix could trivially
            // "pass" by just wiping the whole database every request. Also
            // reused as the payload for the concurrent-writers stress check
            // (see driver.php's cross-thread matrix): many of these fired
            // at once from different worker threads must not lose/corrupt
            // any commits.
            $con->beginTransaction();
            $con->exec("INSERT INTO worker_rows (label) VALUES ('committed-from-pid-" . getmypid() . "')");
            $con->commit();
            return $common + ['committed' => true];

        case 'set-force-master':
            Propulsion::getSession()->setForceMasterConnection(true);
            return $common + ['force_master_set_to' => true];

        case 'get-force-master':
            return $common + ['force_master' => Propulsion::getSession()->getForceMasterConnection()];

        case 'connection-id':
            // Identifies *which* PDO connection object this request got,
            // so the driver can confirm request B reuses request A's
            // connection object (process-scoped state, must NOT reset).
            return $common + ['connection_object_id' => spl_object_id($con)];

        case 'session-id':
            // Identifies which Session object this request is using.
            // Empirically, this is the *same* object across every worker
            // thread in a process regardless of WORKER_THREAD_COUNT --
            // Propulsion::$session is a plain class-static
            // (runtime/Lib/Propulsion.php), and FrankenPHP does not give
            // each worker thread its own copy of it. So this value is
            // useful as a "still the same shared Session" sanity check, not
            // as a way to distinguish which thread served a request.
            return $common;

        case 'concurrency-probe':
            return $common + concurrencyProbe();

        default:
            return $common + ['ok' => true];
    }
};

// The reset hook a real worker-mode integration wires in at the request
// boundary -- this is the exact call this entire rework exists to prove
// actually prevents state bleeding between requests sharing this process.
$resetAtRequestBoundary = static function (): void {
    Propulsion::getSession()->reset();
};

$maxRequests = (int) (getenv('MAX_REQUESTS') ?: 0);
for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    $keepRunning = \frankenphp_handle_request(function () use ($handleOneRequest): void {
        $response = $handleOneRequest();
        header('Content-Type: application/json');
        echo json_encode($response);
    });

    // Request boundary: reset request-scoped state before the *next*
    // iteration picks up a new request, but only after this response has
    // already been sent (frankenphp_handle_request() flushes the response
    // before returning), so this never delays the client.
    $resetAtRequestBoundary();

    if (!$keepRunning) {
        break;
    }
}
