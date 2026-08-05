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
 * This is an "inner" class that describes an object in the criteria.
 *
 * In Torque this is an inner class of the Criteria class.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @version    $Revision$
 */
 use Propulsion\Propulsion;
 use Propulsion\Adapter\DBAdapter;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\Adapter\DBPostgres;
 use \Exception;
class Criterion
{

	const UND = " AND ";
	const ODER = " OR ";

	/**
	 * Value of the CO.
	 * @var mixed
	 */
	protected $value;

	/** Comparison value.
	 * @var string
	 */
	protected $comparison;

	/**
	 * Table name.
	 * @var string|null
	 */
	protected $table;

	/**
	 * Real table name
	 * @var string|null
	 */
	protected $realtable;

	/**
	 * Column name.
	 * @var string|null
	 */
	protected $column;

	/** flag to ignore case in comparison */
	protected bool $ignoreStringCase = false;

	/**
	 * The DBAdaptor which might be used to get db specific
	 * variations of sql.
	 * @var DBAdapter|null
	 */
	protected $db;

	/**
	 * other connected criteria and their conjunctions.
	 * @var array<int, Criterion>
	 */
	protected $clauses = array();

	/**
	 * @var array<int, string>
	 */
	protected $conjunctions = array();

	/**
	 * "Parent" Criteria class
	 * @var mixed
	 */
	protected $parent;

	/**
	 * Create a new instance.
	 *
	 * @param      Criteria $outer The outer class (this is an "inner" class).
	 * @param      string|null $column TABLE.COLUMN format, or null for a custom expression with no column.
	 * @param      mixed $value
	 * @param      string $comparison
	 */
	public function __construct(Criteria $outer, $column, $value, $comparison = null)
	{
		$this->value = $value;
		$dotPos = $column !== null ? strrpos($column, '.') : false;
		if ($dotPos === false) {
			// no dot => aliased column
			$this->table = null;
			$this->column = $column;
		} else {
			$this->table = substr($column, 0, $dotPos);
			$this->column = substr($column, $dotPos + 1);
		}
		$this->comparison = ($comparison === null) ? Criteria::EQUAL : $comparison;
		$this->init($outer);
	}

	/**
	* Init some properties with the help of outer class
	* @param      Criteria $criteria The outer class
	*/
	public function init(Criteria $criteria): void
	{
		// init $this->db
		try {
			$db = Propulsion::getDB($criteria->getDbName());
			$this->setDB($db);
		} catch (Exception $e) {
			// we are only doing this to allow easier debugging, so
			// no need to throw up the exception, just make note of it.
			Propulsion::log("Could not get a DBAdapter, sql may be wrong", Propulsion::LOG_ERR);
		}

		// init $this->realtable
		$realtable = $criteria->getTableForAlias($this->table);
		$this->realtable = $realtable ? $realtable : $this->table;

	}

	/**
	 * Get the column name.
	 *
	 * @return     string|null A String with the column name, or null for a custom expression with no column.
	 */
	public function getColumn()
	{
		return $this->column;
	}

	/**
	 * Set the table name.
	 *
	 * @param      string $name A String with the table name.
	 * @return     void
	 */
	public function setTable($name)
	{
		$this->table = $name;
	}

	/**
	 * Get the table name.
	 *
	 * @return     string|null A String with the table name, or null for an aliased/unqualified column.
	 */
	public function getTable()
	{
		return $this->table;
	}

	/**
	 * Get the comparison.
	 *
	 * @return     string A String with the comparison.
	 */
	public function getComparison()
	{
		return $this->comparison;
	}

	/**
	 * Get the value.
	 *
	 * @return     mixed An Object with the value.
	 */
	public function getValue()
	{
		return $this->value;
	}

	/**
	 * Get the value of db.
	 * The DBAdapter which might be used to get db specific
	 * variations of sql.
	 * @return     DBAdapter|null value of db, or null if init() couldn't resolve one (logged, not thrown, for easier debugging).
	 */
	public function getDB()
	{
		return $this->db;
	}

