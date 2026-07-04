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
    'propulsion.project' => 'bookstore',
    'propulsion.database' => 'mysql',
    'propulsion.database.url' => 'mysql:dbname=test',
    'propulsion.mysql.tableType' => 'InnoDB',
    'propulsion.disableIdentifierQuoting' => 'true',
    'propulsion.schema.autoPrefix' => 'true',

    // For MySQL or Oracle, you also need to specify username & password
    // 'propulsion.database.user' => '[db username]',
    // 'propulsion.database.password' => '[db password]',

    'propulsion.targetPackage' => 'bookstore',

    // We need to test behavior hooks
    'propulsion.behavior.test_all_hooks.class' => '../test.tools.helpers.bookstore.behavior.Testallhooksbehavior',
    'propulsion.behavior.do_nothing.class' => '../test.tools.helpers.bookstore.behavior.DonothingBehavior',
    'propulsion.behavior.add_class.class' => '../test.tools.helpers.bookstore.behavior.AddClassBehavior',
];
