<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Util;
/**
 * This is a utility class for all generated Peer classes in the system.
 *
 * Peer classes are responsible for isolating all of the database access
 * for a specific business object.  They execute all of the SQL
 * against the database.  Over time this class has grown to include
 * utility methods which ease execution of cross-database queries and
 * the implementation of concrete Peers.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Kaspars Jaudzems <kaspars.jaudzems@inbox.lv> (Propel)
 * @author     Heltem <heltem@o2php.com> (Propel)
 * @author     Frank Y. Kim <frank.kim@clearink.com> (Torque)
 * @author     John D. McNally <jmcnally@collab.net> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Stephen Haberman <stephenh@chase3000.com> (Torque)
 * @version    $Revision$
 */

 use Propulsion\Query\ColumnSqlRewriter;
 use Propulsion\Query\Criteria;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\Connection\PropulsionPDO;
 use Propulsion\Propulsion;
 use \Exception;
 use \PDOStatement;
 use Propulsion\Map\ColumnMap;
 use Propulsion\Validator\ValidationFailed;
 use Propulsion\Validator\BasicValidator;
 use Propulsion\Adapter\DBAdapter;
class BasePeer
{

	/**
	 * Array (hash) that contains cached validators
	 *
	 * @var array<string, BasicValidator>
	 */
	private static array $validatorMap = array();

	/**
	 * phpname type
	 * e.g. 'AuthorId'
	 */
	const TYPE_PHPNAME = 'phpName';

	/**
	 * studlyphpname type
	 * e.g. 'authorId'
	 */
	const TYPE_STUDLYPHPNAME = 'studlyPhpName';

	/**
	 * column (peer) name type
	 * e.g. 'book.AUTHOR_ID'
	 */
	const TYPE_COLNAME = 'colName';

	/**
	 * column part of the column peer name
	 * e.g. 'AUTHOR_ID'
	 */
	const TYPE_RAW_COLNAME = 'rawColName';

	/**
	 * column fieldname type
	 * e.g. 'author_id'
	 */
	const TYPE_FIELDNAME = 'fieldName';

	/**
	 * num type
	 * simply the numerical array index, e.g. 4
	 */
	const TYPE_NUM = 'num';

	/**
	 * Generic, table-name-taking counterpart to each generated Peer's own static
	 * getFieldNames($type). Every generated Peer class (e.g. BookPeer) already implements
	 * this against its own table; this delegates to the right one by naming convention
	 * (<Classname>Peer), so callers that only know the model's class name at runtime (not
	 * which generated Peer that maps to) don't have to look it up themselves.
	 *
	 * @param      string $classname The PHP name of the model class (e.g. "Book").
	 * @param      string $type The type of fieldnames to return: one of the class type
	 *                     constants TYPE_PHPNAME, TYPE_STUDLYPHPNAME, TYPE_COLNAME,
	 *                     TYPE_FIELDNAME, TYPE_NUM.
	 * @return     array<int, int|string> A list of field names (ints when $type is TYPE_NUM)
	 */
	public static function getFieldNames($classname, $type = self::TYPE_PHPNAME)
	{
		$peerClass = $classname . 'Peer';
		$fieldNames = $peerClass::getFieldNames($type);
		if (!is_array($fieldNames)) {
			throw new PropulsionException("{$peerClass}::getFieldNames() was expected to return an array");
		}

		$result = array();
		foreach ($fieldNames as $fieldName) {
			if (!is_int($fieldName) && !is_string($fieldName)) {
				throw new PropulsionException("{$peerClass}::getFieldNames() was expected to return an array of ints or strings");
			}
			$result[] = $fieldName;
		}

		return $result;
	}

	/**
	 * Generic, table-name-taking counterpart to each generated Peer's own static
	 * translateFieldName($name, $fromType, $toType). See getFieldNames() above.
	 *
	 * @param      string $classname The PHP name of the model class (e.g. "Book").
	 * @param      string $name
	 * @param      string $fromType
	 * @param      string $toType
	 * @return     mixed
	 */
	public static function translateFieldName($classname, $name, $fromType, $toType)
	{
		$peerClass = $classname . 'Peer';

		return $peerClass::translateFieldName($name, $fromType, $toType);
	}

	/**
	 * Method to perform deletes based on values and keys in a
	 * Criteria.
	 *
	 * @param      Criteria $criteria The criteria to use.
	 * @param      PropulsionPDO $con A PropulsionPDO connection object.
	 * @param      ?array<int,string> $returningColumns Fully-qualified column names to
	 *             return for every removed row, instead of just a count -- see
	 *             DBAdapter::supportsRowReturning(). Null (the default) means "just
	 *             return the count", the original behavior.
	 * @return     ($returningColumns is null ? int : array<int,array<string,mixed>>) The number of rows affected by last
	 *             statement execution (see the multi-table caveat below), or, when
	 *             $returningColumns is given, the removed rows themselves (each an
	 *             associative array keyed by column name) -- concatenated across every
	 *             table statement executed, for the rare multi-table Criteria. For most
	 *             uses there is only one delete statement executed, so this corresponds
	 *             directly to the one statement's own result. Note that the return value
	 *             does require that this information is returned (supported) by the PDO
	 *             driver.
	 * @throws     PropulsionException
	 */
	public static function doDelete(Criteria $criteria, PropulsionPDO $con, ?array $returningColumns = null)
	{
		$db = Propulsion::getDB($criteria->getDbName());
		$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());

		if ($returningColumns !== null && !$db->supportsRowReturning($con)) {
			throw new PropulsionException(get_class($db) . ' does not support RETURNING on DELETE');
		}

		//join are not supported with DELETE statement
		if (count($criteria->getJoins())) {
			throw new PropulsionException('Delete does not support join');
		}

		// Set up a list of required tables (one DELETE statement will
		// be executed per table)
		$tables = $criteria->getTablesColumns();
		if (empty($tables)) {
			throw new PropulsionException("Cannot delete from an empty Criteria");
		}

		$affectedRows = 0; // initialize this in case the next loop has no iterations.
		$returnedRows = array();

		foreach ($tables as $tableName => $columns) {

			$whereClause = array();
			$params = array();
			$stmt = null;
			try {
				$sql = $db->getDeleteFromClause($criteria, $tableName);

				foreach ($columns as $colName) {
					$sb = "";
					$criteria->getCriterion($colName)->appendPsTo($sb, $params);
					$whereClause[] = $sb;
				}
				$sql .= " WHERE " .  implode(" AND ", $whereClause);

				if ($returningColumns !== null) {
					$sql = $db->getDeleteReturningSql($sql, self::unqualifyAndQuoteColumnNames($returningColumns, $db));
				}

				$db->cleanupSQL($sql, $params, $criteria, $dbMap);

				$stmt = $con->prepare($sql);
				if ($stmt === false) {
					throw new PropulsionException('PropulsionPDO::prepare() returned false');
				}
				$db->bindValues($stmt, $params, $dbMap);
				$stmt->execute();

				if ($returningColumns !== null) {
					$returnedRows = array_merge($returnedRows, $stmt->fetchAll(\PDO::FETCH_ASSOC));
				} else {
					$affectedRows = $stmt->rowCount();
				}
			} catch (Exception $e) {
				Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
				throw new PropulsionException(sprintf('Unable to execute DELETE statement [%s]', $sql), $e);
			}

			// The loop key is whatever the criterion map carried -- a table alias
			// for an aliased Criteria. Every other write path here invalidates
			// under the real table name (doUpdate() resolves the alias for its
			// own SQL and reuses the resolved name; doInsert()/doDeleteAll()/
			// doUpsert() never see an alias at all), and so does
			// Criteria::getQueryCacheTouchedTables() on the read side, so
			// resolve it here too rather than bumping a key nothing else uses.
			Propulsion::getSession()->getQueryCache()->invalidateTable(
				$criteria->resolveTableAlias((string) $tableName),
				$con,
				$criteria->getDbName()
			);

		} // for each table

