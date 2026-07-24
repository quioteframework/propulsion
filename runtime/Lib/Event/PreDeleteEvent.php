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
 * Dispatched from {@see \Propulsion\OM\BaseObject::preDelete()}, immediately
 * before an object's row is deleted. Stoppable: a listener calling
 * stopPropagation() vetoes the delete.
 */
class PreDeleteEvent extends StoppableModelLifecycleEvent
{
}
