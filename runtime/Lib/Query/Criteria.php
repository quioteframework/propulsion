<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

/**
 * This is a utility class for holding criteria information for a query.
 *
 * BasePeer constructs SQL statements based on the values in this class.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Kaspars Jaudzems <kaspars.jaudzems@inbox.lv> (Propel)
 * @author     Frank Y. Kim <frank.kim@clearink.com> (Torque)
 * @author     John D. McNally <jmcnally@collab.net> (Torque)
 * @author     Brett McLaughlin <bmclaugh@algx.net> (Torque)
 * @author     Eric Dobbs <eric@dobbse.net> (Torque)
 * @author     Henning P. Schmiedehausen <hps@intermeta.de> (Torque)
 * @author     Sam Joseph <sam@neurogrid.com> (Torque)
 * @version    $Revision$
 */

 use Propulsion\Propulsion;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\Util\BasePeer;
 use Propulsion\Util\PropulsionConditionalProxy;
 use \Exception;
/**
 * @implements \IteratorAggregate<string, Criterion>
 */
class Criteria implements \IteratorAggregate
{

	/** Comparison type. */
	const EQUAL = "=";

	/** Comparison type. */
	const NOT_EQUAL = "<>";

	/** Comparison type. */
	const ALT_NOT_EQUAL = "!=";

	/** Comparison type. */
	const GREATER_THAN = ">";

	/** Comparison type. */
	const LESS_THAN = "<";

	/** Comparison type. */
	const GREATER_EQUAL = ">=";

	/** Comparison type. */
	const LESS_EQUAL = "<=";

	/** Comparison type. */
	const LIKE = " LIKE ";

	/** Comparison type. */
	const NOT_LIKE = " NOT LIKE ";

	/** Comparison for array column types */
	const CONTAINS_ALL = "CONTAINS_ALL";

	/** Comparison for array column types */
	const CONTAINS_SOME = "CONTAINS_SOME";

	/** Comparison for array column types */
	const CONTAINS_NONE = "CONTAINS_NONE";

	/** PostgreSQL comparison type */
	const ILIKE = " ILIKE ";

	/** PostgreSQL comparison type */
	const NOT_ILIKE = " NOT ILIKE ";

	/** Comparison type. */
	const CUSTOM = "CUSTOM";

	/** Comparison type for update */
	const CUSTOM_EQUAL = "CUSTOM_EQUAL";

	/** Comparison type. */
	const DISTINCT = "DISTINCT";

	/** Comparison type. */
	const IN = " IN ";

	/** Comparison type. */
	const NOT_IN = " NOT IN ";

	/** Comparison type for a correlated-subquery filter -- see addExistsQuery(). */
	const EXISTS = "EXISTS ";

	/** Comparison type for a correlated-subquery filter -- see addExistsQuery(). */
	const NOT_EXISTS = "NOT EXISTS ";

	/** Set-operation type -- see union(). */
	const UNION = "UNION";

	/** Set-operation type -- see unionAll(). */
	const UNION_ALL = "UNION ALL";

	/** Set-operation type -- see intersect(). */
	const INTERSECT = "INTERSECT";

	/** Set-operation type -- see except(). */
	const EXCEPT = "EXCEPT";

	/** Comparison type. */
	const ALL = "ALL";

	/** Comparison type. */
	const JOIN = "JOIN";

	/** Binary math operator: AND */
	const BINARY_AND = "&";

	/** Binary math operator: OR */
	const BINARY_OR = "|";

	/** "Order by" qualifier - ascending */
	const ASC = "ASC";

	/** "Order by" qualifier - descending */
	const DESC = "DESC";

	/** "IS NULL" null comparison */
	const ISNULL = " IS NULL ";

	/** "IS NOT NULL" null comparison */
	const ISNOTNULL = " IS NOT NULL ";

	/** "CURRENT_DATE" ANSI SQL function */
	const CURRENT_DATE = "CURRENT_DATE";

	/** "CURRENT_TIME" ANSI SQL function */
	const CURRENT_TIME = "CURRENT_TIME";

	/** "CURRENT_TIMESTAMP" ANSI SQL function */
	const CURRENT_TIMESTAMP = "CURRENT_TIMESTAMP";

	/** "LEFT JOIN" SQL statement */
	const LEFT_JOIN = "LEFT JOIN";

	/** "RIGHT JOIN" SQL statement */
	const RIGHT_JOIN = "RIGHT JOIN";

	/** "INNER JOIN" SQL statement */
	const INNER_JOIN = "INNER JOIN";

	/** logical OR operator */
	const LOGICAL_OR = "OR";

	/** logical AND operator */
	const LOGICAL_AND = "AND";

	/** "SELECT ... FOR UPDATE" pessimistic write lock */
	const LOCK_FOR_UPDATE = "FOR UPDATE";

	/** "SELECT ... FOR SHARE" pessimistic read lock */
	const LOCK_FOR_SHARE = "FOR SHARE";

	protected bool $ignoreCase = false;
	protected bool $singleRecord = false;

	/**
	 * Whether a result formatted for this query may be served from (and
	 * stored into) the current request's {@see \Propulsion\Cache\QueryResultCache}.
	 * Opt-in and false by default: an uncached read is always correct, so
	 * only queries the caller knows are safe to cache should turn this on.
	 */
	protected bool $queryCacheEnabled = false;

	/**
	 * Per-query TTL override in seconds for the shared cache tier, or null to
	 * use the configured default. See {@see setQueryCache()}.
	 */
	protected ?int $queryCacheTtl = null;

	/**
	 * Whether this query's result may reach the process-shared cache tier.
	 * See {@see isQueryCacheShared()} for when to turn it off.
	 */
	protected bool $queryCacheShared = true;

	/**
	 * Shape key for the process-wide {@see \Propulsion\Cache\CompiledQueryCache},
	 * or null (the default) if compiled-query caching is off for this Criteria.
	 * Unlike $queryCacheEnabled this is a caller-supplied string, not a bool --
	 * see setCompiledQueryCache() for what the caller is responsible for.
	 */
	protected ?string $compiledQueryCacheKey = null;

	/**
	 * Storage of select data. Collection of column names.
	 * @var        array<int, string>
	 */
	protected $selectColumns = array();

	/**
	 * Storage of aliased select data. Collection of column names.
	 * @var        array<string, string>
	 */
	protected $asColumns = array();

	/**
	 * Storage of select modifiers data. Collection of modifier names.
	 * @var        array<int, string>
	 */
	protected $selectModifiers = array();

	/**
	 * Storage of conditions data. Collection of Criterion objects.
	 * @var        array<string, Criterion>
	 */
	protected $map = array();

	/**
	 * Storage of ordering data. Collection of column names.
	 * @var        array<int, string>
	 */
	protected $orderByColumns = array();

	/**
	 * Storage of grouping data. Collection of column names.
	 * @var        array<int, string>
	 */
	protected $groupByColumns = array();

	/**
	 * Storage of having data.
	 * @var        Criterion|null
	 */
	protected $having = null;

	/**
	 * Storage of set operations (UNION/UNION ALL/INTERSECT/EXCEPT) combined with this
	 * Criteria's own query. See union().
	 * @var        array<int, array{0: string, 1: Criteria}>
	 */
	protected $setOperations = array();

	/**
	 * Storage of join data. colleciton of Join objects.
	 * @var        array<int|string, Join>
	 */
	protected $joins = array();

	/**
	 * @var        array<string, Criteria>
	 */
	protected $selectQueries = array();

	/**
	 * Storage of common table expressions (WITH ... AS (...)) prefixed onto this
	 * Criteria's own query. See withCte().
	 * @var        array<int, array{name: string, query: Criteria, columns: array<int, string>, recursive: bool}>
	 */
	protected $commonTableExpressions = array();

	/**
	 * The name of the database.
	 * @var        string
	 */
	protected $dbName;

	/**
	 * The primary table for this Criteria.
	 * Useful in cases where there are no select or where
	 * columns. Null until setPrimaryTableName() is called -- not every
	 * Criteria/ModelCriteria construction path sets it.
	 * @var        string|null
	 */
	protected $primaryTableName;

	/**
	 * The name of the database as given in the contructor.
	 * @var        string|null
	 */
	protected $originalDbName;

	/**
	 * To limit the number of rows to return.  <code>0</code> means return all
	 * rows.
	 */
	protected int $limit = 0;

	/** To start the results at a row other than the first one. */
	protected int $offset = 0;

	/**
	 * Pessimistic lock mode for this query: null (no lock), Criteria::LOCK_FOR_UPDATE
	 * or Criteria::LOCK_FOR_SHARE.
	 */
	protected ?string $lockMode = null;

	/** Whether the lock should fail immediately instead of blocking (NOWAIT). */
	protected bool $lockNoWait = false;

	/** Whether locked-but-busy rows should be silently skipped (SKIP LOCKED). */
	protected bool $lockSkipLocked = false;

	/**
	 * Comment to add to the SQL query
	 * @var        string|null
	 */
	protected $queryComment;

	// flag to note that the criteria involves a blob.
	protected mixed $blobFlag = null;

	/**
	 * @var        array<string, string|null>
	 */
	protected $aliases = array();

	protected bool $useTransaction = false;

	/**
	 * Storage for Criterions expected to be combined
	 * @var        array<string, Criterion>
	 */
	protected $namedCriterions = array();

	/**
	 * Default operator for combination of criterions
	 * @see        addUsingOperator
	 * @var        string Criteria::LOGICAL_AND or Criteria::LOGICAL_OR
	 */
	protected $defaultCombineOperator = Criteria::LOGICAL_AND;

	// flags for boolean functions
	protected bool $isInIf = false;
	protected bool $wasTrue = false;

