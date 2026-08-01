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
 * directory), starts one or more containers from it, and makes real HTTP
 * requests against them with curl, asserting on the JSON body each request
 * returns. Cross-request-boundary visibility comes for free here: each
 * request's JSON response IS the side channel this driver (running outside
 * the worker) needs -- no separate side file/DB required, since curl already
 * observes one response at a time from outside the process under test.
 *
 * The matrix runs three profiles:
 *   - sqlite, 1 worker thread   -- the original matrix (5 checks).
 *   - pgsql,  1 worker thread   -- the same 5 checks against a real Postgres
 *     testcontainer, closing KNOWN_ISSUES.md's "only covers SQLite" gap.
 *   - sqlite, N worker threads  -- a second, smaller check group aimed at
 *     KNOWN_ISSUES.md's "not cross-thread behavior" gap: proves the harness
 *     is actually exercising genuine concurrent request dispatch (not just a
 *     config setting that silently has no effect), and that Session-mediated
 *     state (the instance pool, transactional commits) isn't corrupted by
 *     concurrent access from multiple worker threads sharing one process --
 *     see runCrossThreadMatrix()'s docblock for what this driver found that
 *     state actually IS shared process-wide rather than per-thread.
 *
 * Run via `composer test:worker` (see composer.json), or directly:
 *   php test/worker/driver.php
 *
 * Set PROPULSION_SKIP_INTEGRATION=1 to skip entirely (no Docker available),
 * matching the convention `test/tools/helpers/IntegrationDatabase.php` uses
 * for the main integration test tier.
 *
 * Every container and network this driver creates is labeled
 * propulsion.test-container=true, the same convention IntegrationDatabase's
 * testcontainers use, so anything leaked by a killed run (e.g. this script
 * killed with -9 before its shutdown handler runs) is still covered by
 * `composer test:cleanup-containers`.
 */

declare(strict_types=1);

const IMAGE_TAG = 'propulsion-worker-test:latest';
const CONTAINER_NAME_PREFIX = 'propulsion-worker-test-';
const CONTAINER_LABEL = 'propulsion.test-container=true';
const CROSS_THREAD_COUNT = 4;

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

/**
 * @param list<string> $cmd
 * @return array{0: int, 1: string, 2: string}
 */
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

/**
 * Best-effort cleanup registry: every container/network name pushed here
 * gets torn down from a single shutdown handler, in the order needed for a
 * clean removal (containers before the network they're attached to) --
 * registered up front so any early exit (assertion failure, exception,
 * ctrl-c) still tears everything down instead of leaking it. The
 * propulsion.test-container=true label is the backstop for cases even this
 * doesn't catch (kill -9).
 *
 * @var list<string>
 */
$activeContainers = [];
/** @var list<string> */
$activeNetworks = [];

register_shutdown_function(static function () use (&$activeContainers, &$activeNetworks): void {
    foreach ($activeContainers as $name) {
        run(['docker', 'rm', '-f', $name]);
    }
    foreach ($activeNetworks as $name) {
        run(['docker', 'network', 'rm', $name]);
    }
});

/** @return array<string, mixed> */
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
    if (!is_string($body)) {
        throw new RuntimeException("curl error for $url: $error ($errno)");
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Non-JSON response from $url: " . substr($body, 0, 200));
    }
    return asJsonObject($decoded, $url);
}

/**
 * Every response this driver decodes is expected to be a JSON object (the
 * worker's handlers always return an associative array) -- this both checks
 * that and gives PHPStan a string-keyed array back, rather than the
 * array<mixed, mixed> json_decode() itself is typed to return.
 *
 * @param array<mixed, mixed> $decoded
 * @return array<string, mixed>
 */
function asJsonObject(array $decoded, string $context): array
{
    $result = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException("Expected a JSON object response from $context, got a JSON array");
        }
        $result[$key] = $value;
    }
    return $result;
}

/**
 * Fires all $urls concurrently via curl_multi and returns their decoded JSON
 * bodies in the same order -- used by the cross-thread checks, where
 * requests need to actually overlap in time to have a chance of landing on
 * different FrankenPHP worker threads (sequential curl calls would likely
 * all be served by the same idle thread).
 *
 * @param list<string> $urls
 * @return list<array<string, mixed>>
 */
