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
 * DBAdapter</code> defines the interface for a Propulsion database adapter.
 *
 * <p>Support for new databases is added by subclassing
 * <code>DBAdapter</code> and implementing its abstract interface, and by
 * registering the new database adapter and corresponding Propulsion
 * driver in the private adapters map (array) in this class.</p>
 *
 * <p>The Propulsion database adapters exist to present a uniform
 * interface to database access across all available databases.  Once
 * the necessary adapters have been written and configured,
 * transparent swapping of databases is theoretically supported with
 * <i>zero code change</i> and minimal configuration file
 * modifications.</p>
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Jon S. Stevens <jon@latchkey.com> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @version    $Revision$
 */
use Propulsion\Exception\PropulsionException;
use PDO;
use Propulsion\Map\ColumnMap;
use Propulsion\Util\PropulsionDateTime;
use Propulsion\Util\PropulsionColumnTypes;
use Propulsion\Query\Criteria;
use Propulsion\Map\DatabaseMap;
use Propulsion\Connection\PropulsionPDO;

abstract class DBAdapter
{
	const ID_METHOD_NONE = 0;
	const ID_METHOD_AUTOINCREMENT = 1;
	const ID_METHOD_SEQUENCE = 2;

	/**
	 * Propulsion driver to Propulsion adapter map.
	 * @var array<string,string>
	 */
	private static $adapters = array(
		'mysql'  => 'Propulsion\Adapter\DBMySQL',
		'mysqli' => 'Propulsion\Adapter\DBMySQLi',
		'mssql'  => 'Propulsion\Adapter\DBMSSQL',
		'sqlsrv' => 'Propulsion\Adapter\DBSQLSRV',
		'oracle' => 'Propulsion\Adapter\DBOracle',
		'oci'    => 'Propulsion\Adapter\DBOracle',
		'pgsql'  => 'Propulsion\Adapter\DBPostgres',
		'sqlite' => 'Propulsion\Adapter\DBSQLite',
		''       => 'Propulsion\Adapter\DBNone',
	);

	/**
	 * Creates a new instance of the database adapter associated
	 * with the specified Propulsion driver.
	 *
	 * @param     string  $driver The name of the Propulsion driver to create a new adapter instance
	 *                            for or a shorter form adapter key.
	 *
	 * @throws    PropulsionException  If the adapter could not be instantiated.
	 * @return    DBAdapter        An instance of a Propulsion database adapter.
	 */
	public static function factory($driver) {
		$adapterClass = isset(self::$adapters[$driver]) ? self::$adapters[$driver] : null;
		if ($adapterClass !== null) {
			$a = new $adapterClass();
			if (!$a instanceof DBAdapter) {
				throw new PropulsionException("Configured adapter class \"$adapterClass\" is not a " . DBAdapter::class);
			}
			return $a;
		} else {
			throw new PropulsionException("Unsupported Propulsion driver: " . $driver . ": Check your configuration file");
		}
	}

	/**
	 * Prepare connection parameters.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public function prepareParams($settings)
	{
		return $settings;
	}

	/**
	 * This method is called after a connection was created to run necessary
	 * post-initialization queries or code.
	 *
	 * If a charset was specified, this will be set before any other queries
	 * are executed.
	 *
	 * This base method runs queries specified using the "query" setting.
	 *
	 * @see       setCharset()
	 *
	 * @param     PDO    $con  A PDO connection instance.
	 * @param     array<string,mixed>  $settings  An array of settings.
	 */
	public function initConnection(PDO $con, array $settings): void
	{
		if (isset($settings['charset']) && is_array($settings['charset'])
			&& isset($settings['charset']['value']) && is_string($settings['charset']['value'])
		) {
			$this->setCharset($con, $settings['charset']['value']);
		}
		if (isset($settings['queries']) && is_array($settings['queries'])) {
			foreach ($settings['queries'] as $queries) {
				foreach ((array)$queries as $query) {
					if (!is_string($query)) {
						continue;
					}
					$con->exec($query);
				}
			}
		}
	}

