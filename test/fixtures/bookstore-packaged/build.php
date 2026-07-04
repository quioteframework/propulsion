<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.project' => 'bookstore-packaged',
    'propel.database' => 'sqlite',
    'propel.database.url' => 'sqlite:/var/tmp/test.db',

    'propel.targetPackage' => 'bookstore-packaged',
    'propel.packageObjectModel' => 'true',
];
