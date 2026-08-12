<?php

/**
 * Propulsion generated-code performance baseline.
 *
 * Self-contained: builds a small two-table model with PropulsionQuickBuilder
 * against in-memory SQLite (no Docker / no external DB), seeds it, and times
 * the hot paths that the code generators emit:
 *
 *   - populateObjects()/hydrate() : the per-row read path (pooling on/off, warm)
 *   - doSelectJoin*()             : the joined read path
 *   - save() (insert)             : the write path
 *   - setter churn + buildCriteria: the modified-columns tracking path
 *
 * Usage:
 *   php bench/hydration_bench.php [rows] [reps]
 *
 * Prints median ns/op and ops/sec per scenario. Run it before and after a
 * change to the generators to see whether the change actually helps.
 */

require __DIR__ . '/../vendor/autoload.php';

// Bare legacy class names (Criteria, BasePeer, ...) the generated code uses.
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
$REPS = isset($argv[2]) ? max(3, (int) $argv[2]) : 15;

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

// Build the model as *file-based* generated classes (require'd, opcache-cacheable),
// mirroring how a real project runs -- rather than QuickBuilder's eval() path, whose
// static-method dispatch on eval'd classes is not representative of production.
$builder = new PropulsionQuickBuilder();
$builder->setSchema($schema);
$classSrc = "<?php\n" . $builder->getClasses();
$classFile = ($cacheDir = getenv('BENCH_TMP') ?: sys_get_temp_dir()) . '/propulsion_bench_model.php';
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

// ---- Seed data directly via PDO (fast, keeps the benchmark about reads/writes) ----
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

// ---- timing helper: warmup + median over $REPS ----
function bench(string $label, int $reps, callable $fn, ?int $opsPerRun = null): void
{
    $fn(); // warmup (also lets JIT/opcache settle, populates pool if relevant)
    $times = [];
    $mem0 = memory_get_peak_usage();
    for ($r = 0; $r < $reps; $r++) {
        $t = hrtime(true);
        $fn();
        $times[] = hrtime(true) - $t;
    }
    sort($times);
    $median = $times[intdiv(count($times), 2)];
    $line = sprintf('%-38s  %9.3f ms/run', $label, $median / 1e6);
    if ($opsPerRun) {
        $nsPerOp = $median / $opsPerRun;
        $opsPerSec = 1e9 / $nsPerOp;
        $line .= sprintf('  | %8.0f ns/op  | %11s ops/s', $nsPerOp, number_format($opsPerSec));
    }
    echo $line . "\n";
}

echo sprintf("Propulsion generated-code baseline  (rows=%d, reps=%d, PHP %s)\n", $ROWS, $REPS, PHP_VERSION);
echo sprintf("JIT: %s | opcache: %s\n",
    function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['enabled'] ?? false) ? 'on' : 'off',
    function_exists('opcache_get_status') && opcache_get_status(false) ? 'on' : 'off');
echo str_repeat('-', 96) . "\n";

// ---- Scenario A: full-table read, pooling ON, cold pool (clear each run) ----
// This is the real hydrate() + addInstanceToPool() cost most apps pay.
bench('A read+hydrate pool=on cold', $REPS, function () {
    BenchBookPeer::clearInstancePool();
    $c = new Criteria();
    $res = BenchBookPeer::doSelect($c);
    if (count($res) < 1) { throw new RuntimeException('no rows'); }
}, $ROWS);

// ---- Scenario B: full-table read, pooling OFF ----
Propulsion::disableInstancePooling();
bench('B read+hydrate pool=off', $REPS, function () {
    $c = new Criteria();
    $res = BenchBookPeer::doSelect($c);
    if (count($res) < 1) { throw new RuntimeException('no rows'); }
}, $ROWS);
Propulsion::enableInstancePooling();

// ---- Scenario C: full-table read, pooling ON, warm pool (pool already full) ----
// Exercises the getInstanceFromPool() hit path (no hydrate).
BenchBookPeer::clearInstancePool();
BenchBookPeer::doSelect(new Criteria()); // fill pool
bench('C read pool=on warm (pool hits)', $REPS, function () {
    $c = new Criteria();
    $res = BenchBookPeer::doSelect($c);
    if (count($res) < 1) { throw new RuntimeException('no rows'); }
}, $ROWS);

// ---- Scenario D: joined read (book + author), cold pool ----
bench('D doSelectJoinBenchAuthor cold', $REPS, function () {
    BenchBookPeer::clearInstancePool();
    BenchAuthorPeer::clearInstancePool();
    $c = new Criteria();
    $res = BenchBookPeer::doSelectJoinBenchAuthor($c);
    if (count($res) < 1) { throw new RuntimeException('no rows'); }
}, $ROWS);

// ---- Scenario E: write path (insert), N new objects, one transaction ----
$WRITE = min($ROWS, 2000);
bench('E save() insert x' . $WRITE, max(3, intdiv($REPS, 2)), function () use ($con, $WRITE) {
    $con->beginTransaction();
    for ($i = 0; $i < $WRITE; $i++) {
        $b = new BenchBook();
        $b->setTitle('New Book ' . $i);
        $b->setIsbn('000-' . $i);
        $b->setPrice(1.23);
        $b->setStock(5);
        $b->setPublished(true);
        $b->setAuthorId(1);
        $b->save($con);
    }
    $con->rollBack(); // don't actually grow the table between runs
}, $WRITE);

// ---- Scenario F: micro -- setter churn + buildCriteria (modified-columns path) ----
$CHURN = 50000;
bench('F setter churn + buildCriteria x' . $CHURN, $REPS, function () use ($CHURN) {
    for ($i = 0; $i < $CHURN; $i++) {
        $b = new BenchBook();
        $b->setTitle('t');
        $b->setTitle('t2');     // repeated set -> duplicate in modifiedColumns
        $b->setIsbn('x');
        $b->setPrice(1.0);
        $b->setStock(1);
        $c = $b->buildCriteria(); // N x isColumnModified (in_array)
    }
}, $CHURN);

echo str_repeat('-', 96) . "\n";
echo "peak memory: " . round(memory_get_peak_usage() / 1048576, 1) . " MB\n";