	/**
	 * Sets the character encoding using SQL standard SET NAMES statement.
	 *
	 * This method is invoked from the default initConnection() method and must
	 * be overridden for an RDMBS which does _not_ support this SQL standard.
	 *
	 * @see       initConnection()
	 *
	 * @param     PDO     $con  A $PDO PDO connection instance.
	 * @param     string  $charset  The $string charset encoding.
	 */
	public function setCharset(PDO $con, $charset): void
	{
		$con->exec("SET NAMES '" . $charset . "'");
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in The string to transform to upper case.
	 * @return    string  The upper case string.
	 */
	public abstract function toUpperCase($in);

	/**
	 * Returns the character used to indicate the beginning and end of
	 * a piece of text used in a SQL statement (generally a single
	 * quote).
	 *
	 * @return    string  The text delimeter.
	 */
	public function getStringDelimiter()
	{
		return '\'';
	}

	/**
	 * This method is used to ignore case.
	 *
	 * @param     string  $in The string whose case to ignore.
	 * @return    string  The string in a case that can be ignored.
	 */
	public abstract function ignoreCase($in);

	/**
	 * This method is used to ignore case in an ORDER BY clause.
	 * Usually it is the same as ignoreCase, but some databases
	 * (Interbase for example) does not use the same SQL in ORDER BY
	 * and other clauses.
	 *
	 * @param     string  $in  The string whose case to ignore.
	 * @return    string  The string in a case that can be ignored.
	 */
	public function ignoreCaseInOrderBy($in)
	{
		return $this->ignoreCase($in);
	}

	/**
	 * Returns SQL which concatenates the second string to the first.
	 *
	 * @param     string  $s1  String to concatenate.
	 * @param     string  $s2  String to append.
	 *
	 * @return    string
	 */
	public abstract function concatString($s1, $s2);

	/**
	 * Returns SQL which extracts a substring.
	 *
	 * @param     string   $s  String to extract from.
	 * @param     integer  $pos  Offset to start from.
	 * @param     integer  $len  Number of characters to extract.
	 *
	 * @return    string
	 */
	public abstract function subString($s, $pos, $len);

	/**
	 * Returns SQL which calculates the length (in chars) of a string.
	 *
	 * @param     string  $s  String to calculate length of.
	 * @return    string
	 */
	public abstract function strLength($s);

	/**
	 * Quotes database objec identifiers (table names, col names, sequences, etc.).
	 * @param     string  $text  The identifier to quote.
	 * @return    string  The quoted identifier.
	 */
	public function quoteIdentifier($text)
	{
		return '"' . $text . '"';
	}

	/**
	 * Quotes a database table which could have space seperating it from an alias, both should be identified seperately
	 * This doesn't take care of dots which separate schema names from table names. Adapters for RDBMs which support
	 * schemas have to implement that in the platform-specific way.
	 *
	 * @param     string  $table  The table name to quo
	 * @return    string  The quoted table name
	 **/
	public function quoteIdentifierTable($table) {
		return implode(" ", array_map(array($this, "quoteIdentifier"), explode(" ", $table) ) );
	}

	/**
	 * Returns the native ID method for this RDBMS.
	 *
	 * @return    integer  One of DBAdapter:ID_METHOD_SEQUENCE, DBAdapter::ID_METHOD_AUTOINCREMENT.
	 */
	protected function getIdMethod()
	{
		return DBAdapter::ID_METHOD_AUTOINCREMENT;
	}

	/**
	 * Whether this adapter uses an ID generation system that requires getting ID _before_ performing INSERT.
	 *
	 * @return    boolean
	 */
	public function isGetIdBeforeInsert()
	{
		return ($this->getIdMethod() === DBAdapter::ID_METHOD_SEQUENCE);
	}

	/**
	 * Whether this adapter uses an ID generation system that requires getting ID _before_ performing INSERT.
	 *
	 * @return    boolean
	 */
	public function isGetIdAfterInsert()
	{
		return ($this->getIdMethod() === DBAdapter::ID_METHOD_AUTOINCREMENT);
	}

	/**
	 * Gets the generated ID (either last ID for autoincrement or next sequence ID).

	 * @param     PDO     $con
	 * @param     string  $name
	 *
	 * @return    mixed
	 */
	public function getId(PDO $con, $name = null)
	{
		return $con->lastInsertId($name);
	}

	/**
	 * Whether this platform can return the generated primary key value directly from
	 * the INSERT statement itself (e.g. MSSQL's OUTPUT clause), instead of getId()'s
	 * separate before/after round trip (a sequence query before INSERT, or
	 * lastInsertId() after it). False by default.
	 *
	 * @return    boolean
	 */
	public function supportsInsertReturning(): bool
	{
		return false;
	}

	/**
	 * Rewrites a plain "INSERT INTO table (cols) VALUES (...)" statement so it also
	 * returns the generated value of $idColumnName. Only called when
	 * supportsInsertReturning() is true.
	 *
	 * @param     string $sql
	 * @param     string $idColumnName The unqualified (and already-quoted-if-necessary) id column name.
	 *
	 * @return    string
	 */
	public function getInsertReturningSql(string $sql, string $idColumnName): string
	{
		throw new PropulsionException(static::class . ' does not support folding id retrieval into INSERT');
	}

	/**
	 * Builds the "UPDATE ..." clause's target-table portion when the query uses a
	 * table alias -- "table alias" (e.g. "book b") for every platform except MSSQL,
	 * whose UPDATE statement can only name the alias itself here (the real table
	 * name is introduced separately via getUpdateFromClauseSql()'s FROM clause).
	 *
	 * @param     string $tableName Already-quoted-if-necessary table name.
	 * @param     string $alias
	 *
	 * @return    string
	 */
	public function getUpdateTargetSql(string $tableName, string $alias): string
	{
		return "$tableName $alias";
	}

	/**
	 * A clause to splice in right after an aliased UPDATE's SET clause (and before
	 * its WHERE clause). Empty for every platform except MSSQL, whose UPDATE
	 * statement needs an explicit "FROM table AS alias" to declare the alias
	 * getUpdateTargetSql() only named -- "UPDATE alias SET ... FROM table AS alias
	 * WHERE ...", since plain "UPDATE table alias SET ..." (fine everywhere else)
	 * is a syntax error in T-SQL.
	 *
	 * @param     string $tableName Already-quoted-if-necessary table name.
	 * @param     string $alias
	 *
	 * @return    string
	 */
	public function getUpdateFromClauseSql(string $tableName, string $alias): string
	{
		return '';
	}

	/**
	 * Builds a complete INSERT statement for a row with no columns to set at all
	 * (every column either has no value or -- for an auto-increment primary key on
	 * a platform where supportsInsertNullPk() is false -- was stripped out of the
	 * Criteria specifically because an explicit NULL isn't valid there). This is
	 * standard SQL's "INSERT INTO table DEFAULT VALUES" -- overridden only by
	 * MySQL, which doesn't support that syntax.
	 *
	 * @param     string $tableName Already-quoted-if-necessary table name.
	 * @param     ?string $idColumnName Already-quoted-if-necessary id column name to
	 *            fold into the statement via getInsertReturningSql()-equivalent
	 *            syntax, or null if the caller isn't using insert-returning here.
	 *
	 * @return    string
	 */
	public function getEmptyInsertSql(string $tableName, ?string $idColumnName): string
	{
		if ($idColumnName !== null) {
			throw new PropulsionException(static::class . ' does not support folding id retrieval into an empty INSERT');
		}
		return 'INSERT INTO ' . $tableName . ' DEFAULT VALUES';
	}

	/**
	 * Extracts the generated primary key value from a statement built via
	 * getInsertReturningSql(), immediately after it has been executed. Only called
	 * when supportsInsertReturning() is true.
	 *
	 * @param     \PDOStatement $stmt
	 *
	 * @return    mixed
	 */
	public function extractInsertedId(\PDOStatement $stmt): mixed
	{
		throw new PropulsionException(static::class . ' does not support folding id retrieval into INSERT');
	}

	/**
	 * Whether this platform can express "insert this row, or update it instead if a
	 * conflicting row already exists" as a single INSERT statement (Postgres/SQLite's
	 * `ON CONFLICT`, MySQL/MariaDB's `ON DUPLICATE KEY UPDATE`). False by default;
	 * MSSQL/Oracle need `MERGE`, a structurally different statement (not just a
	 * clause appended to INSERT), and are not implemented via this hook.
	 *
	 * @return    boolean
	 */
	public function supportsUpsert(): bool
	{
		return false;
	}

	/**
	 * Rewrites a plain "INSERT INTO table (cols) VALUES (...)" statement into an
	 * upsert. Only called when supportsUpsert() is true.
	 *
	 * @param     string             $sql
	 * @param     array<int,string>  $conflictColumnNames Unqualified, already-quoted-if-necessary
	 *                                column names identifying the conflict target
	 *                                (ignored by platforms, like MySQL, that infer it).
	 * @param     string             $setClause Already-built "col1 = :p2, col2 = :p3" SET-clause
	 *                                fragment (no leading "SET " and no trailing comma). Empty
	 *                                string means "no columns to update on conflict" -- platforms
	 *                                that have no such form (MySQL) should throw.
	 *
	 * @return    string
	 */
	public function getUpsertSql(string $sql, array $conflictColumnNames, string $setClause): string
	{
		throw new PropulsionException(static::class . ' does not support upserts');
	}

	/**
	 * Whether this platform can bulk-load rows via a dedicated fast path (Postgres
	 * COPY, MySQL/MariaDB LOAD DATA), an order of magnitude faster than multi-row
	 * INSERT for seeding/imports. False by default; MSSQL's BULK INSERT/OPENROWSET
	 * needs the file to be readable by the *server* process (not the PHP client),
	 * which a generic client library can't assume, so it isn't implemented here.
	 *
	 * @return    boolean
	 */
	public function supportsBulkLoad(): bool
	{
		return false;
	}

	/**
	 * Bulk-loads $rows into $tableName via this platform's fast bulk-insert mechanism,
	 * bypassing the ORM's normal per-row INSERT path entirely. Only called when
	 * supportsBulkLoad() is true.
	 *
	 * @param     PropulsionPDO             $con
	 * @param     string                    $tableName Real (unquoted) table name.
	 * @param     array<int,string>         $columns Unqualified column names, in the same order as each row's values.
	 * @param     iterable<int,array<int,mixed>> $rows Each row is a plain, ordinally-indexed array of values matching $columns.
	 *
	 * @return    int Number of rows loaded.
	 */
	public function bulkLoad(PropulsionPDO $con, string $tableName, array $columns, iterable $rows): int
	{
		throw new PropulsionException(static::class . ' does not support bulk loading');
	}

	/**
	 * Formats a temporal value brefore binding, given a ColumnMap object
	 *
	 * @param     mixed      $value  The temporal value
	 * @param     ColumnMap  $cMap
	 *
	 * @return    mixed  The formatted (string) temporal value, or the original value unchanged
	 *                   if it could not be parsed as a temporal value.
	 */
	protected function formatTemporalValue($value, ColumnMap $cMap)
	{
		$dt = PropulsionDateTime::newInstance($value);
		if ($dt instanceof \DateTimeInterface) {
			switch($cMap->getType()) {
			case PropulsionColumnTypes::TIMESTAMP:
			case PropulsionColumnTypes::BU_TIMESTAMP:
				$value = $dt->format($this->getTimestampFormatter());
				break;
			case PropulsionColumnTypes::DATE:
			case PropulsionColumnTypes::BU_DATE:
				$value = $dt->format($this->getDateFormatter());
				break;
			case PropulsionColumnTypes::TIME:
				$value = $dt->format($this->getTimeFormatter());
				break;
			}
		}
		return $value;
	}

	/**
	 * Returns timestamp formatter string for use in date() function.
	 *
	 * @return    string
	 */
	public function getTimestampFormatter()
	{
		return 'Y-m-d H:i:s';
	}

	/**
	 * Returns date formatter string for use in date() function.
	 *
	 * @return    string
	 */
	public function getDateFormatter()
	{
		return "Y-m-d";
	}

	/**
	 * Returns time formatter string for use in date() function.
	 *
	 * @return    string
	 */
	public function getTimeFormatter()
	{
		return "H:i:s";
	}

	/**
	 * Should Column-Names get identifiers for inserts or updates.
	 * By default false is returned -> backwards compability.
	 *
	 * it`s a workaround...!!!
	 *
	 * @todo       should be abstract
	 * @deprecated
	 *
	 * @return    boolean
	 */
	public function useQuoteIdentifier()
	{
		return false;
	}

	/**
	 * Allows manipulation of the query string before PDOStatement is instantiated.
	 *
	 * @param     string       $sql  The sql statement
	 * @param     array<int,array<string,mixed>>  $params  array('column' => ..., 'table' => ..., 'value' => ...)
	 * @param     Criteria     $values
	 * @param     DatabaseMap  $dbMap
	 */
	public function cleanupSQL(&$sql, array &$params, Criteria $values, DatabaseMap $dbMap): void
	{
	}

	/**
	 * Modifies the passed-in SQL to add LIMIT and/or OFFSET.
	 *
	 * @param     string   $sql
	 * @param     integer  $offset
	 * @param     integer  $limit
	 * @param     Criteria $criteria  Optional Criteria object, used by some adapters (e.g. DBOracle) to build the LIMIT clause.
	 */
	public abstract function applyLimit(&$sql, $offset, $limit, $criteria = null): void;

	/**
	 * Whether this platform supports SELECT ... FOR UPDATE.
	 *
	 * @return    boolean
	 */
	public function supportsForUpdate(): bool
	{
		return true;
	}

	/**
	 * Whether this platform supports SELECT ... FOR SHARE (a pessimistic read lock,
	 * as opposed to FOR UPDATE's write lock).
	 *
	 * @return    boolean
	 */
	public function supportsForShare(): bool
	{
		return true;
	}

	/**
	 * Whether this platform can fail immediately, instead of blocking, when a row
	 * matched by a locking SELECT is already locked (NOWAIT).
	 *
	 * @return    boolean
	 */
	public function supportsNoWait(): bool
	{
		return true;
	}

	/**
	 * Whether this platform can silently skip rows already locked by another
	 * transaction, instead of blocking, in a locking SELECT (SKIP LOCKED).
	 *
	 * @return    boolean
	 */
	public function supportsSkipLocked(): bool
	{
		return true;
	}

	/**
	 * Appends the pessimistic-locking clause (if any) requested on $criteria to $sql.
	 * Called by BasePeer::createSelectSql() after LIMIT/OFFSET have already been applied.
	 *
	 * The default implementation appends a trailing "FOR UPDATE"/"FOR SHARE" clause,
	 * which is correct for Postgres, MySQL/MariaDB, and Oracle. Platforms that express
	 * row locking differently (e.g. MSSQL's table hints) must override this method.
	 *
	 * @param     string   $sql
	 * @param     Criteria $criteria
	 *
	 * @throws    PropulsionException If the requested lock mode, or NOWAIT/SKIP LOCKED, is unsupported.
	 */
	public function applyLock(string &$sql, Criteria $criteria): void
	{
		$lockMode = $criteria->getLockMode();
		if ($lockMode === null) {
			return;
		}

		if ($lockMode === Criteria::LOCK_FOR_UPDATE && !$this->supportsForUpdate()) {
			throw new PropulsionException(static::class . ' does not support SELECT ... FOR UPDATE');
		}
		if ($lockMode === Criteria::LOCK_FOR_SHARE && !$this->supportsForShare()) {
			throw new PropulsionException(static::class . ' does not support SELECT ... FOR SHARE');
		}
		if ($criteria->isLockNoWait() && !$this->supportsNoWait()) {
			throw new PropulsionException(static::class . ' does not support NOWAIT locking');
		}
		if ($criteria->isLockSkipLocked() && !$this->supportsSkipLocked()) {
			throw new PropulsionException(static::class . ' does not support SKIP LOCKED');
		}

		$sql .= ' ' . $lockMode;
		if ($criteria->isLockNoWait()) {
			$sql .= ' NOWAIT';
		} elseif ($criteria->isLockSkipLocked()) {
			$sql .= ' SKIP LOCKED';
		}
	}

	/**
	 * Applies any per-table locking hints (as opposed to a trailing lock clause) to the
	 * FROM/JOIN clause fragments built by BasePeer::createSelectSql(), before they are
	 * assembled into the final SQL string. The default implementation is a no-op, since
	 * most platforms use a trailing clause (see applyLock()); MSSQL overrides this to
	 * splice in "WITH (UPDLOCK, ROWLOCK)"-style table hints instead.
	 *
	 * @param     array<int,string|null> $fromClause
	 * @param     array<int,string|null> $joinClause
	 * @param     Criteria               $criteria
	 */
	public function applyLockHints(array &$fromClause, array &$joinClause, Criteria $criteria): void
	{
	}

	/**
	 * Gets the SQL string that this adapter uses for getting a random number.
	 *
	 * Nullable: DBNone's implementation returns nothing (this adapter is used when
	 * there is no database installed), while every other adapter returns a SQL
	 * fragment string.
	 *
	 * @param     mixed $seed (optional) seed value for databases that support this
	 */
	public abstract function random($seed = null): ?string;

	/**
	 * Returns the "DELETE FROM <table> [AS <alias>]" part of DELETE query.
	 *
	 * @param     Criteria  $criteria
	 * @param     string    $tableName
	 *
	 * @return    string
	 */
	public function getDeleteFromClause($criteria, $tableName)
	{
		$sql = 'DELETE ';
		if ($queryComment = $criteria->getComment()) {
			$sql .= '/* ' . $queryComment . ' */ ';
		}
		if ($realTableName = $criteria->getTableForAlias($tableName)) {
			if ($this->useQuoteIdentifier()) {
				$realTableName = $this->quoteIdentifierTable($realTableName);
			}
			$sql .= $tableName . ' FROM ' . $realTableName . ' AS ' . $tableName;
		} else {
			if ($this->useQuoteIdentifier()) {
				$tableName = $this->quoteIdentifierTable($tableName);
			}
			$sql .= 'FROM ' . $tableName;
		}
		return $sql;
	}

	/**
	 * Builds the SELECT part of a SQL statement based on a Criteria
	 * taking into account select columns and 'as' columns (i.e. columns aliases)
	 * Move from BasePeer to DBAdapter and turn from static to non static
	 *
	 * @param     Criteria  $criteria
	 * @param     array<int,string> $fromClause
	 * @param     boolean   $aliasAll
	 *
	 * @return    string
	 */
	public function createSelectSqlPart(Criteria $criteria, &$fromClause, $aliasAll = false): string
	{
		$selectClause = array();

		if ($aliasAll) {
			$this->turnSelectColumnsToAliases($criteria);
			// no select columns after that, they are all aliases
		} else {
			foreach ($criteria->getSelectColumns() as $columnName) {

				// expect every column to be of "table.column" formation
				// it could be a function:  e.g. MAX(books.price)

				$tableName = "";

				$selectClause[] = $columnName; // the full column name: e.g. MAX(books.price)

				// Find the last "table.column"-shaped reference in the expression and take its
				// table part by scanning backwards from the last dot for identifier characters
				// (and further embedded dots, to keep multi-schema-qualified table names like
				// "myschema.mytable.column" intact -- see the pgsql-multi-schema fixture).
				// This must work not just for a bare "table.column" or a single-level function
				// wrapper ("MAX(books.price)", "COUNT(DISTINCT books.price)") but also for
				// expressions with several nested function calls and/or several qualified column
				// references, e.g. "substring(book.TITLE from position('Potter' in book.TITLE))"
				// -- a previous implementation located the table name between the *last* "(" and
				// the *last* "." in the whole expression, which for a nested expression like that
				// one picks up everything in between (including unrelated string literals and
				// keywords) instead of just the identifier immediately before the dot.
				$dotPos = strrpos($columnName, '.');

				if ($dotPos !== false) {
					$start = $dotPos;
					while ($start > 0 && (ctype_alnum($columnName[$start - 1]) || $columnName[$start - 1] === '_' || $columnName[$start - 1] === '.')) {
						$start--;
					}
					$tableName = substr($columnName, $start, $dotPos - $start);

					// is it a table alias?
					$tableName2 = $criteria->getTableForAlias($tableName);
					if ($tableName2 !== null) {
						$fromClause[] = $tableName2 . ' ' . $tableName;
					} else {
						$fromClause[] = $tableName;
					}
				} // if $dotPost !== false
			}
		}

		// set the aliases
		foreach ($criteria->getAsColumns() as $alias => $col) {
			$selectClause[] = $col . ' AS ' . $alias;
		}

		$selectModifiers = $criteria->getSelectModifiers();
		$queryComment = $criteria->getComment();

		// Build the SQL from the arrays we compiled
		$sql =  "SELECT "
			. ($queryComment ? '/* ' . $queryComment . ' */ ' : '')
			. ($selectModifiers ? (implode(' ', $selectModifiers) . ' ') : '')
			. implode(", ", $selectClause);

		return $sql;
	}

	/**
	 * Ensures uniqueness of select column names by turning them all into aliases
	 * This is necessary for queries on more than one table when the tables share a column name
	 * Moved from BasePeer to DBAdapter and turned from static to non static
	 *
	 *
	 * @param     Criteria  $criteria
	 * @return    Criteria  The input, with Select columns replaced by aliases
	 */
	public function turnSelectColumnsToAliases(Criteria $criteria)
	{
		$selectColumns = $criteria->getSelectColumns();
		// clearSelectColumns also clears the aliases, so get them too
		$asColumns = $criteria->getAsColumns();
		$criteria->clearSelectColumns();
		$columnAliases = $asColumns;
		// add the select columns back
		foreach ($selectColumns as $clause) {
			// Generate a unique alias
			$baseAlias = preg_replace('/\W/', '_', $clause) ?? $clause;
			$alias = $baseAlias;
			// If it already exists, add a unique suffix
			$i = 0;
			while (isset($columnAliases[$alias])) {
				$i++;
				$alias = $baseAlias . '_' . $i;
			}
			// Add it as an alias
			$criteria->addAsColumn($alias, $clause);
			$columnAliases[$alias] = $clause;
		}
		// Add the aliases back, don't modify them
		foreach ($asColumns as $name => $clause) {
			$criteria->addAsColumn($name, $clause);
		}

		return $criteria;
	}

	/**
	 * Binds values in a prepared statement.
	 *
	 * This method is designed to work with the BasePeer::createSelectSql() method, which creates
	 * both the SELECT SQL statement and populates a passed-in array of parameter
	 * values that should be substituted.
	 *
	 * <code>
	 * $db = Propulsion::getDB($criteria->getDbName());
	 * $sql = BasePeer::createSelectSql($criteria, $params);
	 * $stmt = $con->prepare($sql);
	 * $params = array();
	 * $db->populateStmtValues($stmt, $params, Propulsion::getDatabaseMap($critera->getDbName()));
	 * $stmt->execute();
	 * </code>
	 *
	 * @param     \PDOStatement  $stmt
	 * @param     array<int,array<string,mixed>>  $params  array('column' => ..., 'table' => ..., 'value' => ...)
	 * @param     DatabaseMap   $dbMap
	 */
	public function bindValues(\PDOStatement $stmt, array $params, DatabaseMap $dbMap): void
	{
		$position = 0;
		foreach ($params as $param) {
			$position++;
			$parameter = ':p' . $position;
			$value = $param['value'];
			if (null === $value) {
				$stmt->bindValue($parameter, null, PDO::PARAM_NULL);
				continue;
			}
			$tableName = $param['table'];
			if (null === $tableName) {
				$stmt->bindValue($parameter, $value);
				continue;
			}
			if (!is_string($tableName) || !is_string($param['column'])) {
				throw new PropulsionException('DBAdapter::bindValues() expected param table/column names to be strings');
			}
			$cMap = $dbMap->getTable($tableName)->getColumn($param['column']);
			$this->bindValue($stmt, $parameter, $value, $cMap, $position);
		}
	}

	/**
	 * Binds a value to a positioned parameted in a statement,
	 * given a ColumnMap object to infer the binding type.
	 *
	 * @param     \PDOStatement  $stmt  The statement to bind
	 * @param     string        $parameter  Parameter identifier
	 * @param     mixed         $value  The value to bind
	 * @param     ColumnMap     $cMap  The ColumnMap of the column to bind
	 * @param     null|integer  $position  The position of the parameter to bind
	 *
	 * @return    boolean
	 */
	public function bindValue(\PDOStatement $stmt, $parameter, $value, ColumnMap $cMap, $position = null)
	{
		if (is_array($value)) {
			throw new \Propulsion\Exception\PropulsionException(
				sprintf('Cannot bind array value for parameter %s. Use IN() criteria instead.', $parameter)
			);
		}
		if ($cMap->isTemporal()) {
			$value = $this->formatTemporalValue($value, $cMap);
		} elseif (is_resource($value) && $cMap->isLob()) {
			// we always need to make sure that the stream is rewound, otherwise nothing will
			// get written to database.
			rewind($value);
		}

		return $stmt->bindValue($parameter, $value, $cMap->getPdoType());
	}
}
