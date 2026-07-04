<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.project' => 'generator_parity',
    'propulsion.database' => 'pgsql',
    'propulsion.database.url' => 'pgsql:dbname=propulsion_test',
    'propulsion.disableIdentifierQuoting' => 'true',
    'propulsion.targetPackage' => 'generator_parity',
];
