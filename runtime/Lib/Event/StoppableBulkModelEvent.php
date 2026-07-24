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
 * Base class for the "pre" bulk lifecycle events (PreBulkUpdateEvent,
 * PreBulkDeleteEvent). A listener that calls stopPropagation() vetoes the
 * whole bulk update/delete.
 *
 * Unlike {@see StoppableModelLifecycleEvent}, which mirrors an existing
 * boolean-veto convention already present in BaseObject's preSave() etc.,
 * ModelCriteria::update()/delete()/deleteAll() had no such convention to
 * mirror: their basePreUpdate()/basePreDelete() hooks use a "truthy return
 * value replaces the affected-row count and skips the real SQL" convention
 * instead (used by e.g. SoftDeleteBehavior to redirect a delete() into an
 * UPDATE), where a legitimate result can itself be 0 (falsy) -- so there is
 * no return value that unambiguously means "veto" without also being
 * indistinguishable from "did not veto, 0 rows affected". Rather than
 * overload that convention, this event is dispatched by
 * ModelCriteria::update()/delete()/deleteAll() directly, before any
 * transaction is opened or hook is invoked, and a stopped event makes those
 * methods return 0 immediately -- so this veto path is independent of, and
 * does not alter, the existing basePreUpdate()/basePreDelete() semantics
 * that behaviors rely on.
 */
abstract class StoppableBulkModelEvent extends BulkModelEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    /**
     * Veto the bulk update/delete this event was dispatched for.
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