	/**
	 * Creates a new instance with the default capacity which corresponds to
	 * the specified database.
	 *
	 * @param      string $dbName The dabase name.
	 */
	public function __construct($dbName = null)
	{
		$this->setDbName($dbName);
		$this->originalDbName = $dbName;
	}

	/**
	 * Implementing SPL IteratorAggregate interface.  This allows
	 * you to foreach () over a Criteria object.
	 */
	public function getIterator(): \Traversable
	{
		return new CriterionIterator($this);
	}

	/**
	 * Get the criteria map, i.e. the array of Criterions
	 * @return     array<string, Criterion>
	 */
	public function getMap()
	{
		return $this->map;
	}

	/**
	 * Brings this criteria back to its initial state, so that it
	 * can be reused as if it was new. Except if the criteria has grown in
	 * capacity, it is left at the current capacity.
	 * @return     void
	 */
	public function clear()
	{
		$this->map = array();
		$this->namedCriterions = array();
		$this->ignoreCase = false;
		$this->singleRecord = false;
		$this->queryCacheEnabled = false;
		// Reset alongside queryCacheEnabled: setQueryCache() sets all three
		// together, so leaving the TTL/shared flags behind would silently apply
		// them to whatever the reused object is opted into next.
		$this->queryCacheTtl = null;
		$this->queryCacheShared = true;
		$this->compiledQueryCacheKey = null;
		$this->primaryTableName = null;
		$this->queryComment = null;
		// _or() flips this and it is only cleared once the next condition
		// consumes it, so a Criteria cleared mid-expression would have OR'ed
		// its first new condition onto nothing.
		$this->defaultCombineOperator = Criteria::LOGICAL_AND;
		$this->selectModifiers = array();
		$this->selectColumns = array();
		$this->orderByColumns = array();
		$this->groupByColumns = array();
		$this->having = null;
		$this->asColumns = array();
		$this->joins = array();
		$this->selectQueries = array();
		$this->setOperations = array();
		$this->commonTableExpressions = array();
		$this->setDbName($this->originalDbName);
		$this->offset = 0;
		$this->limit = 0;
		$this->lockMode = null;
		$this->lockNoWait = false;
		$this->lockSkipLocked = false;
		$this->blobFlag = null;
		$this->aliases = array();
		$this->useTransaction = false;
		$this->isInIf = false;
		$this->wasTrue = false;
	}

	/**
	 * Add an AS clause to the select columns. Usage:
	 *
	 * <code>
	 * Criteria myCrit = new Criteria();
	 * myCrit->addAsColumn("alias", "ALIAS(".MyPeer::ID.")");
	 * </code>
	 *
	 * @param      string $name Wanted Name of the column (alias).
	 * @param      string $clause SQL clause to select from the table
	 *
	 * If the name already exists, it is replaced by the new clause.
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addAsColumn($name, $clause)
	{
		$this->asColumns[$name] = $clause;
		return $this;
	}

	/**
	 * Get the column aliases.
	 *
	 * @return     array<string, string> An assoc array which map the column alias names
	 * to the alias clauses.
	 */
	public function getAsColumns()
	{
		return $this->asColumns;
	}

	/**
	 * Returns the column name associated with an alias (AS-column).
	 *
	 * @param      string $as
	 * @return     string|null
	 */
	public function getColumnForAs($as): string|null
	{
		if (isset($this->asColumns[$as])) {
			return $this->asColumns[$as];
		}
		return null;
	}

	/**
	 * Allows one to specify an alias for a table that can
	 * be used in various parts of the SQL.
	 *
	 * @param      string $alias
	 * @param      string|null $table Table name; callers may pass a TableMap::getName() result,
	 *              which is nullable in that class's own contract even though it is always set
	 *              by the time a real, initialized TableMap is reachable through a join/relation.
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addAlias($alias, $table)
	{
		$this->aliases[$alias] = $table;

		return $this;
	}

	/**
	 * Remove an alias for a table (useful when merging Criterias).
	 *
	 * @param      string $alias
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function removeAlias($alias)
	{
		unset($this->aliases[$alias]);

		return $this;
	}

	/**
	 * Returns the aliases for this Criteria
	 *
	 * @return     array<string, string|null>
	 */
	public function getAliases()
	{
		return $this->aliases;
	}

	/**
	 * Returns the table name associated with an alias.
	 *
	 * @param      string|null $alias
	 * @return     string|null $string
	 */
	public function getTableForAlias($alias): string|null
	{
		if ($alias !== null && isset($this->aliases[$alias])) {
			return $this->aliases[$alias];
		}
		return null;
	}

	/**
	 * Returns the table name and alias based on a table alias or name.
	 * Use this method to get the details of a table name that comes in a clause,
	 * which can be either a table name or an alias name.
	 *
	 * @param      string $tableAliasOrName
	 * @return     array{0: string, 1: ?string} array($tableName, $tableAlias)
	 */
	public function getTableNameAndAlias($tableAliasOrName)
	{
		if (isset($this->aliases[$tableAliasOrName])) {
			return array($this->aliases[$tableAliasOrName], $tableAliasOrName);
		} else {
			return array($tableAliasOrName, null);
		}
	}

	/**
	 * Get the keys of the criteria map, i.e. the list of columns bearing a condition
	 * <code>
	 * print_r($c->keys());
	 *  => array('book.price', 'book.title', 'author.first_name')
	 * </code>
	 *
	 * @return     array<int, string>
	 */
	public function keys()
	{
		return array_keys($this->map);
	}

	/**
	 * Does this Criteria object contain the specified key?
	 *
	 * @param      string $column [table.]column
	 * @return     boolean True if this Criteria object contain the specified key.
	 */
	public function containsKey($column)
	{
		// must use array_key_exists() because the key could
		// exist but have a NULL value (that'd be valid).
		return array_key_exists($column, $this->map);
	}

	/**
	 * Does this Criteria object contain the specified key and does it have a value set for the key
	 *
	 * @param      string $column [table.]column
	 * @return     boolean True if this Criteria object contain the specified key and a value for that key
	 */
	public function keyContainsValue($column)
	{
		// must use array_key_exists() because the key could
		// exist but have a NULL value (that'd be valid).
		return (array_key_exists($column, $this->map) && ($this->map[$column]->getValue() !== null) );
	}

	/**
	 * Whether this Criteria has any where columns.
	 *
	 * This counts conditions added with the add() method.
	 *
	 * @return     boolean
	 * @see        add()
	 */
	public function hasWhereClause()
	{
		return !empty($this->map);
	}

	/**
	 * Will force the sql represented by this criteria to be executed within
	 * a transaction.  This is here primarily to support the oid type in
	 * postgresql.  Though it can be used to require any single sql statement
	 * to use a transaction.
	 * @return     void
	 */
	public function setUseTransaction(bool $v): void
	{
		$this->useTransaction = $v;
	}

	/**
	 * Whether the sql command specified by this criteria must be wrapped
	 * in a transaction.
	 *
	 * @return     boolean
	 */
	public function isUseTransaction()
	{
		return $this->useTransaction;
	}

	/**
	 * Method to return criteria related to columns in a table.
	 *
	 * Make sure you call containsKey($column) prior to calling this method,
	 * since no check on the existence of the $column is made in this method.
	 *
	 * @param      string $column Column name.
	 * @return     Criterion A Criterion object.
	 */
	public function getCriterion($column)
	{
		return $this->map[$column];
	}

	/**
	 * Method to return the latest Criterion in a table.
	 *
	 * @return     Criterion|null A Criterion or null no Criterion is added.
	 */
	public function getLastCriterion()
	{
		if($cnt = count($this->map)) {
			$map = array_values($this->map);
			return $map[$cnt - 1];
		}
		return null;
	}

	/**
	 * Method to return criterion that is not added automatically
	 * to this Criteria.  This can be used to chain the
	 * Criterions to form a more complex where clause.
	 *
	 * @param      string $column Full name of column (for example TABLE.COLUMN).
	 * @param      mixed $value
	 * @param      string $comparison
	 * @return     Criterion
	 */
	public function getNewCriterion($column, $value = null, $comparison = self::EQUAL)
	{
		return new Criterion($this, $column, $value, $comparison);
	}

