<?php

/**
 * This is a project-specific build config file. The properties in this file
 * override anything set in generator/default.php when *this* project is
 * being built.
 *
 * Because this file is included before generator/default.php's own
 * defaults are considered as a base (it's an override layer, not the base),
 * you cannot refer to any properties set therein via ${...} interpolation.
 */
return [
    'propel.project' => 'bookstore',
    'propel.database' => 'mysql',
    'propel.database.url' => 'mysql:dbname=test',
    'propel.mysql.tableType' => 'InnoDB',
    'propel.disableIdentifierQuoting' => 'true',
    'propel.schema.autoPrefix' => 'true',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propel.database.user' => '[db username]',
    // 'propel.database.password' => '[db password]',

    'propel.targetPackage' => 'bookstore',

    // We need to test behavior hooks
    'propel.behavior.test_all_hooks.class' => '../test.tools.helpers.bookstore.behavior.Testallhooksbehavior',
    'propel.behavior.do_nothing.class' => '../test.tools.helpers.bookstore.behavior.DonothingBehavior',
    'propel.behavior.add_class.class' => '../test.tools.helpers.bookstore.behavior.AddClassBehavior',
];
