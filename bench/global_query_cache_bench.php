<?php

/**
 * Global (L2) query result cache performance (see runtime/Lib/Cache/SharedQueryCache.php,
 * the `cache.query` runtime configuration section).
 *
 * Measures the thing the shared tier is actually for: the *second* request to
 * run a given query, in a process where the request-scoped tier has already
 * been wiped. Every L2 scenario therefore calls Session::reset() before each
 * iteration -- exactly what a worker host does at a request boundary -- so the
 * numbers are genuine cross-request hits rather than L1 hits in disguise. A
 * reset()-only control column is reported so L2 can be read net of that.
 *
 * Usage:
 *   php bench/global_query_cache_bench.php [rows] [repeats] [drivers]
 *
 * drivers = comma-separated subset of array,apcu,file (default: all available).
 *
 * Reproduce (production-representative: JIT on, profiler off):
 *   php -dpcov.enabled=0 -dopcache.enable_cli=1 -dopcache.jit=tracing \
 *       -dopcache.jit_buffer_size=64M -dapc.enable_cli=1 \
 *       bench/global_query_cache_bench.php 5000 200
 *
 * Caveat that must accompany any file-driver figure quoted from this script:
 * the pages were written milliseconds earlier by this same process, so those
 * numbers are warm-page-cache numbers and an upper bound on what a real
 * deployment sees. No attempt is made to measure a cold cache -- dropping the
 * page cache needs root and is not reproducible.
 */

require __DIR__ . '/../vendor/autoload.php';

foreach (require __DIR__ . '/../test/tools/helpers/generator-legacy-class-map.php' as $legacy => $fqcn) {
    if (!class_exists($legacy, false) && !interface_exists($legacy, false)) {
        try { class_alias($fqcn, $legacy); } catch (\Throwable $e) {}
    }
}
class_exists(\Propulsion\Propulsion::class);

use Propulsion\Adapter\DBSQLite;
use Propulsion\Cache\CacheDriverFactory;
use Propulsion\Cache\QueryCacheConfig;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Generator\Util\PropulsionQuickBuilder;
use Propulsion\Propulsion;
use Propulsion\ServiceContainer;
use Propulsion\Session;

$ROWS = isset($argv[1]) ? max(1, (int) $argv[1]) : 5000;
$REPEATS = isset($argv[2]) ? max(3, (int) $argv[2]) : 200;
$DRIVERS = isset($argv[3]) ? explode(',', $argv[3]) : ['array', 'apcu', 'file'];

$schema = <<<EOF
<database name="bench">
  <table name="bench_author">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="100"/>
  </table>
  <table name="bench_book">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="title" type="VARCHAR" size="255"/>
    <column name="price" type="FLOAT"/>
    <column name="author_id" type="INTEGER"/>
    <foreign-key foreignTable="bench_author">
      <reference local="author_id" foreign="id"/>
    </foreign-key>
  </table>
</database>
EOF;

$builder = new PropulsionQuickBuilder();
$builder->setSchema($schema);
$classSrc = "<?php\n" . $builder->getClasses();
$classFile = (getenv('BENCH_TMP') ?: sys_get_temp_dir()) . '/propulsion_bench_global_qcache_model.php';
file_put_contents($classFile, $classSrc);
require $classFile;

$con = new SqlitePropulsionPDO('sqlite::memory:');
$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$builder->buildSQL($con);
$dbName = $builder->getDatabase()->getName();
if (!Propulsion::isInit()) {
    Propulsion::setConfiguration(array('datasources' => array('default' => $dbName)));
}
Propulsion::setDB($dbName, new DBSQLite());
Propulsion::setConnection($dbName, $con, Propulsion::CONNECTION_READ);
Propulsion::setConnection($dbName, $con, Propulsion::CONNECTION_WRITE);

// Seed.
$con->beginTransaction();
define('BENCH_DB', $dbName);

$authorStmt = $con->prepare('INSERT INTO bench_author (name) VALUES (?)');
for ($i = 0; $i < 20; $i++) {
    $authorStmt->execute(['Author ' . $i]);
}
$bookStmt = $con->prepare('INSERT INTO bench_book (title, price, author_id) VALUES (?, ?, ?)');
for ($i = 0; $i < $ROWS; $i++) {
    $bookStmt->execute(['Book ' . $i, ($i % 100) + 0.5, ($i % 20) + 1]);
}
$con->commit();

/**
 * Median nanoseconds per operation.
 */
function bench(int $repeats, callable $op): float
{
    $samples = [];
    for ($i = 0; $i < $repeats; $i++) {
        $start = hrtime(true);
        $op($i);
        $samples[] = hrtime(true) - $start;
    }
    sort($samples);

    return (float) $samples[intdiv(count($samples), 2)];
}

function useDriver(string $driver, array $options): void
{
    $container = new ServiceContainer();
    $container->setQueryCacheConfig(new QueryCacheConfig(
        enabled: true,
        driver: $driver,
        ttl: 300,
        namespace: 'bench',
        // Cache on first miss and disable probabilistic early recomputation:
        // this benchmark measures the hit path, not the admission policy.
        minSightings: 1,
        beta: 0.0,
    ));
    $container->setQueryCachePool(CacheDriverFactory::factory($driver, $options, 300));
    Propulsion::setServiceContainer($container);
    Propulsion::setSession(new Session());
}

