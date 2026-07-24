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
 * Dispatched from {@see \Propulsion\OM\BaseObject::preInsert()}, immediately
 * before a new object's row is inserted. Stoppable: a listener calling
 * stopPropagation() vetoes the insert.
 */
class PreInsertEvent extends StoppableModelLifecycleEvent
{
}
