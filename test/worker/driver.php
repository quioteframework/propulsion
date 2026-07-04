<?php

/**
 * Worker-safety test matrix driver (KNOWN_ISSUES.md "Phase 4 -- Worker-safety
 * rework" / "Worker-safety test matrix not run").
 *
 * This is a standalone, black-box driver -- not a PHPUnit test -- because
 * what it needs to prove ("does state actually stop bleeding across a real
 * HTTP request boundary in a real persistent-worker process") can only be
 * observed from *outside* that process: it builds a FrankenPHP worker-mode
 * Docker image (see Dockerfile, Caddyfile, public/index.php in this
 * directory), starts a container from it, and makes real sequential HTTP
 * requests against it with curl, asserting on the JSON body each request
 * returns. Cross-request-boundary visibility comes for free here: each
 * request's JSON response IS the side channel this driver (running outside
 * the worker) needs -- no separate side file/DB required, since curl already
 * observes one response at a time from outside the process under test.
 *
 * Run via `composer test:worker` (see composer.json), or directly:
 *   php test/worker/driver.php
 *
 * Set PROPULSION_SKIP_INTEGRATION=1 to skip entirely (no Docker available),
 * matching the convention `test/tools/helpers/IntegrationDatabase.php` uses
 * for the main integration test tier.
 *
 * The container this starts is labeled propulsion.test-container=true, the
 * same convention IntegrationDatabase's testcontainers use, so a leaked
 * container (e.g. this script killed with -9 before its shutdown handler
 * runs) is still covered by `composer test:cleanup-containers`.
 */

declare(strict_types=1);

const IMAGE_TAG = 'propulsion-worker-test:latest';
const CONTAINER_NAME_PREFIX = 'propulsion-worker-test-';
const CONTAINER_LABEL = 'propulsion.test-container=true';

function repoRoot(): string
{
    return dirname(__DIR__, 2);
}

function skip(string $reason): never
{
    fwrite(STDOUT, "SKIP: $reason\n");
    exit(0);
}

function fail(string $reason): never
{
    fwrite(STDERR, "FAIL: $reason\n");
    exit(1);
}

function run(array $cmd, ?string $cwd = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        fail('Unable to start process: ' . implode(' ', $cmd));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, trim((string) $stdout), trim((string) $stderr)];
}

if (getenv('PROPULSION_SKIP_INTEGRATION')) {
    skip('PROPULSION_SKIP_INTEGRATION is set.');
}

[$dockerCheckCode] = run(['docker', 'info']);
if ($dockerCheckCode !== 0) {
    skip('Docker is not available/running in this environment.');
}

if (!is_file(repoRoot() . '/vendor/autoload.php')) {
    fail('vendor/autoload.php missing -- run `composer install` before `composer test:worker`.');
}

$containerName = CONTAINER_NAME_PREFIX . bin2hex(random_bytes(4));
$hostPort = null;

/**
 * Best-effort cleanup, registered up front so any early exit (assertion
 * failure, exception, ctrl-c) still tears the container down instead of
 * leaking it -- the propulsion.test-container=true label is the backstop
 * for cases even this doesn't catch (kill -9).
 */
register_shutdown_function(static function () use ($containerName): void {
    run(['docker', 'rm', '-f', $containerName]);
});

function httpGetJson(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException("curl error for $url: $error ($errno)");
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Non-JSON response from $url: " . substr($body, 0, 200));
    }
    return $decoded;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new RuntimeException("Assertion failed: $message (expected $e, got $a)");
    }
}

// --- Build the image -------------------------------------------------------

fwrite(STDOUT, "Building worker-safety test image...\n");
[$buildCode, , $buildErr] = run(
    ['docker', 'build', '-f', 'test/worker/Dockerfile', '-t', IMAGE_TAG, '.'],
    repoRoot()
);
if ($buildCode !== 0) {
    fail("docker build failed:\n$buildErr");
}

// --- Start the container ----------------------------------------------------

