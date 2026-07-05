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
class PropulsionStatementFormatter extends PropulsionFormatter
{
	public function format(PDOStatement $stmt)
	{
		return $stmt;
	}

	public function formatOne(PDOStatement $stmt)
	{
		if ($stmt->rowCount() == 0) {
			return null;
		} else {
			return $stmt;
		}
	}

	public function formatRecord($record = null)
	{
		throw new PropulsionException('The Statement formatter cannot transform a record into a statement');
	}

	public function isObjectFormatter()
	{
		return false;
	}

}