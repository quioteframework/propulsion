<?php

/**
 * Overrides for building the bookstore test fixtures via bin/propulsion
 * (invoked from the repository root, not from inside test/fixtures/bookstore/
 * the way the legacy Phing propel-gen entry point was) -- these behavior
 * class dot-paths are relative to the current working directory, so they
 * drop the leading "../" that build.php uses for the (now-removed) Phing
 * path.
 */
return [
    'propulsion.behavior.test_all_hooks.class' => 'test.tools.helpers.bookstore.behavior.Testallhooksbehavior',
    'propulsion.behavior.do_nothing.class' => 'test.tools.helpers.bookstore.behavior.DonothingBehavior',
    'propulsion.behavior.add_class.class' => 'test.tools.helpers.bookstore.behavior.AddClassBehavior',
];
