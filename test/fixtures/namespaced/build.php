<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.project' => 'bookstore_namespaced',
    'propel.database' => 'mysql',
    'propel.database.url' => 'mysql:dbname=test',
    'propel.mysql.tableType' => 'InnoDB',
    'propel.disableIdentifierQuoting' => 'true',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propel.database.user' => '[db username]',
    // 'propel.database.password' => '[db password]',

    'propel.targetPackage' => 'bookstore',
];
