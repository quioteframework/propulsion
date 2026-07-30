<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\SQL;

/**
 * Baseclass for SQL data dump SQL building classes.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Builder\DataModelBuilder;
use Propulsion\Generator\Builder\Util\DataRow;
use Propulsion\Generator\Builder\Util\ColumnValue;
use Propulsion\Generator\Exception\EngineException;
abstract class DataSQLBuilder extends DataModelBuilder
{

	/**
	 * strtotime() returns int|false on a malformed date string -- a real data-dump
	 * value that fails to parse is a genuine error worth surfacing, not something to
	 * silently coerce into "now" (the false-to-int-cast behavior date() itself has).
	 */
	private function requireTimestamp(string $value): int
	{
		$timestamp = strtotime($value);
		if ($timestamp === false) {
			throw new EngineException("Unable to parse date/time value: $value");
		}
		return $timestamp;
	}

	/**
	 * Perform any reset between runs of this builder.
	 *
	 * This can be used, for example, to clear any stored start/end SQL.
	 */
	public static function reset(): void
	{
		// does nothing by default
	}

	/**
	 * Gets any SQL to place at the start of all the row inserts.
	 *
	 * @return     string
	 */
	public static function getDatabaseStartSql()
	{
		return '';
	}

	/**
	 * Gets any SQL to place at the end of all the row inserts.
	 *
	 * @return     string
	 */
	public static function getDatabaseEndSql()
	{
		return '';
	}

	/**
	 * Gets any SQL to place before row inserts for a new table.
	 *
	 * @return     string
	 */
	public function getTableStartSql()
	{
		return '';
	}

	/**
	 * Gets any SQL to place at the end of row inserts for a table.
	 *
	 * @return     string
	 */
	public function getTableEndSql()
	{
		return '';
	}

	/**
	 * The main method in this class, returns the SQL for INSERTing data into a row.
	 * @param      DataRow $row The row to process.
	 * @return     string
	 */
	public function buildRowSql(DataRow $row)
	{
		$sql = "";
		$platform = $this->getPlatform();
		$table = $this->getTable();

		$sql .= "INSERT INTO ".$this->quoteIdentifier($this->getTable()->getName() ?? '(unnamed)')." (";

		// add column names to SQL
		$colNames = array();
		foreach ($row->getColumnValues() as $colValue) {
			$colNames[] = $this->quoteIdentifier($colValue->getColumn()->getName() ?? '(unnamed)');
		}

		$sql .= implode(',', $colNames);

		$sql .= ") VALUES (";

		$colVals = array();
		foreach ($row->getColumnValues() as $colValue) {
			$colSql = $this->getColumnValueSql($colValue);
			if (!is_scalar($colSql)) {
				throw new EngineException(sprintf(
					'Column value SQL for column "%s" must be a scalar, got %s.',
					$colValue->getColumn()->getName() ?? '(unnamed)',
					get_debug_type($colSql)
				));
			}
			$colVals[] = (string) $colSql;
		}

		$sql .= implode(',', $colVals);
		$sql .= ");
";

		return $sql;
	}

	/**
	 * Gets the propertly escaped (and quoted) value for a column.
	 * @param      ColumnValue $colValue
	 * @return     mixed The proper value to be added to the string.
	 */
	protected function getColumnValueSql(ColumnValue $colValue)
	{
		$column = $colValue->getColumn();
		$method = 'get' . $column->getPhpNative() . 'Sql';
		return $this->$method($colValue->getValue());
	}

	/**
	 * Gets a representation of a binary value suitable for use in a SQL statement.
	 * Default behavior is true = 1, false = 0.
	 * @param      boolean $value
	 * @return     int|string
	 */
	protected function getBooleanSql($value)
	{
		return (int) $value;
	}

	/**
	 * Gets a representation of a BLOB/LONGVARBINARY value suitable for use in a SQL statement.
	 * @param      mixed $blob Blob object or string data.
	 * @return     string
	 */
	protected function getBlobSql($blob)
	{
		// they took magic __toString() out of PHP5.0.0; this sucks
		if ($blob instanceof \Stringable) {
			return $this->getPlatform()->quote($blob->__toString());
		} else {
			if (!is_string($blob)) {
				throw new EngineException(sprintf('BLOB value must be a string or Stringable object, got %s.', get_debug_type($blob)));
			}
			return $this->getPlatform()->quote($blob);
		}
	}

	/**
	 * Gets a representation of a CLOB/LONGVARCHAR value suitable for use in a SQL statement.
	 * @param      mixed $clob Clob object or string data.
	 * @return     string
	 */
	protected function getClobSql($clob)
	{
		// they took magic __toString() out of PHP5.0.0; this sucks
		if ($clob instanceof \Stringable) {
			return $this->getPlatform()->quote($clob->__toString());
		} else {
			if (!is_string($clob)) {
				throw new EngineException(sprintf('CLOB value must be a string or Stringable object, got %s.', get_debug_type($clob)));
			}
			return $this->getPlatform()->quote($clob);
		}
	}

	/**
	 * Gets a representation of a date value suitable for use in a SQL statement.
	 * @param      string $value
	 * @return     string
	 */
	protected function getDateSql($value)
	{
		return "'" . date('Y-m-d', $this->requireTimestamp($value)) . "'";
	}

	/**
	 * Gets a representation of a decimal value suitable for use in a SQL statement.
	 * @param      double $value
	 * @return     float
	 */
	protected function getDecimalSql($value)
	{
		return (float) $value;
	}

	/**
	 * Gets a representation of a double value suitable for use in a SQL statement.
	 * @param      double $value
	 * @return     double
	 */
	protected function getDoubleSql($value)
	{
		return (float) $value;
	}

	/**
	 * Gets a representation of a float value suitable for use in a SQL statement.
	 * @param      float $value
	 * @return     float
	 */
	protected function getFloatSql($value)
	{
		return (float) $value;
	}

	/**
	 * Gets a representation of an integer value suitable for use in a SQL statement.
	 * @param      int $value
	 * @return     int
	 */
	protected function getIntSql($value)
	{
		return (int) $value;
	}

	/**
	 * Gets a representation of a NULL value suitable for use in a SQL statement.
	 * @return     string
	 */
	protected function getNullSql()
	{
		return 'NULL';
	}

	/**
	 * Gets a representation of a string value suitable for use in a SQL statement.
	 * @param      string $value
	 * @return     string
	 */
	protected function getStringSql($value)
	{
		return $this->getPlatform()->quote($value);
	}

	/**
	 * Gets a representation of a time value suitable for use in a SQL statement.
	 * @param      string $value
	 * @return     string
	 */
	protected function getTimeSql($value)
	{
		return "'" . date('H:i:s', $this->requireTimestamp($value)) . "'";
	}

	/**
	 * Gets a representation of a timestamp value suitable for use in a SQL statement.
	 * @param      string $value
	 * @return     string
	 */
	function getTimestampSql($value)
	{
		return "'" . date('Y-m-d H:i:s', $this->requireTimestamp($value)) . "'";
	}

}
