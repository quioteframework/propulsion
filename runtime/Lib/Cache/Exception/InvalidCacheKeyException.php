<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Cache\Exception;

use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * Thrown by Propulsion's first-party PSR-16 drivers when handed a key that
 * PSR-16 does not permit (see {@see \Propulsion\Cache\Driver\AbstractCacheDriver::validateKey()}).
 *
 * PSR-16 requires that such a failure be signalled by an exception
 * implementing `Psr\SimpleCache\InvalidArgumentException`; extending
 * `\InvalidArgumentException` as well keeps it catchable by callers that only
 * know about SPL. It deliberately does *not* extend `PropulsionException` --
 * the PSR-16 interface is the contract being violated here, not Propulsion's.
 */
class InvalidCacheKeyException extends \InvalidArgumentException implements Psr16InvalidArgumentException
{
}