fwrite(STDOUT, "Starting worker container...\n");
[$runCode, $containerId, $runErr] = run([
    'docker', 'run', '-d',
    '--name', $containerName,
    '--label', CONTAINER_LABEL,
    '-p', '127.0.0.1::8080',
    IMAGE_TAG,
]);
if ($runCode !== 0) {
    fail("docker run failed:\n$runErr");
}

[$portCode, $portOut, $portErr] = run(['docker', 'port', $containerName, '8080/tcp']);
if ($portCode !== 0) {
    fail("docker port failed:\n$portErr");
}
// e.g. "0.0.0.0:34567"
if (!preg_match('/:(\d+)$/', $portOut, $m)) {
    fail("Could not parse host port from: $portOut");
}
$hostPort = (int) $m[1];
$baseUrl = "http://127.0.0.1:$hostPort";

// --- Wait for readiness ------------------------------------------------------

$ready = false;
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    try {
        $resp = httpGetJson("$baseUrl/?action=noop");
        if (($resp['ok'] ?? false) === true) {
            $ready = true;
            break;
        }
    } catch (\Throwable) {
        // not up yet
    }
    usleep(250_000);
}
if (!$ready) {
    [, $logs] = run(['docker', 'logs', $containerName]);
    fail("Worker never became ready within 20s. Container logs:\n$logs");
}

fwrite(STDOUT, "Worker is up on $baseUrl. Running worker-safety test matrix...\n");

$results = [];

/**
 * Runs a single named check, capturing pass/fail without aborting the whole
 * matrix on the first failure -- all five properties get a verdict in one
 * run, which is much more useful for diagnosing a regression than stopping
 * at the first one.
 */
function check(string $name, \Closure $fn): void
{
    global $results;
    try {
        $fn();
        $results[$name] = true;
        fwrite(STDOUT, "  PASS  $name\n");
    } catch (\Throwable $e) {
        $results[$name] = false;
        fwrite(STDOUT, "  FAIL  $name: {$e->getMessage()}\n");
    }
}

// 1. No object bleed across requests: request A pools an object, request B
//    (after the boundary reset) must not see it.
check('no object bleed across requests (instance pool)', function () use ($baseUrl) {
    $a = httpGetJson("$baseUrl/?action=pool-add&key=bleed-test");
    assertTrue($a['pooled'] === true, 'request A should have pooled an instance');

    $b = httpGetJson("$baseUrl/?action=pool-check&key=bleed-test");
    assertSame(false, $b['pooled_instance_present'], 'request B must not see request A\'s pooled instance after Session::reset()');

    assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
});

// 2. Transaction cleanup: request A opens a transaction and never
//    commits/rolls back; request B must not inherit a dangling open
//    transaction, and the uncommitted insert must actually be gone.
check('dangling transaction rolled back at request boundary', function () use ($baseUrl) {
    $a = httpGetJson("$baseUrl/?action=txn-open-dangling");
    assertTrue($a['opened_transaction'] === true, 'request A should have opened a transaction');
    assertTrue($a['in_transaction_at_end_of_request'] === true, 'request A should still be inside the transaction when it returns (simulating an app bug that forgot to commit/rollback)');

    $b = httpGetJson("$baseUrl/?action=txn-check");
    assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
    assertSame(false, $b['in_transaction'], 'request B must not inherit an open transaction from request A');
    assertSame(0, $b['row_count'], 'the uncommitted insert from request A\'s dangling transaction must have been rolled back, not just left uncommitted');
});

// 2b. Control case: a transaction that WAS committed must survive the reset
//     -- otherwise the above could trivially "pass" by wiping the database
//     unconditionally every request.
check('committed transactions are NOT rolled back (control case)', function () use ($baseUrl) {
    httpGetJson("$baseUrl/?action=txn-commit-row");
    $check = httpGetJson("$baseUrl/?action=txn-check");
    assertSame(1, $check['row_count'], 'a properly committed row must survive Session::reset()');
});