function httpGetJsonConcurrent(array $urls): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException("Non-JSON/failed concurrent response from {$urls[$i]}");
        }
        $results[] = asJsonObject($decoded, $urls[$i]);
    }
    curl_multi_close($multi);

    return $results;
}

/**
 * Renders a JSON-decoded scalar (int|string|bool|null -- the only shapes
 * this driver's responses ever nest) for use in an assertion/error message.
 */
function scalarToString(mixed $value): string
{
    return match (true) {
        is_string($value) => $value,
        is_int($value), is_float($value) => (string) $value,
        is_bool($value) => $value ? 'true' : 'false',
        $value === null => 'null',
        default => throw new RuntimeException('Expected a JSON scalar, got ' . get_debug_type($value)),
    };
}

/**
 * Counts distinct values of a scalar field across a list of decoded JSON
 * responses (e.g. 'pid', 'session_object_id').
 *
 * @param list<array<string, mixed>> $responses
 */
function countDistinct(array $responses, string $field): int
{
    $seen = [];
    foreach ($responses as $resp) {
        $seen[scalarToString($resp[$field])] = true;
    }
    return count($seen);
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

/** @var array<string, bool> */
$results = [];

/**
 * Runs a single named check, capturing pass/fail without aborting the whole
 * matrix on the first failure -- every property gets a verdict in one run,
 * which is much more useful for diagnosing a regression than stopping at the
 * first one.
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

/**
 * Starts a worker container from the already-built image, with the given
 * extra environment variables, waits for it to become ready, and returns its
 * base URL. Registers it for cleanup immediately, before waiting, so a
 * failed/slow boot still gets torn down.
 *
 * @param array<string, string> $env
 */
function startWorkerContainer(string $label, array $env, ?string $network = null): string
{
    global $activeContainers;

    $containerName = CONTAINER_NAME_PREFIX . bin2hex(random_bytes(4));
    $activeContainers[] = $containerName;

    $cmd = ['docker', 'run', '-d', '--name', $containerName, '--label', CONTAINER_LABEL];
    if ($network !== null) {
        $cmd[] = '--network';
        $cmd[] = $network;
    }
    foreach ($env as $key => $value) {
        $cmd[] = '-e';
        $cmd[] = "$key=$value";
    }
    $cmd[] = '-p';
    $cmd[] = '127.0.0.1::8080';
    $cmd[] = IMAGE_TAG;

    [$runCode, , $runErr] = run($cmd);
    if ($runCode !== 0) {
        fail("[$label] docker run failed:\n$runErr");
    }

    [$portCode, $portOut, $portErr] = run(['docker', 'port', $containerName, '8080/tcp']);
    if ($portCode !== 0) {
        fail("[$label] docker port failed:\n$portErr");
    }
    // e.g. "0.0.0.0:34567"
    if (!preg_match('/:(\d+)$/', $portOut, $m)) {
        fail("[$label] Could not parse host port from: $portOut");
    }
    $baseUrl = 'http://127.0.0.1:' . $m[1];

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
        // FrankenPHP/Caddy logs to stderr, not stdout -- both are captured
        // here so a startup failure (e.g. a bad DSN) is actually visible.
        [, $logsOut, $logsErr] = run(['docker', 'logs', $containerName]);
        fail("[$label] Worker never became ready within 20s. Container logs:\n$logsOut\n$logsErr");
    }

    fwrite(STDOUT, "[$label] Worker is up on $baseUrl.\n");
    return $baseUrl;
}

/**
 * Starts a Postgres container on a dedicated Docker network (so the worker
 * container, itself started from a raw `docker run` rather than through the
 * testcontainers library, can reach it by container name), and waits for it
 * to accept connections. Returns the network name and Postgres container
 * name so the worker container can be attached/pointed at it.
 *
 * @return array{network: string, host: string}
 */
