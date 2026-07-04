<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.targetPackage' => 'treetest',
    'propel.project' => 'treetest',

    'propel.database' => 'sqlite',
    'propel.database.url' => 'sqlite:/var/tmp/treetest.db',

    // 'propel.database' => 'mysql',
    // 'propel.database.url' => 'mysql://localhost/test',

    // 'propel.database' => 'codebase',
    // 'propel.database.url' => 'odbc://localhost/Driver=CodeBaseOdbcStand;DBQ=test;?adapter=CodeBase',
];