		return $returningColumns !== null ? $returnedRows : $affectedRows;
	}

	/**
	 * Method to deletes all contents of specified table.
	 *
	 * This method is invoked from generated Peer classes like this:
	 * <code>
	 * public static function doDeleteAll($con = null)
	 * {
	 *   if ($con === null) $con = Propulsion::getConnection(self::DATABASE_NAME);
	 *   BasePeer::doDeleteAll(self::TABLE_NAME, $con, self::DATABASE_NAME);
	 * }
	 * </code>
	 *
	 * @param      string $tableName The name of the table to empty.
	 * @param      PropulsionPDO $con A PropulsionPDO connection object.
	 * @param      string $databaseName the name of the database.
	 * @return     int	The number of rows affected by the statement.  Note
	 * 				that the return value does require that this information
	 * 				is returned (supported) by the Propulsion db driver.
	 * @throws     PropulsionException - wrapping SQLException caught from statement execution.
	 */
	public static function doDeleteAll(string $tableName, PropulsionPDO $con, string $databaseName)
	{
		$originalTableName = $tableName;
		$sql = null;
		try {
			$db = Propulsion::getDB($databaseName);
			if ($db->useQuoteIdentifier()) {
				$tableName = $db->quoteIdentifierTable($tableName);
			}
			$sql = "DELETE FROM " . $tableName;
			$stmt = $con->prepare($sql);
			if ($stmt === false) {
				throw new PropulsionException('PropulsionPDO::prepare() returned false');
			}
			$stmt->execute();
			$affectedRows = $stmt->rowCount();
		} catch (Exception $e) {
			Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
			throw new PropulsionException(sprintf('Unable to execute DELETE ALL statement [%s]', $sql), $e);
		}

		Propulsion::getSession()->getQueryCache()->invalidateTable($originalTableName, $con, $databaseName);

		return $affectedRows;
	}

	/**
	 * Bulk-loads $rows into $tableName via the platform's fast bulk-insert mechanism
	 * (Postgres COPY, MySQL/MariaDB LOAD DATA), bypassing doInsert()'s per-row path
	 * entirely -- an order of magnitude faster for seeding/imports. See
	 * DBAdapter::bulkLoad() for the per-platform mechanism and value-encoding details.
	 * MSSQL/Oracle aren't supported here: MSSQL's BULK INSERT needs the file readable
	 * by the SQL Server process itself, not the PHP client, and Oracle has no
	 * equivalent bulk-load mechanism this codebase implements.
	 *
	 * @param      string $tableName Real (unquoted) table name.
	 * @param      array<int,string> $columns Unqualified column names, in the same order as each row's values.
	 * @param      iterable<int,array<int,mixed>> $rows Each row is a plain, ordinally-indexed array of values matching $columns.
	 * @param      PropulsionPDO $con A PropulsionPDO connection.
	 * @param      string|null $dbName Datasource name; defaults to the configured default datasource.
	 *
	 * @return     int Number of rows loaded.
	 * @throws     PropulsionException If the platform doesn't support bulk loading.
	 */
	public static function doBulkInsert(string $tableName, array $columns, iterable $rows, PropulsionPDO $con, ?string $dbName = null): int
	{
		$db = Propulsion::getDB($dbName);
		if (!$db->supportsBulkLoad()) {
			throw new PropulsionException(get_class($db) . ' does not support bulk loading');
		}

		$count = $db->bulkLoad($con, $tableName, $columns, $rows);

		Propulsion::getSession()->getQueryCache()->invalidateTable($tableName, $con, $dbName);

		return $count;
	}

	/**
	 * Method to perform inserts based on values and keys in a
	 * Criteria.
	 * <p>
	 * If the primary key is auto incremented the data in Criteria
	 * will be inserted and the auto increment value will be returned.
	 * <p>
	 * If the primary key is included in Criteria then that value will
	 * be used to insert the row.
	 * <p>
	 * If no primary key is included in Criteria then we will try to
	 * figure out the primary key from the database map and insert the
	 * row with the next available id using util.db.IDBroker.
	 * <p>
	 * If no primary key is defined for the table the values will be
	 * inserted as specified in Criteria and null will be returned.
	 *
	 * @param      Criteria $criteria Object containing values to insert.
	 * @param      PropulsionPDO $con A PropulsionPDO connection.
	 * @return     mixed The primary key for the new row if (and only if!) the primary key
	 *				is auto-generated.  Otherwise will return <code>null</code>.
	 * @throws     PropulsionException
	 */
	public static function doInsert(Criteria $criteria, PropulsionPDO $con) {

		// the primary key
		$id = null;

		$db = Propulsion::getDB($criteria->getDbName());

		// Get the table name and method for determining the primary
		// key value. Ordinarily derived from the first column key, but a Criteria
		// can legitimately have zero columns at all -- e.g. inserting a row whose
		// every column already equals its own default, on a platform where
		// supportsInsertNullPk() is false, so the auto-increment PK's (null) entry
		// was stripped out entirely rather than left in with an explicit NULL --
		// in which case the generated object model calls setPrimaryTableName()
		// itself so the table can still be identified.
		$keys = $criteria->keys();
		if (!empty($keys)) {
			$tableName = $criteria->getTableName( $keys[0] );
		} else {
			$tableName = $criteria->getPrimaryTableName();
		}
		if (empty($tableName)) {
			throw new PropulsionException("Database insert attempted without anything specified to insert");
		}

		$originalTableName = $tableName;

		$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());
		$tableMap = $dbMap->getTable($tableName);
		$keyInfo = $tableMap->getPrimaryKeyMethodInfo();
		$useIdGen = $tableMap->isUseIdGenerator();
		//$keyGen = $con->getIdGenerator();

		$pk = self::getPrimaryKey($criteria, $tableName);

		// only get a new key value if you need to
		// the reason is that a primary key might be defined
		// but you are still going to set its value. for example:
		// a join table where both keys are primary and you are
		// setting both columns with your own values

		// pk will be null if there is no primary key defined for the table
		// we're inserting into.
		$needsGeneratedId = $pk !== null && $useIdGen && !$criteria->keyContainsValue($pk->getFullyQualifiedName());

		// The opposite case: an explicit, non-null PK value was supplied for a
		// column the id generator would otherwise have populated (allowPkInsert,
		// or a Criteria-based doInsert() bypassing the object model entirely).
		// SQL Server rejects this outright for an IDENTITY column unless
		// IDENTITY_INSERT is toggled on for the statement -- every other
		// platform here just accepts the explicit value as-is.
		$explicitAutoIncrementValue = $pk !== null && $useIdGen && $criteria->keyContainsValue($pk->getFullyQualifiedName())
			? $pk->getFullyQualifiedName()
			: null;

		// When the adapter can hand back the generated id from the INSERT statement
		// itself (e.g. MSSQL's OUTPUT clause), skip the separate before/after getId()
		// round trip entirely -- one of the two branches below would otherwise run an
		// extra query for exactly the value the INSERT is about to return anyway.
		$useInsertReturning = $needsGeneratedId && $db->supportsInsertReturning($con);

		// An allowPkInsert table's generated code never strips its PK column
		// from the Criteria the way a plain auto-increment table's does (see
		// ObjectBuilder.php's own comment on that), since the whole point of
		// allowPkInsert is to let a caller supply an explicit value -- but when
		// nobody actually does (still null here, e.g. relying on the id
		// generator as usual), that leaves an explicit NULL entry that either
		// a platform where supportsInsertNullPk() is false (MSSQL) can't accept
		// at all, not even implicitly, or -- now that $useInsertReturning skips
		// the separate before-insert getId() call that used to overwrite this
		// same null with a real value before the INSERT ever ran -- a NOT NULL
		// id column on any platform (Postgres/Oracle; MySQL/MariaDB's
		// AUTO_INCREMENT and SQLite's INTEGER PRIMARY KEY both special-case an
		// explicit NULL as "generate one", so this never bit them) would
		// otherwise reject outright.
		if ($needsGeneratedId && ($useInsertReturning || !$db->supportsInsertNullPk()) && $criteria->containsKey($pk->getFullyQualifiedName())) {
			$criteria->remove($pk->getFullyQualifiedName());
		}

		if ($needsGeneratedId && $db->isGetIdBeforeInsert() && !$useInsertReturning) {
			if (!$con instanceof \PDO) {
				throw new PropulsionException("Unable to get sequence id: connection is not a PDO instance.");
			}
			try {
				$id = $db->getId($con, $keyInfo);
			} catch (Exception $e) {
				throw new PropulsionException("Unable to get sequence id.", $e);
			}
			$criteria->add($pk->getFullyQualifiedName(), $id);
		}

		$sql = null;
		try {
			$adapter = Propulsion::getDB($criteria->getDBName());

			$qualifiedCols = $criteria->keys(); // we need table.column cols when populating values
			$columns = array(); // but just 'column' cols for the SQL
			foreach ($qualifiedCols as $qualifiedCol) {
				$columns[] = substr($qualifiedCol, strrpos($qualifiedCol, '.') + 1);
			}

			// add identifiers
			if ($adapter->useQuoteIdentifier()) {
				$columns = array_map(array($adapter, 'quoteIdentifier'), $columns);
				$tableName = $adapter->quoteIdentifierTable($tableName);
			}

			$idColumnName = null;
			if ($useInsertReturning) {
				$idColumnName = substr($pk->getFullyQualifiedName(), strrpos($pk->getFullyQualifiedName(), '.') + 1);
				if ($adapter->useQuoteIdentifier()) {
					$idColumnName = $adapter->quoteIdentifier($idColumnName);
				}
			}

			if (empty($columns)) {
				// Every column either has no value to set, or (for an
				// auto-increment PK on a supportsInsertNullPk()===false platform)
				// was stripped out above rather than left in with an explicit
				// NULL -- there's nothing left to build a normal column list from.
				$sql = $adapter->getEmptyInsertSql($tableName, $idColumnName);
			} else {
				$sql = 'INSERT INTO ' . $tableName
				. ' (' . implode(',', $columns) . ')'
				. ' VALUES (';
				// . substr(str_repeat("?,", count($columns)), 0, -1) .
				$sql .= self::buildBindPlaceholderList($qualifiedCols, $adapter, $criteria->getDbName(), 1);
				$sql .= ')';

				if ($useInsertReturning) {
					$sql = $adapter->getInsertReturningSql($sql, $idColumnName);
				}
			}

			$params = self::buildParams($qualifiedCols, $criteria);

			$db->cleanupSQL($sql, $params, $criteria, $dbMap);

			$stmt = $con->prepare($sql);
			if ($stmt === false) {
				throw new PropulsionException('PropulsionPDO::prepare() returned false');
			}
			$db->bindValues($stmt, $params, $dbMap);

			if ($useInsertReturning) {
				$adapter->prepareInsertReturning($stmt, $idColumnName);
			}

			$identityInsertOnSql = $explicitAutoIncrementValue !== null ? $adapter->getIdentityInsertOnSql($tableName) : null;
			if ($identityInsertOnSql !== null) {
				$con->exec($identityInsertOnSql);
			}
			try {
				$stmt->execute();
			} finally {
				if ($identityInsertOnSql !== null) {
					$identityInsertOffSql = $adapter->getIdentityInsertOffSql($tableName);
					if ($identityInsertOffSql !== null) {
						$con->exec($identityInsertOffSql);
					}
				}
			}

			if ($useInsertReturning) {
				$id = $db->extractInsertedId($stmt);
			}

		} catch (Exception $e) {
			Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
			throw new PropulsionException(sprintf('Unable to execute INSERT statement [%s]', $sql), $e);
		}

		Propulsion::getSession()->getQueryCache()->invalidateTable($originalTableName, $con, $criteria->getDbName());

		// If the primary key column is auto-incremented, get the id now.
		if ($needsGeneratedId && $db->isGetIdAfterInsert() && !$useInsertReturning) {
			if (!$con instanceof \PDO) {
				throw new PropulsionException("Unable to get autoincrement id: connection is not a PDO instance.");
			}
			try {
				$id = $db->getId($con, $keyInfo);
			} catch (Exception $e) {
				throw new PropulsionException("Unable to get autoincrement id.", $e);
			}
		}

		return $id;
	}

	/**
	 * Method used to update rows in the DB.  Rows are selected based
	 * on selectCriteria and updated using values in updateValues.
	 * <p>
	 * Use this method for performing an update of the kind:
	 * <p>
	 * WHERE some_column = some value AND could_have_another_column =
	 * another value AND so on.
	 *
	 * @param      $selectCriteria A Criteria object containing values used in where
	 *		clause.
	 * @param      $updateValues A Criteria object containing values used in set
	 *		clause.
	 * @param      PropulsionPDO $con The PropulsionPDO connection object to use.
	 * @param      ?array<int,string> $returningColumns Fully-qualified column names to
	 *             return for every affected row, instead of just a count -- see
	 *             DBAdapter::supportsRowReturning(). Null (the default) means "just
	 *             return the count", the original behavior.
	 * @return     ($returningColumns is null ? int : array<int,array<string,mixed>>)
	 *             The number of rows affected by last update statement (see the
	 *             multi-table caveat below), or, when $returningColumns is given, the
	 *             affected rows themselves (each an associative array keyed by column
	 *             name) -- concatenated across every table statement executed, for the
	 *             rare multi-table Criteria. For most uses there is only one update
	 *             statement executed, so this corresponds directly to the one
	 *             statement's own result. Note that the return value does require that
	 *             this information is returned (supported) by the Propulsion db driver.
	 * @throws     PropulsionException
	 */
	public static function doUpdate(Criteria $selectCriteria, Criteria $updateValues, PropulsionPDO $con, ?array $returningColumns = null) {

		$db = Propulsion::getDB($selectCriteria->getDbName());
		$dbMap = Propulsion::getDatabaseMap($selectCriteria->getDbName());

		if ($returningColumns !== null && !$db->supportsRowReturning($con)) {
			throw new PropulsionException(get_class($db) . ' does not support RETURNING on UPDATE');
		}

		// Get list of required tables, containing all columns
		$tablesColumns = $selectCriteria->getTablesColumns();
		if (empty($tablesColumns)) {
			$primaryTableName = $selectCriteria->getPrimaryTableName();
			if ($primaryTableName === null) {
				throw new PropulsionException('Cannot update: no columns and no primary table name set on the Criteria');
			}
			$tablesColumns = array($primaryTableName => array());
		}

		// we also need the columns for the update SQL
		$updateTablesColumns = $updateValues->getTablesColumns();

		$affectedRows = 0; // initialize this in case the next loop has no iterations.
		$returnedRows = array();

		foreach ($tablesColumns as $tableName => $columns) {

			$whereClause = array();
			$params = array();
			$stmt = null;
			try {
				$sql = 'UPDATE ';
				if ($queryComment = $selectCriteria->getComment()) {
					$sql .= '/* ' . $queryComment . ' */ ';
				}
				// is it a table alias?
				$aliasName = null;
				if ($tableName2 = $selectCriteria->getTableForAlias($tableName)) {
					$aliasName = $tableName;
					$udpateTable = $db->getUpdateTargetSql($tableName2, $aliasName);
					$tableName = $tableName2;
				} else {
					$udpateTable = $tableName;
				}
				if ($db->useQuoteIdentifier()) {
					$sql .= $db->quoteIdentifierTable($udpateTable);
				} else {
					$sql .= $udpateTable;
				}
				// Check if there are columns to update for this table
				if (!isset($updateTablesColumns[$tableName]) || empty($updateTablesColumns[$tableName])) {
					throw new PropulsionException("No columns specified for update in table '$tableName'");
				}

				$sql .= " SET ";
				$sql .= self::buildSetClause($updateTablesColumns[$tableName], $updateValues, $db, 1)[0];

				if ($aliasName !== null) {
					$sql .= $db->getUpdateFromClauseSql($tableName, $aliasName);
				}

				$params = self::buildParams($updateTablesColumns[$tableName], $updateValues);

				if (!empty($columns)) {
					foreach ($columns as $colName) {
						$sb = "";
						$selectCriteria->getCriterion($colName)->appendPsTo($sb, $params);
						$whereClause[] = $sb;
					}
					$sql .= " WHERE " .  implode(" AND ", $whereClause);
				}

				if ($returningColumns !== null) {
					$sql = $db->getUpdateReturningSql($sql, self::unqualifyAndQuoteColumnNames($returningColumns, $db));
				}

				$db->cleanupSQL($sql, $params, $updateValues, $dbMap);

				$stmt = $con->prepare($sql);
				if ($stmt === false) {
					throw new PropulsionException('PropulsionPDO::prepare() returned false');
				}

				// Replace ':p?' with the actual values
				$db->bindValues($stmt, $params, $dbMap);

				$stmt->execute();

				if ($returningColumns !== null) {
					$returnedRows = array_merge($returnedRows, $stmt->fetchAll(\PDO::FETCH_ASSOC));
				} else {
					$affectedRows = $stmt->rowCount();
				}

				$stmt = null; // close

			} catch (Exception $e) {
				if ($stmt) $stmt = null; // close
				Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
				throw new PropulsionException(sprintf('Unable to execute UPDATE statement [%s]', $sql), $e);
			}

			Propulsion::getSession()->getQueryCache()->invalidateTable((string) $tableName, $con, $selectCriteria->getDbName());

		} // foreach table in the criteria

		return $returningColumns !== null ? $returnedRows : $affectedRows;
	}

	/**
	 * Inserts a row, or updates it instead if it conflicts with an existing row (on
	 * $conflictColumns, or the table's primary key if $conflictColumns is empty) --
	 * "upsert", a single round trip instead of a separate existence check plus
	 * insert-or-update.
	 *
	 * Supported on Postgres, SQLite (`ON CONFLICT ... DO UPDATE`/`DO NOTHING`) and
	 * MySQL/MariaDB (`ON DUPLICATE KEY UPDATE`, which ignores $conflictColumns --
	 * MySQL infers the conflict target from any unique/primary key violation, and has
	 * no "do nothing" form, so an empty $updateValues throws there). MSSQL/Oracle
	 * express this via `MERGE` instead (DBAdapter::usesMergeUpsert()), a structurally
	 * different statement built from scratch by getMergeUpsertSql() rather than a
	 * clause appended to a plain INSERT.
	 *
	 * @param      Criteria $criteria The values to insert.
	 * @param      Criteria $updateValues The columns (and values, or a
	 *             Criteria::CUSTOM_EQUAL raw expression -- see ColumnExpression) to
	 *             set on conflict. Empty means "do nothing on conflict".
	 * @param      PropulsionPDO $con A PropulsionPDO connection object.
	 * @param      array<int,string> $conflictColumns Fully-qualified column names identifying
	 *             the conflict target. Defaults to the table's primary key columns.
	 *
	 * @return     int Number of affected rows -- except on MySQL/MariaDB, whose own C
	 *             API reports 1 for a row inserted fresh but *2* (not 1) for a row
	 *             updated via the ON DUPLICATE KEY UPDATE path, a real, documented
	 *             MySQL quirk (see mysql_affected_rows()'s manual page) rather than
	 *             something this method can normalize away without losing the
	 *             insert-vs-update distinction other callers might want from it.
	 * @throws     PropulsionException
	 */
	public static function doUpsert(Criteria $criteria, Criteria $updateValues, PropulsionPDO $con, array $conflictColumns = array()): int
	{
		$db = Propulsion::getDB($criteria->getDbName());
		if (!$db->supportsUpsert()) {
			throw new PropulsionException(get_class($db) . ' does not support upserts');
		}

		$keys = $criteria->keys();
		if (empty($keys)) {
			throw new PropulsionException("Database upsert attempted without anything specified to insert");
		}
		$tableName = $criteria->getTableName($keys[0]);
		if ($tableName === null) {
			throw new PropulsionException("Database upsert attempted without a resolvable table name");
		}
		$originalTableName = $tableName;

		$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());
		$tableMap = $dbMap->getTable($tableName);

		if (empty($conflictColumns)) {
			foreach ($tableMap->getPrimaryKeys() as $pkColumn) {
				$conflictColumns[] = $pkColumn->getFullyQualifiedName();
			}
			if (empty($conflictColumns)) {
				throw new PropulsionException("doUpsert() needs either an explicit \$conflictColumns list or a table with a primary key");
			}
		}

		// Same explicit-auto-increment-value detection as doInsert() -- an upsert's
		// conflict target is typically the PK, so this is the common case on MSSQL,
		// not the rare allowPkInsert one doInsert() mostly sees it for.
		$pk = self::getPrimaryKey($criteria, $tableName);
		$explicitAutoIncrementValue = $pk !== null && $tableMap->isUseIdGenerator() && $criteria->keyContainsValue($pk->getFullyQualifiedName())
			? $pk->getFullyQualifiedName()
			: null;

		$sql = null;
		try {
			$qualifiedCols = $criteria->keys();
			$columns = array();
			foreach ($qualifiedCols as $qualifiedCol) {
				$columns[] = substr($qualifiedCol, strrpos($qualifiedCol, '.') + 1);
			}

			$insertTableName = $tableName;
			if ($db->useQuoteIdentifier()) {
				$columns = array_map(array($db, 'quoteIdentifier'), $columns);
				$insertTableName = $db->quoteIdentifierTable($insertTableName);
			}

			$params = self::buildParams($qualifiedCols, $criteria);

			$conflictColumnNames = self::unqualifyAndQuoteColumnNames($conflictColumns, $db);

			$updateColumnKeys = $updateValues->keys();
			$setClause = self::buildSetClause($updateColumnKeys, $updateValues, $db, count($columns) + 1)[0];

			if ($db->usesMergeUpsert()) {
				$sql = $db->getMergeUpsertSql($insertTableName, $columns, $conflictColumnNames, $setClause);
			} else {
				$sql = 'INSERT INTO ' . $insertTableName
				. ' (' . implode(',', $columns) . ')'
				. ' VALUES ('
				. self::buildBindPlaceholderList($qualifiedCols, $db, $criteria->getDbName(), 1)
				. ')';

				$sql = $db->getUpsertSql($sql, $conflictColumnNames, $setClause);
			}

			$params = array_merge($params, self::buildParams($updateColumnKeys, $updateValues));

			$db->cleanupSQL($sql, $params, $criteria, $dbMap);

			$stmt = $con->prepare($sql);
			if ($stmt === false) {
				throw new PropulsionException('PropulsionPDO::prepare() returned false');
			}
			$db->bindValues($stmt, $params, $dbMap);

			$identityInsertOnSql = $explicitAutoIncrementValue !== null ? $db->getIdentityInsertOnSql($insertTableName) : null;
			if ($identityInsertOnSql !== null) {
				$con->exec($identityInsertOnSql);
			}
			try {
				$stmt->execute();
			} finally {
				if ($identityInsertOnSql !== null) {
					$identityInsertOffSql = $db->getIdentityInsertOffSql($insertTableName);
					if ($identityInsertOffSql !== null) {
						$con->exec($identityInsertOffSql);
					}
				}
			}

			$affectedRows = $stmt->rowCount();

		} catch (Exception $e) {
			Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
			throw new PropulsionException(sprintf('Unable to execute UPSERT statement [%s]', $sql), $e);
		}

		Propulsion::getSession()->getQueryCache()->invalidateTable($originalTableName, $con, $criteria->getDbName());

		return $affectedRows;
	}

	/**
	 * Executes query build by createSelectSql() and returns the resultset statement.
	 *
	 * @param      Criteria $criteria A Criteria.
	 * @param      PropulsionPDO $con A PropulsionPDO connection to use.
	 * @return     PDOStatement The resultset.
	 * @throws     PropulsionException
	 * @see        createSelectSql()
	 */
	public static function doSelect(Criteria $criteria, ?PropulsionPDO $con = null)
	{
		if ($con === null) {
			$con = Propulsion::getConnection($criteria->getDbName(), Propulsion::CONNECTION_READ);
		}
		if (!$con instanceof PropulsionPDO) {
			throw new PropulsionException('Expected a PropulsionPDO connection');
		}

		$params = array();
		$sql = self::createSelectSql($criteria, $params);

		return self::executeSelectSql($criteria, $con, $sql, $params);
	}

	/**
	 * Executes an already-built SELECT/COUNT SQL + params pair (see
	 * {@see createSelectSql()}) and returns the resultset statement. Split
	 * out of {@see doSelect()} so a caller that needs the generated SQL
	 * *before* deciding whether to actually hit the database (e.g. the query
	 * result cache, see {@see Criteria::setQueryCache()}) can build it once
	 * and reuse it here on a cache miss, instead of generating it twice.
	 *
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params
	 * @throws     PropulsionException
	 */
	public static function executeSelectSql(Criteria $criteria, PropulsionPDO $con, string $sql, array $params): \PDOStatement
	{
		$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());
		$db = Propulsion::getDB($criteria->getDbName());

		$db->cleanupSQL($sql, $params, $criteria, $dbMap);

		try {
			$stmt = $con->prepare($sql);
			if ($stmt === false) {
				throw new PropulsionException('PropulsionPDO::prepare() returned false');
			}

			$db->bindValues($stmt, $params, $dbMap);

			$stmt->execute();
		} catch (Exception $e) {
			Propulsion::log($e->getMessage(), Propulsion::LOG_ERR);
			throw new PropulsionException(sprintf('Unable to execute SELECT statement [%s]', $sql), $e);
		}

		return $stmt;
	}

	/**
	 * Executes a COUNT query using either a simple SQL rewrite or, for more complex queries, a
	 * sub-select of the SQL created by createSelectSql() and returns the statement.
	 *
	 * @param      Criteria $criteria A Criteria.
	 * @param      PropulsionPDO $con A PropulsionPDO connection to use.
	 * @return     PDOStatement The resultset statement.
	 * @throws     PropulsionException
	 * @see        createSelectSql()
	 */
	public static function doCount(Criteria $criteria, ?PropulsionPDO $con = null)
	{
		if ($con === null) {
			$con = Propulsion::getConnection($criteria->getDbName(), Propulsion::CONNECTION_READ);
		}
		if (!$con instanceof PropulsionPDO) {
			throw new PropulsionException('Expected a PropulsionPDO connection');
		}

		$params = array();
		$sql = self::createCountSql($criteria, $params);

		return self::executeSelectSql($criteria, $con, $sql, $params);
	}

	/**
	 * Builds the SQL (and bound params) for a COUNT query, using either a
	 * simple SQL rewrite or, for more complex queries, a sub-select of the SQL
	 * created by {@see createSelectSql()}. Split out of {@see doCount()} for
	 * the same reason {@see createSelectSql()} is split from {@see doSelect()}
	 * -- so a caller can compute the SQL for a cache-key check before
	 * deciding whether to execute (see {@see Criteria::setQueryCache()}).
	 *
	 * Note this mutates $criteria (aliasing/replacing select columns), same
	 * as the code it was extracted from -- callers that need the original
	 * columns afterwards must pass a clone.
	 *
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> &$params
	 * @throws     PropulsionException
	 */
	public static function createCountSql(Criteria $criteria, array &$params): string
	{
		$db = Propulsion::getDB($criteria->getDbName());

		$needsComplexCount = $criteria->getGroupByColumns()
			|| $criteria->getOffset()
			|| $criteria->getLimit()
			|| $criteria->getHaving()
			|| in_array(Criteria::DISTINCT, $criteria->getSelectModifiers());

		if ($needsComplexCount) {
			if (self::needsSelectAliases($criteria)) {
				if ($criteria->getHaving()) {
					throw new PropulsionException('Propulsion cannot create a COUNT query when using HAVING and  duplicate column names in the SELECT part');
				}
				$db->turnSelectColumnsToAliases($criteria);
			}
			$selectSql = self::createSelectSql($criteria, $params);
			return 'SELECT COUNT(*) FROM (' . $selectSql . ') propelmatch4cnt';
		}

		// Replace SELECT columns with COUNT(*)
		$criteria->clearSelectColumns()->addSelectColumn('COUNT(*)');
		return self::createSelectSql($criteria, $params);
	}

	/**
	 * Applies any validators that were defined in the schema to the specified columns.
	 *
	 * @param      string $dbName The name of the database
	 * @param      string $tableName The name of the table
	 * @param      array<string, mixed> $columns Array of column names as key and column values as value.
	 * @return     array<string, ValidationFailed>|true
	 */
	public static function doValidate($dbName, $tableName, $columns)
	{
		$dbMap = Propulsion::getDatabaseMap($dbName);
		$tableMap = $dbMap->getTable($tableName);
		$failureMap = array(); // map of ValidationFailed objects
		foreach ($columns as $colName => $colValue) {
			if ($tableMap->hasColumn($colName)) {
				$col = $tableMap->getColumn($colName);
				foreach ($col->getValidators() as $validatorMap) {
					$validator = BasePeer::getValidator($validatorMap->getClass());
					if ($validator && ($col->isNotNull() || $colValue !== null) && $validator->isValid($validatorMap, $colValue) === false) {
						if (!isset($failureMap[$colName])) { // for now we do one ValidationFailed per column, not per rule
							$failureMap[$colName] = new ValidationFailed($colName, $validatorMap->getMessage(), $validator);
						}
					}
				}
			}
		}
		return (!empty($failureMap) ? $failureMap : true);
	}

	/**
	 * Helper method which returns the primary key contained
	 * in the given Criteria object.
	 *
	 * @param      Criteria $criteria A Criteria.
	 * @return     ColumnMap|null If the Criteria object contains a primary
	 *		  key, or null if it doesn't.
	 * @throws     PropulsionException
	 */
	private static function getPrimaryKey(Criteria $criteria, ?string $tableName = null)
	{
		if ($tableName === null) {
			// Assume all the keys are for the same table.
			$keys = $criteria->keys();
			if (empty($keys)) {
				return null;
			}
			$tableName = $criteria->getTableName($keys[0]);
		}
		$table = $tableName;

		$pk = null;

		if (!empty($table)) {

			$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());

			$pks = $dbMap->getTable($table)->getPrimaryKeys();
			if (!empty($pks)) {
				$pk = array_shift($pks);
			}
		}
		return $pk;
	}

	/**
	 * Checks whether the Criteria needs to use column aliasing
	 * This is implemented in a service class rather than in Criteria itself
	 * in order to avoid doing the tests when it's not necessary (e.g. for SELECTs)
	 */
	public static function needsSelectAliases(Criteria $criteria): bool
	{
		$columnNames = array();
		foreach ($criteria->getSelectColumns() as $fullyQualifiedColumnName) {
			if ($pos = strrpos($fullyQualifiedColumnName, '.')) {
				$columnName = substr($fullyQualifiedColumnName, $pos);
				if (isset($columnNames[$columnName])) {
					// more than one column with the same name, so aliasing is required
					return true;
				}
				$columnNames[$columnName] = true;
			}
		}
		return false;
	}

	/**
	 * Method to create an SQL query based on values in a Criteria.
	 *
	 * This method creates only prepared statement SQL (using ? where values
	 * will go).  The second parameter ($params) stores the values that need
	 * to be set before the statement is executed.  The reason we do it this way
	 * is to let the PDO layer handle all escaping & value formatting.
	 *
	 * @param      Criteria $criteria Criteria for the SELECT query.
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> &$params Parameters that are to be replaced in prepared statement.
	 * @return     string
	 * @throws     PropulsionException Trouble creating the query string.
	 */
	public static function createSelectSql(Criteria $criteria, &$params)
	{
		if ($criteria->getCommonTableExpressions()) {
			return self::createCommonTableExpressionSql($criteria, $params);
		}

		if ($criteria->getSetOperations()) {
			return self::createSetOperationSql($criteria, $params);
		}

		if ($criteria->isCompiledQueryCacheEnabled() && !$criteria->hasSelectQueries()) {
			return self::createSelectSqlWithCompiledCache($criteria, $params);
		}

		return self::buildSelectSql($criteria, $params);
	}

	/**
	 * Consults/populates {@see \Propulsion\Cache\CompiledQueryCache} for a plain
	 * SELECT (no CTEs/set operations -- excluded by createSelectSql() already;
	 * no FROM-clause subqueries -- excluded by the caller's hasSelectQueries()
	 * check) before falling back to {@see buildSelectSql()}.
	 *
	 * On a cache hit, the SQL string itself is not rebuilt -- instead
	 * {@see collectSelectParams()} re-walks just the joins/WHERE/HAVING
	 * criterions to collect $params (cheap: no adapter calls, no identifier
	 * quoting, no FROM/JOIN/ORDER BY clause assembly), the same traversal
	 * order buildSelectSql() itself uses, so placeholder positions line up.
	 *
	 * @param      Criteria $criteria
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> &$params
	 * @return     string
	 * @throws     PropulsionException if the freshly-collected parameter count for a
	 *             cache hit doesn't match the count recorded when the entry was built --
	 *             see Criteria::setCompiledQueryCache()'s docblock for what this guards against.
	 */
	private static function createSelectSqlWithCompiledCache(Criteria $criteria, &$params): string
	{
		$key = $criteria->getCompiledQueryCacheKey();
		if ($key === null) {
			// Can't happen via createSelectSql()'s own isCompiledQueryCacheEnabled() guard,
			// but this method isn't itself guaranteed a non-null key by its signature.
			throw new PropulsionException('createSelectSqlWithCompiledCache() called without a compiled query cache key');
		}
		$cache = Propulsion::getServiceContainer()->getCompiledQueryCache();

		// The datasource is part of the entry's identity even though the caller
		// never supplies it. Two datasources can use different adapters, and the
		// adapter decides identifier quoting and how LIMIT/OFFSET is written, so
		// the same Criteria shape compiles to genuinely different SQL on each --
		// while the documented, recommended key (the calling method's own
		// __METHOD__) is identical for both. The paramCount guard would not
		// catch that: the shapes match, only the dialect differs. Namespacing
		// here rather than asking callers to remember keeps the caller's
		// contract "identify the query shape", which is the part they actually
		// know.
		$key = $criteria->getDbName() . '|' . $key;

		$cached = $cache->get($key);
		if ($cached !== null) {
			self::collectSelectParams($criteria, $params);
			if (count($params) !== $cached['paramCount']) {
				throw new PropulsionException(sprintf(
					"Compiled query cache key '%s' was reused for a query with a different number ".
					"of bound parameters (expected %d, got %d) -- a compiled query cache key must ".
					"uniquely identify one query shape; only bound parameter values may vary between ".
					"calls sharing a key. See Criteria::setCompiledQueryCache().",
					$key,
					$cached['paramCount'],
					count($params)
				));
			}
			return $cached['sql'];
		}

		$sql = self::buildSelectSql($criteria, $params);
		$cache->set($key, $sql, count($params));
		return $sql;
	}

	/**
	 * Re-walks a plain SELECT Criteria's joins, WHERE criterions, and HAVING
	 * criterion to append bound values to $params, in the exact same order
	 * buildSelectSql() itself produces them in -- without any of the SQL-text
	 * assembly (FROM/JOIN clause building, identifier quoting, ORDER BY/ignore
	 * -case resolution) that doesn't affect which/how-many values get bound.
	 * Reused as-is by createSelectSqlWithCompiledCache()'s cache-hit path.
	 *
	 * @param      Criteria $criteria
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> &$params
	 * @return     void
	 */
	private static function collectSelectParams(Criteria $criteria, array &$params): void
	{
		foreach ($criteria->getJoins() as $join) {
			$join->getClause($params);
		}

		foreach ($criteria->keys() as $key) {
			$criterion = $criteria->getCriterion($key);
			$sb = '';
			$criterion->appendPsTo($sb, $params);
		}

		$having = $criteria->getHaving();
		if ($having !== null) {
			$sb = '';
			$having->appendPsTo($sb, $params);
		}
	}

	/**
	 * The actual plain-SELECT SQL builder -- everything createSelectSql() used
	 * to do inline before the compiled-query cache fast path above was split out.
	 *
	 * @param      Criteria $criteria Criteria for the SELECT query.
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> &$params Parameters that are to be replaced in prepared statement.
	 * @return     string
	 * @throws     PropulsionException Trouble creating the query string.
	 */
	private static function buildSelectSql(Criteria $criteria, &$params)
	{
		$db = Propulsion::getDB($criteria->getDbName());
		$dbMap = Propulsion::getDatabaseMap($criteria->getDbName());

		$fromClause = array();
		$joinClause = array();
		$joinTables = array();
		$whereClause = array();
		$orderByClause = array();

		$orderBy = $criteria->getOrderByColumns();
		$groupBy = $criteria->getGroupByColumns();
		$ignoreCase = $criteria->isIgnoreCase();

		// get the first part of the SQL statement, the SELECT part
		$selectSql = $db->createSelectSqlPart($criteria, $fromClause);

		// Handle joins
		// joins with a null join type will be added to the FROM clause and the condition added to the WHERE clause.
		// joins of a specified type: the LEFT side will be added to the fromClause and the RIGHT to the joinClause
		foreach ($criteria->getJoins() as $join) {

			$join->setDB($db);

			// add 'em to the queues..
			if (!$fromClause) {
				$leftTableWithAlias = $join->getLeftTableWithAlias();
				if ($leftTableWithAlias === null) {
					throw new PropulsionException('Cannot build a join clause: the join has no left table name');
				}
				$fromClause[] = $leftTableWithAlias;
			}
			$rightTableWithAlias = $join->getRightTableWithAlias();
			if ($rightTableWithAlias === null) {
				throw new PropulsionException('Cannot build a join clause: the join has no right table name');
			}
			$joinTables[] = $rightTableWithAlias;
			$joinClause[] = $join->getClause($params);
		}

		// add the criteria to WHERE clause
		// this will also add the table names to the FROM clause if they are not already
		// included via a LEFT JOIN
		foreach ($criteria->keys() as $key) {

			$criterion = $criteria->getCriterion($key);
			$table = null;
			foreach ($criterion->getAttachedCriterion() as $attachedCriterion) {
				// A raw/custom-SQL criterion with no column at all (e.g. where('1=1'))
				// has no table either -- it contributes nothing to the FROM clause and
				// has no column to look up for the ignore-case check below.
				$tableName = $attachedCriterion->getTable();
				if ($tableName === null) {
					continue;
				}

				$table = $criteria->getTableForAlias($tableName);
				if ($table !== null) {
					$fromClause[] = $table . ' ' . $tableName;
				} else {
					$fromClause[] = $tableName;
					$table = $tableName;
				}

				$columnName = $attachedCriterion->getColumn();
				if ($columnName !== null
				&& ($criteria->isIgnoreCase() || $attachedCriterion->isIgnoreCase())
				&& $dbMap->getTable($table)->getColumn($columnName)->isText()) {
					$attachedCriterion->setIgnoreCase(true);
				}
			}

			$criterion->setDB($db);

			$sb = '';
			$criterion->appendPsTo($sb, $params);
			$whereClause[] = $sb;
		}

		// Unique from clause elements
		$fromClause = array_unique($fromClause);
		$fromClause = array_diff($fromClause, array(''));

		// A table should not appear in both the from and join clauses: if a join
		// already introduces a table (with or without alias) via "... JOIN table [alias] ON ...",
		// drop any from-clause entry that is the exact same table reference.
		//
		// This must be an *exact* match (same table name AND same alias, or lack thereof) rather
		// than a match on the base table name alone: for self-joins (e.g. a table joined to itself
		// via an alias), the from-clause entry for the unaliased anchor table and the join-clause
		// entry for the aliased joined table share the same base table name but are genuinely
		// different table references that both need to remain in the SQL. Stripping the from-clause
		// entry just because its base name matches an aliased join target used to blank out the
		// entire FROM clause for self-joins (bookstore_employee's Supervisor/Subordinate relation).
		if ($joinTables && $fromClause) {
			foreach ($fromClause as $fi => $ftable) {
				if (in_array($ftable, $joinTables)) {
					unset($fromClause[$fi]);
				}
			}
		}

		// Add the GROUP BY columns
		$groupByClause = $groupBy;

		$having = $criteria->getHaving();
		$havingString = null;
		if ($having !== null) {
			$sb = '';
			$having->appendPsTo($sb, $params);
			$havingString = $sb;
		}

		if (!empty($orderBy)) {

			foreach ($orderBy as $orderByColumn) {

				// Add function expression as-is.

				if (strpos($orderByColumn, '(') !== false) {
					$orderByClause[] = $orderByColumn;
					continue;
				}

				// Split orderByColumn (i.e. "table.column DESC")

				$dotPos = strrpos($orderByColumn, '.');

				if ($dotPos !== false) {
					$tableName = substr($orderByColumn, 0, $dotPos);
					$columnName = substr($orderByColumn, $dotPos + 1);
				} else {
					$tableName = '';
					$columnName = $orderByColumn;
				}

				$spacePos = strpos($columnName, ' ');

				if ($spacePos !== false) {
					$direction = substr($columnName, $spacePos);
					$columnName = substr($columnName, 0, $spacePos);
				}	else {
					$direction = '';
				}

				$tableAlias = $tableName;
				if ($aliasTableName = $criteria->getTableForAlias($tableName)) {
					$tableName = $aliasTableName;
				}

				$columnAlias = $columnName;
				if ($asColumnName = $criteria->getColumnForAs($columnName)) {
					$columnName = $asColumnName;
				}

				$column = $tableName ? $dbMap->getTable($tableName)->getColumn($columnName) : null;

				if ($criteria->isIgnoreCase() && $column && $column->isText()) {
					$ignoreCaseColumn = $db->ignoreCaseInOrderBy("$tableAlias.$columnAlias");
					$orderByClause[] =  $ignoreCaseColumn . $direction;
					$selectSql .= ', ' . $ignoreCaseColumn;
				} else {
					$orderByClause[] = $orderByColumn;
				}
			}
		}

		if (empty($fromClause)) {
			$primaryTableName = $criteria->getPrimaryTableName();
			if ($primaryTableName !== null) {
				$fromClause[] = $primaryTableName;
			}
		}

		// tables should not exist as alias of subQuery
		if ($criteria->hasSelectQueries()) {
			foreach ($fromClause as $key => $ftable) {
				if (strpos($ftable, ' ') !== false) {
					list($realtable, $tableName) = explode(' ', $ftable);
				} else {
					$tableName = $ftable;
				}
				if ($criteria->hasSelectQuery($tableName)) {
					unset($fromClause[$key]);
				}
			}
		}

		// from tables quoted if it is necessary. Join clauses are not quoted
		// here: each Join quotes its own right-hand table inside
		// Join::getClause(), using the adapter it was handed by setDB() above.
		// (This line used to also carry
		// `$joinClause = $joinClause ? $joinClause : array_map(...)`, inherited
		// from Propel 1 -- a no-op by construction, since the map only ever ran
		// on an already-empty array. It quoted nothing and was purely
		// misleading about where join quoting happens.)
		if ($db->useQuoteIdentifier()) {
			$fromClause = array_map(array($db, 'quoteIdentifierTable'), $fromClause);
		}

		// let the adapter splice in any per-table locking hints (e.g. MSSQL's
		// "WITH (UPDLOCK, ROWLOCK)") before the FROM/JOIN clauses are assembled
		if ($criteria->getLockMode() !== null) {
			$db->applyLockHints($fromClause, $joinClause, $criteria);
		}

		// add subQuery to From after adding quotes
		foreach ($criteria->getSelectQueries() as $subQueryAlias => $subQueryCriteria) {
			$fromClause[] = '(' . BasePeer::createSelectSql($subQueryCriteria, $params) . ') AS ' . $subQueryAlias;
		}

		// build from-clause
		$from = '';
		
		if (!empty($joinClause) && count($fromClause) > 1) {
			$from .= implode(" CROSS JOIN ", $fromClause);
		} else {
			$from .= implode(", ", $fromClause);
		}

		$from .= $joinClause ? ' ' . implode(' ', $joinClause) : '';

		// Build the SQL from the arrays we compiled
		$sql =  $selectSql
		." FROM "  . $from
		.($whereClause ? " WHERE ".implode(" AND ", $whereClause) : "")
		.($groupByClause ? " GROUP BY ".implode(",", $groupByClause) : "")
		.($havingString ? " HAVING ".$havingString : "")
		.($orderByClause ? " ORDER BY ".implode(",", $orderByClause) : "");

		// APPLY OFFSET & LIMIT to the query.
		if ($criteria->getLimit() || $criteria->getOffset()) {
			$db->applyLimit($sql, $criteria->getOffset(), $criteria->getLimit(), $criteria);
		}

		// APPLY pessimistic lock clause (e.g. "FOR UPDATE"), if requested.
		if ($criteria->getLockMode() !== null) {
			$db->applyLock($sql, $criteria);
		}

		return $sql;
	}

	/**
	 * Builds "WITH [RECURSIVE] name (col1, col2) AS (<cte query>), ... <criteria's own
	 * query>" for a Criteria with getCommonTableExpressions() set -- see Criteria::withCte().
	 * Each CTE's own query is built via a recursive createSelectSql() call sharing the
	 * same $params array (the same nesting mechanism addSelectQuery()'s FROM-clause
	 * subqueries already use), so a CTE query may itself carry set operations, further
	 * CTEs, or bound parameters with no special-casing here. The main body (this
	 * Criteria stripped of its own CTE list, which may still itself have set operations)
	 * is built via the ordinary createSelectSql() entry point, so it falls straight
	 * through to whichever of the plain-SELECT or set-operation branches applies -- the
	 * WITH clause is purely a textual prefix, unrelated to how the body itself is shaped.
	 *
	 * @param      Criteria $criteria
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params
	 * @return     string
	 */
	private static function createCommonTableExpressionSql(Criteria $criteria, &$params): string
	{
		$db = Propulsion::getDB($criteria->getDbName());

		$recursive = false;
		$cteParts = array();
		foreach ($criteria->getCommonTableExpressions() as $cte) {
			$recursive = $recursive || $cte['recursive'];
			$columnList = $cte['columns'] ? ' (' . implode(', ', $cte['columns']) . ')' : '';
			$cteParts[] = $cte['name'] . $columnList . ' AS (' . self::createSelectSql($cte['query'], $params) . ')';
		}

		$bodyCriteria = clone $criteria;
		$bodyCriteria->clearCommonTableExpressions();

		$recursiveKeyword = ($recursive && $db->supportsRecursiveCteKeyword()) ? 'RECURSIVE ' : '';

		return 'WITH ' . $recursiveKeyword . implode(', ', $cteParts) . ' ' . self::createSelectSql($bodyCriteria, $params);
	}

	/**
	 * Builds "(<criteria's own query>) UNION (<other's query>) ..." (or UNION ALL/
	 * INTERSECT/EXCEPT) for a Criteria with getSetOperations() set -- see
	 * Criteria::union(). $criteria's own ORDER BY/LIMIT/OFFSET/lock apply to the
	 * *combined* result (appended after every branch), not to its own branch alone --
	 * its own branch is built from a clone with those cleared. Each $other query keeps
	 * whatever ORDER BY/LIMIT/lock it has as part of its own (parenthesized) branch.
	 *
	 * @param      Criteria $criteria
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params
	 * @return     string
	 */
	private static function createSetOperationSql(Criteria $criteria, &$params): string
	{
		$db = Propulsion::getDB($criteria->getDbName());

		$bodyCriteria = clone $criteria;
		$bodyCriteria->clearSetOperations();
		$bodyCriteria->clearOrderByColumns();
		$bodyCriteria->setLimit(0);
		$bodyCriteria->setOffset(0);
		$bodyCriteria->clearLock();

		$sql = '(' . self::createSelectSql($bodyCriteria, $params) . ')';

		foreach ($criteria->getSetOperations() as $setOperation) {
			list($operator, $otherCriteria) = $setOperation;
			$sql .= ' ' . $operator . ' (' . self::createSelectSql($otherCriteria, $params) . ')';
		}

		$orderByColumns = $criteria->getOrderByColumns();
		if ($orderByColumns) {
			$sql .= ' ORDER BY ' . implode(',', $orderByColumns);
		}

		if ($criteria->getLimit() || $criteria->getOffset()) {
			$db->applyLimit($sql, $criteria->getOffset(), $criteria->getLimit(), $criteria);
		}

		if ($criteria->getLockMode() !== null) {
			$db->applyLock($sql, $criteria);
		}

		return $sql;
	}

	/**
	 * Builds the ":p1,:p2,..." bound-placeholder list an INSERT's VALUES clause
	 * needs for $qualifiedCols, in order, starting at $startParamIndex.
	 *
	 * Each placeholder passes through {@see DBAdapter::getColumnBindExpression()}
	 * when the adapter reports {@see DBAdapter::usesColumnSqlRewriting()}, so a
	 * column whose native type cannot accept the bound wire format directly
	 * (MariaDB's `VECTOR(n)`, a real geometry column) gets its conversion
	 * function spliced in here -- one placeholder still binds one value either
	 * way, so nothing downstream in the params array shifts.
	 *
	 * Not applied to the `MERGE`-shaped upsert path (MSSQL/Oracle), which
	 * builds its own placeholder list inside
	 * {@see DBAdapter::getMergeUpsertSql()}; neither of those adapters rewrites
	 * any column today, and the hook can be threaded through that shape if one
	 * ever does.
	 *
	 * @param      array<int, string> $qualifiedCols Fully-qualified column names.
	 * @return     string
	 */
	private static function buildBindPlaceholderList(array $qualifiedCols, DBAdapter $db, string $dbName, int $startParamIndex): string
	{
		$rewrites = $db->usesColumnSqlRewriting();
		$placeholders = array();
		$p = $startParamIndex;
		foreach ($qualifiedCols as $qualifiedCol) {
			$placeholder = ':p' . $p++;
			if ($rewrites) {
				$dotPos = strrpos($qualifiedCol, '.');
				$placeholder = ColumnSqlRewriter::bind(
					$db,
					$dbName,
					$dotPos === false ? null : substr($qualifiedCol, 0, $dotPos),
					$dotPos === false ? null : substr($qualifiedCol, $dotPos + 1),
					$placeholder
				);
			}
			$placeholders[] = $placeholder;
		}

		return implode(',', $placeholders);
	}

	/**
	 * Builds a "col1=:p1, col2 = <raw expr>, ..." SET-clause fragment (no leading
	 * "SET " and no trailing comma) for $columns from $values, starting bound-parameter
	 * numbering at $startParamIndex. A column whose comparison is Criteria::CUSTOM_EQUAL
	 * carries a raw SQL expression instead of a literal value -- see ColumnExpression --
	 * as either a plain string (interpolated as-is, no bound parameter at all) or an
	 * array('raw' => '...possibly containing a single "?"...', 'value' => $boundValue).
	 *
	 * Mutates $values in place (put()/remove()) the same way callers have always
	 * needed to, so a subsequent buildParams() call against the same $columns/$values
	 * picks up exactly the bound values this fragment's placeholders reference --
	 * no more, no less. Shared between doUpdate() and doUpsert().
	 *
	 * @param      array<int, string> $columns Fully-qualified column names.
	 * @param      Criteria $values
	 * @param      DBAdapter $db
	 * @param      int $startParamIndex
	 * @return     array{0: string, 1: int} [SET-clause fragment, next unused param index]
	 */
	private static function buildSetClause(array $columns, Criteria $values, DBAdapter $db, int $startParamIndex): array
	{
		$sql = '';
		$p = $startParamIndex;
		$rewrites = $db->usesColumnSqlRewriting();
		foreach ($columns as $col) {
			$dotPos = strrpos($col, '.');
			$updateColumnName = substr($col, $dotPos + 1);
			// add identifiers for the actual database?
			if ($db->useQuoteIdentifier()) {
				$updateColumnName = $db->quoteIdentifier($updateColumnName);
			}
			if ($values->getComparison($col) != Criteria::CUSTOM_EQUAL) {
				// Same conversion-function wrapping the INSERT value list gets
				// -- see buildBindPlaceholderList(). A raw CUSTOM_EQUAL
				// expression (the branch below) is deliberately left alone:
				// the caller wrote that SQL themselves and owns its shape.
				$placeholder = ':p'.$p++;
				if ($rewrites) {
					$placeholder = ColumnSqlRewriter::bind(
						$db,
						$values->getDbName(),
						$dotPos === false ? null : substr($col, 0, $dotPos),
						$dotPos === false ? null : substr($col, $dotPos + 1),
						$placeholder
					);
				}
				$sql .= $updateColumnName . '=' . $placeholder . ', ';
			} else {
				$param = $values->get($col);
				$sql .= $updateColumnName . ' = ';
				if (is_array($param)) {
					$hasPlaceholder = false;
					if (isset($param['raw'])) {
						$raw = $param['raw'];
						if (!is_string($raw)) {
							throw new PropulsionException("Raw update expression for column '$col' must be a string.");
						}
						// Exactly one bound value is available for this column
						// ($param['value']), so more than one "?" would
						// allocate placeholders nothing binds to and silently
						// shift every :pN after it onto the wrong value. Caught
						// here rather than left to produce a mis-bound
						// statement or an opaque driver error.
						$placeholderCount = substr_count($raw, '?');
						if ($placeholderCount > 1) {
							throw new PropulsionException(
								"Raw update expression for column '$col' has $placeholderCount \"?\" placeholders, "
								. 'but only one bound value can be supplied for a column. Inline the other values into '
								. 'the expression, or split them across separate columns.'
							);
						}
						$rawcvt = '';
						// parse the $params['raw'] for ? chars
						for($r=0,$len=strlen($raw); $r < $len; $r++) {
							if ($raw[$r] == '?') {
								$rawcvt .= ':p'.$p++;
								$hasPlaceholder = true;
							} else {
								$rawcvt .= $raw[$r];
							}
						}
						$sql .= $rawcvt . ', ';
					} else {
						$sql .= ':p'.$p++.', ';
						$hasPlaceholder = true;
					}
					if ($hasPlaceholder) {
						if (!isset($param['value'])) {
							throw new PropulsionException("Raw update expression for column '$col' has a \"?\" placeholder but no 'value' to bind to it.");
						}
						$values->put($col, $param['value']);
					} else {
						// No "?" placeholder in the raw expression means there is no
						// bound parameter for this column at all: remove it so
						// buildParams() below doesn't emit a param with nothing in the
						// SQL for it to bind to, which would misalign every :pN placeholder
						// that follows.
						$values->remove($col);
					}
				} else {
					if (!is_string($param)) {
						throw new PropulsionException("Raw update expression for column '$col' must be a string.");
					}
					$values->remove($col);
					$sql .= $param . ', ';
				}
			}
		}
		return array(substr($sql, 0, -2), $p);
	}

	/**
	 * Builds a params array, like the kind populated by Criterion::appendPsTo().
	 * This is useful for building an array even when it is not using the appendPsTo() method.
	 * @param      array<int, string> $columns
	 * @param      Criteria $values
	 * @return     array<int, array<string, mixed>> params array('column' => ..., 'table' => ..., 'value' => ...)
	 */
	/**
	 * @param array<int, string> $columns
	 * @return array<int, array{table: string|null, column: string|null, value: mixed}>
	 */
	private static function buildParams(array $columns, Criteria $values): array
	{
		$params = array();
		foreach ($columns as $key) {
			if ($values->containsKey($key)) {
				$crit = $values->getCriterion($key);
				$params[] = array('column' => $crit->getColumn(), 'table' => $crit->getTable(), 'value' => $crit->getValue());
			}
		}
		return $params;
	}

	/**
	 * Unqualifies ("table.column" -> "column") and quotes-if-necessary each of
	 * $columns, the shape doUpsert()'s $conflictColumns/getUpsertSql() and
	 * doUpdate()/doDelete()'s $returningColumns/getUpdateReturningSql()/
	 * getDeleteReturningSql() all need their column-name lists in.
	 *
	 * @param      array<int,string> $columns Fully-qualified column names.
	 * @param      DBAdapter $db
	 * @return     array<int,string>
	 */
	private static function unqualifyAndQuoteColumnNames(array $columns, DBAdapter $db): array
	{
		$names = array();
		foreach ($columns as $col) {
			$name = substr($col, strrpos($col, '.') + 1);
			if ($db->useQuoteIdentifier()) {
				$name = $db->quoteIdentifier($name);
			}
			$names[] = $name;
		}
		return $names;
	}

	/**
	 * This function searches for the given validator $name under propel/validator/$name.php,
	 * imports and caches it.
	 *
	 * @param      string $classname The dot-path name of class (e.g. myapp.propel.MyValidator)
	 * @return     BasicValidator|null The validator instance, or null if not able to instantiate validator class (and error will be logged in this case)
	 */
	public static function getValidator($classname)
	{
		try {
			$v = isset(self::$validatorMap[$classname]) ? self::$validatorMap[$classname] : null;
			if ($v === null) {
				$cls = Propulsion::importClass($classname);
				$v = new $cls();
				if (!$v instanceof BasicValidator) {
					throw new PropulsionException("Configured validator class \"$cls\" is not a " . BasicValidator::class);
				}
				self::$validatorMap[$classname] = $v;
			}
			return $v;
		} catch (Exception $e) {
			Propulsion::log("BasePeer::getValidator(): failed trying to instantiate " . $classname . ": ".$e->getMessage(), Propulsion::LOG_ERR);
		}
		return null;
	}

}