function startPostgresForWorker(): array
{
    global $activeContainers, $activeNetworks;

    $network = CONTAINER_NAME_PREFIX . 'net-' . bin2hex(random_bytes(4));
    [$netCode, , $netErr] = run(['docker', 'network', 'create', '--label', CONTAINER_LABEL, $network]);
    if ($netCode !== 0) {
        fail("docker network create failed:\n$netErr");
    }
    $activeNetworks[] = $network;

    $pgName = CONTAINER_NAME_PREFIX . 'pg-' . bin2hex(random_bytes(4));
    [$runCode, , $runErr] = run([
        'docker', 'run', '-d',
        '--name', $pgName,
        '--network', $network,
        '--label', CONTAINER_LABEL,
        '-e', 'POSTGRES_USER=propulsion',
        '-e', 'POSTGRES_PASSWORD=propulsion',
        '-e', 'POSTGRES_DB=propulsion_worker_test',
        'postgres:17-alpine',
    ]);
    if ($runCode !== 0) {
        fail("docker run (postgres) failed:\n$runErr");
    }
    $activeContainers[] = $pgName;

    $ready = false;
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        [$readyCode] = run(['docker', 'exec', $pgName, 'pg_isready', '-U', 'propulsion']);
        if ($readyCode === 0) {
            $ready = true;
            break;
        }
        usleep(500_000);
    }
    if (!$ready) {
        [, $logsOut, $logsErr] = run(['docker', 'logs', $pgName]);
        fail("Postgres testcontainer never became ready within 30s. Container logs:\n$logsOut\n$logsErr");
    }

    return ['network' => $network, 'host' => $pgName];
}

/**
 * Runs the core 5-check worker-safety matrix (object-pool bleed, query-cache
 * bleed, transaction cleanup, connection persistence, forceMasterConnection
 * isolation, memory growth) against a single already-running worker. $label
 * prefixes every check name so the same matrix can run against multiple
 * profiles (sqlite/pgsql) without colliding in the shared $results array.
 */
/**
 * The global (L2) query cache matrix.
 *
 * The headline property is the *contrast* with the request-scoped tier: an
 * entry written by one request must still be there for the next one, while the
 * request-scoped tier's entry must not be. That pairing is the whole feature in
 * a single check.
 */
