<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\SQL\PgSQL;

/**
 * PostgreSQL class for building data dump SQL.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Builder\SQL\DataSQLBuilder;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\Util\DataRow;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Exception\EngineException;
class PgsqlDataSQLBuilder extends DataSQLBuilder
{

	/**
	 * The largets serial value encountered this far.
	 *
	 * @var        int
	 */
	private int $maxSeqVal = 0;

	/**
	 * Construct a new PgsqlDataSQLBuilder object.
	 *
	 * @param      Table $table
	 */
	public function __construct(Table $table)
	{
		parent::__construct($table);
	}

	/**
	 * The main method in this class, returns the SQL for INSERTing data into a row.
	 * @param      DataRow $row The row to process.
	 * @return     string
	 */
	public function buildRowSql(DataRow $row)
	{
		$sql = parent::buildRowSql($row);

		$table = $this->getTable();

		if ($table->hasAutoIncrementPrimaryKey() && $table->getIdMethod() == IDMethod::NATIVE) {
			foreach ($row->getColumnValues() as $colValue) {
				if ($colValue->getColumn()->isAutoIncrement()) {
					$value = $colValue->getValue();
					if (is_numeric($value) && (int) $value > $this->maxSeqVal) {
						$this->maxSeqVal = (int) $value;
					}
				}
			}
		}

		return $sql;
	}

	public function getTableEndSql()
	{
		$table = $this->getTable();
		$sql = "";
		if ($table->hasAutoIncrementPrimaryKey() && $table->getIdMethod() == IDMethod::NATIVE) {
			$seqname = $this->getPlatform()->getSequenceName($table);
			$sql .= "SELECT pg_catalog.setval('$seqname', ".((int)$this->maxSeqVal).");
";
		}
		return $sql;
	}

	/**
	 * Get SQL value to insert for Postgres BOOLEAN column.
	 * @param      mixed $value
	 * @return     string The representation of boolean for Postgres ('t' or 'f').
	 */
	protected function getBooleanSql($value)
	{
		return $this->parseBoolean($value) ? "'t'" : "'f'";
	}

	/**
	 *
	 * @param      mixed $blob Blob object or string containing data.
	 * @return     string
	 */
	protected function getBlobSql($blob)
	{
		// they took magic __toString() out of PHP5.0.0; this sucks
		if (is_object($blob)) {
			if (!$blob instanceof \Stringable) {
				throw new EngineException(sprintf('BLOB value object of type %s does not implement Stringable.', get_class($blob)));
			}
			$blob = (string) $blob;
		}
		if (!is_string($blob)) {
			throw new EngineException(sprintf('BLOB value must be a string or Stringable object, got %s.', get_debug_type($blob)));
		}
		return "'" . pg_escape_bytea($blob) . "'";
	}

}
