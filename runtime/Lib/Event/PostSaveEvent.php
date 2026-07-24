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
 * Dispatched from {@see \Propulsion\OM\BaseObject::postSave()}, after both
 * inserts and updates (and after the more specific PostInsertEvent/
 * PostUpdateEvent). Not stoppable -- the operation has already happened.
 */
class PostSaveEvent extends ModelLifecycleEvent
{
}
