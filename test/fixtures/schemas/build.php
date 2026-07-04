<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.project' => 'bookstore',
    'propulsion.database' => 'mysql',
    'propulsion.database.url' => 'mysql:dbname=test',
    'propulsion.mysql.tableType' => 'InnoDB',
    'propulsion.disableIdentifierQuoting' => 'true',
    'propulsion.schema.autoPrefix' => 'true',
];
