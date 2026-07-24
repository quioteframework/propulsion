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
use Propulsion\Query\ModelCriteria;

/**
 * Base class for the PSR-14 events dispatched around the bulk
 * update/delete path: {@see \Propulsion\Query\ModelCriteria::update()},
 * ::delete(), and ::deleteAll() (see Propulsion::dispatch()). Unlike
 * {@see ModelLifecycleEvent}, which concerns a single BaseObject instance
 * loaded into memory, these operate on a whole result set via a single bulk
 * SQL UPDATE/DELETE, so they carry the ModelCriteria/Query describing that
 * result set instead of a model instance.
 */
abstract class BulkModelEvent
{
    public function __construct(
        private readonly ModelCriteria $criteria,
        private readonly ?PropulsionPDO $connection = null,
    ) {
    }

    /**
     * The ModelCriteria (or generated *Query, which extends it) describing
     * the rows the bulk update/delete concerns.
     */
    public function getCriteria(): ModelCriteria
    {
        return $this->criteria;
    }

    /**
     * The database connection the update()/delete()/deleteAll() call is
     * running on, if one was available at the point the event was
     * dispatched.
     */
    public function getConnection(): ?PropulsionPDO
    {
        return $this->connection;
    }
}
