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
 * Dispatched from {@see \Propulsion\OM\BaseObject::preSave()}, before both
 * inserts and updates (and before the more specific PreInsertEvent/
 * PreUpdateEvent). Stoppable: a listener calling stopPropagation() vetoes
 * the save.
 */
class PreSaveEvent extends StoppableModelLifecycleEvent
{
}
