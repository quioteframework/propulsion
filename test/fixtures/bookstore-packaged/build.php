<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.project' => 'bookstore-packaged',
    'propulsion.database' => 'sqlite',
    'propulsion.database.url' => 'sqlite:/var/tmp/test.db',

    'propulsion.targetPackage' => 'bookstore-packaged',
    'propulsion.packageObjectModel' => 'true',
];