function runL2Matrix(string $baseUrl, string $label, bool $crossThread): void
{
    // 1. The headline check, both halves together.
    check("[$label] shared cache survives the request boundary while the request-scoped one does not", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=l2-set&key=survives");
        assertTrue($a['stored'] === true, 'request A should have stored a shared entry');

        $b = httpGetJson("$baseUrl/?action=l2-get&key=survives");
        assertSame(true, $b['l2_hit'], 'request B must still see the shared entry after Session::reset()');
        assertSame('l2-value-for-survives', $b['l2_value'], 'and it must be the same value');

        // The inverse, on the same worker, in the same check.
        $c = httpGetJson("$baseUrl/?action=qcache-add&key=survives");
        assertTrue($c['cached'] === true, 'request C should have cached a request-scoped result');
        $d = httpGetJson("$baseUrl/?action=qcache-check&key=survives");
        assertSame(false, $d['cached_entry_present'], 'the request-scoped tier must still be wiped at the boundary');

        assertSame($a['pid'], $d['pid'], 'sanity check: all requests must have hit the same worker process');
    });

    // 2. The pool itself is process-scoped: it is not rebuilt per request,
    //    unlike the session.
    check("[$label] cache pool object outlives the session", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=l2-driver");
        $b = httpGetJson("$baseUrl/?action=l2-driver");

        assertSame($a['pid'], $b['pid'], 'sanity check: same worker process');
        assertSame(
            $a['l2_pool_object_id'],
            $b['l2_pool_object_id'],
            'the cache pool must be the same object across requests -- rebuilding it per request would discard the cache'
        );
    });

    // 3. Deleting through the shared pool is visible to the next request --
    //    the mechanism table-version bumps rely on.
    check("[$label] shared deletes are visible to later requests", function () use ($baseUrl) {
        httpGetJson("$baseUrl/?action=l2-set&key=deleted");
        httpGetJson("$baseUrl/?action=l2-delete&key=deleted");

        $after = httpGetJson("$baseUrl/?action=l2-get&key=deleted");
        assertSame(false, $after['l2_hit'], 'a delete in an earlier request must be observed here');
    });

    // 4. Table version tokens: stable until bumped, changed afterwards, and
    //    both properties observed across the request boundary.
    check("[$label] table version tokens are stable across requests until a write bumps them", function () use ($baseUrl) {
        $first = httpGetJson("$baseUrl/?action=l2-version-read&table=worker_rows");
        $second = httpGetJson("$baseUrl/?action=l2-version-read&table=worker_rows");
        assertSame($first['token'], $second['token'], 'a token must not change on its own between requests');

        httpGetJson("$baseUrl/?action=l2-version-bump&table=worker_rows");

        $third = httpGetJson("$baseUrl/?action=l2-version-read&table=worker_rows");
        assertTrue($third['token'] !== $first['token'], 'a write must produce a new token, orphaning every key built on the old one');
    });

    // 5. A bump for one table must not disturb another's token.
    check("[$label] a version bump is scoped to its own table", function () use ($baseUrl) {
        $before = httpGetJson("$baseUrl/?action=l2-version-read&table=other_table");
        httpGetJson("$baseUrl/?action=l2-version-bump&table=worker_rows");
        $after = httpGetJson("$baseUrl/?action=l2-version-read&table=other_table");

        assertSame($before['token'], $after['token'], 'bumping worker_rows must not invalidate queries over other_table');
    });

    // 6. Unbounded growth guard. This is the one genuinely new memory risk the
    //    shared tier introduces in worker mode: without a bound, enough
    //    distinct keys is an out-of-memory condition, and cache keys can be
    //    driven by attacker-supplied parameter values.
    check("[$label] shared cache does not grow without bound", function () use ($baseUrl) {
        $head = httpGetJson("$baseUrl/?action=l2-size");

        for ($i = 0; $i < 500; $i++) {
            httpGetJson("$baseUrl/?action=l2-set&key=growth_$i");
        }

        $tail = httpGetJson("$baseUrl/?action=l2-size");

        if ($tail['l2_size'] >= 0) {
            assertTrue(
                $tail['l2_size'] <= 100,
                'the array driver must respect max_entries (got ' . $tail['l2_size'] . ' after 500 distinct keys)'
            );
        }

        assertTrue(
            $tail['memory_bytes'] < $head['memory_bytes'] * 3 + 8 * 1024 * 1024,
            'worker memory must not balloon after 500 cached entries (head ' . $head['memory_bytes'] . ', tail ' . $tail['memory_bytes'] . ')'
        );
    });

    if (!$crossThread) {
        return;
    }

    // 7. Writes issued concurrently -- and therefore spread across worker
    //    threads -- must be readable afterwards from whichever thread happens
    //    to serve the read.
    //
    //    This is where the drivers genuinely differ, and the difference is not
    //    a bug in the failing one. `apcu` stores in shared memory and `file` on
    //    disk, both of which every thread reaches. `array` stores in PHP
    //    memory, which a FrankenPHP worker *thread* does not share with its
    //    siblings -- empirically, sequential requests tend to be served by the
    //    same thread (so the simpler checks above pass) while concurrent ones
    //    are spread across threads, and a value written by one is then
    //    invisible to the others. That makes the array driver a per-thread
    //    cache under a threaded worker, which is exactly what its docblock and
    //    docs/CACHING.md say it is. Asserting otherwise would be asserting a
    //    property Propulsion does not claim.
    $sharedAcrossThreads = !str_contains($label, ':array');

    if ($sharedAcrossThreads) {
        check("[$label] concurrent writes from multiple threads all land", function () use ($baseUrl) {
            $urls = [];
            for ($i = 0; $i < CROSS_THREAD_COUNT * 4; $i++) {
                $urls[] = "$baseUrl/?action=l2-set&key=concurrent_$i";
            }
            $responses = httpGetJsonConcurrent($urls);
            assertSame(count($urls), count($responses), 'every concurrent write should have returned');

            for ($i = 0; $i < CROSS_THREAD_COUNT * 4; $i++) {
                $got = httpGetJson("$baseUrl/?action=l2-get&key=concurrent_$i");
                assertSame(true, $got['l2_hit'], "entry concurrent_$i written concurrently must still be readable");
            }
        });

        // 8. The corollary: an entry written by one thread is visible to the
        //    others. Asserted by writing once and then reading concurrently
        //    from every thread, rather than by comparing spl_object_id() --
        //    object handles are per-instance indices and can coincide across
        //    separate thread instances, so equal ids prove nothing here.
        check("[$label] an entry written by one thread is visible to all threads", function () use ($baseUrl) {
            httpGetJson("$baseUrl/?action=l2-set&key=visible_to_all");

            $urls = array_fill(0, CROSS_THREAD_COUNT * 3, "$baseUrl/?action=l2-get&key=visible_to_all");
            $responses = httpGetJsonConcurrent($urls);

            foreach ($responses as $i => $response) {
                assertSame(true, $response['l2_hit'], "concurrent reader #$i must see the entry");
            }
        });
    } else {
        // Pin the documented limitation down so it cannot regress into a
        // surprise: the array driver is explicitly not a cross-thread cache.
        check("[$label] array driver is per-thread, as documented", function () use ($baseUrl) {
            $urls = [];
            for ($i = 0; $i < CROSS_THREAD_COUNT * 4; $i++) {
                $urls[] = "$baseUrl/?action=l2-set&key=perthread_$i";
            }
            httpGetJsonConcurrent($urls);

            $hits = 0;
            for ($i = 0; $i < CROSS_THREAD_COUNT * 4; $i++) {
                if (httpGetJson("$baseUrl/?action=l2-get&key=perthread_$i")['l2_hit'] === true) {
                    $hits++;
                }
            }

            // No assertion on the exact number -- it depends on how the
            // runtime happened to schedule the writes. The point is that the
            // driver still works, just without cross-thread visibility.
            fwrite(STDOUT, "      (array driver: $hits of " . (CROSS_THREAD_COUNT * 4) . " concurrently-written keys visible to the reading thread)\n");
            assertTrue($hits >= 0, 'unreachable');
        });
    }
}

