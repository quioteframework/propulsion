<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.targetPackage' => 'treetest',
    'propulsion.project' => 'treetest',

    'propulsion.database' => 'sqlite',
    'propulsion.database.url' => 'sqlite:/var/tmp/treetest.db',

    // 'propulsion.database' => 'mysql',
    // 'propulsion.database.url' => 'mysql://localhost/test',

    // 'propulsion.database' => 'codebase',
    // 'propulsion.database.url' => 'odbc://localhost/Driver=CodeBaseOdbcStand;DBQ=test;?adapter=CodeBase',
];
