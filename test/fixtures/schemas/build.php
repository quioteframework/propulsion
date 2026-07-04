<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.project' => 'bookstore',
    'propel.database' => 'mysql',
    'propel.database.url' => 'mysql:dbname=test',
    'propel.mysql.tableType' => 'InnoDB',
    'propel.disableIdentifierQuoting' => 'true',
    'propel.schema.autoPrefix' => 'true',
];
