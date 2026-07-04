<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.project' => 'generator_parity',
    'propel.database' => 'pgsql',
    'propel.database.url' => 'pgsql:dbname=propulsion_test',
    'propel.disableIdentifierQuoting' => 'true',
    'propel.targetPackage' => 'generator_parity',
];
