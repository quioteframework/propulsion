<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\Util;

use Propulsion\Generator\Model\Table;

/**
 * A single row of data to insert, as a Table plus its column values.
 *
 * Used by DataSQLBuilder::buildRowSql() -- see DataDumpManager/DataSqlManager
 * for where rows are actually produced/consumed.
 *
 */
class DataRow
{
    private Table $table;

    /** @var ColumnValue[] */
    private array $columnValues;

    /**
     * @param ColumnValue[] $columnValues
     */
    public function __construct(Table $table, array $columnValues)
    {
        $this->table = $table;
        $this->columnValues = $columnValues;
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * @return ColumnValue[]
     */
    public function getColumnValues(): array
    {
        return $this->columnValues;
    }
}
