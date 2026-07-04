<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propulsion.project' => 'nestedset',
    'propulsion.database' => 'sqlite',
    'propulsion.database.url' => 'sqlite:/var/tmp/nestedset.db',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propulsion.database.user' => '[db username]',
    // 'propulsion.database.password' => '[db password]',

    'propulsion.targetPackage' => 'nestedset',

    // The unit tests need to test this stuff
    'propulsion.addGenericAccessors' => 'true',
    'propulsion.addGenericMutators' => 'true',

    // Use the new PHP 5.2 DateTime class
    'propulsion.useDateTimeClass' => 'true',
];
