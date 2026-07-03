<?php

/**
 * PHPUnit bootstrap file for Propel tests
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

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