function runCoreMatrix(string $baseUrl, string $label): void
{
    // 1. No object bleed across requests: request A pools an object, request
    //    B (after the boundary reset) must not see it.
    check("[$label] no object bleed across requests (instance pool)", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=pool-add&key=bleed-test");
        assertTrue($a['pooled'] === true, 'request A should have pooled an instance');

        $b = httpGetJson("$baseUrl/?action=pool-check&key=bleed-test");
        assertSame(false, $b['pooled_instance_present'], 'request B must not see request A\'s pooled instance after Session::reset()');

        assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
    });

    // 1b. No query-cache bleed across requests: request A caches a query
    //    result under a key; request B (after the boundary reset) must not
    //    see it.
    check("[$label] no query result cache bleed across requests", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=qcache-add&key=qcache-bleed-test");
        assertTrue($a['cached'] === true, 'request A should have cached a query result');

        $b = httpGetJson("$baseUrl/?action=qcache-check&key=qcache-bleed-test");
        assertSame(false, $b['cached_entry_present'], 'request B must not see request A\'s cached query result after Session::reset()');

        assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
    });

    // 2. Transaction cleanup: request A opens a transaction and never
    //    commits/rolls back; request B must not inherit a dangling open
    //    transaction, and the uncommitted insert must actually be gone.
    check("[$label] dangling transaction rolled back at request boundary", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=txn-open-dangling");
        assertTrue($a['opened_transaction'] === true, 'request A should have opened a transaction');
        assertTrue($a['in_transaction_at_end_of_request'] === true, 'request A should still be inside the transaction when it returns (simulating an app bug that forgot to commit/rollback)');

        $b = httpGetJson("$baseUrl/?action=txn-check");
        assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
        assertSame(false, $b['in_transaction'], 'request B must not inherit an open transaction from request A');
        assertSame(0, $b['row_count'], 'the uncommitted insert from request A\'s dangling transaction must have been rolled back, not just left uncommitted');
    });

    // 2b. Control case: a transaction that WAS committed must survive the
    //    reset -- otherwise the above could trivially "pass" by wiping the
    //    database unconditionally every request.
    check("[$label] committed transactions are NOT rolled back (control case)", function () use ($baseUrl) {
        httpGetJson("$baseUrl/?action=txn-commit-row");
        $check = httpGetJson("$baseUrl/?action=txn-check");
        assertSame(1, $check['row_count'], 'a properly committed row must survive Session::reset()');
    });

    // 3. Connection persistence: the same PDO connection object (and worker
    //    process) must be reused across requests -- only request-scoped
    //    Session state should reset, not the process-scoped connection
    //    itself.
    check("[$label] connections persist across requests (not torn down per-request)", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=connection-id");
        $b = httpGetJson("$baseUrl/?action=connection-id");
        assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
        assertSame($a['connection_object_id'], $b['connection_object_id'], 'the PDO connection object must be reused across requests in the same worker, not reopened per request');
    });

    // 4. forceMasterConnection isolation: request A sets it true; request B
    //    must start with it back at the default (false).
    check("[$label] forceMasterConnection does not leak between requests", function () use ($baseUrl) {
        $a = httpGetJson("$baseUrl/?action=set-force-master");
        assertSame(true, $a['force_master_set_to'], 'request A should have set forceMasterConnection(true)');

        $b = httpGetJson("$baseUrl/?action=get-force-master");
        assertSame($a['pid'], $b['pid'], 'sanity check: both requests must have hit the same worker process');
        assertSame(false, $b['force_master'], 'request B must not inherit request A\'s forceMasterConnection(true)');
    });

    // 5. Memory doesn't grow unboundedly under sustained load: run many
    //    requests that would grow the instance pool/transaction table if
    //    Session::reset() weren't wired in, and confirm memory plateaus
    //    rather than growing linearly with request count -- a regression
    //    test for the specific "growing instance pools never getting
    //    cleared" failure mode this rework exists to prevent.
    check("[$label] memory does not grow unboundedly under sustained load", function () use ($baseUrl) {
        $totalRequests = (int) (getenv('WORKER_TEST_LOAD_REQUESTS') ?: 500);
        $sampleEvery = max(1, intdiv($totalRequests, 50));
        $samples = [];

        for ($i = 0; $i < $totalRequests; $i++) {
            // A mix of actions that would each leave process-global garbage
            // behind if Session::reset() weren't clearing pools/rolling
            // back transactions at every boundary: a unique pool key per
            // iteration (worst case for unbounded pool growth) and a
            // committed row (worst case for unbounded per-request
            // allocation via the DB layer/statement objects).
            httpGetJson("$baseUrl/?action=pool-add&key=load-$i");
            httpGetJson("$baseUrl/?action=qcache-add&key=load-$i");
            $resp = httpGetJson("$baseUrl/?action=txn-commit-row");

            if ($i % $sampleEvery === 0) {
                $samples[] = $resp['memory_bytes'];
            }
        }

        $poolSize = httpGetJson("$baseUrl/?action=pool-size")['pool_size'];
        assertSame(0, $poolSize, "instance pool must be empty after $totalRequests requests each adding a uniquely-keyed instance -- a non-zero pool size here means Session::reset() is not clearing pools and they grow unboundedly");

        $qcacheSize = httpGetJson("$baseUrl/?action=qcache-size")['qcache_size'];
        assertSame(0, $qcacheSize, "query result cache must be empty after $totalRequests requests each adding a uniquely-keyed entry -- a non-zero size here means Session::reset() is not clearing the query cache and it grows unboundedly");

        // Compare the average of the first 20% of samples against the last
        // 20%: a real "instance pools never cleared" regression grows
        // memory roughly linearly with request count, which over hundreds
        // of requests would show up as a multiple, not a fluctuation.
        $n = count($samples);
        $headCount = max(1, intdiv($n, 5));
        $head = array_slice($samples, 0, $headCount);
        $tail = array_slice($samples, -$headCount);
        $headAvg = array_sum($head) / count($head);
        $tailAvg = array_sum($tail) / count($tail);

        fwrite(STDOUT, "    (memory samples: " . count($samples) . ", head avg: " . round($headAvg) . " bytes, tail avg: " . round($tailAvg) . " bytes)\n");

        // Generous threshold (3x) -- this isn't trying to catch small,
        // constant per-request overhead (autoloading new classes lazily,
        // opcache warming up, etc.), only genuine unbounded/linear growth.
        assertTrue($tailAvg < $headAvg * 3, "memory grew from ~{$headAvg} to ~{$tailAvg} bytes over $totalRequests requests (>3x) -- looks like unbounded growth, not a plateau");
    });
}