	/**
	 * Adds a "WHERE EXISTS (<subquery>)" (or "WHERE NOT EXISTS (...)") correlated-subquery
	 * condition. Unlike addSelectQuery(), which nests a Criteria in the FROM clause, this
	 * nests it in the WHERE clause -- $subQuery is expected to correlate itself to this
	 * Criteria's own tables (e.g. via a raw condition added to $subQuery referencing this
	 * Criteria's table/alias by name).
	 *
	 * @param      Criteria $subQuery
	 * @param      bool $negate Use NOT EXISTS instead of EXISTS.
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function addExistsQuery(Criteria $subQuery, bool $negate = false)
	{
		$criterion = new Criterion($this, null, $subQuery, $negate ? Criteria::NOT_EXISTS : Criteria::EXISTS);
		return $this->addUsingOperator($criterion);
	}

	/**
	 * Adds a "WHERE column IN (<subquery>)" (or "WHERE column NOT IN (...)") correlated- or
	 * uncorrelated-subquery condition.
	 *
	 * @param      string $column Fully-qualified column name (e.g. "book.PUBLISHER_ID").
	 * @param      Criteria $subQuery Expected to select exactly one column.
	 * @param      bool $negate Use NOT IN instead of IN.
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function addInQuery($column, Criteria $subQuery, bool $negate = false)
	{
		$criterion = new Criterion($this, $column, $subQuery, $negate ? Criteria::NOT_IN : Criteria::IN);
		return $this->addUsingOperator($criterion);
	}

	/**
	 * Combines this query's result set with $other's via SQL UNION (deduplicating rows) --
	 * "(<this query>) UNION (<$other's query>)". Chainable: subsequent orderBy()/limit()
	 * calls apply to the *combined* result set, not to either branch individually -- see
	 * BasePeer::createSelectSql()'s handling of getSetOperations() for details. Composable:
	 * $other may itself already have set operations of its own (chained unions).
	 *
	 * @param      Criteria $other
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function union(Criteria $other)
	{
		$this->setOperations[] = array(Criteria::UNION, $other);
		return $this;
	}

	/**
	 * Like union(), but via SQL UNION ALL (keeps duplicate rows, and is cheaper -- no
	 * dedup pass required).
	 *
	 * @param      Criteria $other
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function unionAll(Criteria $other)
	{
		$this->setOperations[] = array(Criteria::UNION_ALL, $other);
		return $this;
	}

	/**
	 * Like union(), but via SQL INTERSECT (only rows present in both result sets).
	 *
	 * @param      Criteria $other
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function intersect(Criteria $other)
	{
		$this->setOperations[] = array(Criteria::INTERSECT, $other);
		return $this;
	}

	/**
	 * Like union(), but via SQL EXCEPT (rows in this query's result set that are not
	 * also in $other's).
	 *
	 * @param      Criteria $other
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function except(Criteria $other)
	{
		$this->setOperations[] = array(Criteria::EXCEPT, $other);
		return $this;
	}

	/**
	 * @return     array<int, array{0: string, 1: Criteria}> Set operations combined with
	 *             this query, in the order they were added.
	 */
	public function getSetOperations(): array
	{
		return $this->setOperations;
	}

	/**
	 * Removes any set operations previously added via union()/unionAll()/intersect()/except().
	 *
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function clearSetOperations()
	{
		$this->setOperations = array();
		return $this;
	}

	/**
	 * Prefixes this query with a "WITH name AS (<$query>)" common table expression --
	 * or "WITH RECURSIVE name AS (...)" when $recursive is true. $name can then be
	 * referenced anywhere a table name is otherwise expected in this Criteria's own
	 * query (setPrimaryTableName(), addJoin(), a plain "name.column" in a WHERE/select
	 * column) -- CTE resolution is purely name-based, the same way a real table name
	 * is, so no separate "reference a CTE" API is needed.
	 *
	 * A recursive CTE's own $query is typically built as an anchor branch UNION ALL a
	 * recursive branch that joins back to $name by that same name (see union()/
	 * unionAll()); $recursive requires an explicit $columns list because the
	 * self-reference inside $query's own recursive branch needs $name's column names
	 * to exist before $query itself can be analyzed -- some platforms (Postgres) can
	 * infer them from the anchor branch's own SELECT list, but Oracle cannot, so an
	 * explicit list is required uniformly here rather than only on the one platform
	 * that strictly needs it.
	 *
	 * Multiple withCte() calls are cumulative and chainable: "WITH a AS (...), b AS (...)
	 * SELECT ...". "RECURSIVE" (on platforms that use the keyword at all -- see
	 * DBAdapter::supportsRecursiveCteKeyword()) is emitted once for the whole WITH
	 * clause if *any* of them is recursive, per standard SQL.
	 *
	 * @param      string $name
	 * @param      Criteria $query
	 * @param      array<int, string> $columns Explicit column list for "name (col1, col2)
	 *                                         AS (...)" -- required when $recursive is true.
	 * @param      bool $recursive
	 * @return     static A modified Criteria object (for fluent API).
	 * @throws     PropulsionException If $recursive is true and $columns is empty.
	 */
	public function withCte(string $name, Criteria $query, array $columns = array(), bool $recursive = false)
	{
		if ($recursive && !$columns) {
			throw new PropulsionException("withCte(): a recursive common table expression requires an explicit \$columns list (needed by the self-reference inside its own recursive branch, and by platforms, e.g. Oracle, that cannot infer it from the anchor branch)");
		}
		$this->commonTableExpressions[] = array(
			'name' => $name,
			'query' => $query,
			'columns' => $columns,
			'recursive' => $recursive,
		);
		return $this;
	}

	/**
	 * @return     array<int, array{name: string, query: Criteria, columns: array<int, string>, recursive: bool}>
	 *             Common table expressions combined with this query, in the order they were added.
	 */
	public function getCommonTableExpressions(): array
	{
		return $this->commonTableExpressions;
	}

	/**
	 * Removes any common table expressions previously added via withCte().
	 *
	 * @return     static A modified Criteria object (for fluent API).
	 */
	public function clearCommonTableExpressions()
	{
		$this->commonTableExpressions = array();
		return $this;
	}

	/**
	 * Method to return a String table name.
	 *
	 * @param      string $name Name of the key.
	 * @return     string|null The value of the object at key.
	 */
	public function getColumnName($name)
	{
		if (isset($this->map[$name])) {
			return $this->map[$name]->getColumn();
		}
		return null;
	}

	/**
	 * Shortcut method to get an array of columns indexed by table.
	 * <code>
	 * print_r($c->getTablesColumns());
	 *  => array(
	 *       'book'   => array('book.price', 'book.title'),
	 *       'author' => array('author.first_name')
	 *     )
	 * </code>
	 *
	 * @return     array<string, array<int, string>> array(table => array(table.column1, table.column2))
	 */
	public function getTablesColumns()
	{
		$tables = array();
		foreach ($this->keys() as $key) {
			$dotPos = strrpos($key, '.');
			$tableName = substr($key, 0, $dotPos === false ? 0 : $dotPos);
			$tables[$tableName][] = $key;
		}
		return $tables;
	}

	/**
	 * Method to return a comparison String.
	 *
	 * @param      string $key String name of the key.
	 * @return     string|null A String with the value of the object at key.
	 */
	public function getComparison($key)
	{
		if ( isset ( $this->map[$key] ) ) {
			return $this->map[$key]->getComparison();
		}
		return null;
	}

	/**
	 * Get the Database(Map) name.
	 *
	 * @return     string A String with the Database(Map) name.
	 */
	public function getDbName()
	{
		return $this->dbName;
	}

	/**
	 * Set the DatabaseMap name.  If <code>null</code> is supplied, uses value
	 * provided by <code>Propulsion::getDefaultDB()</code>.
	 *
	 * @param      string $dbName The Database (Map) name.
	 * @return     void
	 */
	public function setDbName($dbName = null)
	{
		$this->dbName = ($dbName === null ? Propulsion::getDefaultDB() : $dbName);
	}

	/**
	 * Get the primary table for this Criteria.
	 *
	 * This is useful for cases where a Criteria may not contain
	 * any SELECT columns or WHERE columns.  This must be explicitly
	 * set, of course, in order to be useful.
	 *
	 * @return     string|null
	 */
	public function getPrimaryTableName() : ?string
	{
		return $this->primaryTableName;
	}

	/**
	 * Sets the primary table for this Criteria.
	 *
	 * This is useful for cases where a Criteria may not contain
	 * any SELECT columns or WHERE columns.  This must be explicitly
	 * set, of course, in order to be useful.
	 *
	 * @param      string $tableName
	 */
	public function setPrimaryTableName(string $tableName): void
	{
		$this->primaryTableName = $tableName;
	}

	/**
	 * Method to return a String table name.
	 *
	 * @param      string $name The name of the key.
	 * @return     string|null The value of table for criterion at key.
	 */
	public function getTableName($name)
	{
		if (isset($this->map[$name])) {
			return $this->map[$name]->getTable();
		}
		return null;
	}

	/**
	 * Method to return the value that was added to Criteria.
	 *
	 * @param      string $name A String with the name of the key.
	 * @return     mixed The value of object at key.
	 */
	public function getValue($name)
	{
		if (isset($this->map[$name])) {
			return $this->map[$name]->getValue();
		}
		return null;
	}

	/**
	 * An alias to getValue() -- exposing a Hashtable-like interface.
	 *
	 * @param      string $key An Object.
	 * @return     mixed The value within the Criterion (not the Criterion object).
	 */
	public function get($key)
	{
		return $this->getValue($key);
	}

	/**
	 * Overrides Hashtable put, so that this object is returned
	 * instead of the value previously in the Criteria object.
	 * The reason is so that it more closely matches the behavior
	 * of the add() methods. If you want to get the previous value
	 * then you should first Criteria.get() it yourself. Note, if
	 * you attempt to pass in an Object that is not a String, it will
	 * throw a NPE. The reason for this is that none of the add()
	 * methods support adding anything other than a String as a key.
	 *
	 * @param      string $key
	 * @param      mixed $value
	 * @return     Criteria instance of self.
	 */
	public function put($key, $value)
	{
		return $this->add($key, $value);
	}

	/**
	 * Copies all of the mappings from the specified Map to this Criteria
	 * These mappings will replace any mappings that this Criteria had for any
	 * of the keys currently in the specified Map.
	 *
	 * if the map was another Criteria, its attributes are copied to this
	 * Criteria, overwriting previous settings.
	 *
	 * @param      mixed $t Mappings to be stored in this map.
	 */
	public function putAll($t): void
	{
		if (is_array($t)) {
			foreach ($t as $key=>$value) {
				if ($value instanceof Criterion) {
					$this->map[$key] = $value;
				} else {
					$this->put($key, $value);
				}
			}
		} elseif ($t instanceof Criteria) {
			$this->joins = $t->joins;
		}
	}

