<?php

/**
 * Ad hoc benchmark for the compiled-query (SQL-string) cache added to
 * BasePeer::createSelectSql() (see PLATFORM_FEATURES.md, "Compiled-query /
 * SQL-string cache"). Times repeated createSelectSql() calls against the same
 * Criteria "shape" (a join + a few WHERE conditions + ORDER BY + LIMIT/OFFSET),
 * with only bound values changing between iterations, cache off vs cache on.
 *
 * Usage:
 *   php bench/compiled_query_cache_bench.php [iterations]
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
use Propulsion\Connection\PropulsionPDO;
use Propulsion\Propulsion;
use Propulsion\Util\BasePeer;

$ITER = isset($argv[1]) ? max(100, (int) $argv[1]) : 20000;

$schema = <<<EOF
<database name="bench_cqc">
  <table name="bench_cqc_book">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="title" type="VARCHAR" size="255"/>
    <column name="isbn" type="VARCHAR" size="24"/>
    <column name="price" type="FLOAT"/>
    <column name="author_id" type="INTEGER"/>
    <foreign-key foreignTable="bench_cqc_author">
      <reference local="author_id" foreign="id"/>
    </foreign-key>
  </table>
  <table name="bench_cqc_author">
    <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    <column name="name" type="VARCHAR" size="128"/>
  </table>
</database>
EOF;

$builder = new PropulsionQuickBuilder();
$builder->setSchema($schema);
$classSrc = "<?php\n" . $builder->getClasses();
$classFile = (getenv('BENCH_TMP') ?: sys_get_temp_dir()) . '/propulsion_bench_cqc_model.php';
file_put_contents($classFile, $classSrc);
require $classFile;

$con = new PropulsionPDO('sqlite::memory:');
$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$builder->buildSQL($con);
$dbName = $builder->getDatabase()->getName();
if (!Propulsion::isInit()) {
    Propulsion::setConfiguration(array('datasources' => array('default' => $dbName)));
}
Propulsion::setDB($dbName, new DBSQLite());
Propulsion::setConnection($dbName, $con, Propulsion::CONNECTION_READ);
Propulsion::setConnection($dbName, $con, Propulsion::CONNECTION_WRITE);

function buildCriteria(int $i): \Criteria
{
    $c = new \Criteria('bench_cqc');
    $c->setPrimaryTableName('bench_cqc_book');
    $c->addSelectColumn('bench_cqc_book.ID');
    $c->addSelectColumn('bench_cqc_book.TITLE');
    $c->addJoin('bench_cqc_book.AUTHOR_ID', 'bench_cqc_author.ID', \Criteria::LEFT_JOIN);
    $c->add('bench_cqc_book.PRICE', $i % 100, \Criteria::GREATER_EQUAL);
    $c->add('bench_cqc_author.NAME', 'Author ' . $i, \Criteria::NOT_EQUAL);
    $c->addAscendingOrderByColumn('bench_cqc_book.ID');
    $c->setLimit(20);
    $c->setOffset(0);
    return $c;
}

function median(array $v): float
{
    sort($v);
    $n = count($v);
    return $n % 2 === 0 ? ($v[$n / 2 - 1] + $v[$n / 2]) / 2 : $v[(int) ($n / 2)];
}

function timeIt(callable $fn, int $iter): array
{
    $samples = [];
    for ($i = 0; $i < $iter; $i++) {
        $t0 = hrtime(true);
        $fn($i);
        $samples[] = hrtime(true) - $t0;
    }
    return $samples;
}

echo "Compiled query cache bench -- $ITER iterations\n";
echo str_repeat('-', 60) . "\n";

// Warm up autoloading/opcache before timing.
for ($i = 0; $i < 50; $i++) {
    $params = [];
    BasePeer::createSelectSql(buildCriteria($i), $params);
}

$samplesUncached = timeIt(function ($i) {
    $params = [];
    $c = buildCriteria($i);
    return BasePeer::createSelectSql($c, $params);
}, $ITER);

$samplesCached = timeIt(function ($i) {
    $params = [];
    $c = buildCriteria($i);
    $c->setCompiledQueryCache('bench-shape');
    return BasePeer::createSelectSql($c, $params);
}, $ITER);

$medianUncached = median($samplesUncached);
$medianCached = median($samplesCached);

printf("uncached (rebuilt every call):  median %.0f ns/op, %.0f ops/sec\n", $medianUncached, 1e9 / $medianUncached);
printf("compiled cache (cache hit):     median %.0f ns/op, %.0f ops/sec\n", $medianCached, 1e9 / $medianCached);
printf("speedup: %.2fx\n", $medianUncached / $medianCached);

// Sanity: prove a cache hit still returns SQL usable to fetch the right params count.
$cSanity = buildCriteria(1);
$cSanity->setCompiledQueryCache('bench-shape');
$paramsSanity = [];
$sql = BasePeer::createSelectSql($cSanity, $paramsSanity);
echo "\nSample cached SQL: $sql\n";
echo 'Param count: ' . count($paramsSanity) . "\n";
