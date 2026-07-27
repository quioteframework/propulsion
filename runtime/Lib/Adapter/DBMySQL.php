<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Adapter;

/**
 * This is used in order to connect to a MySQL database.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Jon S. Stevens <jon@clearink.com> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @version    $Revision$
 */
use PDO;
use PDOStatement;
use PDOException;
use Exception;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Query\Criteria;
use Propulsion\Exception\PropulsionException;
use Propulsion\Connection\PropulsionPDO;
class DBMySQL extends DBAdapter
{
	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string to transform to upper case.
	 * @return    string  The upper case string.
	 */
	public function toUpperCase($in)
	{
		return "UPPER(" . $in . ")";
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string whose case to ignore.
	 * @return    string  The string in a case that can be ignored.
	 */
	public function ignoreCase($in)
	{
		return "UPPER(" . $in . ")";
	}

	/**
	 * Returns SQL which concatenates the second string to the first.
	 *
	 * @param     string  $s1  String to concatenate.
	 * @param     string  $s2  String to append.
	 *
	 * @return    string
	 */
	public function concatString($s1, $s2)
	{
		return "CONCAT($s1, $s2)";
	}

	/**
	 * Returns SQL which extracts a substring.
	 *
	 * @param     string   $s  String to extract from.
	 * @param     integer  $pos  Offset to start from.
	 * @param     integer  $len  Number of characters to extract.
	 *
	 * @return    string
	 */
	public function subString($s, $pos, $len)
	{
		return "SUBSTRING($s, $pos, $len)";
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return "CHAR_LENGTH($s)";
	}

	/**
	 * Locks the specified table.
	 *
	 * @param     PDO     $con  The Propulsion connection to use.
	 * @param     string  $table  The name of the table to lock.
	 *
	 * @throws    PDOException  No Statement could be created or executed.
	 */
	public function lockTable(PDO $con, $table): void
	{
		$con->exec("LOCK TABLE " . $table . " WRITE");
	}

	/**
	 * Unlocks the specified table.
	 *
	 * @param     PDO     $con  The PDO connection to use.
	 * @param     string  $table  The name of the table to unlock.
	 *
	 * @throws    PDOException  No Statement could be created or executed.
	 */
	public function unlockTable(PDO $con, $table): void
	{
		$statement = $con->exec("UNLOCK TABLES");
	}

	/**
	 * @see       DBAdapter::quoteIdentifier()
	 *
	 * @param     string  $text
	 * @return    string
	 */
	public function quoteIdentifier($text)
	{
		return '`' . $text . '`';
	}

	/**
	 * @see       DBAdapter::quoteIdentifierTable()
	 *
	 * @param     string  $table
	 * @return    string
	 */
	public function quoteIdentifierTable($table)
	{
		// e.g. 'database.table alias' should be escaped as '`database`.`table` `alias`'
		return '`' . strtr($table, array('.' => '`.`', ' ' => '` `')) . '`';
	}

	/**
	 * @see       DBAdapter::useQuoteIdentifier()
	 *
	 * @return    boolean
	 */
	public function useQuoteIdentifier()
	{
		return true;
	}

	/**
	 * @see       DBAdapter::applyLimit()
	 *
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 * @param     Criteria $criteria  Unused by this adapter.
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if ( $limit > 0 ) {
			$sql .= " LIMIT " . ($offset > 0 ? $offset . ", " : "") . $limit;
		} else if ( $offset > 0 ) {
			$sql .= " LIMIT " . $offset . ", 18446744073709551615";
		}
	}

	/**
	 * @see       DBAdapter::supportsUpsert()
	 */
	public function supportsUpsert(): bool
	{
		return true;
	}

	/**
	 * MySQL/MariaDB infer the conflict target from any unique/primary key violation,
	 * so (unlike Postgres/SQLite) $conflictColumnNames is ignored here. There is also
	 * no "do nothing on conflict" form of ON DUPLICATE KEY UPDATE, so an empty
	 * $setClause throws rather than silently producing invalid SQL.
	 *
	 * @see       DBAdapter::getUpsertSql()
	 */
	public function getUpsertSql(string $sql, array $conflictColumnNames, string $setClause): string
	{
		if ($setClause === '') {
			throw new PropulsionException("DBMySQL::getUpsertSql() needs at least one column to update: MySQL's ON DUPLICATE KEY UPDATE has no \"do nothing\" form");
		}
		return $sql . ' ON DUPLICATE KEY UPDATE ' . $setClause;
	}

	/**
	 * @see       DBAdapter::supportsBulkLoad()
	 */
	public function supportsBulkLoad(): bool
	{
		return true;
	}

	/**
	 * Bulk-loads $rows via LOAD DATA LOCAL INFILE, writing them to a temporary file first
	 * (unlike Postgres's pgsqlCopyFromArray(), there is no rows-array variant for MySQL --
	 * LOAD DATA always reads from a file). Requires the connection to have been created
	 * with PDO::MYSQL_ATTR_LOCAL_INFILE enabled (pass it in the datasource's PDO connection
	 * options -- it cannot be toggled on an already-open connection) *and* the server's
	 * `local_infile` global variable set to 1 (defaults to 0/OFF on stock MySQL 8+). Throws
	 * a clear, actionable error up front if the connection-side half of that isn't set,
	 * rather than letting it fail with MySQL's own less obvious error message.
	 *
	 * @see       DBAdapter::bulkLoad()
	 */
	public function bulkLoad(PropulsionPDO $con, string $tableName, array $columns, iterable $rows): int
	{
		if (!$con->getAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE)) {
			throw new PropulsionException(
				'DBMySQL::bulkLoad() requires the connection to be created with PDO::MYSQL_ATTR_LOCAL_INFILE '
				. "enabled (pass it in the datasource's PDO connection options) and the server's local_infile "
				. 'global variable set to 1 -- both are required, and neither can be toggled on an already-open connection.'
			);
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'propulsion_bulk_');
		if ($tmpFile === false) {
			throw new PropulsionException('DBMySQL::bulkLoad() could not create a temporary file');
		}

		$count = 0;
		try {
			$handle = fopen($tmpFile, 'wb');
			if ($handle === false) {
				throw new PropulsionException('DBMySQL::bulkLoad() could not open its temporary file for writing');
			}
			foreach ($rows as $row) {
				$fields = array();
				foreach ($row as $value) {
					$fields[] = $this->escapeLoadDataValue($value);
				}
				fwrite($handle, implode("\t", $fields) . "\n");
				$count++;
			}
			fclose($handle);

			if ($count > 0) {
				$quotedColumns = implode(',', array_map(array($this, 'quoteIdentifier'), $columns));
				$quotedTable = $this->useQuoteIdentifier() ? $this->quoteIdentifierTable($tableName) : $tableName;
				$sql = 'LOAD DATA LOCAL INFILE ' . $con->quote($tmpFile)
					. ' INTO TABLE ' . $quotedTable
					. " FIELDS TERMINATED BY '\\t'"
					. ' (' . $quotedColumns . ')';
				$con->exec($sql);
			}
		} finally {
			unlink($tmpFile);
		}

		return $count;
	}

