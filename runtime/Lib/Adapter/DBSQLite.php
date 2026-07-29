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
 * This is used in order to connect to a SQLite database.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
use PDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Connection\PropulsionPDO;
class DBSQLite extends DBAdapter
{

	/**
	 * For SQLite this method has no effect, since SQLite doesn't support specifying a character
	 * set (or, another way to look at it, it doesn't require a single character set per DB).
	 *
	 * @param     PDO     $con  A PDO connection instance.
	 * @param     string  $charset  The charset encoding.
	 *
	 * @throws    PropulsionException If the specified charset doesn't match sqlite_libencoding()
	 */
	public function setCharset(PDO $con, $charset): void
	{
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string to transform to upper case.
	 * @return    string  The upper case string.
	 */
	public function toUpperCase($in)
	{
		return 'UPPER(' . $in . ')';
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in  The string whose case to ignore.
	 * @return    string  The string in a case that can be ignored.
	 */
	public function ignoreCase($in)
	{
		return 'UPPER(' . $in . ')';
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
		return "($s1 || $s2)";
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
		return "substr($s, $pos, $len)";
	}

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public function strLength($s)
	{
		return "length($s)";
	}

	/**
	 * @see        DBAdapter::quoteIdentifier()
	 *
	 * @param     string  $text
	 * @return    string
	 */
	public function quoteIdentifier($text)
	{
		return '[' . $text . ']';
	}

	/**
	 * @see        DBAdapter::applyLimit()
	 *
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 */
	public function applyLimit(&$sql, $offset, $limit, $criteria = null): void
	{
		if ( $limit > 0 ) {
			$sql .= " LIMIT " . $limit . ($offset > 0 ? " OFFSET " . $offset : "");
		} elseif ( $offset > 0 ) {
			$sql .= " LIMIT -1 OFFSET " . $offset;
		}
	}

	/**
	 * SQLite (3.35+, released 2021) supports RETURNING, folding id retrieval into the
	 * INSERT statement itself instead of a separate lastInsertId() round trip. Assumed
	 * available unconditionally: every PHP version still receiving security support
	 * bundles a far newer SQLite than 3.35 (no runtime version probe exists anywhere in
	 * this codebase today -- revisit this assumption if it ever needs to change for an
	 * unusually old libsqlite3).
	 *
	 * @see       DBAdapter::supportsInsertReturning()
	 */
	public function supportsInsertReturning(?PropulsionPDO $con = null): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getInsertReturningSql()
	 */
	public function getInsertReturningSql(string $sql, string $idColumnName): string
	{
		return $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * @see       DBAdapter::getEmptyInsertSql()
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		$sql = 'INSERT INTO ' . $tableName . ' DEFAULT VALUES';
		return $idColumnName === null ? $sql : $sql . ' RETURNING ' . $idColumnName;
	}

	/**
	 * @see       DBAdapter::extractInsertedId()
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		return $stmt->fetchColumn();
	}

	/**
	 * SQLite (3.24+) supports the same "INSERT ... ON CONFLICT (...) DO UPDATE SET ..."
	 * syntax as Postgres.
	 *
	 * @see       DBAdapter::supportsUpsert()
	 */
	public function supportsUpsert(): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getUpsertSql()
	 */
	public function getUpsertSql(string $sql, array $conflictColumnNames, string $setClause): string
	{
		if (empty($conflictColumnNames)) {
			throw new PropulsionException('DBSQLite::getUpsertSql() needs at least one conflict-target column');
		}
		$sql .= ' ON CONFLICT (' . implode(', ', $conflictColumnNames) . ')';
		$sql .= $setClause === '' ? ' DO NOTHING' : ' DO UPDATE SET ' . $setClause;
		return $sql;
	}

	/**
	 * SQLite (3.35+, same release RETURNING itself was added in) also supports it
	 * on UPDATE/DELETE, for affected-row hydration without a separate re-SELECT.
	 *
	 * @see       DBAdapter::supportsRowReturning()
	 */
	public function supportsRowReturning(?PropulsionPDO $con = null): bool
	{
		return true;
	}

	/**
	 * @see       DBAdapter::getUpdateReturningSql()
	 */
	public function getUpdateReturningSql(string $sql, array $columnNames): string
	{
		return $sql . ' RETURNING ' . implode(', ', $columnNames);
	}

	/**
	 * @see       DBAdapter::getDeleteReturningSql()
	 */
	public function getDeleteReturningSql(string $sql, array $columnNames): string
	{
		return $sql . ' RETURNING ' . implode(', ', $columnNames);
	}

	/**
	 * @see       DBAdapter::supportsExplain()
	 */
	public function supportsExplain(): bool
	{
		return true;
	}

	/**
	 * SQLite's `EXPLAIN QUERY PLAN` (unlike plain `EXPLAIN`, which dumps raw VDBE
	 * bytecode, not a human-readable plan) never actually executes the query --
	 * $analyze is accepted for interface parity with Postgres/MySQL but has no
	 * SQLite equivalent to apply it to, so it's ignored.
	 *
	 * @see       DBAdapter::getExplainSql()
	 */
	public function getExplainSql(string $sql, bool $analyze = false): string
	{
		return 'EXPLAIN QUERY PLAN ' . $sql;
	}

	/**
	 * SQLite has no row-level locking (the whole database file is locked at the
	 * connection/transaction level), so SELECT ... FOR UPDATE has no equivalent here.
	 *
	 * @see       DBAdapter::supportsForUpdate()
	 */
	public function supportsForUpdate(): bool
	{
		return false;
	}

	/**
	 * @see       DBAdapter::supportsForShare()
	 */
	public function supportsForShare(): bool
	{
		return false;
	}

	/**
	 * @param     string  $seed
	 * @return    string
	 */
	public function random($seed = NULL): string
	{
		return 'random()';
	}
}
