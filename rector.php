<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
declare(strict_types=1);

use Propulsion\Generator\Rector\UseQueryToWithQueryRector;
use Rector\Config\RectorConfig;

// This is Propulsion's own config for migrating its internal test suite off
// useQuery()/endUse() -- see README.md's "Migrating useQuery()/endUse() to
// withQuery() with Rector" section for the config a downstream consumer of
// this package should write, pointed at their own codebase instead.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/test/testsuite/runtime/formatter/PropulsionObjectFormatterWithTest.php',
        __DIR__ . '/test/testsuite/runtime/query/SubQueryTest.php',
        __DIR__ . '/test/testsuite/generator/behavior/i18n/I18nBehaviorQueryBuilderModifierTest.php',
        __DIR__ . '/test/testsuite/generator/builder/NamespaceTest.php',
        __DIR__ . '/test/testsuite/generator/builder/om/QueryBuilderTest.php',
    ])
    ->withRules([UseQueryToWithQueryRector::class]);
