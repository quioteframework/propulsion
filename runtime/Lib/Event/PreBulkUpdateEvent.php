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
 * Dispatched from {@see \Propulsion\Query\ModelCriteria::update()}, before
 * the transaction for the bulk update is opened. Stoppable: a listener
 * calling stopPropagation() vetoes the whole bulk update, causing update()
 * to return 0 without touching the database.
 *
 * Carries the column-name-to-new-value map the caller passed to update().
 * A listener can call setValues() to replace it before the update proceeds
 * (e.g. to add/remove a column, or normalize a value) -- the mutated array
 * is what actually gets applied to the matched rows.
 */
class PreBulkUpdateEvent extends StoppableBulkModelEvent
{
    /**
     * @var array<string, mixed>
     */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        ModelCriteria $criteria,
        array $values,
        ?PropulsionPDO $connection,
        private readonly bool $forceIndividualSaves = false,
    ) {
        parent::__construct($criteria, $connection);
        $this->values = $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Replace the column-name-to-new-value map that will be applied to the
     * matched rows.
     *
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): void
    {
        $this->values = $values;
    }

    /**
     * Whether update() will apply the change via a series of individual
     * save() calls (true, triggering per-object lifecycle events too) or a
     * single bulk UPDATE statement (false, the default).
     */
    public function isForceIndividualSaves(): bool
    {
        return $this->forceIndividualSaves;
    }
}
