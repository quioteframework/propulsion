<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Generates all three fixture projects' model classes, without running the test
 * suite.
 *
 * The generated tree is what `phpstan-generated.neon` analyses, and CI needs it
 * built before that gate can mean anything. Running the whole suite would do it
 * as a side effect, but that is a minute of test execution to produce files, and
 * it puts every test's flakiness in front of a static-analysis gate.
 *
 * Only the bookstore project can be generated without Docker (see the comment in
 * test/bootstrap.php); the namespaced and schemas projects are Postgres-specific
 * and their builders need a live connection. So this exits non-zero if a
 * container cannot be started, rather than quietly producing a third of the tree
 * -- an empty or partial tree is the one input that makes a PHPStan run pass by
 * having nothing to look at.
 */

require __DIR__ . '/../bootstrap.php';

$projects = [
    'bookstore'  => static fn () => IntegrationDatabase::ensureReady(),
    'namespaced' => static fn () => IntegrationDatabase::ensureNamespacedReady(),
    'schemas'    => static fn () => IntegrationDatabase::ensureSchemasReady(),
];

$failed = [];
foreach ($projects as $name => $build) {
    try {
        $build();
        fwrite(STDOUT, "generated: $name\n");
    } catch (\Throwable $e) {
        $failed[$name] = $e->getMessage();
        fwrite(STDERR, "FAILED: $name -- " . $e->getMessage() . "\n");
    }
}

// Count what actually landed, per project. A builder that "succeeded" without
// writing anything would otherwise hand PHPStan an empty directory, which it
// reports as clean -- and a single combined total would let one project vanish
// behind the other two.
//
// Floors rather than exact counts, so adding a table to a fixture schema does
// not break the build. Current output is bookstore 360, namespaced 48,
// schemas 49.
$floors = ['bookstore' => 250, 'namespaced' => 30, 'schemas' => 30];

$total = 0;
foreach ($floors as $name => $floor) {
    $dir = __DIR__ . '/../fixtures/' . $name . '/build/classes';
    if (!is_dir($dir)) {
        fwrite(STDERR, "FAILED: $name -- no build/classes directory\n");
        $failed[$name] ??= 'no build/classes directory';
        continue;
    }
    $count = iterator_count(new \CallbackFilterIterator(
        new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
        static fn (\SplFileInfo $f): bool => $f->getExtension() === 'php'
    ));
    $total += $count;

    if ($count < $floor) {
        fwrite(STDERR, sprintf("FAILED: %s -- only %d php files, expected at least %d\n", $name, $count, $floor));
        $failed[$name] ??= sprintf('only %d php files', $count);
        continue;
    }
    fwrite(STDOUT, sprintf("%-11s %5d php files\n", $name . ':', $count));
}

if ($failed !== []) {
    fwrite(STDERR, "\nFixture generation failed for: " . implode(', ', array_keys($failed)) . "\n");
    exit(1);
}

fwrite(STDOUT, "\ntotal: $total generated php files\n");
