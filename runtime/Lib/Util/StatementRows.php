<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Util;

use PDO;
use PDOStatement;

/**
 * Adapts a `PDOStatement` to a plain iterable of numerically-indexed rows.
 *
 * Every formatter used to own its own `while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false)`
 * loop. The global query result cache stores *rows* rather than formatted
 * results, so those same loops now have to be drivable from an array that came
 * back from the cache instead of from a live statement -- and duplicating each
 * loop body would be an invitation for the two copies to drift apart. Routing
 * both sources through one `iterable` keeps a single per-row body in each
 * formatter.
 *
 * {@see iterate()} is a generator on purpose. The alternative -- expressing
 * `format()` as `formatFromRows($stmt->fetchAll(PDO::FETCH_NUM))` -- would be
 * behaviourally identical but would materialise the whole result set as an
 * array *in addition to* the formatted collection for every uncached query in
 * the codebase, which is a real memory regression on wide result sets. The
 * generator preserves the streaming profile on the uncached path and only the
 * cached path pays for a materialised array (which it must, since it is about
 * to store it).
 */
final class StatementRows
{
    /**
     * Stream a statement's rows, then close its cursor.
     *
     * Rows that are not lists are skipped, preserving the guard the individual
     * formatter loops used to carry.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    public static function iterate(PDOStatement $stmt): \Generator
    {
        // Closing in a finally rather than after the loop keeps the
        // FreeTDS/pdo_dblib "results pending" hazard handled in exactly one
        // place *and* handles the consumer that stops early. A plain
        // post-loop close only runs when the generator is driven to
        // exhaustion; a consumer that `return`s or `break`s out mid-iteration
        // -- ModelCriteria::countFromRows() returns from inside its foreach as
        // soon as it has the scalar -- leaves the generator suspended forever,
        // so that statement's result set would never be released. PHP runs a
        // suspended generator's finally blocks when it is destroyed, so this
        // covers both paths.
        try {
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if (!is_array($row) || !array_is_list($row)) {
                    continue;
                }
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Materialise a statement's rows -- what the shared cache stores.
     *
     * @return list<array<int, mixed>>
     */
    public static function all(PDOStatement $stmt): array
    {
        return iterator_to_array(self::iterate($stmt), false);
    }
}
