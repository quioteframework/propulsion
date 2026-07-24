<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Event;

use Propulsion\Connection\PropulsionPDO;
use Propulsion\OM\BaseObject;

/**
 * Base class for the PSR-14 events dispatched by {@see BaseObject}'s
 * preSave()/postSave()/preInsert()/postInsert()/preUpdate()/postUpdate()/
 * preDelete()/postDelete() lifecycle hooks (see Propulsion::dispatch()).
 *
 * Carries a reference to the model object the lifecycle operation concerns
 * (so listeners can inspect or mutate it -- it's the same live instance the
 * save()/delete() call is operating on, not a copy) and the database
 * connection the operation is running on, when one is available.
 */
abstract class ModelLifecycleEvent
{
    public function __construct(
        private readonly BaseObject $object,
        private readonly ?PropulsionPDO $connection = null,
    ) {
    }

    /**
     * The model object this lifecycle event concerns. This is the actual
     * instance being saved/deleted, not a copy, so mutating it from a
     * listener is visible to the rest of the save()/delete() call.
     */
    public function getObject(): BaseObject
    {
        return $this->object;
    }

    /**
     * The database connection the save()/delete() operation is running on,
     * if one was available at the point the event was dispatched.
     */
    public function getConnection(): ?PropulsionPDO
    {
        return $this->connection;
    }
}
