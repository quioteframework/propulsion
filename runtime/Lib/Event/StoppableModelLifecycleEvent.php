<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for the "pre" lifecycle events (PreSaveEvent, PreInsertEvent,
 * PreUpdateEvent, PreDeleteEvent) that mirrors BaseObject's existing
 * preSave()/preInsert()/preUpdate()/preDelete() veto convention (returning
 * false from the hook aborts the operation) via PSR-14's
 * {@see StoppableEventInterface}: a listener that calls stopPropagation()
 * on one of these events vetoes the save/insert/update/delete the same way
 * an overridden hook method returning false always has.
 */
abstract class StoppableModelLifecycleEvent extends ModelLifecycleEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    /**
     * Veto the lifecycle operation this event was dispatched for. Once
     * called, the corresponding preSave()/preInsert()/preUpdate()/
     * preDelete() hook returns false, which the generated save()/delete()
     * code treats exactly like any other hook-method veto.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