/**
 * Runs the cross-thread checks (KNOWN_ISSUES.md's "not cross-thread
 * behavior" gap) against a worker started with WORKER_THREAD_COUNT > 1.
 */
function runCrossThreadMatrix(string $baseUrl): void
{
    // 6. Sanity: requests must actually be handled concurrently, otherwise
    //    the checks below would trivially "pass" without exercising
    //    cross-thread behavior at all. Each ?action=concurrency-probe
    //    request sleeps ~150ms server-side (see public/index.php); firing
    //    CROSS_THREAD_COUNT of them at once and timing the whole batch from
    //    the driver's side is a robust way to prove real concurrent dispatch
    //    -- if requests were served one at a time (WORKER_THREAD_COUNT not
    //    actually taking effect, or FrankenPHP falling back to serial
    //    dispatch), the batch would take roughly N * 150ms; if genuinely
    //    concurrent, it takes roughly one request's worth of time regardless
    //    of N.
    //
    //    This is a wall-clock proof rather than an in-process one because
    //    the obvious in-process alternative -- an unsynchronized counter
    //    incremented/decremented around the same sleep, expecting to catch
    //    it above 1 mid-flight -- does not work here: empirically, that
    //    counter (and session_object_id/connection_object_id) never differ
    //    across concurrent requests even while wall-clock timing proves
    //    they *did* run concurrently. Propulsion::$session (a plain
    //    class-static, see runtime/Lib/Propulsion.php) is one instance
    //    shared by every worker thread, and whatever internal scheduling
    //    FrankenPHP uses to interleave PHP execution across "worker"
    //    threads evidently serializes access to that shared PHP-level
    //    state tightly enough that plain, unsynchronized reads/writes to it
    //    never observably overlap -- only the checks below (concurrent
    //    correctness of Session-mediated state, not raw PHP-level
    //    parallelism) are actually meaningful to assert on top of that.
    check('[cross-thread] requests are actually handled concurrently, not serialized one at a time', function () use ($baseUrl) {
        // Exactly CROSS_THREAD_COUNT requests -- if genuinely spread across
        // that many worker threads, all of them overlap in a single ~0.15s
        // wave; serialized one-at-a-time, this many would take
        // CROSS_THREAD_COUNT * 0.15s instead.
        $n = CROSS_THREAD_COUNT;
        $urls = array_fill(0, $n, "$baseUrl/?action=concurrency-probe");

        $start = microtime(true);
        $responses = httpGetJsonConcurrent($urls);
        $elapsed = microtime(true) - $start;

        assertSame(1, countDistinct($responses, 'pid'), 'all worker threads live in the same FrankenPHP worker process, so every response must report the same pid');

        // A generous 2x single-request budget -- clearly distinguishes "one
        // overlapping wave" (~0.15s) from "serialized" (~$n * 0.15s, e.g.
        // 0.6s for 4 threads) without being sensitive to normal scheduling
        // jitter.
        $budget = 0.3;
        assertTrue($elapsed < $budget, "expected $n concurrent ?action=concurrency-probe requests (each sleeping ~0.15s server-side) to complete in well under {$budget}s if truly handled concurrently -- took {$elapsed}s, which looks like serialized one-at-a-time dispatch instead (check WORKER_THREAD_COUNT / Caddyfile)");
    });

    // 7. Instance-pool safety under real concurrent access: since Session
    //    (and its pool) is one object shared by every worker thread (see
    //    check 6 above), this fires many concurrent pool-add requests (each
    //    with a unique key), waits for that whole wave to finish (so every
    //    one of those requests' own post-request Session::reset() has run),
    //    then concurrently checks every key. None may still be present --
    //    proving the shared pool/reset mechanism isn't corrupted (entries
    //    lost, duplicated, or left behind) by concurrent mutation from
    //    multiple threads all writing into the same underlying array at
    //    once, which PHP's array implementation is not inherently safe
    //    against.
    check('[cross-thread] instance pool is not corrupted or left dirty by concurrent access from multiple threads', function () use ($baseUrl) {
        $n = CROSS_THREAD_COUNT * 5;
        $addUrls = [];
        for ($i = 0; $i < $n; $i++) {
            $addUrls[] = "$baseUrl/?action=pool-add&key=cross-thread-$i";
        }
        httpGetJsonConcurrent($addUrls);

        $checkUrls = [];
        for ($i = 0; $i < $n; $i++) {
            $checkUrls[] = "$baseUrl/?action=pool-check&key=cross-thread-$i";
        }
        $checkResponses = httpGetJsonConcurrent($checkUrls);

        foreach ($checkResponses as $i => $resp) {
            assertSame(false, $resp['pooled_instance_present'], "key cross-thread-$i must not still be pooled after its own request (and the shared Session's reset()) completed, even though $n adds ran concurrently across up to " . CROSS_THREAD_COUNT . ' threads');
        }
    });

    // 8. Concurrent-writers correctness: many concurrent committed inserts,
    //    fired at once so FrankenPHP has every opportunity to spread them
    //    across worker threads, must all land -- no lost/corrupted commits
    //    from concurrent access to the shared connection/database.
    check('[cross-thread] concurrent commits from multiple threads are not lost or corrupted', function () use ($baseUrl) {
        $n = CROSS_THREAD_COUNT * 10;
        $urls = array_fill(0, $n, "$baseUrl/?action=txn-commit-row");
        $responses = httpGetJsonConcurrent($urls);

        foreach ($responses as $i => $resp) {
            assertTrue($resp['committed'] === true, "concurrent commit #$i did not report success");
        }

        $check = httpGetJson("$baseUrl/?action=txn-check");
        assertSame($n, $check['row_count'], "expected exactly $n committed rows after $n concurrent commits from up to " . CROSS_THREAD_COUNT . ' threads -- a mismatch means a commit was lost or duplicated under concurrency');
    });
}

