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
 * the hood -- see runtime/Lib/Session.php), plus a real SQLite-backed
 * PropulsionPDO connection for the transaction-cleanup property.
 *
 * Each request is a tiny JSON API selected by ?action=..., see the switch
 * below. The external test driver (test/worker/driver.php) makes real HTTP
 * requests against this worker (via FrankenPHP/Caddy) and asserts on the
 * JSON bodies it gets back -- that's how a black-box test run *outside* the
 * worker process observes state that is normally invisible across the
 * request boundary.
 */

require '/app/vendor/autoload.php';

use Propulsion\Propulsion;

$dbFile = getenv('WORKER_SQLITE_FILE') ?: '/tmp/propulsion-worker-test.sqlite';

// Boot Propulsion once, at worker start -- this is the process-scoped setup
// that must survive across every request this worker process ever handles.
Propulsion::setConfiguration([
    'datasources' => [
        'default' => 'workertest',
        'workertest' => [
            'adapter' => 'sqlite',
            'connection' => [
                'dsn' => 'sqlite:' . $dbFile,
                'classname' => 'PropulsionPDO',
            ],
        ],
    ],
]);
Propulsion::initialize();

// Create the table used by the transaction-cleanup test, and reset it to a
// known state. This runs once per worker *process* boot, not per request.
$bootstrapCon = Propulsion::getConnection();
$bootstrapCon->exec('DROP TABLE IF EXISTS worker_rows');
$bootstrapCon->exec('CREATE TABLE worker_rows (id INTEGER PRIMARY KEY, label TEXT)');

/**
 * Handles exactly one HTTP request, returning a JSON-serializable array.
 * Fresh $_GET/$_SERVER globals are populated by frankenphp_handle_request()
 * before this runs on each iteration of the loop below.
 */
$handleOneRequest = static function () use ($dbFile): array {
    $action = $_GET['action'] ?? 'noop';
    $con = Propulsion::getConnection();

    $common = [
        'pid' => getmypid(),
        'action' => $action,
        'memory_bytes' => memory_get_usage(),
    ];

    switch ($action) {
        case 'pool-add':
            // Simulates a request loading a model object and populating the
            // instance pool -- exactly what a generated Peer's
            // addInstanceToPool() does under the hood.
            $key = $_GET['key'] ?? '1';
            Propulsion::getSession()->addPooledInstance('WorkerTestPeer', $key, (object) ['key' => $key]);
            return $common + ['pooled' => true, 'key' => $key];

        case 'pool-check':
            // Simulates a later, unrelated request checking whether an
            // object from a *previous* request is still sitting in the
            // pool. If Session::reset() ran at the boundary, this must
            // come back null for every key request A used.
            $key = $_GET['key'] ?? '1';
            $instance = Propulsion::getSession()->getPooledInstance('WorkerTestPeer', $key);
            return $common + ['pooled_instance_present' => $instance !== null];

        case 'pool-size':
            return $common + ['pool_size' => count(Propulsion::getSession()->getPool('WorkerTestPeer'))];

        case 'txn-open-dangling':
            // Simulates a bug/timeout in application code: opens a
            // transaction, writes a row, and returns *without* committing
            // or rolling back. Session::reset() at the request boundary is
            // what's supposed to clean this up before the next request.
            $con->beginTransaction();
            $con->exec("INSERT INTO worker_rows (label) VALUES ('dangling-from-pid-" . getmypid() . "')");
            return $common + ['opened_transaction' => true, 'in_transaction_at_end_of_request' => $con->isInTransaction()];

        case 'txn-check':
            // A later request checking that it did not inherit an open
            // transaction, and that the uncommitted insert from the
            // dangling transaction above was actually rolled back (not
            // left committed or half-applied).
            $rowCount = (int) $con->query('SELECT COUNT(*) FROM worker_rows')->fetchColumn();
            return $common + [
                'in_transaction' => $con->isInTransaction(),
                'row_count' => $rowCount,
            ];

        case 'txn-commit-row':
            // A control case: a *properly* committed row must survive
            // Session::reset() (only dangling/uncommitted state is rolled
            // back) -- otherwise this whole test matrix could trivially
            // "pass" by just wiping the whole database every request.
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
            // Identifies which Session object this request is using, so
            // the driver can confirm (a) it's stable across requests in
            // the absence of an explicit setSession() call and (b) the
            // reset actually mutates state without swapping the object.
            return $common + ['session_object_id' => spl_object_id(Propulsion::getSession())];

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
