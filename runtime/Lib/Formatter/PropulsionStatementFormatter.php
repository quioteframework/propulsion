<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Formatter;

use PDOStatement;
/**
 * statement formatter for Propulsion query
 * format() returns a PDO statement
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */
use Propulsion\Exception\PropulsionException;
use Propulsion\OM\BaseObject;
class PropulsionStatementFormatter extends PropulsionFormatter
{
	public function format(PDOStatement $stmt): PDOStatement
	{
		return $stmt;
	}

	/**
	 * PDOStatement::rowCount() is, per its own PHP manual entry, "not
	 * guaranteed... for SELECT statements" -- and in practice, checking it
	 * against 0 to detect "no rows" is actively wrong on at least one driver
	 * this project supports: pdo_oci (Oracle) always reports 0 for a SELECT
	 * regardless of how many rows it actually returned (unlike e.g.
	 * pdo_dblib/MSSQL, which at least reports an unambiguous -1 sentinel
	 * instead of colliding with the real "empty" value). Peeking with an
	 * actual fetch() is the only universally reliable way to tell -- and
	 * since that consumes the first row, re-execute()ing the same statement
	 * (a standard, portable way to restart any PDO statement's cursor from
	 * scratch, prepared or query()'d) afterwards, rather than trying to
	 * "push the row back", gives the caller every row from the beginning,
	 * same as if this method had never touched the cursor at all.
	 */
	public function formatOne(PDOStatement $stmt): ?PDOStatement
	{
		if ($stmt->fetch() === false) {
			return null;
		}
		$stmt->execute();
		return $stmt;
	}

	public function formatRecord(?BaseObject $record = null): mixed
	{
		throw new PropulsionException('The Statement formatter cannot transform a record into a statement');
	}

	public function isObjectFormatter(): bool
	{
		return false;
	}

}