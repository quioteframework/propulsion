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
 * Dispatched from {@see \Propulsion\OM\BaseObject::postUpdate()}, right
 * after an existing object's row has been updated. Not stoppable -- the
 * update has already happened.
 */
class PostUpdateEvent extends ModelLifecycleEvent
{
}
