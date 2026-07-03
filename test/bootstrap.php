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