	/**
	 * This method adds a new criterion to the list of criterias.
	 * If a criterion for the requested column already exists, it is
	 * replaced. If is used as follow:
	 *
	 * <code>
	 * $crit = new Criteria();
	 * $crit->add($column, $value, Criteria::GREATER_THAN);
	 * </code>
	 *
	 * Any comparison can be used.
	 *
	 * The name of the table must be used implicitly in the column name,
	 * so the Column name must be something like 'TABLE.id'.
	 *
	 * @param      string|Criterion|null $p1 The column to run the comparison on, or Criterion object.
	 * @param      mixed $value
	 * @param      string $comparison A String.
	 *
	 * @return     static A modified Criteria object.
	 */
	public function add($p1, $value = null, $comparison = null)
	{
		if ($p1 instanceof Criterion) {
			$this->map[$p1->getTable() . '.' . $p1->getColumn()] = $p1;
		} else {
			$criterion = new Criterion($this, $p1, $value, $comparison);
			$this->map[$p1 ?? ''] = $criterion;
		}
		return $this;
	}

	/**
	 * This method creates a new criterion but keeps it for later use with combine()
	 * Until combine() is called, the condition is not added to the query
	 *
	 * <code>
	 * $crit = new Criteria();
	 * $crit->addCond('cond1', $column1, $value1, Criteria::GREATER_THAN);
	 * $crit->addCond('cond2', $column2, $value2, Criteria::EQUAL);
	 * $crit->combine(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
	 * </code>
	 *
	 * Any comparison can be used.
	 *
	 * The name of the table must be used implicitly in the column name,
	 * so the Column name must be something like 'TABLE.id'.
	 *
	 * @param      string $name name to combine the criterion later
	 * @param      string|Criterion $p1 The column to run the comparison on, or Criterion object.
	 * @param      mixed $value
	 * @param      string $comparison A String.
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addCond($name, $p1, $value = null, $comparison = null)
	{
		if ($p1 instanceof Criterion) {
			$this->namedCriterions[$name] = $p1;
		} else {
			$criterion = new Criterion($this, $p1, $value, $comparison);
			$this->namedCriterions[$name] = $criterion;
		}
		return $this;
	}

	/**
	 * Combine several named criterions with a logical operator
	 *
	 * @param      array<int, string> $criterions array of the name of the criterions to combine
	 * @param      string $operator logical operator, either Criteria::LOGICAL_AND, or Criteria::LOGICAL_OR
	 * @param      string $name optional name to combine the criterion later
	 * @return     static
	 */
	public function combine($criterions = array(), $operator = self::LOGICAL_AND, $name = null)
	{
		$operatorMethod = (strtoupper($operator) == self::LOGICAL_AND) ? 'addAnd' : 'addOr';
		$namedCriterions = array();
		foreach ($criterions as $key) {
			if (array_key_exists($key, $this->namedCriterions)) {
				$namedCriterions[]= $this->namedCriterions[$key];
				unset($this->namedCriterions[$key]);
			} else {
				throw new PropulsionException('Cannot combine unknown condition ' . $key);
			}
		}
		$firstCriterion = array_shift($namedCriterions);
		if ($firstCriterion === null) {
			throw new PropulsionException('combine() requires at least one criterion name');
		}
		foreach ($namedCriterions as $criterion) {
			$firstCriterion->$operatorMethod($criterion);
		}
		if ($name === null) {
			$this->add($firstCriterion, null, null);
		} else {
			$this->addCond($name, $firstCriterion, null, null);
		}

		return $this;
	}

	/**
	 * This is the way that you should add a join of two tables.
	 * Example usage:
	 * <code>
	 * $c->addJoin(ProjectPeer::ID, FooPeer::PROJECT_ID, Criteria::LEFT_JOIN);
	 * // LEFT JOIN FOO ON (PROJECT.ID = FOO.PROJECT_ID)
	 * </code>
	 *
	 * @param      string|array<int|string, string> $left  A String with the left side of the join, or an array of such strings for a multi-condition join.
	 * @param      string|array<int|string, string> $right A String with the right side of the join, or an array of such strings for a multi-condition join.
	 * @param      ?string $joinType A String with the join operator
	 *                             among Criteria::INNER_JOIN, Criteria::LEFT_JOIN,
	 *                             and Criteria::RIGHT_JOIN
   *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addJoin(string|array $left, string|array $right, ?string $joinType = null)
	{
		if (is_array($left)) {
			if (!is_array($right)) {
				throw new PropulsionException('addJoin(): $right must be an array when $left is an array');
			}
			$conditions = array();
			foreach ($left as $key => $value) {
				$condition = array($value, $right[$key]);
				$conditions []= $condition;
			}
			return $this->addMultipleJoin($conditions, $joinType);
		}

		if (is_array($right)) {
			throw new PropulsionException('addJoin(): $right must not be an array when $left is not an array');
		}

		$join = new Join();

		// is the left table an alias ?
		$dotpos = strrpos($left, '.');
		$leftTableAlias = substr($left, 0, $dotpos === false ? 0 : $dotpos);
		$leftColumnName = substr($left, $dotpos === false ? 0 : $dotpos + 1);
		list($leftTableName, $leftTableAlias) = $this->getTableNameAndAlias($leftTableAlias);

		// is the right table an alias ?
		$dotpos = strrpos($right, '.');
		$rightTableAlias = substr($right, 0, $dotpos === false ? 0 : $dotpos);
		$rightColumnName = substr($right, $dotpos === false ? 0 : $dotpos + 1);
		list($rightTableName, $rightTableAlias) = $this->getTableNameAndAlias($rightTableAlias);

		$join->addExplicitCondition(
			$leftTableName, $leftColumnName, $leftTableAlias,
			$rightTableName, $rightColumnName, $rightTableAlias,
			Join::EQUAL);

		$join->setJoinType($joinType);

		return $this->addJoinObject($join);
	}

	/**
	 * Add a join with multiple conditions
	 * @deprecated use Join::setJoinCondition($criterion) instead
	 *
	 * Example usage:
	 * $c->addMultipleJoin(array(
	 *     array(LeftPeer::LEFT_COLUMN, RightPeer::RIGHT_COLUMN),  // if no third argument, defaults to Criteria::EQUAL
	 *     array(FoldersPeer::alias( 'fo', FoldersPeer::LFT ), FoldersPeer::alias( 'parent', FoldersPeer::RGT ), Criteria::LESS_EQUAL )
	 *   ),
	 *   Criteria::LEFT_JOIN
 	 * );
	 *
	 * @see        addJoin()
	 * @param      array<int, array{0: string, 1: string, 2?: string}> $conditions An array of conditions, each condition being an array (left, right, operator)
	 * @param      ?string $joinType  A String with the join operator. Defaults to an implicit join.
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addMultipleJoin(array $conditions, ?string $joinType = null)
	{
		$join = new Join();
		$joinCondition = null;
		foreach ($conditions as $condition) {
			$left = $condition[0];
			$right = $condition[1];
			if ($pos = strrpos($left, '.')) {
				$leftTableAlias = substr($left, 0, $pos);
				$leftColumnName = substr($left, $pos + 1);
				list($leftTableName, $leftTableAlias) = $this->getTableNameAndAlias($leftTableAlias);
			} else {
				list($leftTableName, $leftTableAlias) = array(null, null);
				$leftColumnName = $left;
			}
			if ($pos = strrpos($right, '.')) {
				$rightTableAlias = substr($right, 0, $pos);
				$rightColumnName = substr($right, $pos + 1);
				list($rightTableName, $rightTableAlias) = $this->getTableNameAndAlias($rightTableAlias);
			} else {
				list($rightTableName, $rightTableAlias) = array(null, null);
				$rightColumnName = $right;
			}
			if (!$join->getRightTableName()) {
				$join->setRightTableName($rightTableName);
			}
			if (!$join->getRightTableAlias()) {
				$join->setRightTableAlias($rightTableAlias);
			}
			$conditionClause = $leftTableAlias ? $leftTableAlias . '.' : ($leftTableName ? $leftTableName . '.' : '');
			$conditionClause .= $leftColumnName;
			$conditionClause .= isset($condition[2]) ? $condition[2] : Join::EQUAL;
			$conditionClause .= $rightTableAlias ? $rightTableAlias . '.' : ($rightTableName ? $rightTableName . '.' : '');
			$conditionClause .= $rightColumnName;
			$criterion = $this->getNewCriterion($leftTableName.'.'.$leftColumnName, $conditionClause, Criteria::CUSTOM);
			if (null === $joinCondition) {
				$joinCondition = $criterion;
			} else {
				$joinCondition = $joinCondition->addAnd($criterion);
			}
		}
		if ($joinCondition === null) {
			throw new PropulsionException('addMultipleJoin() requires at least one condition');
		}
		$join->setJoinType($joinType);
		$join->setJoinCondition($joinCondition);
		return $this->addJoinObject($join);
	}

	/**
	 * Add a join object to the Criteria
	 *
	 * @param Join $join A join object
	 *
	 * @return Criteria A modified Criteria object
	 */
	public function addJoinObject(Join $join, ?string $name = null)
	{
		if (!in_array($join, $this->joins)) { // compare equality, NOT identity
			if (null === $name) {
				$this->joins[] = $join;
			} else {
				$this->joins[$name] = $join;
			}
		}
		return $this;
	}

	/**
	 * Get the array of Joins.
	 * @return     array<int|string, Join>
	 */
	public function getJoins()
	{
		return $this->joins;
	}

	/**
	 * Build the query result cache key for a given SQL+params pair (see
	 * {@see \Propulsion\Cache\QueryResultCache}). Identical SQL, params, and
	 * database name guarantee identical rows for a given DB state, so the key
	 * is built from those rather than from Criteria-object structure.
	 *
	 * @param array<int, array{table: string|null, column: string|null, value: mixed}> $params
	 */
	public function getQueryCacheKey(string $sql, array $params): string
	{
		return $sql . '|' . serialize($params) . '|' . $this->getDbName();
	}