	/**
	 * Escapes a single value for LOAD DATA's default FIELDS ESCAPED BY '\\' format:
	 * backslash, tab, newline, and carriage return all need backslash-escaping; null
	 * becomes the literal 2-character "\N" sequence, MySQL's own NULL sentinel for
	 * LOAD DATA (matching the default ESCAPED BY behavior -- no FIELDS ESCAPED BY clause
	 * is passed above, so this relies on MySQL's own default of '\\').
	 *
	 * @param     mixed $value
	 * @return    string
	 */
	private function escapeLoadDataValue($value): string
	{
		if ($value === null) {
			return '\\N';
		}
		if (is_bool($value)) {
			$value = $value ? '1' : '0';
		} elseif (!is_scalar($value)) {
			throw new PropulsionException('DBMySQL::bulkLoad() cannot serialize a non-scalar value');
		}
		return strtr((string) $value, array(
			'\\' => '\\\\',
			"\t" => '\\t',
			"\n" => '\\n',
			"\r" => '\\r',
		));
	}

	/**
	 * @see       DBAdapter::random()
	 *
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed = null): string
	{
		return 'rand('.((int) $seed).')';
	}

	/**
	 * @see       DBAdapter::bindValue()
	 *
	 * @param     PDOStatement  $stmt
	 * @param     string        $parameter
	 * @param     mixed         $value
	 * @param     ColumnMap     $cMap
	 * @param     null|integer  $position
	 *
	 * @return    boolean
	 */
	public function bindValue(PDOStatement $stmt, $parameter, $value, ColumnMap $cMap, $position = null)
	{
		$pdoType = $cMap->getPdoType();
		// FIXME - This is a temporary hack to get around apparent bugs w/ PDO+MYSQL
		// See http://pecl.php.net/bugs/bug.php?id=9919
		if ($pdoType == PDO::PARAM_BOOL) {
			if (!is_scalar($value) && $value !== null) {
				throw new PropulsionException('DBMySQL::bindValue() cannot cast a non-scalar value to int for a boolean column');
			}
			$value = (int) $value;
			$pdoType = PDO::PARAM_INT;
			return $stmt->bindValue($parameter, $value, $pdoType);
		} elseif ($cMap->isTemporal()) {
			$value = $this->formatTemporalValue($value, $cMap);
		} elseif (is_resource($value) && $cMap->isLob()) {
			// we always need to make sure that the stream is rewound, otherwise nothing will
			// get written to database.
			rewind($value);
		}

		return $stmt->bindValue($parameter, $value, $pdoType);
	}

	/**
	 * Prepare connection parameters.
	 * See: http://www.propelorm.org/ticket/1360
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	public function prepareParams($params)
	{
		$params = parent::prepareParams($params);

		if (isset($params['settings']) && is_array($params['settings'])
			&& isset($params['settings']['charset']) && is_array($params['settings']['charset'])
			&& isset($params['settings']['charset']['value']) && is_string($params['settings']['charset']['value'])
			&& isset($params['dsn']) && is_string($params['dsn'])
		) {
			if (strpos($params['dsn'], ';charset=') === false) {
				$params['dsn'] .= ';charset=' . $params['settings']['charset']['value'];
				unset($params['settings']['charset']);
			}
		}

		return $params;
	}
}