function noCache(): void
{
    Propulsion::setServiceContainer(new ServiceContainer());
    Propulsion::setSession(new Session());
}

/** A single-table cached find(), limited so hydration cost does not dominate. */
function cachedFind(): void
{
    $q = new ModelCriteria(BENCH_DB, 'BenchBook', 'b');
    $q->setQueryCache(true);
    $q->setLimit(50);
    $q->orderBy('b.Id');
    $q->find();
}

/** A joined query: three table version tokens to read on every hit. */
function cachedJoinFind(): void
{
    $q = new ModelCriteria(BENCH_DB, 'BenchBook', 'b');
    $q->setQueryCache(true);
    $q->join('b.BenchAuthor a');
    $q->where('b.Price > ?', 10);
    $q->setLimit(50);
    $q->orderBy('b.Id');
    $q->find();
}

function uncachedFind(): void
{
    $q = new ModelCriteria(BENCH_DB, 'BenchBook', 'b');
    $q->setLimit(50);
    $q->orderBy('b.Id');
    $q->find();
}

$fileDir = sys_get_temp_dir() . '/propulsion-bench-cache-' . bin2hex(random_bytes(4));

$available = [];
foreach ($DRIVERS as $driver) {
    $driver = trim($driver);
    if ($driver === 'apcu' && (!extension_loaded('apcu') || !apcu_enabled())) {
        fwrite(STDERR, "skipping apcu: extension not loaded/enabled (need -dapc.enable_cli=1)\n");
        continue;
    }
    $available[$driver] = match ($driver) {
        'file' => ['directory' => $fileDir],
        'array' => ['max_entries' => 100000],
        default => [],
    };
}

printf("Global query cache bench: %d rows, %d repeats, PHP %s\n\n", $ROWS, $REPEATS, PHP_VERSION);

// --- Controls ---------------------------------------------------------------
noCache();
$uncached = bench($REPEATS, static fn () => uncachedFind());

noCache();
$resetOnly = bench($REPEATS, static fn () => Propulsion::getSession()->reset());

// L1 hit: cache on, no reset between iterations.
useDriver('array', ['max_entries' => 100000]);
cachedFind();
$l1 = bench($REPEATS, static fn () => cachedFind());

printf("%-34s %12s %12s\n", 'Scenario', 'ns/op', 'vs uncached');
printf("%-34s %12s %12s\n", str_repeat('-', 34), str_repeat('-', 12), str_repeat('-', 12));
printf("%-34s %12.0f %12s\n", 'U  uncached find()', $uncached, '1.0x');
printf("%-34s %12.0f %11.1fx\n", 'L1 request-scoped hit', $l1, $uncached / max($l1, 1));
printf("%-34s %12.0f %12s\n", 'C  Session::reset() control', $resetOnly, '-');
printf("\n");

printf("%-34s %12s %12s %12s\n", 'Scenario (per driver)', 'ns/op', 'net of C', 'vs uncached');
printf("%-34s %12s %12s %12s\n", str_repeat('-', 34), str_repeat('-', 12), str_repeat('-', 12), str_repeat('-', 12));

foreach ($available as $driver => $options) {
    useDriver($driver, $options);

    // Warm the entry, then measure genuine cross-request hits.
    cachedFind();
    $l2 = bench($REPEATS, static function () {
        Propulsion::getSession()->reset();
        cachedFind();
    });
    $l2Net = max($l2 - $resetOnly, 1);
    printf("%-34s %12.0f %12.0f %11.1fx\n", "L2 hit [$driver]", $l2, $l2Net, $uncached / $l2Net);

    cachedJoinFind();
    $l2Join = bench($REPEATS, static function () {
        Propulsion::getSession()->reset();
        cachedJoinFind();
    });
    printf("%-34s %12.0f %12.0f %12s\n", "L2 hit, joined [$driver]", $l2Join, max($l2Join - $resetOnly, 1), '-');

    // Miss + store: a distinct version token per iteration guarantees a miss.
    $missCounter = 0;
    $l2Miss = bench($REPEATS, static function () use (&$missCounter) {
        Propulsion::getSession()->reset();
        $q = new ModelCriteria(BENCH_DB, 'BenchBook', 'b');
        $q->setQueryCache(true);
        $q->where('b.Price > ?', ($missCounter++ % 1000) / 10);
        $q->setLimit(50);
        $q->find();
    });
    printf("%-34s %12.0f %12.0f %12s\n", "L2 miss + store [$driver]", $l2Miss, max($l2Miss - $resetOnly, 1), '-');

    // Invalidation cost on the write path.
    $inv = bench($REPEATS, static function () {
        Propulsion::invalidateQueryCacheForTables(['bench_book'], BENCH_DB);
    });
    printf("%-34s %12.0f %12s %12s\n", "INV table version bump [$driver]", $inv, '-', '-');
    printf("\n");
}

// Clean up the file driver's tree.
if (is_dir($fileDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fileDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($fileDir);
}

fwrite(STDOUT, "Note: file-driver figures are warm-page-cache and an upper bound on real-world performance.\n");
