<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Event;

/**
 * Dispatched from {@see \Propulsion\OM\BaseObject::postInsert()}, right
 * after a new object's row has been inserted. Not stoppable -- the insert
 * has already happened.
 */
class PostInsertEvent extends ModelLifecycleEvent
{
}
