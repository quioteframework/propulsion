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
 * Dispatched from {@see \Propulsion\Query\ModelCriteria::update()}, right
 * after the bulk UPDATE has run (and its behavior hooks, if any) but before
 * the surrounding transaction is committed. Not stoppable -- the update has
 * already happened.
 */
class PostBulkUpdateEvent extends BulkModelEvent
{
    /**
     * @param array<string, mixed> $values The column-name-to-new-value map
     *        that was actually applied (post any PreBulkUpdateEvent
     *        listener mutation).
     */
    public function __construct(
        ModelCriteria $criteria,
        ?PropulsionPDO $connection,
        private readonly int $affectedRowCount,
        private readonly array $values,
    ) {
        parent::__construct($criteria, $connection);
    }

    /**
     * The number of rows update() reported as affected. When update() was
     * called with $forceIndividualSaves = true, this is the count of
     * objects saved, not a SQL affected-row count.
     */
    public function getAffectedRowCount(): int
    {
        return $this->affectedRowCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
