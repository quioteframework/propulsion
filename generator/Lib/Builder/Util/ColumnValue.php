<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\Util;

use Propulsion\Generator\Model\Column;

/**
 * A single column's value within a DataRow.
 *
 */
class ColumnValue
{
    private Column $col;
    private mixed $val;

    public function __construct(Column $col, mixed $val)
    {
        $this->col = $col;
        $this->val = $val;
    }

    public function getColumn(): Column
    {
        return $this->col;
    }

    public function getValue(): mixed
    {
        return $this->val;
    }
}
