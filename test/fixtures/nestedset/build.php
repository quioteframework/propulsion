<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 */
return [
    'propel.project' => 'nestedset',
    'propel.database' => 'sqlite',
    'propel.database.url' => 'sqlite:/var/tmp/nestedset.db',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propel.database.user' => '[db username]',
    // 'propel.database.password' => '[db password]',

    'propel.targetPackage' => 'nestedset',

    // The unit tests need to test this stuff
    'propel.addGenericAccessors' => 'true',
    'propel.addGenericMutators' => 'true',

    // Use the new PHP 5.2 DateTime class
    'propel.useDateTimeClass' => 'true',
];