	/**
	 * Every table name this query reads from -- used to index a cached result
	 * so a write to any of these tables (see `BasePeer::doInsert()`/doUpdate()/
	 * doDelete()/doDeleteAll()) can evict it.
	 *
	 * Names are always the *real* table names, never aliases: the write paths
	 * that call `TieredQueryCache::invalidateTable()` only ever know a table by
	 * its real name, so a dependency recorded under an alias (which is what the
	 * criterion-map keys carry for an aliased query -- `b.TITLE`, not
	 * `book.TITLE`) would be indexed under a key nothing ever bumps, and the
	 * entry would stay served for the rest of the request/TTL after a write that
	 * should have evicted it.
	 *
	 * Both sides of every join are included. The right side alone is not enough:
	 * a query whose only condition is on the joined table (or which has no
	 * WHERE clause at all) contributes nothing for the left table via
	 * getTablesColumns(), and setPrimaryTableName() is only called on some
	 * construction paths -- so `BookQuery::create()->join('Book.Author')` used
	 * to depend on `author` but not on `book`.
	 *
	 * **The select columns are a dependency source too, and the only one for a
	 * filter-less query.** `getTablesColumns()` is derived purely from the
	 * criterion (WHERE) map, and `setPrimaryTableName()` is called on only two
	 * paths (`ModelCriteria::configureSelectColumns()` and `count()`, the latter
	 * with a comment saying exactly why) -- never by `ModelCriteria`'s
	 * constructor, and never by `find()`/`findOne()`. So
	 * `BookQuery::create()->setQueryCache()->find()` -- no WHERE, no join, the
	 * plainest "select every row" query there is -- used to record *no*
	 * dependencies at all, while `->count()` on the same query correctly
	 * recorded `book`. An entry with no dependencies cannot be invalidated by
	 * anything: `QueryResultCache::invalidateTable()` never reaches it, and
	 * because `SharedQueryCache::buildKey()` folds in one version token per
	 * dependency, its L2 key folds in none and so is immune to
	 * `TableVersionRegistry::publish()` -- which makes it immune to
	 * `Propulsion::invalidateQueryCacheForTables()`, the documented escape
	 * hatch, as well. Every process kept serving the pre-write row set until the
	 * TTL lapsed (300s by default).
	 *
	 * That table is recoverable because it is exactly what the FROM clause of
	 * such a query is built from -- see `DBAdapter::createSelectSqlPart()`, which
	 * derives FROM entries from the select columns for this very reason. This
	 * method reads the same source, deliberately more liberally, and additionally
	 * reads the `asColumns` expressions (`withColumn()`), which
	 * `createSelectSqlPart()` emits into the SELECT list but never derives a FROM
	 * entry from -- so a correlated expression like
	 * `withColumn('(SELECT COUNT(*) FROM review WHERE review.BOOK_ID = book.ID)')`
	 * names a table that appears nowhere else in the query. See
	 * {@see tableNamesInSelectedExpressions()}.
	 *
	 * @return list<string>
	 */
	public function getQueryCacheTouchedTables(): array
	{
		$tables = array();
		foreach (array_keys($this->getTablesColumns()) as $tableAliasOrName) {
			$tables[] = $this->resolveTableAlias($tableAliasOrName);
		}
		if ($primaryTable = $this->getPrimaryTableName()) {
			$tables[] = $this->resolveTableAlias($primaryTable);
		}
		foreach ($this->getJoins() as $join) {
			foreach (array($join->getLeftTableName(), $join->getRightTableName()) as $joinTableName) {
				if ($joinTableName !== null && $joinTableName !== '') {
					$tables[] = $this->resolveTableAlias($joinTableName);
				}
			}
		}
		foreach ($this->tableNamesInSelectedExpressions() as $selectTableName) {
			$tables[] = $this->resolveTableAlias($selectTableName);
		}

		return array_values(array_unique(array_filter($tables, static fn (string $table): bool => $table !== '')));
	}

	/**
	 * The table (or alias) qualifying each `table.column`-shaped reference in
	 * this Criteria's select columns and `withColumn()` expressions.
	 *
	 * Every qualified reference in the expression contributes, not just the last
	 * one -- so `substring(book.TITLE from position('x' in book.TITLE))` yields
	 * `book`, and a two-table expression yields both. That is a superset of what
	 * `DBAdapter::createSelectSqlPart()` extracts from the same strings (it needs
	 * the one table the column belongs to, for the FROM clause), and the bias is
	 * deliberate: the two have opposite failure modes. Naming a table this query
	 * does not really read costs a redundant invalidation and, at the shared
	 * tier, one extra version token folded into the key -- nothing ever bumps it,
	 * so the key stays stable and the entry stays servable. *Missing* one serves
	 * stale rows, which is the bug this exists to prevent. Over-inclusion is
	 * therefore the safe direction, and is chosen on purpose.
	 *
	 * Multi-segment (schema-qualified) names survive intact: only the final
	 * segment of a dotted run is dropped, so `myschema.mytable.COLUMN` yields
	 * `myschema.mytable`, matching the pgsql-multi-schema fixture's table names
	 * and the form createSelectSqlPart() resolves aliases against.
	 *
	 * @return list<string>
	 */
	private function tableNamesInSelectedExpressions(): array
	{
		$expressions = array_merge(
			array_values($this->getSelectColumns()),
			array_values($this->getAsColumns())
		);

		$tableNames = array();
		foreach ($expressions as $columnExpression) {
			// Each match is a maximal dotted identifier run, e.g. "book.TITLE"
			// or "myschema.mytable.COLUMN"; everything before its last dot is
			// the table part.
			if (!preg_match_all('/[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+/', $columnExpression, $matches)) {
				continue;
			}
			foreach ($matches[0] as $qualifiedReference) {
				$lastDot = strrpos($qualifiedReference, '.');
				if ($lastDot !== false && $lastDot > 0) {
					$tableNames[] = substr($qualifiedReference, 0, $lastDot);
				}
			}
		}

		return $tableNames;
	}

	/**
	 * The real table name behind $tableAliasOrName, or $tableAliasOrName itself
	 * when it isn't a registered alias (a real table name, or a CTE/subquery
	 * name, both of which are already their own canonical form here).
	 */
	public function resolveTableAlias(string $tableAliasOrName): string
	{
		return $this->aliases[$tableAliasOrName] ?? $tableAliasOrName;
	}

	/**
	 * Adds a Criteria as subQuery in the From Clause.
	 *
	 * @param Criteria $subQueryCriteria Criteria to build the subquery from
	 * @param string   $alias            alias for the subQuery
	 *
	 * @return Criteria this modified Criteria object (Fluid API)
	 */
	public function addSelectQuery(Criteria $subQueryCriteria, ?string $alias = null) : Criteria
	{
		if (null === $alias) {
			$alias = 'alias_' . ($subQueryCriteria->forgeSelectQueryAlias() + count($this->selectQueries));
		}
		$this->selectQueries[$alias] = $subQueryCriteria;

		return $this;
	}

	/**
	 * Checks whether this Criteria has a subquery.
	 *
	 * @return     boolean
	 */
	public function hasSelectQueries()
	{
		return (bool) $this->selectQueries;
	}

	/**
	 * Get the associative array of Criteria for the subQueries per alias.
	 *
	 * @return     array<string, Criteria>
	 */
	public function getSelectQueries()
	{
		return $this->selectQueries;
	}

	/**
	 * Get the Criteria for a specific subQuery.
	 *
	 * @param string   $alias            alias for the subQuery
	 * @return Criteria
	 */
	public function getSelectQuery($alias)
	{
		return $this->selectQueries[$alias];
	}

	/**
	 * checks if the Criteria for a specific subQuery is set.
	 *
	 * @param string   $alias            alias for the subQuery
	 * @return boolean
	 */
	public function hasSelectQuery($alias)
	{
		return isset($this->selectQueries[$alias]);
	}

	public function forgeSelectQueryAlias(): int
	{
		$aliasNumber = 0;
		foreach ($this->getSelectQueries() as $c1) {
			$aliasNumber += $c1->forgeSelectQueryAlias();
		}
		return ++$aliasNumber;
	}

	/**
	 * Adds "ALL" modifier to the SQL statement.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setAll()
	{
		$this->removeSelectModifier(self::DISTINCT);
		$this->addSelectModifier(self::ALL);

		return $this;
	}

	/**
	 * Adds "DISTINCT" modifier to the SQL statement.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setDistinct()
	{
		$this->removeSelectModifier(self::ALL);
		$this->addSelectModifier(self::DISTINCT);

		return $this;
	}

	/**
	 * Adds a modifier to the SQL statement.
	 * e.g. self::ALL, self::DISTINCT, 'SQL_CALC_FOUND_ROWS', 'HIGH_PRIORITY', etc.
	 *
	 * @param      string $modifier The modifier to add
	 *
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function addSelectModifier($modifier)
	{
		//only allow the keyword once
		if (!$this->hasSelectModifier($modifier)) {
			$this->selectModifiers[] = $modifier;
		}

		return $this;
	}

	/**
	 * Removes a modifier to the SQL statement.
	 * Checks for existence before removal
	 *
	 * @param      string $modifier The modifier to add
	 *
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function removeSelectModifier($modifier)
	{
		$this->selectModifiers = array_values(array_diff($this->selectModifiers, array($modifier)));

		return $this;
	}

	/**
	 * Checks the existence of a SQL select modifier
	 *
	 * @param      string $modifier The modifier to add
	 *
	 * @return     bool
	 */
	public function hasSelectModifier($modifier)
	{
		return in_array($modifier, $this->selectModifiers);
	}

