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
 * Dispatched from {@see \Propulsion\Query\ModelCriteria::delete()} and
 * ::deleteAll(), right after the bulk DELETE has run (and its behavior
 * hooks, e.g. SoftDeleteBehavior's, if any) but before the surrounding
 * transaction is committed. Not stoppable -- the delete has already
 * happened.
 */
class PostBulkDeleteEvent extends BulkModelEvent
{
    public function __construct(
        ModelCriteria $criteria,
        ?PropulsionPDO $connection,
        private readonly int $affectedRowCount,
    ) {
        parent::__construct($criteria, $connection);
    }

    /**
     * The number of rows the bulk delete reported as affected. Note that
     * when a behavior (e.g. SoftDeleteBehavior) redirects the delete into
     * something else (an UPDATE), this is that operation's row count, not
     * necessarily rows physically removed from the table.
     */
    public function getAffectedRowCount(): int
    {
        return $this->affectedRowCount;
    }
}