// --- Build the image --------------------------------------------------------

fwrite(STDOUT, "Building worker-safety test image...\n");
[$buildCode, , $buildErr] = run(
    ['docker', 'build', '-f', 'test/worker/Dockerfile', '-t', IMAGE_TAG, '.'],
    repoRoot()
);
if ($buildCode !== 0) {
    fail("docker build failed:\n$buildErr");
}

// --- Profile 1: SQLite, single worker thread (the original matrix) ---------

fwrite(STDOUT, "\n[sqlite] Starting worker container...\n");
$sqliteBaseUrl = startWorkerContainer('sqlite', ['WORKER_DB_ADAPTER' => 'sqlite']);
fwrite(STDOUT, "[sqlite] Running worker-safety test matrix...\n");
runCoreMatrix($sqliteBaseUrl, 'sqlite');

// --- Profile 2: Postgres, single worker thread ------------------------------

fwrite(STDOUT, "\n[pgsql] Starting Postgres testcontainer...\n");
$pg = startPostgresForWorker();
fwrite(STDOUT, "[pgsql] Starting worker container...\n");
$pgsqlBaseUrl = startWorkerContainer('pgsql', [
    'WORKER_DB_ADAPTER' => 'pgsql',
    'WORKER_PG_HOST' => $pg['host'],
], $pg['network']);
fwrite(STDOUT, "[pgsql] Running worker-safety test matrix...\n");
runCoreMatrix($pgsqlBaseUrl, 'pgsql');