	/**
	 * Sets ignore case.
	 *
	 * @param      boolean $b True if case should be ignored.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setIgnoreCase($b)
	{
		$this->ignoreCase = (bool) $b;
		return $this;
	}

	/**
	 * Is ignore case on or off?
	 *
	 * @return     boolean True if case is ignored.
	 */
	public function isIgnoreCase()
	{
		return $this->ignoreCase;
	}

	/**
	 * Set single record?  Set this to <code>true</code> if you expect the query
	 * to result in only a single result record (the default behaviour is to
	 * throw a PropulsionException if multiple records are returned when the query
	 * is executed).  This should be used in situations where returning multiple
	 * rows would indicate an error of some sort.  If your query might return
	 * multiple records but you are only interested in the first one then you
	 * should be using setLimit(1).
	 *
	 * @param      boolean $b Set to TRUE if you expect the query to select just one record.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setSingleRecord($b)
	{
		$this->singleRecord = (bool) $b;
		return $this;
	}

	/**
	 * Is single record?
	 *
	 * @return     boolean True if a single record is being returned.
	 */
	public function isSingleRecord()
	{
		return $this->singleRecord;
	}

	/**
	 * Opt this query into the current request's query result cache (see
	 * {@see \Propulsion\Cache\QueryResultCache}). Off by default: turn it on
	 * only for queries you know are safe to serve a request-old result for --
	 * a cached result is only ever evicted by a write (insert/update/delete)
	 * to one of the query's tables going through this same process, so a
	 * write via a different connection/process (or a non-deterministic
	 * expression like NOW() baked into the query) will not be reflected in a
	 * cache hit.
	 *
	 * @param      boolean $b Set to TRUE to allow this query to be served from/stored into the query result cache.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setQueryCache(bool $b = true, ?int $ttl = null, bool $shared = true)
	{
		$this->queryCacheEnabled = $b;
		$this->queryCacheTtl = $ttl;
		$this->queryCacheShared = $shared;
		return $this;
	}

	/**
	 * This query's cache TTL override in seconds, or null to use the
	 * configured `cache.query.ttl`. Only meaningful for the shared tier; the
	 * request-scoped tier is bounded by the request itself.
	 */
	public function getQueryCacheTtl(): ?int
	{
		return $this->queryCacheTtl;
	}

	/**
	 * Whether this query's result may be published to the *shared* cache tier.
	 *
	 * The escape hatch for queries whose SQL text is stable but whose correct
	 * result is not -- anything built on NOW(), CURRENT_DATE, RANDOM(), or a
	 * LIMIT over an unstable ORDER BY. Such a query has an identical cache key
	 * every time, so its first result would be frozen for the whole TTL, and
	 * across every process sharing the backend rather than merely for the rest
	 * of one request. Set `shared: false` to keep it in the request-scoped tier
	 * only.
	 *
	 * Propulsion deliberately does not try to detect these by inspecting the
	 * SQL: a scan for volatile function names gives false positives on ordinary
	 * column names like `now_at` and false negatives on user-defined functions
	 * and on volatility reached through a view, and a detector that is
	 * confidently wrong is worse than no detector.
	 */
	public function isQueryCacheShared(): bool
	{
		return $this->queryCacheShared;
	}

	/**
	 * Is this query allowed to be served from/stored into the query result cache?
	 *
	 * @return     boolean
	 */
	public function isQueryCacheEnabled(): bool
	{
		return $this->queryCacheEnabled;
	}

	/**
	 * Opt this query into the compiled-query cache (see
	 * {@see \Propulsion\Cache\CompiledQueryCache}) -- a cache of *SQL strings*,
	 * not rows (contrast {@see setQueryCache()}). Useful in long-lived worker
	 * processes where the same generated Query/Peer method is called
	 * repeatedly with only bound values differing between calls: the SELECT
	 * SQL text itself is identical every time, so re-walking joins/columns/
	 * criterions to re-derive it is wasted work.
	 *
	 * The cache is **process-scoped**: an entry compiled while serving one
	 * request is reused by every later request the same worker process serves,
	 * which is where the benefit actually comes from. It therefore outlives
	 * `Session::reset()`, and a key must identify a query shape for the whole
	 * process rather than merely within one request -- see the paragraph on `$key`
	 * below, which was always the contract but now has a longer reach.
	 *
	 * `$key` must uniquely identify this query's *shape* -- the same joins,
	 * the same WHERE/HAVING comparisons in the same order, the same number of
	 * elements in any `IN (...)` list, the same LIMIT/OFFSET values (these are
	 * written as literal integers into the SQL text on every platform, not
	 * bound -- see `DBAdapter::applyLimit()`), and the same literal text for
	 * any `Criteria::CUSTOM` raw expression. Only ordinary bound scalar values
	 * (the common case: `$criteria->add('book.TITLE', $title)`) may vary
	 * between calls sharing a key. A natural choice is the calling method's
	 * own name (e.g. `__METHOD__`), since that's exactly "the same query shape
	 * rebuilt on every request" this feature targets.
	 *
	 * This is the caller's responsibility to get right -- there is no general
	 * way to auto-derive a shape fingerprint that's both cheap to compute
	 * (cheaper than just building the SQL) and safe against every case above,
	 * so unlike {@see setQueryCache()}'s plain boolean this takes an explicit
	 * key. As a safety net against the most common mistake (reusing a key for
	 * a Criteria with a different bound-parameter count), a cache hit whose
	 * freshly-collected parameter count doesn't match the count recorded when
	 * the entry was built throws a {@see \Propulsion\Exception\PropulsionException}
	 * rather than silently returning mismatched SQL -- this does not catch
	 * every possible shape mismatch (e.g. same count, different structure),
	 * only the common one.
	 *
	 * Not supported (silently falls back to an uncached build; the cache is
	 * simply never consulted) for a Criteria carrying common table expressions,
	 * set operations, or FROM-clause subqueries ({@see withCte()}, {@see union()}
	 * and friends, {@see addSelectQuery()}) -- each of those recurses into
	 * further `Criteria` objects with their own params, which the fast
	 * params-only collection path this cache relies on does not attempt to
	 * mirror.
	 *
	 * @param      string|null $key Shape key, or null to disable (the default).
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setCompiledQueryCache(?string $key)
	{
		$this->compiledQueryCacheKey = $key;
		return $this;
	}

	/**
	 * Is this query allowed to be served from/stored into the compiled-query cache?
	 *
	 * @return     boolean
	 */
	public function isCompiledQueryCacheEnabled(): bool
	{
		return $this->compiledQueryCacheKey !== null;
	}

	/**
	 * The caller-supplied compiled-query cache shape key, or null if disabled.
	 * @see setCompiledQueryCache()
	 */
	public function getCompiledQueryCacheKey(): ?string
	{
		return $this->compiledQueryCacheKey;
	}

	/**
	 * Set limit.
	 *
	 * @param      int $limit An int with the value for limit.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setLimit($limit)
	{
		// TODO: do we enforce int here? 32bit issue if we do
		$this->limit = $limit;
		return $this;
	}

	/**
	 * Get limit.
	 *
	 * @return     int An int with the value for limit.
	 */
	public function getLimit()
	{
		return $this->limit;
	}

	/**
	 * Set offset.
	 *
	 * @param      int $offset An int with the value for offset.  (Note this values is
	 * 							cast to a 32bit integer and may result in truncatation)
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setOffset($offset)
	{
		$this->offset = (int) $offset;
		return $this;
	}

	/**
	 * Get offset.
	 *
	 * @return     int An int with the value for offset.
	 */
	public function getOffset()
	{
		return $this->offset;
	}

	/**
	 * Locks the rows matched by this query with a pessimistic write lock
	 * (SELECT ... FOR UPDATE), blocking (or skipping/failing, per the flags)
	 * concurrent writers until the current transaction ends.
	 *
	 * @param      bool $skipLocked Skip rows already locked by another transaction (SKIP LOCKED) instead of waiting.
	 * @param      bool $noWait Fail immediately instead of waiting if a row is already locked (NOWAIT).
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setLockForUpdate(bool $skipLocked = false, bool $noWait = false)
	{
		if ($skipLocked && $noWait) {
			throw new PropulsionException('Criteria::setLockForUpdate() cannot combine $skipLocked and $noWait');
		}
		$this->lockMode = Criteria::LOCK_FOR_UPDATE;
		$this->lockSkipLocked = $skipLocked;
		$this->lockNoWait = $noWait;
		return $this;
	}

	/**
	 * Locks the rows matched by this query with a pessimistic read lock
	 * (SELECT ... FOR SHARE), blocking (or skipping/failing, per the flags)
	 * concurrent writers until the current transaction ends.
	 *
	 * @param      bool $skipLocked Skip rows already locked by another transaction (SKIP LOCKED) instead of waiting.
	 * @param      bool $noWait Fail immediately instead of waiting if a row is already locked (NOWAIT).
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setLockForShare(bool $skipLocked = false, bool $noWait = false)
	{
		if ($skipLocked && $noWait) {
			throw new PropulsionException('Criteria::setLockForShare() cannot combine $skipLocked and $noWait');
		}
		$this->lockMode = Criteria::LOCK_FOR_SHARE;
		$this->lockSkipLocked = $skipLocked;
		$this->lockNoWait = $noWait;
		return $this;
	}

	/**
	 * Removes any pessimistic lock previously set via setLockForUpdate()/setLockForShare().
	 *
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function clearLock()
	{
		$this->lockMode = null;
		$this->lockSkipLocked = false;
		$this->lockNoWait = false;
		return $this;
	}

	/**
	 * Get the pessimistic lock mode for this query.
	 *
	 * @return     string|null Criteria::LOCK_FOR_UPDATE, Criteria::LOCK_FOR_SHARE, or null if unlocked.
	 */
	public function getLockMode(): ?string
	{
		return $this->lockMode;
	}