	/**
	 * Set the value of db.
	 * The DBAdapter might be used to get db specific variations of sql.
	 * @param      DBAdapter $v Value to assign to db.
	 * @return     void
	 */
	public function setDB(DBAdapter $v)
	{
		$this->db = $v;
		foreach ( $this->clauses as $clause ) {
			$clause->setDB($v);
		}
	}

	/**
	 * Sets ignore case.
	 *
	 * @param      boolean $b True if case should be ignored.
	 * @return     Criterion A modified Criterion object.
	 */
	public function setIgnoreCase($b)
	{
		$this->ignoreStringCase = (bool) $b;
		return $this;
	}

	/**
	 * Is ignore case on or off?
	 *
	 * @return     boolean True if case is ignored.
	 */
	 public function isIgnoreCase()
	 {
		 return $this->ignoreStringCase;
	 }

	/**
	 * Get the list of clauses in this Criterion.
	 * @return     array<int, Criterion>
	 */
	public function getClauses(): array
	{
		return $this->clauses;
	}

	/**
	 * Get the list of conjunctions in this Criterion
	 * @return     array<int, string>
	 */
	public function getConjunctions()
	{
		return $this->conjunctions;
	}

	/**
	 * Append an AND Criterion onto this Criterion's list.
	 * @return static
	 */
	public function addAnd(Criterion $criterion)
	{
		$this->clauses[] = $criterion;
		$this->conjunctions[] = self::UND;
		return $this;
	}

