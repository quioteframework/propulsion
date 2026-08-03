<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Map;

/**
 * DatabaseMap is used to model a database.
 *
 * GENERAL NOTE
 * ------------
 * The propel.map classes are abstract building-block classes for modeling
 * the database at runtime.  These classes are similar (a lite version) to the
 * propel.engine.database.model classes, which are build-time modeling classes.
 * These classes in themselves do not do any database metadata lookups.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     John D. McNally <jmcnally@collab.net> (Torque)
 * @author     Daniel Rall <dlr@collab.net> (Torque)
 * @version    $Revision$
 */

 use Propulsion\Exception\PropulsionException;
 use Propulsion\Adapter\DBAdapter;
 use Propulsion\Propulsion;
 
class DatabaseMap
{

  /** @var string Name of the database. */
  protected $name;

  /** @var array<string, TableMap> Tables in the database, using table name as key */
  protected $tables = array();

  /** @var array<string, TableMap> Tables in the database, using table phpName as key */
  protected $tablesByPhpName = array();

  /**
   * Constructor.
   *
   * @param      string $name Name of the database.
   */
  public function __construct($name)
  {
    $this->name = $name;
  }

  /**
   * Get the name of this database.
   *
   * @return     string The name of the database.
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * Add a new table to the database by name.
   *
   * @param      string $tableName The name of the table.
   * @return     TableMap The newly created TableMap.
   */
  public function addTable($tableName)
  {
    $this->tables[$tableName] = new TableMap($tableName, $this);
    return $this->tables[$tableName];
  }

  /**
   * Add a new table object to the database.
   *
   * @param      TableMap $table The table to add
   */
  public function addTableObject(TableMap $table): void
  {
    $table->setDatabaseMap($this);
    $this->tables[$table->getName() ?? ''] = $table;
    // Register under the class name as specified in the TableMap
    $phpName = ltrim($table->getClassname() ?? '', '\\');
    $this->tablesByPhpName[$phpName] = $table;
  }

  /**
   * Add a new table to the database, using the tablemap class name.
   *
   * @param      string $tableMapClass The name of the table map to add
   * @return     TableMap The TableMap object
   */
  public function addTableFromMapClass($tableMapClass)
  {
    $table = new $tableMapClass();
    if (!$table instanceof TableMap) {
      throw new PropulsionException(sprintf(
        "Table map class (%s) does not extend %s.",
        get_class($table),
        TableMap::class
      ));
    }
    $tableName = $table->getName();
    if ($tableName === null) {
      throw new PropulsionException("TableMap " . $tableMapClass . " has no name set");
    }
    if(!$this->hasTable($tableName)) {
      $this->addTableObject($table);
      return $table;
    } else {
      return $this->getTable($tableName);
    }
  }

  /**
   * Does this database contain this specific table?
   *
   * @param      string $name The String representation of the table.
   * @return     boolean True if the database contains the table.
   */
  public function hasTable($name)
  {
    // The exact name first. Tables are registered under whatever getName()
    // returns, and for a schema-qualified table that is the *dotted* name
    // ("contest.bookstore_contest" -- see the schemas fixture), which is also
    // what getTable() looks up. Truncating at the first dot unconditionally, as
    // this used to, therefore answered "no" for every schema-qualified table
    // that was in fact registered: it checked $tables['contest']. That made
    // RawQuery::dependsOn() reject legitimate table names outright, and turned
    // the `if (!$dbMap->hasTable(...))` guard in every generated
    // buildTableMap() into a permanent no-op for those tables.
    if (array_key_exists($name, $this->tables)) {
      return true;
    }

    // The historic first-segment fallback is kept: a caller may still be asking
    // about "book.AUTHOR_ID"-shaped input, where the leading segment is the
    // table. Checking it second rather than instead means this can only ever
    // answer "yes" where it used to answer "yes", never the reverse.
    if (strpos($name, '.') > 0) {
      return array_key_exists(substr($name, 0, strpos($name, '.')), $this->tables);
    }

    return false;
  }

  /**
   * Get a TableMap for the table by name.
   *
   * @param      string $name Name of the table.
   * @return     TableMap A TableMap
   * @throws     PropulsionException if the table is undefined
   */
  public function getTable($name)
  {
    if (!isset($this->tables[$name])) {
      throw new PropulsionException("Cannot fetch TableMap for undefined table: " . $name );
    }
    return $this->tables[$name];
  }

  /**
   * Get a TableMap[] of all of the tables in the database.
   *
   * @return     array<string, TableMap> A TableMap[].
   */
  public function getTables(): array
  {
    return $this->tables;
  }