	/**
	 * @return     bool Whether the lock (if any) should fail immediately instead of waiting (NOWAIT).
	 */
	public function isLockNoWait(): bool
	{
		return $this->lockNoWait;
	}

	/**
	 * @return     bool Whether the lock (if any) should skip already-locked rows instead of waiting (SKIP LOCKED).
	 */
	public function isLockSkipLocked(): bool
	{
		return $this->lockSkipLocked;
	}

	/**
	 * Add select column.
	 *
	 * @param      string $name Name of the select column.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function addSelectColumn($name)
	{
		$this->selectColumns[] = $name;
		return $this;
	}

	/**
	 * Add several select columns at once.
	 *
	 * Generated Peer::addSelectColumns() emits a single call to this with the
	 * table's full (static, known-at-generation-time) column list instead of
	 * one addSelectColumn() method call per column on every query.
	 *
	 * @param      array<int,string> $names Names of the select columns.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function addSelectColumns(array $names)
	{
		if ($names) {
			array_push($this->selectColumns, ...$names);
		}
		return $this;
	}

	/**
	 * Set the query comment, that appears after the first verb in the SQL query
	 *
	 * @param      ?string $comment The comment to add to the query, without comment sign
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function setComment(?string $comment = null)
	{
		$this->queryComment = $comment;

		return $this;
	}

	/**
	 * Get the query comment, that appears after the first verb in the SQL query
	 *
	 * @return      ?string The comment to add to the query, without comment sign
	 */
	public function getComment(): ?string
	{
		return $this->queryComment;
	}

	/**
	 * Whether this Criteria has any select columns.
	 *
	 * This will include columns added with addAsColumn() method.
	 *
	 * @return     boolean
	 * @see        addAsColumn()
	 * @see        addSelectColumn()
	 */
	public function hasSelectClause()
	{
		return (!empty($this->selectColumns) || !empty($this->asColumns));
	}

	/**
	 * Get select columns.
	 *
	 * @return     array<int, string> An array with the name of the select columns.
	 */
	public function getSelectColumns()
	{
		return $this->selectColumns;
	}

	/**
	 * Clears current select columns.
	 *
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function clearSelectColumns()
	{
		$this->selectColumns = $this->asColumns = array();
		return $this;
	}

	/**
	 * Get select modifiers.
	 *
	 * @return array<int, string> An array with the select modifiers.
	 */
	public function getSelectModifiers()
	{
		return $this->selectModifiers;
	}

	/**
	 * Add group by column name.
	 *
	 * @param      string $groupBy The name of the column to group by.
	 * @return     Criteria A modified Criteria object.
	 */
	public function addGroupByColumn($groupBy)
	{
		$this->groupByColumns[] = $groupBy;
		return $this;
	}

	/**
	 * Add order by column name, explicitly specifying ascending.
	 *
	 * @param      string $name The name of the column to order by.
	 * @return     Criteria A modified Criteria object.
	 */
	public function addAscendingOrderByColumn($name)
	{
		$this->orderByColumns[] = $name . ' ' . self::ASC;
		return $this;
	}

	/**
	 * Add order by column name, explicitly specifying descending.
	 *
	 * @param      string $name The name of the column to order by.
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function addDescendingOrderByColumn($name)
	{
		$this->orderByColumns[] = $name . ' ' . self::DESC;
		return $this;
	}

	/**
	 * Get order by columns.
	 *
	 * @return     array<int, string> An array with the name of the order columns.
	 */
	public function getOrderByColumns()
	{
		return $this->orderByColumns;
	}

	/**
	 * Clear the order-by columns.
	 *
	 * @return     Criteria Modified Criteria object (for fluent API)
	 */
	public function clearOrderByColumns()
	{
		$this->orderByColumns = array();
		return $this;
	}

	/**
	 * Clear the group-by columns.
	 *
	 * @return     Criteria
	 */
	public function clearGroupByColumns()
	{
		$this->groupByColumns = array();
		return $this;
	}

	/**
	 * Get group by columns.
	 *
	 * @return     array<int, string>
	 */
	public function getGroupByColumns()
	{
		return $this->groupByColumns;
	}

	/**
	 * Get Having Criterion.
	 *
	 * @return     Criterion|null A Criterion object that is the having clause, or null if none was set.
	 */
	public function getHaving()
	{
		return $this->having;
	}

	/**
	 * Remove an object from the criteria.
	 *
	 * @param      string $key A string with the key to be removed.
	 * @return     mixed The removed value.
	 */
	public function remove($key)
	{
		if ( isset ( $this->map[$key] ) ) {
			$removed = $this->map[$key];
			unset ( $this->map[$key] );
			return $removed->getValue();
		}
	}

	/**
	 * Build a string representation of the Criteria.
	 *
	 * @return     string A String with the representation of the Criteria.
	 */
	public function toString()
	{

		$sb = "Criteria:";
		try {

			$params = array();
			$sb .= "\nSQL (may not be complete): "
			  . BasePeer::createSelectSql($this, $params);

			$sb .= "\nParams: ";
			$paramstr = array();
			foreach ($params as $param) {
				$paramstr[] = $param['table'] . '.' . $param['column'] . ' => ' . var_export($param['value'], true);
			}
			$sb .= implode(", ", $paramstr);

		} catch (Exception $exc) {
			$sb .= "(Error: " . $exc->getMessage() . ")";
		}

		return $sb;
	}

	/**
	 * Returns the size (count) of this criteria.
	 * @return     int
	 */
	public function size()
	{
		return count($this->map);
	}

