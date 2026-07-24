<?php
require __DIR__ . '/../vendor/autoload.php';
foreach (require __DIR__ . '/../test/tools/helpers/generator-legacy-class-map.php' as $legacy => $fqcn) {
    if (!class_exists($legacy, false) && !interface_exists($legacy, false)) {
        try { class_alias($fqcn, $legacy); } catch (\Throwable $e) {}
    }
}
class_exists(\Propulsion\Propulsion::class);
use Propulsion\Generator\Util\PropulsionQuickBuilder;
use Propulsion\Propulsion;

$schema = file_get_contents(__DIR__ . '/bench_schema.xml');
$con = PropulsionQuickBuilder::buildSchema($schema);

$fail = 0; $pass = 0;
function check($cond, $msg) { global $fail, $pass; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $msg\n"; } }

// seed
$con->beginTransaction();
$con->prepare('INSERT INTO bench_author (name) VALUES (?)')->execute(['Ada']);
$ins = $con->prepare('INSERT INTO bench_book (title, isbn, price, stock, published, created_at, author_id) VALUES (?,?,?,?,?,?,?)');
$ins->execute(['Book A', '111', 12.5, 3, 1, '2021-05-06 08:00:00', 1]);
$ins->execute(['Book B', '222', 0.0, 0, 0, null, 1]);
$con->commit();

// --- read + hydrate values ---
$books = BenchBookPeer::doSelect(new Criteria());
check(count($books) === 2, 'row count 2, got ' . count($books));
$a = $books[0];
check($a->getTitle() === 'Book A', 'title A');
check($a->getIsbn() === '111', 'isbn A');
check(abs($a->getPrice() - 12.5) < 1e-9, 'price A');
check($a->getStock() === 3, 'stock A (int)');
check($a->getPublished() === true, 'published A (bool true)');
check($a->getCreatedAt() instanceof DateTime, 'createdAt DateTime');
check($a->getCreatedAt()->format('Y-m-d H:i:s') === '2021-05-06 08:00:00', 'createdAt value');
$b = $books[1];
check($b->getPublished() === false, 'published B (bool false)');
check($b->getCreatedAt() === null, 'createdAt B null');
check($b->getStock() === 0, 'stock B zero');

// --- instance pooling identity (warm) ---
$books2 = BenchBookPeer::doSelect(new Criteria());
check($books2[0] === $a, 'pooling returns same instance');

// --- pooling disabled path ---
Propulsion::disableInstancePooling();
$books3 = BenchBookPeer::doSelect(new Criteria());
check($books3[0] !== $a, 'pooling disabled -> fresh instance');
check($books3[0]->getTitle() === 'Book A', 'pooling disabled hydrate ok');
Propulsion::enableInstancePooling();

// --- modified columns: set-representation semantics ---
$n = new BenchBook();
check($n->isModified() === false, 'new object not modified');
$n->setTitle('X');
$n->setTitle('Y');       // repeated -> must not duplicate
$n->setIsbn('z');
check($n->isColumnModified(BenchBookPeer::TITLE), 'title modified flag');
check(!$n->isColumnModified(BenchBookPeer::PRICE), 'price not modified');
$mod = $n->getModifiedColumns();
check(count($mod) === count(array_unique($mod)), 'no duplicate modified columns');
check(in_array(BenchBookPeer::TITLE, $mod, true) && in_array(BenchBookPeer::ISBN, $mod, true), 'modified list content');
check(count($mod) === 2, 'exactly 2 modified cols after repeated set, got ' . count($mod));
// reset a single column
$n->resetModified(BenchBookPeer::TITLE);
check(!$n->isColumnModified(BenchBookPeer::TITLE), 'title reset');
check($n->isColumnModified(BenchBookPeer::ISBN), 'isbn still modified after single reset');

// --- buildCriteria only contains modified cols ---
$n2 = new BenchBook();
$n2->setTitle('T');
$n2->setStock(9);
$crit = $n2->buildCriteria();
check($crit->containsKey(BenchBookPeer::TITLE), 'criteria has title');
check($crit->containsKey(BenchBookPeer::STOCK), 'criteria has stock');
check(!$crit->containsKey(BenchBookPeer::PRICE), 'criteria lacks price');

// --- save (insert) then reload actually persists ---
$c = new BenchBook();
$c->setTitle('Saved Book');
$c->setIsbn('999');
$c->setPrice(7.25);
$c->setStock(11);
$c->setPublished(true);
$c->setAuthorId(1);
$c->save();
check($c->getId() !== null, 'insert assigned PK');
$reloaded = BenchBookPeer::retrieveByPK($c->getId());
check($reloaded === $c, 'saved object pooled by PK');
BenchBookPeer::clearInstancePool();
$fresh = BenchBookPeer::retrieveByPK($c->getId());
check($fresh !== null && $fresh->getTitle() === 'Saved Book', 'reload after save');
check($fresh->getStock() === 11, 'reload stock');

// --- update path: modify + save persists only changed ---
$fresh->setTitle('Updated Title');
check($fresh->isModified(), 'modified before update');
$fresh->save();
BenchBookPeer::clearInstancePool();
$fresh2 = BenchBookPeer::retrieveByPK($c->getId());
check($fresh2->getTitle() === 'Updated Title', 'update persisted');
check($fresh2->getIsbn() === '999', 'update left other col intact');

// --- join select ---
BenchBookPeer::clearInstancePool();
BenchAuthorPeer::clearInstancePool();
$joined = BenchBookPeer::doSelectJoinBenchAuthor(new Criteria());
check(count($joined) >= 2, 'join returns rows');
check($joined[0]->getBenchAuthor() !== null, 'join prefilled author');
check($joined[0]->getBenchAuthor()->getName() === 'Ada', 'join author name');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
