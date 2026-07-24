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
 * Dispatched from {@see \Propulsion\OM\BaseObject::preUpdate()}, immediately
 * before an existing object's row is updated. Stoppable: a listener calling
 * stopPropagation() vetoes the update.
 */
class PreUpdateEvent extends StoppableModelLifecycleEvent
{
}