	/**
	 * This method checks another Criteria to see if they contain
	 * the same attributes and hashtable entries.
	 * @param      mixed $crit
	 * @return     boolean
	 */
	public function equals($crit)
	{
		if ($crit === null || !($crit instanceof Criteria)) {
			return false;
		} elseif ($this === $crit) {
			return true;
		} elseif ($this->size() === $crit->size()) {

			// Important: nested criterion objects are checked

			$criteria = $crit; // alias
			if  ($this->offset          === $criteria->getOffset()
				&& $this->limit           === $criteria->getLimit()
				&& $this->ignoreCase      === $criteria->isIgnoreCase()
				&& $this->singleRecord    === $criteria->isSingleRecord()
				&& $this->dbName          === $criteria->getDbName()
				&& $this->selectModifiers === $criteria->getSelectModifiers()
				&& $this->selectColumns   === $criteria->getSelectColumns()
				&& $this->asColumns       === $criteria->getAsColumns()
				&& $this->orderByColumns  === $criteria->getOrderByColumns()
				&& $this->groupByColumns  === $criteria->getGroupByColumns()
				&& $this->aliases         === $criteria->getAliases()
				&& $this->lockMode        === $criteria->getLockMode()
				&& $this->lockNoWait      === $criteria->isLockNoWait()
				&& $this->lockSkipLocked  === $criteria->isLockSkipLocked()
			   ) // what about having ??
			{
				foreach ($criteria->keys() as $key) {
					if ($this->containsKey($key)) {
						$a = $this->getCriterion($key);
						$b = $criteria->getCriterion($key);
						if (!$a->equals($b)) {
							return false;
						}
					} else {
						return false;
					}
				}
				$joins = $criteria->getJoins();
				if (count($joins) != count($this->joins)) {
					return false;
				}
				foreach ($joins as $key => $join) {
					if (!$join->equals($this->joins[$key])) {
						return false;
					}
				}
				return true;
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * Add the content of a Criteria to the current Criteria
	 * In case of conflict, the current Criteria keeps its properties
	 *
	 * @param     Criteria $criteria The criteria to read properties from
	 * @param     string $operator The logical operator used to combine conditions
	 *            Defaults to Criteria::LOGICAL_AND, also accapts Criteria::LOGICAL_OR
	 *            This parameter is deprecated, use _or() instead

	 *
	 * @return    Criteria The current criteria object
	 */
	public function mergeWith(Criteria $criteria, ?string $operator = null) : Criteria
	{
		// merge limit
		$limit = $criteria->getLimit();
		if($limit != 0 && $this->getLimit() == 0) {
			$this->limit = $limit;
		}

		// merge offset
		$offset = $criteria->getOffset();
		if($offset != 0 && $this->getOffset() == 0) {
			$this->offset = $offset;
		}

		// merge select modifiers
		$selectModifiers = $criteria->getSelectModifiers();
		if ($selectModifiers && ! $this->selectModifiers){
			$this->selectModifiers = $selectModifiers;
		}

		// merge lock mode
		if ($criteria->getLockMode() !== null && $this->lockMode === null) {
			$this->lockMode = $criteria->getLockMode();
			$this->lockNoWait = $criteria->isLockNoWait();
			$this->lockSkipLocked = $criteria->isLockSkipLocked();
		}

		// merge select columns
		$this->selectColumns = array_merge($this->getSelectColumns(), $criteria->getSelectColumns());

		// merge as columns
		$commonAsColumns = array_intersect_key($this->getAsColumns(), $criteria->getAsColumns());
		if (!empty($commonAsColumns)) {
			throw new PropulsionException('The given criteria contains an AsColumn with an alias already existing in the current object');
		}
		$this->asColumns = array_merge($this->getAsColumns(), $criteria->getAsColumns());

		// merge orderByColumns
		$orderByColumns = array_merge($this->getOrderByColumns(), $criteria->getOrderByColumns());
		$this->orderByColumns = array_unique($orderByColumns);

		// merge groupByColumns
		$groupByColumns = array_merge($this->getGroupByColumns(), $criteria->getGroupByColumns());
		$this->groupByColumns = array_unique($groupByColumns);

		// merge where conditions
		if ($operator == Criteria::LOGICAL_OR) {
			$this->_or();
		}
		$isFirstCondition = true;
		foreach ($criteria->getMap() as $key => $criterion) {
			if ($isFirstCondition && $this->defaultCombineOperator == Criteria::LOGICAL_OR) {
				$this->addOr($criterion, null, null, false);
				$this->defaultCombineOperator = Criteria::LOGICAL_AND;
			} elseif ($this->containsKey($key)) {
				$this->addAnd($criterion);
			} else {
				$this->add($criterion);
			}
			$isFirstCondition = false;
		}

		// merge having
		if ($having = $criteria->getHaving()) {
			if ($this->getHaving()) {
				$this->addHaving($this->getHaving()->addAnd($having));
			} else {
				$this->addHaving($having);
			}
		}

		// merge alias
		$commonAliases = array_intersect_key($this->getAliases(), $criteria->getAliases());
		if (!empty($commonAliases)) {
			throw new PropulsionException('The given criteria contains an alias already existing in the current object');
		}
		$this->aliases = array_merge($this->getAliases(), $criteria->getAliases());

		// merge join
		$this->joins = array_merge($this->getJoins(), $criteria->getJoins());

		return $this;
	}

	/**
	 * This method adds a prepared Criterion object to the Criteria as a having clause.
	 * You can get a new, empty Criterion object with the
	 * getNewCriterion() method.
	 *
	 * <p>
	 * <code>
	 * $crit = new Criteria();
	 * $c = $crit->getNewCriterion(BasePeer::ID, 5, Criteria::LESS_THAN);
	 * $crit->addHaving($c);
	 * </code>
	 *
	 * @param      Criterion $having A Criterion object
	 *
	 * @return     Criteria A modified Criteria object.
	 */
	public function addHaving(Criterion $having)
	{
		$this->having = $having;
		return $this;
	}

	/**
	 * If a criterion for the requested column already exists, the condition is "AND"ed to the existing criterion (necessary for Propulsion 1.4 compatibility).
	 * If no criterion for the requested column already exists, the condition is "AND"ed to the latest criterion.
	 * If no criterion exist, the condition is added a new criterion
	 *
	 * Any comparison can be used.
	 *
	 * Supports a number of different signatures:
	 *  - addAnd(column, value, comparison)
	 *  - addAnd(column, value)
	 *  - addAnd(Criterion)
	 *
	 * @param      string|Criterion|null $p1
	 * @param      mixed $p2
	 * @param      string|null $p3
	 * @return     static A modified Criteria object.
	 */
	public function addAnd($p1, $p2 = null, $p3 = null, bool $preferColumnCondition = true)
	{
		$criterion = ($p1 instanceof Criterion) ? $p1 : new Criterion($this, $p1, $p2, $p3);

		$key = $criterion->getTable() . '.' . $criterion->getColumn();
		if ($preferColumnCondition && $this->containsKey($key)) {
			// FIXME: addAnd() operates preferably on existing conditions on the same column
			// this may cause unexpected results, but it's there for BC with Propulsion 14
			$this->getCriterion($key)->addAnd($criterion);
		} else {
			// simply add the condition to the list - this is the expected behavior
			$this->add($criterion);
		}

		return $this;
	}

	/**
	 * If a criterion for the requested column already exists, the condition is "OR"ed to the existing criterion (necessary for Propulsion 1.4 compatibility).
	 * If no criterion for the requested column already exists, the condition is "OR"ed to the latest criterion.
	 * If no criterion exist, the condition is added a new criterion
	 *
	 * Any comparison can be used.
	 *
	 * Supports a number of different signatures:
	 *  - addOr(column, value, comparison)
	 *  - addOr(column, value)
	 *  - addOr(Criterion)
	 *
	 * @param      string|Criterion|null $p1
	 * @param      mixed $p2
	 * @param      string|null $p3
	 * @return     static A modified Criteria object.
	 */
	public function addOr($p1, $p2 = null, $p3 = null, bool $preferColumnCondition = true)
	{
		$rightCriterion = ($p1 instanceof Criterion) ? $p1 : new Criterion($this, $p1, $p2, $p3);

		$key = $rightCriterion->getTable() . '.' . $rightCriterion->getColumn();
		if ($preferColumnCondition && $this->containsKey($key)) {
			// FIXME: addOr() operates preferably on existing conditions on the same column
			// this may cause unexpected results, but it's there for BC with Propulsion 14
			$leftCriterion = $this->getCriterion($key);
		} else {
			// fallback to the latest condition - this is the expected behavior
			$leftCriterion = $this->getLastCriterion();
		}

		if ($leftCriterion !== null) {
			// combine the given criterion with the existing one with an 'OR'
			$leftCriterion->addOr($rightCriterion);
		} else {
			// nothing to do OR / AND with, so make it first condition
			$this->add($rightCriterion);
		}

		return $this;
	}

	/**
	 * Overrides Criteria::add() to use the default combine operator
	 * @see        Criteria::add()
	 *
	 * @param      string|Criterion $p1 The column to run the comparison on (e.g. BookPeer::ID), or Criterion object
	 * @param      mixed $value
	 * @param      string $operator A String, like Criteria::EQUAL.
	 * @param      boolean $preferColumnCondition If true, the condition is combined with an existing condition on the same column
	*                      (necessary for Propulsion 1.4 compatibility).
	 *                     If false, the condition is combined with the last existing condition.
	 *
	 * @return     static A modified Criteria object.
	 */
	public function addUsingOperator($p1, $value = null, $operator = null, $preferColumnCondition = true)
	{
		if ($this->defaultCombineOperator == Criteria::LOGICAL_OR) {
			$this->defaultCombineOperator = Criteria::LOGICAL_AND;
			return $this->addOr($p1, $value, $operator, $preferColumnCondition);
		} else {
			return $this->addAnd($p1, $value, $operator, $preferColumnCondition);
		}
	}

	// Fluid operators

	/**
	 * @return static
	 */
	public function _or()
	{
		$this->defaultCombineOperator = Criteria::LOGICAL_OR;

		return $this;
	}

	/**
	 * @return static
	 */
	public function _and()
	{
		$this->defaultCombineOperator = Criteria::LOGICAL_AND;

		return $this;
	}

	// Fluid Conditions

	/**
	 * Returns the current object if the condition is true,
	 * or a PropulsionConditionalProxy instance otherwise.
	 * Allows for conditional statements in a fluid interface.
	 *
	 * @param      bool $cond
	 *
	 * @return     PropulsionConditionalProxy|Criteria
	 */
	public function _if($cond)
	{
		if ($this->isInIf) {
			throw new PropulsionException('_if() statements cannot be nested');
		}
		$this->isInIf = true;
		$this->wasTrue = false;
		if ($cond) {
			$this->wasTrue = true;
			return $this;
		} else {
			return new PropulsionConditionalProxy($this);
		}
	}

	/**
	 * Returns a PropulsionConditionalProxy instance.
	 * Allows for conditional statements in a fluid interface.
	 *
	 * @param      bool $cond ignored
	 *
	 * @return     PropulsionConditionalProxy|Criteria
	 */
	public function _elseif($cond)
	{
		if (!$this->isInIf) {
			throw new PropulsionException('_elseif() must be called after _if()');
		}
		if ($cond && !$this->wasTrue) {
			$this->wasTrue = true;
			return $this;
		} else {
			return new PropulsionConditionalProxy($this);
		}
	}

	/**
	 * Returns a PropulsionConditionalProxy instance.
	 * Allows for conditional statements in a fluid interface.
	 *
	 * @return     PropulsionConditionalProxy|Criteria
	 */
	public function _else()
	{
		if (!$this->isInIf) {
			throw new PropulsionException('_else() must be called after _if()');
		}
		if (!$this->wasTrue) {
			$this->wasTrue = true;
			return $this;
		} else {
			return new PropulsionConditionalProxy($this);
		}
	}

	/**
	 * Returns the current object
	 * Allows for conditional statements in a fluid interface.
	 *
	 * @return     Criteria
	 */
	public function _endif()
	{
		if (!$this->isInIf) {
			throw new PropulsionException('_endif() must be called after _if()');
		}
		$this->isInIf = false;
		return $this;
	}

	/**
	 * Ensures deep cloning of attached objects
	 */
	public function __clone()
	{
		foreach ($this->map as $key => $criterion) {
			$this->map[$key] = clone $criterion;
		}
		foreach ($this->joins as $key => $join) {
			$this->joins[$key] = clone $join;
		}
		if (null !== $this->having) {
			$this->having = clone $this->having;
		}
		// The three nested-Criteria collections were shallow-copied, so a clone
		// shared its subqueries/CTEs/set-operation branches with the original
		// and mutating one through the other was possible. That matters more
		// than it looks: isKeepQuery() defaults to true, so *every*
		// find()/count()/update() clones the query specifically to avoid
		// mutating the caller's object -- and count() in particular rewrites
		// select columns (clearSelectColumns()/turnSelectColumnsToAliases()).
		// Today those rewrites only reach the outer Criteria, so nothing
		// observably breaks; the invariant is restored here rather than left
		// resting on that.
		foreach ($this->selectQueries as $alias => $subQuery) {
			$this->selectQueries[$alias] = clone $subQuery;
		}
		foreach ($this->setOperations as $index => $setOperation) {
			$this->setOperations[$index] = array($setOperation[0], clone $setOperation[1]);
		}
		foreach ($this->commonTableExpressions as $index => $cte) {
			$cte['query'] = clone $cte['query'];
			$this->commonTableExpressions[$index] = $cte;
		}
	}

}