  /**
   * Get a ColumnMap for the column by name.
   * Name must be fully qualified, e.g. book.AUTHOR_ID
   *
   * @param      $qualifiedColumnName Name of the column.
   * @return     ColumnMap A TableMap
   * @throws     PropulsionException if the table is undefined, or if the table is undefined
   */
  public function getColumn(string $qualifiedColumnName)
  {
    // Split at the *last* dot, not the first: a schema-qualified reference has
    // three segments ("contest.bookstore_contest.ID"), and explode()-into-two
    // took ('contest', 'bookstore_contest') from it -- a table that does not
    // exist and a column name that is really the table. The column is always
    // the final segment, and everything before it is the table name as
    // registered (which is what getTable() expects). Matches how
    // ColumnMap::normalizeName() finds the column part of the same string.
    $lastDot = strrpos($qualifiedColumnName, '.');
    if ($lastDot === false) {
      throw new PropulsionException(
        'DatabaseMap::getColumn() expects a qualified "table.column" name, got: ' . $qualifiedColumnName
      );
    }

    return $this->getTable(substr($qualifiedColumnName, 0, $lastDot))
      ->getColumn(substr($qualifiedColumnName, $lastDot + 1), false);
  }

  // deprecated methods

  /**
   * Does this database contain this specific table?
   *
   * @deprecated Use hasTable() instead
   * @param      string $name The String representation of the table.
   * @return     boolean True if the database contains the table.
   */
  public function containsTable($name)
  {
    return $this->hasTable($name);
  }

  public function getTableByPhpName(string $phpName): TableMap
  {
    $requestedName = $phpName = ltrim($phpName, '\\'); // Normalize key
    if (array_key_exists($phpName, $this->tablesByPhpName)) {
      return $this->tablesByPhpName[$phpName];
    }

    // Everything below is the slow path -- a regex, up to two class_exists()
    // calls, and possibly instantiating a TableMap. Whatever it resolves to gets
    // registered under the name that was *asked for* as well (see the end of
    // this method), because addTableObject() only registers under the map's own
    // getClassname(). When the two differ -- exactly the OM\Base* case handled
    // just below -- the lookup above kept missing and this ran again on every
    // single call.

    // Convert OM base class to concrete class if needed
    // Pattern: Jakamo\Jakamo\OM\BaseMdiUser -> Jakamo\Jakamo\MdiUser
    if (preg_match('/^(.+)\\\\OM\\\\Base(.+)$/', $phpName, $matches)) {
      $concreteClassName = $matches[1] . '\\' . $matches[2];
      if (array_key_exists($concreteClassName, $this->tablesByPhpName)) {
        return $this->rememberTableByPhpName($requestedName, $this->tablesByPhpName[$concreteClassName]);
      }
      // Try dynamic loading with concrete class name
      $phpName = $concreteClassName;
    }

    // Try loading TableMap dynamically
    if (class_exists($tmClass = $phpName . 'TableMap')) {
      return $this->rememberTableByPhpName($requestedName, $this->addTableFromMapClass($tmClass));
    }

    // Try with Map namespace insertion (note capital M)
    $lastNsSepPos = strrpos($phpName, '\\');
    if ($lastNsSepPos !== false && class_exists($tmClass = substr_replace($phpName, '\\Map\\', $lastNsSepPos, 1) . 'TableMap')) {
      return $this->rememberTableByPhpName($requestedName, $this->addTableFromMapClass($tmClass));
    }

    throw new PropulsionException("Cannot fetch TableMap for undefined table phpName: " . $phpName);
  }

  /**
   * Also index $table under the phpName the caller actually asked for, so the
   * fast path in getTableByPhpName() catches the next identical lookup.
   *
   * addTableObject() indexes a map under its own getClassname(); when the
   * requested name differs from that (a generated `...\OM\Base*` class name, or
   * a name that only resolved via the `\Map\` namespace insertion) nothing ever
   * cached the resolution, so every call re-ran the regex, the class_exists()
   * probes, and sometimes addTableFromMapClass(). Aliasing is safe because it
   * only ever adds a second key pointing at the same TableMap instance.
   */
  private function rememberTableByPhpName(string $requestedName, TableMap $table): TableMap
  {
    $this->tablesByPhpName[$requestedName] = $table;

    return $table;
  }

  /**
   * Convenience method to get the DBAdapter registered with Propulsion for this database.
   * @return  DBAdapter
   * @see     Propulsion::getDB(string)
   */
  public function getDBAdapter()
  {
    return Propulsion::getDB($this->name);
  }
}
