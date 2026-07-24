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
 * Dispatched from {@see \Propulsion\OM\BaseObject::postDelete()}, right
 * after an object's row has been deleted. Not stoppable -- the delete has
 * already happened.
 */
class PostDeleteEvent extends ModelLifecycleEvent
{
}
