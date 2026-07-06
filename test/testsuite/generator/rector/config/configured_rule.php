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

return RectorConfig::configure()
    ->withRules([UseQueryToWithQueryRector::class]);
