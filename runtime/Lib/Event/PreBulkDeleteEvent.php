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
 * Dispatched from {@see \Propulsion\Query\ModelCriteria::delete()} and
 * ::deleteAll(), before the transaction for the bulk delete is opened.
 * Stoppable: a listener calling stopPropagation() vetoes the whole bulk
 * delete, causing delete()/deleteAll() to return 0 without touching the
 * database.
 */
class PreBulkDeleteEvent extends StoppableBulkModelEvent
{
}
