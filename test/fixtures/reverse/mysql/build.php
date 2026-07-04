<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.project' => 'reverse_bookstore',

    'propulsion.database' => 'mysql',
    'propulsion.database.url' => 'mysql:dbname=reverse_bookstore',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propulsion.database.user' => '[db username]',
    // 'propulsion.database.password' => '[db password]',

    'propulsion.mysql.tableType' => 'InnoDB',

    'propulsion.disableIdentifierQuoting' => 'true',
    'propulsion.schema.autoPrefix' => 'true',
];
