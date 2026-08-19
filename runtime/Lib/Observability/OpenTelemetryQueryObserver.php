<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;

/**
 * Opens an OpenTelemetry span per statement and closes it with the outcome --
 * the "just turn it on" observer `docs/OBSERVABILITY.md` used to only show as
 * a ~15-line example for an application to write itself.
 *
 * Depends only on `open-telemetry/api` (interfaces plus no-op fallbacks), not
 * on the SDK or an exporter -- exactly the seam the interface's own docblock
 * describes ({@see QueryObserver}). That means this class works two ways:
 *
 *  - **Config-driven**: {@see \Propulsion\Propulsion::setConfiguration()}
 *    builds and registers one automatically when `telemetry.enabled` is true,
 *    wired to a tracer from a `TracerProviderInterface` that
 *    {@see TelemetryTracerProviderFactory} builds from `open-telemetry/sdk`
 *    and `open-telemetry/exporter-otlp` -- both of which stay optional
 *    dependencies, so this class itself never references them.
 *  - **Manual**: construct one directly with any `TracerProviderInterface`
 *    (your own, or one shared with other instrumentation) for full control,
 *    exactly like the interface's docblock always showed.
 *
 * Uses the *incubating* `open-telemetry/sem-conv` DB attributes rather than
 * the stable ones: the stable `db.system.name` value set only names four
 * database systems (mysql, mariadb, postgresql, mssql), which would silently
 * drop SQLite and Oracle -- two of the five platforms this project's own test
 * matrix covers. The incubating set is the actual, current spec surface for
 * everything else; pinning to it via the package's own constants (not
 * hand-typed attribute-name strings) means a spec change shows up as a
 * `composer.json` version bump, not a silent drift.
 */
final class OpenTelemetryQueryObserver implements QueryObserver
{
    private const ATTRIBUTE_SPAN = 'otel.span';

    /**
     * PDO's `PDO::ATTR_DRIVER_NAME` to the incubating `db.system.name` value
     * naming the same system. Deliberately does not distinguish MariaDB from
     * MySQL: PDO's own driver name is `mysql` for both (see
     * `DBMySQL::isMariaDb()`'s runtime detection, which needs a live
     * connection query to tell them apart) -- getting that distinction here
     * would cost a statement per connection to answer a question the span
     * does not strictly need.
     *
     * @var array<string, string>
     */
    private const DB_SYSTEM_BY_DRIVER = [
        'mysql' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
        'pgsql' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
        'sqlite' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_SQLITE,
        'oci' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_ORACLE_DB,
        'sqlsrv' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MICROSOFT_SQL_SERVER,
        'dblib' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MICROSOFT_SQL_SERVER,
    ];

    private ?TracerInterface $tracer = null;

    /**
     * @param \Closure(): TracerInterface $tracerFactory Resolved and memoised
     *        on first use, not eagerly -- letting {@see \Propulsion\Propulsion}
     *        register this observer without paying for SDK/exporter
     *        construction (an HTTP client, a batch processor) in a process
     *        that configures telemetry but never runs a query.
     * @param bool $recordStatementText Whether `db.query.text` is attached to
     *        the span. Most Propulsion traffic is prepared statements with
     *        placeholders, so the text rarely carries a literal value -- but
     *        `exec()`/`query()` traffic can, so leave this off if that is a
     *        compliance concern for those paths. See docs/OBSERVABILITY.md.
     */
    public function __construct(
        private readonly \Closure $tracerFactory,
        private readonly bool $recordStatementText = true,
    ) {
    }

    public function queryStarted(QueryExecution $execution): void
    {
        $tracer = $this->tracer ??= ($this->tracerFactory)();

        $span = $tracer->spanBuilder($this->spanName($execution))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbIncubatingAttributes::DB_SYSTEM_NAME, $this->dbSystem($execution))
            ->startSpan();

        $execution->setAttribute(self::ATTRIBUTE_SPAN, $span);
    }

    public function queryFinished(QueryExecution $execution): void
    {
        $span = $execution->getAttribute(self::ATTRIBUTE_SPAN);
        if (!$span instanceof SpanInterface) {
            // queryStarted() threw before setAttribute() ran (QueryObservers
            // caught and logged it) -- nothing to close.
            return;
        }

        if ($this->recordStatementText) {
            $span->setAttribute(DbIncubatingAttributes::DB_QUERY_TEXT, $execution->sql);
        }

        $rowCount = $execution->getRowCount();
        if ($rowCount !== null) {
            $span->setAttribute(DbIncubatingAttributes::DB_RESPONSE_RETURNED_ROWS, $rowCount);
        }

        $error = $execution->getError();
        if ($error !== null) {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $error::class);
            $span->recordException($error);
            $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        }

        $span->end();
    }

    /**
     * The SQL's leading verb (`SELECT`, `INSERT`, ...), or the statement
     * source when the text does not start with a recognisable one -- a cheap
     * prefix check, not a SQL parser, matching the level of sophistication
     * {@see SlowQueryObserver} already uses for its log message.
     *
     * @return non-empty-string
     */
    private function spanName(QueryExecution $execution): string
    {
        if (preg_match('/^\s*([A-Za-z]+)/', $execution->sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return $execution->source !== '' ? $execution->source : 'QUERY';
    }

    private function dbSystem(QueryExecution $execution): string
    {
        $driver = $execution->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return is_string($driver) && isset(self::DB_SYSTEM_BY_DRIVER[$driver])
            ? self::DB_SYSTEM_BY_DRIVER[$driver]
            : DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_OTHER_SQL;
    }
}
