<?php

/**
 * Query result cache performance baseline (see runtime/Lib/Cache/QueryResultCache.php,
 * Criteria::setQueryCache()).
 *
 * Measures the thing the feature is actually for: running the *same* query
 * repeatedly (the common real-world case -- a lookup called from a loop, or
 * from several unrelated call sites within one request) with the cache off
 * vs on, through both call styles the cache supports (ModelCriteria's
 * find()/findOne()/count(), and the generated Peer's doSelect()/doCount()).
 *
 * Usage:
 *   php bench/query_cache_bench.php [rows] [repeats]
 *
 * repeats = how many times the *same* query is re-issued per scenario (the
 * cache's win grows with this, since only the first call ever touches the DB).
 */

require __DIR__ . '/../vendor/autoload.php';

foreach (require __DIR__ . '/../test/tools/helpers/generator-legacy-class-map.php' as $legacy => $fqcn) {
    if (!class_exists($legacy, false) && !interface_exists($legacy, false)) {
        try { class_alias($fqcn, $legacy); } catch (\Throwable $e) {}
    }
}
class_exists(\Propulsion\Propulsion::class);

use Propulsion\Generator\Util\PropulsionQuickBuilder;
use Propulsion\Adapter\DBSQLite;
use Propulsion\Adapter\Sqlite\SqlitePropulsionPDO;
use Propulsion\Propulsion;

$ROWS = isset($argv[1]) ? max(1, (int) $argv[1]) : 5000;
$REPEATS = isset($argv[2]) ? max(3, (int) $argv[2]) : 200;

$schema = <<<EOF
<database name="bench">
  <table name="bench_book">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="title" type="VARCHAR" size="255"/>
    <column name="isbn" type="VARCHAR" size="24"/>
    <column name="price" type="FLOAT"/>
    <column name="stock" type="INTEGER"/>
    <column name="published" type="BOOLEAN"/>
    <column name="created_at" type="TIMESTAMP"/>
    <column name="author_id" type="INTEGER"/>
    <foreign-key foreignTable="bench_author">
      <reference local="author_id" foreign="id"/>
    </foreign-key>
  </table>
  <table name="bench_author">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="128"/>
  </table>
</database>
EOF;

$builder = new PropulsionQuickBuilder();
$builder->setSchema($schema);
$classSrc = "<?php\n" . $builder->getClasses();
$classFile = ($cacheDir = getenv('BENCH_TMP') ?: sys_get_temp_dir()) . '/propulsion_bench_qcache_model.php';
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

$authors = max(1, intdiv($ROWS, 20));
$con->beginTransaction();
$ins = $con->prepare('INSERT INTO bench_author (name) VALUES (?)');
for ($i = 1; $i <= $authors; $i++) {
    $ins->execute(['Author ' . $i]);
}
$ins = $con->prepare(
    'INSERT INTO bench_book (title, isbn, price, stock, published, created_at, author_id) VALUES (?,?,?,?,?,?,?)'
);
for ($i = 1; $i <= $ROWS; $i++) {
    $ins->execute([
        'Book Title Number ' . $i,
        sprintf('978-%010d', $i),
        9.99 + ($i % 40),
        $i % 500,
        $i % 2,
        '2020-01-01 12:00:00',
        ($i % $authors) + 1,
    ]);
}
$con->commit();

function bench(string $label, int $reps, callable $fn, ?int $opsPerRun = null): void
{
    $fn(); // warmup
    $times = [];
    for ($r = 0; $r < $reps; $r++) {
        $t = hrtime(true);
        $fn();
        $times[] = hrtime(true) - $t;
    }
    sort($times);
    $median = $times[intdiv(count($times), 2)];
    $line = sprintf('%-46s  %9.3f ms/run', $label, $median / 1e6);
    if ($opsPerRun) {
        $nsPerOp = $median / $opsPerRun;
        $opsPerSec = 1e9 / $nsPerOp;
        $line .= sprintf('  | %8.0f ns/op  | %11s ops/s', $nsPerOp, number_format($opsPerSec));
    }
    echo $line . "\n";
}

echo sprintf("Propulsion query result cache baseline  (rows=%d, repeats=%d, PHP %s)\n", $ROWS, $REPEATS, PHP_VERSION);
echo sprintf("JIT: %s | opcache: %s\n",
    function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['enabled'] ?? false) ? 'on' : 'off',
    function_exists('opcache_get_status') && opcache_get_status(false) ? 'on' : 'off');
echo str_repeat('-', 110) . "\n";

// ---- Scenario A: ModelCriteria::find(), cache OFF -- $REPEATS identical queries ----
bench('A ModelCriteria find() cache=off x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    for ($i = 0; $i < $REPEATS; $i++) {
        $c = new ModelCriteria('bench', 'BenchBook');
        $c->add(BenchBookPeer::PUBLISHED, true);
        $res = $c->find($con);
        if (count($res) < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

// ---- Scenario B: ModelCriteria::find(), cache ON -- same $REPEATS identical queries ----
bench('B ModelCriteria find() cache=on x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    Propulsion::getSession()->getQueryResultCache()->clear();
    for ($i = 0; $i < $REPEATS; $i++) {
        $c = new ModelCriteria('bench', 'BenchBook');
        $c->add(BenchBookPeer::PUBLISHED, true);
        $c->setQueryCache(true);
        $res = $c->find($con);
        if (count($res) < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

// ---- Scenario C: ModelCriteria::count(), cache OFF ----
bench('C ModelCriteria count() cache=off x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    for ($i = 0; $i < $REPEATS; $i++) {
        $c = new ModelCriteria('bench', 'BenchBook');
        $c->add(BenchBookPeer::PUBLISHED, true);
        $n = $c->count($con);
        if ($n < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

// ---- Scenario D: ModelCriteria::count(), cache ON ----
bench('D ModelCriteria count() cache=on x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    Propulsion::getSession()->getQueryResultCache()->clear();
    for ($i = 0; $i < $REPEATS; $i++) {
        $c = new ModelCriteria('bench', 'BenchBook');
        $c->add(BenchBookPeer::PUBLISHED, true);
        $c->setQueryCache(true);
        $n = $c->count($con);
        if ($n < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

// ---- Scenario E: raw Peer doSelect(), cache OFF ----
bench('E BenchBookPeer::doSelect() cache=off x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    for ($i = 0; $i < $REPEATS; $i++) {
        BenchBookPeer::clearInstancePool();
        $c = new Criteria();
        $c->add(BenchBookPeer::PUBLISHED, true);
        $res = BenchBookPeer::doSelect($c, $con);
        if (count($res) < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

// ---- Scenario F: raw Peer doSelect(), cache ON ----
bench('F BenchBookPeer::doSelect() cache=on x' . $REPEATS, 10, function () use ($con, $REPEATS) {
    Propulsion::getSession()->getQueryResultCache()->clear();
    for ($i = 0; $i < $REPEATS; $i++) {
        BenchBookPeer::clearInstancePool();
        $c = new Criteria();
        $c->add(BenchBookPeer::PUBLISHED, true);
        $c->setQueryCache(true);
        $res = BenchBookPeer::doSelect($c, $con);
        if (count($res) < 1) { throw new RuntimeException('no rows'); }
    }
}, $REPEATS);

echo str_repeat('-', 110) . "\n";
echo "peak memory: " . round(memory_get_peak_usage() / 1048576, 1) . " MB\n";
