<?php

/**
 * PHPUnit bootstrap file for Propel tests
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// This test suite predates the Propel -> Propulsion namespace rename entirely and
// references generator classes (Criteria, ModelCriteria, Behavior, XmlToAppData, ...)
// by their bare historic name throughout. Alias them eagerly, before PHPUnit loads
// any test file, so `catch (SomeException $e)` (which does not autoload) also works.
// (runtime/Lib/legacy-class-map.php's equivalent aliasing for runtime classes runs
// automatically whenever Propulsion\Propel is loaded.)
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
try {
    foreach (require __DIR__ . '/tools/helpers/generator-legacy-class-map.php' as $legacyName => $fqcn) {
        if (!class_exists($legacyName, false) && !interface_exists($legacyName, false)) {
            try {
                class_alias($fqcn, $legacyName);
            } catch (\Throwable $e) {
                // Skip classes with unmet dependencies (or a stray warning) of their
                // own rather than aborting the whole test run over one legacy alias.
            }
        }
    }
} finally {
    restore_error_handler();
}

// Include the base test case
require_once __DIR__ . '/tools/helpers/BaseTestCase.php';

// Include data populators if they exist  
$dataPopulatorFile = __DIR__ . '/tools/helpers/bookstore/BookstoreDataPopulator.php';
if (file_exists($dataPopulatorFile)) {
    require_once $dataPopulatorFile;
}

// Include all test base classes (these may depend on Propel being loaded)
$testBaseFiles = [
    __DIR__ . '/tools/helpers/bookstore/BookstoreTestBase.php',
    __DIR__ . '/tools/helpers/bookstore/BookstoreEmptyTestBase.php',
    __DIR__ . '/tools/helpers/schemas/SchemasTestBase.php',
    __DIR__ . '/tools/helpers/cms/CmsTestBase.php',
];

foreach ($testBaseFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

// Test helper base classes that live alongside the test cases that use them
// (under test/testsuite/, not test/tools/helpers/) rather than as *Test.php
// files -- PHPUnit's own directory-based suite discovery only picks up
// *Test.php, so these need to be required explicitly too, in dependency order.
$platformTestHelperFiles = [
    __DIR__ . '/testsuite/generator/platform/PlatformTestBase.php',
    __DIR__ . '/testsuite/generator/platform/PlatformTestProvider.php',
    __DIR__ . '/testsuite/generator/platform/PlatformMigrationTestProvider.php',
];

foreach ($platformTestHelperFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

// Some test files declare auxiliary classes at file scope that extend generated
// Bookstore fixture classes (e.g. `class UndeletableTable4 extends Table4`).
// PHPUnit's TestSuiteLoader requires every test file up front during suite
// discovery, before any test's setUp() runs -- so the fixtures (and the classmap
// autoloader for their unnamespaced generated classes) must exist *before* that,
// not lazily on first use. If this fails (e.g. no Docker), leave a clear message;
// individual Bookstore/Cms tests will still fail during setUp()'s own
// ensureReady() call, but anything referencing a generated class at file scope
// will fatal here instead of skipping cleanly -- an inherent constraint of this
// suite's structure, not something a lazier build step could fix.
try {
    IntegrationDatabase::ensureReady();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\nWarning: bookstore fixtures not built (" . $e->getMessage() . ")\n"
        . "Tests that reference generated fixture classes at file scope will fatal during suite discovery.\n\n");
}

// Same file-scope-declaration constraint as above, for test helpers (not test
// classes themselves) that declare classes extending generated fixture classes --
// these must be required only after ensureReady() has built the fixtures.
$fixtureDependentHelperFiles = [
    __DIR__ . '/tools/helpers/bookstore/behavior/BookstoreNestedSetTestBase.php',
    __DIR__ . '/tools/helpers/bookstore/behavior/BookstoreSortableTestBase.php',
    __DIR__ . '/tools/helpers/bookstore/behavior/TestAuthor.php',
];

foreach ($fixtureDependentHelperFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}