// 3. Connection persistence: the same PDO connection object (and worker
//    process) must be reused across requests -- only request-scoped Session
//    state should reset, not the process-scoped connection itself.
check('connections persist across requests (not torn down per-request)', function () use ($baseUrl) {
    $a = httpGetJson("$baseUrl/?action=connection-id");
    $b = httpGetJson("$baseUrl/?action=connection-id");
    assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
    assertSame($a['connection_object_id'], $b['connection_object_id'], 'the PDO connection object must be reused across requests in the same worker, not reopened per request');
});

// 4. forceMasterConnection isolation: request A sets it true; request B must
//    start with it back at the default (false).
check('forceMasterConnection does not leak between requests', function () use ($baseUrl) {
    $a = httpGetJson("$baseUrl/?action=set-force-master");
    assertSame(true, $a['force_master_set_to'], 'request A should have set forceMasterConnection(true)');

    $b = httpGetJson("$baseUrl/?action=get-force-master");
    assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
    assertSame(false, $b['force_master'], 'request B must not inherit request A\'s forceMasterConnection(true)');
});

// 5. Memory doesn't grow unboundedly under sustained load: run many requests
//    that would grow the instance pool/transaction table if Session::reset()
//    weren't wired in, and confirm memory plateaus rather than growing
//    linearly with request count -- a regression test for the specific
//    "growing instance pools never getting cleared" failure mode this rework
//    exists to prevent.
check('memory does not grow unboundedly under sustained load', function () use ($baseUrl) {
    $totalRequests = (int) (getenv('WORKER_TEST_LOAD_REQUESTS') ?: 500);
    $sampleEvery = max(1, intdiv($totalRequests, 50));
    $samples = [];

    for ($i = 0; $i < $totalRequests; $i++) {
        // A mix of actions that would each leave process-global garbage
        // behind if Session::reset() weren't clearing pools/rolling back
        // transactions at every boundary: a unique pool key per iteration
        // (worst case for unbounded pool growth) and a committed row
        // (worst case for unbounded per-request allocation via the DB
        // layer/statement objects).
        httpGetJson("$baseUrl/?action=pool-add&key=load-$i");
        $resp = httpGetJson("$baseUrl/?action=txn-commit-row");

        if ($i % $sampleEvery === 0) {
            $samples[] = $resp['memory_bytes'];
        }
    }

    $poolSize = httpGetJson("$baseUrl/?action=pool-size")['pool_size'];
    assertSame(0, $poolSize, "instance pool must be empty after $totalRequests requests each adding a uniquely-keyed instance -- a non-zero pool size here means Session::reset() is not clearing pools and they grow unboundedly");

    // Compare the average of the first 20% of samples against the last 20%:
    // a real "instance pools never cleared" regression grows memory roughly
    // linearly with request count, which over hundreds of requests would
    // show up as a multiple, not a fluctuation.
    $n = count($samples);
    $headCount = max(1, intdiv($n, 5));
    $head = array_slice($samples, 0, $headCount);
    $tail = array_slice($samples, -$headCount);
    $headAvg = array_sum($head) / count($head);
    $tailAvg = array_sum($tail) / count($tail);

    fwrite(STDOUT, "    (memory samples: " . count($samples) . ", head avg: " . round($headAvg) . " bytes, tail avg: " . round($tailAvg) . " bytes)\n");

    // Generous threshold (3x) -- this isn't trying to catch small, constant
    // per-request overhead (autoloading new classes lazily, opcache warming
    // up, etc.), only genuine unbounded/linear growth.
    assertTrue($tailAvg < $headAvg * 3, "memory grew from ~{$headAvg} to ~{$tailAvg} bytes over $totalRequests requests (>3x) -- looks like unbounded growth, not a plateau");
});

fwrite(STDOUT, "\n");
$failed = array_filter($results, static fn ($ok) => !$ok);
if ($failed) {
    fwrite(STDOUT, count($failed) . ' of ' . count($results) . " checks FAILED.\n");
    exit(1);
}

fwrite(STDOUT, 'All ' . count($results) . " worker-safety checks passed.\n");
exit(0);