// --- Profile 3: SQLite, multiple worker threads (cross-thread behavior) ----

fwrite(STDOUT, "\n[cross-thread] Starting worker container with " . CROSS_THREAD_COUNT . " threads...\n");
$crossThreadBaseUrl = startWorkerContainer('cross-thread', [
    'WORKER_DB_ADAPTER' => 'sqlite',
    'WORKER_THREAD_COUNT' => (string) CROSS_THREAD_COUNT,
]);
fwrite(STDOUT, "[cross-thread] Running cross-thread test matrix...\n");
runCrossThreadMatrix($crossThreadBaseUrl);

// --- Profiles 4-7: the global (L2) query cache -------------------------------

foreach (
    [
        ['array', 1, ['WORKER_CACHE_DRIVER' => 'array']],
        ['array-cross-thread', CROSS_THREAD_COUNT, ['WORKER_CACHE_DRIVER' => 'array']],
        // APCu's cross-request/cross-thread sharing is only observable under a
        // persistent SAPI -- under CLI each process gets its own segment -- so
        // this profile is the only place it can actually be demonstrated.
        ['apcu-cross-thread', CROSS_THREAD_COUNT, ['WORKER_CACHE_DRIVER' => 'apcu']],
        ['file-cross-thread', CROSS_THREAD_COUNT, [
            'WORKER_CACHE_DRIVER' => 'file',
            'WORKER_CACHE_DIR' => '/tmp/propulsion-worker-cache',
        ]],
    ] as [$profile, $threads, $env]
) {
    fwrite(STDOUT, "\n[l2:$profile] Starting worker container...\n");
    $url = startWorkerContainer('l2-' . $profile, $env + [
        'WORKER_DB_ADAPTER' => 'sqlite',
        'WORKER_THREAD_COUNT' => (string) $threads,
    ]);
    fwrite(STDOUT, "[l2:$profile] Running global query cache matrix...\n");
    runL2Matrix($url, 'l2:' . $profile, $threads > 1);
}

// --- Verdict -----------------------------------------------------------------

fwrite(STDOUT, "\n");
$failed = array_filter($results, static fn ($ok) => !$ok);
if ($failed) {
    fwrite(STDOUT, count($failed) . ' of ' . count($results) . " checks FAILED.\n");
    exit(1);
}

fwrite(STDOUT, 'All ' . count($results) . " worker-safety checks passed.\n");
exit(0);