	/**
	 * Append an OR Criterion onto this Criterion's list.
	 * @return     Criterion
	 */
	public function addOr(Criterion $criterion)
	{
		$this->clauses[] = $criterion;
		$this->conjunctions[] = self::ODER;
		return $this;
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion
	 * onto the buffer.
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 * @throws     PropulsionException - if the expression builder cannot figure out how to turn a specified
	 *                           expression into proper SQL.
	 */
	public function appendPsTo(&$sb, array &$params): void
	{
		$sb .= str_repeat ( '(', count($this->clauses) );

		$this->dispatchPsHandling($sb, $params);

		foreach ($this->clauses as $key => $clause) {
			$sb .= $this->conjunctions[$key];
			$clause->appendPsTo($sb, $params);
			$sb .= ')';
		}
	}

	/**
	 * Figure out which Criterion method to use
	 * to build the prepared statement and parameters using to the Criterion comparison
	 * and call it to append the prepared statement and the parameters of the current clause
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function dispatchPsHandling(&$sb, array &$params): void
	{
		switch ($this->comparison) {
			case Criteria::CUSTOM:
				// custom expression with no parameter binding
				$this->appendCustomToPs($sb, $params);
				break;
			case Criteria::EXISTS:
			case Criteria::NOT_EXISTS:
				// EXISTS (<subquery>) or NOT EXISTS (<subquery>) -- see Criteria::addExistsQuery()
				$this->appendExistsToPs($sb, $params);
				break;
			case Criteria::IN:
			case Criteria::NOT_IN:
				if ($this->value instanceof Criteria) {
					// table.column IN (<subquery>) or table.column NOT IN (<subquery>) --
					// see Criteria::addInQuery()
					$this->appendInSubqueryToPs($sb, $params);
				} else {
					// table.column IN (?, ?) or table.column NOT IN (?, ?)
					$this->appendInToPs($sb, $params);
				}
				break;
			case Criteria::LIKE:
			case Criteria::NOT_LIKE:
			case Criteria::ILIKE:
			case Criteria::NOT_ILIKE:
				// table.column LIKE ? or table.column NOT LIKE ?  (or ILIKE for Postgres)
				$this->appendLikeToPs($sb, $params);
				break;
			default:
				// table.column = ? or table.column >= ? etc. (traditional expressions, the default)
				$this->appendBasicToPs($sb, $params);
		}
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For a correlated-subquery EXISTS/NOT EXISTS filter -- see Criteria::addExistsQuery().
	 * The subquery's own SQL is generated (and its bound params appended to $params, in the
	 * order its placeholders occur) via a recursive BasePeer::createSelectSql() call, the same
	 * mechanism Criteria::addSelectQuery()'s FROM-clause subqueries already use.
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendExistsToPs(&$sb, array &$params): void
	{
		if (!($this->value instanceof Criteria)) {
			throw new PropulsionException('EXISTS/NOT EXISTS criterion requires a Criteria value');
		}
		$subSql = \Propulsion\Util\BasePeer::createSelectSql($this->value, $params);
		$sb .= $this->comparison . '(' . $subSql . ')';
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For a "table.column IN/NOT IN (<subquery>)" filter -- see Criteria::addInQuery().
	 * Same subquery-generation mechanism as appendExistsToPs().
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendInSubqueryToPs(&$sb, array &$params): void
	{
		if (!($this->value instanceof Criteria)) {
			throw new PropulsionException('IN/NOT IN subquery criterion requires a Criteria value');
		}
		$field = ($this->table === null) ? $this->column : $this->table . '.' . $this->column;
		$subSql = \Propulsion\Util\BasePeer::createSelectSql($this->value, $params);
		$sb .= $field . $this->comparison . '(' . $subSql . ')';
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For custom expressions with no binding, e.g. 'NOW() = 1'
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendCustomToPs(&$sb, array &$params): void
	{
		if ($this->value !== "") {
			if (!is_string($this->value)) {
				throw new PropulsionException('A Criteria::CUSTOM criterion requires a string expression');
			}
			$sb .= $this->value;
		}
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For IN expressions, e.g. table.column IN (?, ?) or table.column NOT IN (?, ?)
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendInToPs(&$sb, array &$params): void
	{
		if ($this->value !== "") {
			$bindParams = array();
			$index = count($params); // to avoid counting the number of parameters for each element in the array
			foreach ((array) $this->value as $value) {
				$params[] = array('table' => $this->realtable, 'column' => $this->column, 'value' => $value);
				$index++; // increment this first to correct for wanting bind params to start with :p1
				$bindParams[] = ':p' . $index;
			}
			if (count($bindParams)) {
				$field = ($this->table === null) ? $this->column : $this->table . '.' . $this->column;
				$sb .= $field . $this->comparison . '(' . implode(',', $bindParams) . ')';
			} else {
				$sb .= ($this->comparison === Criteria::IN) ? "1<>1" : "1=1";
			}
		}
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For LIKE expressions, e.g. table.column LIKE ? or table.column NOT LIKE ?  (or ILIKE for Postgres)
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendLikeToPs(&$sb, array &$params): void
	{
		$field = ($this->table === null) ? $this->column : $this->table . '.' . $this->column;
		if ($field === null) {
			throw new PropulsionException('Could not build SQL for a LIKE/NOT LIKE expression with no column');
		}
		$db = $this->getDb();
		if ($db === null) {
			throw new PropulsionException('Criterion::appendLikeToPs() has no DBAdapter to build SQL with');
		}
		// If selection is case insensitive use ILIKE for PostgreSQL or SQL
		// UPPER() function on column name for other databases.
		//
		// The Postgres branch resolves into a *local* comparison operator rather
		// than writing back to $this->comparison. Rendering SQL is a read of the
		// criterion, and this was the one place it mutated one: a criterion whose
		// LIKE had been rewritten to ILIKE stayed rewritten afterwards. That is
		// invisible on Postgres (the rewrite is idempotent -- a second render sees
		// ILIKE, matches neither branch, and leaves it alone) but not across
		// adapters: the same Criteria rendered for a Postgres datasource and then
		// for a MySQL one carried ILIKE onto MySQL, which has no such operator, so
		// the second query failed with a syntax error. Criteria objects are
		// legitimately reused that way -- getComparison() is also part of the
		// public API and read by Criteria::equals()/addJoinObject()'s dedupe, both
		// of which should see what the caller asked for, not an artefact of which
		// datasource happened to render first.
		$comparison = $this->comparison;
		if ($this->ignoreStringCase) {
			if ($db instanceof DBPostgres) {
				if ($comparison === Criteria::LIKE) {
					$comparison = Criteria::ILIKE;
				} elseif ($comparison === Criteria::NOT_LIKE) {
					$comparison = Criteria::NOT_ILIKE;
				}
			} else {
				$field = $db->ignoreCase($field);
			}
		}

		$params[] = array('table' => $this->realtable, 'column' => $this->column, 'value' => $this->value);

		$sb .= $field . $comparison;

		// If selection is case insensitive use SQL UPPER() function
		// on criteria or, if Postgres we are using ILIKE, so not necessary.
		if ($this->ignoreStringCase && !($db instanceof DBPostgres)) {
			$sb .= $db->ignoreCase(':p'.count($params));
		} else {
			$sb .= ':p'.count($params);
		}
	}

	/**
	 * Appends a Prepared Statement representation of the Criterion onto the buffer
	 * For traditional expressions, e.g. table.column = ? or table.column >= ? etc.
	 *
	 * @param      string &$sb The string that will receive the Prepared Statement
	 * @param      array<int, array{table: string|null, column: string|null, value: mixed}> $params A list to which Prepared Statement parameters will be appended
	 * @return     void
	 */
	protected function appendBasicToPs(&$sb, array &$params): void
	{
		$field = ($this->table === null) ? $this->column : $this->table . '.' . $this->column;
		if ($field === null) {
			throw new PropulsionException('Could not build SQL for an expression with no column');
		}
		// NULL VALUES need special treatment because the SQL syntax is different
		// i.e. table.column IS NULL rather than table.column = null
		if ($this->value !== null) {

			// ANSI SQL functions get inserted right into SQL (not escaped, etc.)
			if ($this->value === Criteria::CURRENT_DATE || $this->value === Criteria::CURRENT_TIME || $this->value === Criteria::CURRENT_TIMESTAMP) {
				$sb .= $field . $this->comparison . $this->value;
			} else {

				$params[] = array('table' => $this->realtable, 'column' => $this->column, 'value' => $this->value);

				// default case, it is a normal col = value expression; value
				// will be replaced w/ '?' and will be inserted later using PDO bindValue()
				if ($this->ignoreStringCase) {
					$db = $this->getDb();
					if ($db === null) {
						throw new PropulsionException('Criterion::appendBasicToPs() has no DBAdapter to build SQL with');
					}
					$sb .= $db->ignoreCase($field) . $this->comparison . $db->ignoreCase(':p'.count($params));
				} else {
					$sb .= $field . $this->comparison . ':p'.count($params);
				}

			}
		} else {

			// value is null, which means it was either not specified or specifically
			// set to null.
			if ($this->comparison === Criteria::EQUAL || $this->comparison === Criteria::ISNULL) {
				$sb .= $field . Criteria::ISNULL;
			} elseif ($this->comparison === Criteria::NOT_EQUAL || $this->comparison === Criteria::ISNOTNULL) {
				$sb .= $field . Criteria::ISNOTNULL;
			} else {
				// for now throw an exception, because not sure how to interpret this
				throw new PropulsionException("Could not build SQL for expression: $field " . $this->comparison . " NULL");
			}

		}
	}

	/**
	 * This method checks another Criteria to see if they contain
	 * the same attributes and hashtable entries.
	 * @param      mixed $obj
	 * @return     boolean
	 */
	public function equals($obj)
	{
		// TODO: optimize me with early outs
		if ($this === $obj) {
			return true;
		}

		if (($obj === null) || !($obj instanceof Criterion)) {
			return false;
		}

		$crit = $obj;

		$isEquiv = ( ( ($this->table === null && $crit->getTable() === null)
			|| ( $this->table !== null && $this->table === $crit->getTable() )
						  )
			&& $this->column === $crit->getColumn()
			&& $this->comparison === $crit->getComparison());

		// check chained criterion
		$isEquiv = $isEquiv && $this->clausesEqual($crit);

		if ($isEquiv) {
			$isEquiv = $this->value === $crit->getValue();
		}

		return $isEquiv;
	}

	/**
	 * Whether this criterion's chained AND/OR clauses match another's, compared
	 * *recursively* rather than by object identity.
	 *
	 * They used to be compared with `===`, so two structurally identical chained
	 * criterions never compared equal unless they literally shared the same
	 * sub-criterion instances -- which nothing in the query builder arranges,
	 * since each `addAnd()`/`addOr()` appends a freshly constructed object. The
	 * effect was that every field of a criterion was compared by value except
	 * its clauses, which made {@see Criteria::equals()} answer "not equal" for
	 * two queries built the same way from the same inputs the moment either grew
	 * a single chained condition. Inherited from Propel 1.
	 *
	 * The clause chain is a tree: `addAnd()`/`addOr()` append an
	 * already-constructed criterion and nothing re-parents one, so this cannot
	 * revisit a node it is already inside unless a caller deliberately builds a
	 * cycle by hand. The `$this === $obj` fast path in the callers covers the
	 * one-node case of that.
	 */
	protected function clausesEqual(Criterion $crit): bool
	{
		$clausesLength = count($this->clauses);
		if (count($crit->getClauses()) !== $clausesLength) {
			return false;
		}

		$critConjunctions = $crit->getConjunctions();
		$critClauses = $crit->getClauses();
		for ($i = 0; $i < $clausesLength; $i++) {
			if ($this->conjunctions[$i] !== $critConjunctions[$i]) {
				return false;
			}
			if (!$this->clauses[$i]->equals($critClauses[$i])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns a hash code value for the object.
	 * @return int
	 */
	public function hashCode()
	{
		$h = crc32(serialize($this->value)) ^ crc32($this->comparison);

		if ($this->table !== null) {
			$h ^= crc32($this->table);
		}

		if ($this->column !== null) {
			$h ^= crc32($this->column);
		}

		foreach ( $this->clauses as $clause ) {
			// TODO: i KNOW there is a php incompatibility with the following line
			// but i dont remember what it is, someone care to look it up and
			// replace it if it doesnt bother us?
			// $clause->appendPsTo($sb='',$params=array());
			$sb = '';
			$params = array();
			$clause->appendPsTo($sb,$params);
			$h ^= crc32(serialize(array($sb,$params)));
			unset ( $sb, $params );
		}

		return $h;
	}

	/**
	 * Get all tables from nested criterion objects
	 * @return     array<int, string|null>
	 */
	public function getAllTables()
	{
		$tables = array();
		$this->addCriterionTable($this, $tables);
		return $tables;
	}

	/**
	 * method supporting recursion through all criterions to give
	 * us a string array of tables from each criterion
	 * @param      array<int, string|null> &$s
	 * @return     void
	 */
	private function addCriterionTable(Criterion $c, array &$s): void
	{
		$s[] = $c->getTable();
		foreach ( $c->getClauses() as $clause ) {
			$this->addCriterionTable($clause, $s);
		}
	}

	/**
	 * get an array of all criterion attached to this
	 * recursing through all sub criterion
	 * @return     array<int, Criterion>
	 */
	public function getAttachedCriterion()
	{
		$criterions = array($this);
		foreach ($this->getClauses() as $criterion) {
			$criterions = array_merge($criterions, $criterion->getAttachedCriterion());
		}
		return $criterions;
	}

	/**
	 * Ensures deep cloning of attached objects.
	 *
	 * That includes a Criteria held as the *value*, which is how
	 * Criteria::addExistsQuery()/addInQuery() store their subquery -- the one
	 * nested Criteria that used to be shared by reference with the original.
	 * Criteria::__clone() already deep-clones its own nested-Criteria collections
	 * (selectQueries, setOperations, CTE queries) for a reason it states there:
	 * isKeepQuery() defaults to true, so every find()/count()/update() clones the
	 * query specifically to avoid mutating the caller's object, and SQL generation
	 * does write to a Criteria it renders (BasePeer::buildSelectSql() sets
	 * ignore-case flags on criterions and calls setDB() on them;
	 * createCountSql() rewrites select columns). Leaving the EXISTS/IN subquery
	 * shared meant those writes reached through a clone into the original, so the
	 * invariant held for four of the five nested-query kinds and silently not for
	 * the fifth.
	 */
	public function __clone()
	{
		foreach ($this->clauses as $key => $criterion) {
			$this->clauses[$key] = clone $criterion;
		}
		if ($this->value instanceof Criteria) {
			$this->value = clone $this->value;
		}
	}
}