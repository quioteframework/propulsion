<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM;

/**
 * Generates a PHP 8.4 base Peer class for user object model (OM).
 *
 * This class produces the base peer class (e.g. BaseMyPeer) which contains all
 * the custom-built query and manipulator methods with modern PHP 8.4 features:
 * - Static properties for metadata
 * - Enum-backed constants where appropriate
 * - Typed static methods
 * - Modern array syntax and operations
 * - Exception handling with typed exceptions
 *
 * @author     GitHub Copilot
 * @package    propel.generator.builder.om
 */
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\PropelTypes;
use Propulsion\Generator\Model\IDMethod;

class PHP84PeerBuilder extends PeerBuilder
{

	/**
	 * Gets the package for the [base] object classes.
	 * @return     string
	 */
	public function getPackage(): string
	{
		return parent::getPackage() . ".OM";
	}

	public function getNamespace(): string
	{
		if ($namespace = parent::getNamespace()) {
			if ($this->getGeneratorConfig() && $omns = $this->getGeneratorConfig()->getBuildProperty('namespaceOm')) {
				return $namespace . '\\' . $omns;
			} else {
				return $namespace;
			}
		}
		return '';
	}

	/**
	 * Returns the name of the current class being built.
	 * @return     string
	 */
	public function getUnprefixedClassname(): string
	{
		return $this->getBuildProperty('basePrefix') . $this->getStubPeerBuilder()->getUnprefixedClassname();
	}

	/**
	 * Validates the current table to make sure that it won't
	 * result in generated code that will not parse.
	 */
	protected function validateModel(): void
	{
		parent::validateModel();

		$table = $this->getTable();

		// Check to see if any of the column constants are PHP reserved words.
		$colConstants = array();

		foreach ($table->getColumns() as $col) {
			if ($col->getPeerName()) {
				$colConstants[] = strtoupper($col->getPeerName());
			} else {
				$colConstants[] = strtoupper($col->getName());
			}
		}

		$reservedConstants = array_map('strtoupper', ClassTools::getPhpReservedWords());

		$intersect = array_intersect($reservedConstants, $colConstants);
		if (!empty($intersect)) {
			throw new EngineException("One or more of your column names for [" . $table->getName() . "] table conflict with a PHP reserved word (" . implode(", ", $intersect) . ")");
		}
	}

	/**
	 * Adds the include() statements for files that this class depends on or utilizes.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addIncludes(&$script = null)
	{
		// PHP 8.4 uses namespaces and autoloading, so includes are minimal
	}

	/**
	 * Adds class phpdoc comment and opening of class.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addClassOpen(&$script)
	{
		$table = $this->getTable();
		$tableName = $table->getName();
		$tableDesc = $table->getDescription();

		$script .= "
/**
 * Base peer class for the '$tableName' table.
 *
 * $tableDesc
 *
 * This class uses PHP 8.4 features including:
 * - Static properties for metadata
 * - Typed static methods
 * - Modern exception handling
 * - Enhanced array operations
 *";
		if ($this->getBuildProperty('addTimeStamp')) {
			$now = date('c');
			$script .= "
 * @generated on $now";
		}
		$extendingPeerClass = '';
		$parentClass = $this->getBehaviorContent('parentClass');
		if (null !== $parentClass) {
			$extendingPeerClass = ' extends ' . $parentClass;
		} elseif ($this->basePeerClassname) {
			$extendingPeerClass = ' extends ' . $this->basePeerClassname;
		}

		$script .= "
 *
 * @package    " . $this->getPackage() . "
 */
abstract class " . $this->getClassname() . $extendingPeerClass . "
{";
	}

	/**
	 * Adds the constants for the class
	 */
	protected function addConstants(&$script)
	{
		$table = $this->getTable();

		$script .= "

	
	/** Number of columns in table */
	public const NUM_COLUMNS = " . count($table->getColumns()) . ";

	/** Number of lazy-loaded columns */
	public const NUM_LAZY_LOADED_COLUMNS = " . count($this->getLazyLoadColumns()) . ";

	/** Number of columns to hydrate (all non-lazy) */
	public const NUM_HYDRATE_COLUMNS = " . (count($table->getColumns()) - count($this->getLazyLoadColumns())) . ";";

		// Add column constants
		$script .= "

	// Column constants with modern naming";
		foreach ($table->getColumns() as $col) {
			$script .= "
	public const " . strtoupper($col->getName()) . " = '" . $table->getName() . "." . $col->getName() . "';";
		}

		// Add column types enum-style constants  
		$script .= "

	// Column type constants";
		foreach ($table->getColumns() as $col) {
			$script .= "
	public const " . strtoupper($col->getName()) . "_DATATYPE = '" . $col->getType() . "';";
		}
	}

	/**
	 * Adds static properties for metadata
	 */
	protected function addStaticProperties(&$script)
	{
		$table = $this->getTable();

		$script .= "

	/**
	 * The total number of columns
	 */
	protected static int \$numColumns = " . count($table->getColumns()) . ";

	/**
	 * Column names mapped to positions (0-based)
	 */
	protected static array \$columnMap = [";

		$i = 0;
		foreach ($table->getColumns() as $col) {
			$script .= "
		'" . $col->getName() . "' => $i,";
			$i++;
		}

		$script .= "
	];

	/**
	 * PHP names mapped to column names
	 */
	protected static array \$phpNameMap = [";

		foreach ($table->getColumns() as $col) {
			$script .= "
		'" . $col->getPhpName() . "' => '" . $col->getName() . "',";
		}

		$script .= "
	];

	/**
	 * Field types mapped by column name
	 */
	protected static array \$fieldTypes = [";

		foreach ($table->getColumns() as $col) {
			$script .= "
		'" . $col->getName() . "' => '" . $col->getType() . "',";
		}

		$script .= "
	];";
	}

	/**
	 * Adds the main class body
	 */
	protected function addClassBody(&$script)
	{
		// Declare essential classes for Base*Peer classes
		$this->declareClassFromBuilder($this->getStubPeerBuilder());
		$this->declareClassFromBuilder($this->getStubObjectBuilder());
		$this->declareClass('Propulsion\\OM\\BaseObject');
		$this->declareClass('Propulsion\\OM\\Persistent');
		$this->declareClass('Propulsion\\Exception\\PropelException');
		$this->declareClass('Propulsion\\Util\\BasePeer');
		$this->declareClass('Propulsion\\Connection\\PropelPDO');
		$this->declareClass('Propulsion\\Query\\Criteria');
		$this->declareClass('Propulsion\\Propel');
		$this->declareClass('\\DateTime');
		$this->declareClass('\\DateTimeInterface');
		$this->declareClass('\\Exception');
		$this->declareClass('\\PDO');
		$this->declareClass('\\PDOStatement');

		parent::addClassBody($script);
		// Removed duplicate declareClasses() call that was causing duplicate use statements
	}

	/**
	 * Override getUseStatements to provide PHP 8.4 compatible use statements with deduplication
	 */
	public function getUseStatements($ignoredNamespace = null)
	{
		$script = '';
		$declaredClasses = $this->declaredClasses;
		unset($declaredClasses[$ignoredNamespace]);
		
		// Build a map of class names to their preferred fully qualified names
		$classMap = [];
		$preferredNamespaces = [
			'PropelException' => 'Propulsion\\Exception\\PropelException',
			'BasePeer' => 'Propulsion\\Util\\BasePeer',
			'Criteria' => 'Propulsion\\Query\\Criteria',
			'ModelCriteria' => 'Propulsion\\Query\\ModelCriteria',
			'ModelJoin' => 'Propulsion\\Query\\ModelJoin',
			'PropelPDO' => 'Propulsion\\Connection\\PropelPDO',
			'PropelCollection' => 'Propulsion\\Collection\\PropelCollection',
			'Propel' => 'Propulsion\\Propel',
			'BaseObject' => 'Propulsion\\OM\\BaseObject',
			'Persistent' => 'Propulsion\\OM\\Persistent'
		];
		
		// Collect all classes and prefer properly namespaced versions
		foreach ($declaredClasses as $namespace => $classes) {
			foreach ($classes as $class) {
				$fullName = $namespace ? $namespace . '\\' . $class : $class;
				
				// Skip root namespace classes that have preferred namespaced versions
				if ($namespace === '' && isset($preferredNamespaces[$class])) {
					continue;
				}
				
				// Use the class name as the key for deduplication
				$classMap[$class] = $fullName;
			}
		}
		
		// Sort by fully qualified name for consistency
		asort($classMap);
		
		foreach ($classMap as $className => $fullName) {
			if (strpos($fullName, '\\') === 0) {
				// Global namespace class (starts with \)
				$script .= sprintf("use %s;\n", $fullName);
			} else {
				// Namespaced class
				$script .= sprintf("use %s;\n", $fullName);
			}
		}
		
		return $script;
	}

	/**
	 * Adds constant and variable declarations that go at the top of the class.
	 */
	protected function addConstantsAndAttributes(&$script)
	{
		$dbName = $this->getDatabase()->getName();
		$tableName = $this->getTable()->getName();
		$tablePhpName = $this->getTable()->isAbstract() ? '' : addslashes($this->getStubObjectBuilder()->getFullyQualifiedClassname());
		$script .= "
	/** the default database name for this class */
	const DATABASE_NAME = '$dbName';

	/** the table name for this class */
	const TABLE_NAME = '$tableName';

	/** the related Propel class for this table */
	const OM_CLASS = '$tablePhpName';

	/** A class that can be returned by this peer. */
	const CLASS_DEFAULT = '".$this->getStubObjectBuilder()->getFullyQualifiedClassname()."';

	/** the related TableMap class for this table */
	const TM_CLASS = '".$this->getTableMapClass()."';

	/** The total number of columns. */
	const NUM_COLUMNS = ".$this->getTable()->getNumColumns().";

	/** The number of lazy-loaded columns. */
	const NUM_LAZY_LOAD_COLUMNS = ".$this->getTable()->getNumLazyLoadColumns().";

	/** The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS) */
	const NUM_HYDRATE_COLUMNS = ". ($this->getTable()->getNumColumns() - $this->getTable()->getNumLazyLoadColumns()) .";
";
		$this->addColumnNameConstants($script);
		$this->addInheritanceColumnConstants($script);
		if ($this->getTable()->hasEnumColumns()) {
			$this->addEnumColumnConstants($script);
		}

		$script .= "
	/** The default string format for model objects of the related table **/
	const DEFAULT_STRING_FORMAT = '" . $this->getTable()->getDefaultStringFormat() . "';

	/**
	 * An identiy map to hold any loaded instances of ".$this->getObjectClassname()." objects.
	 * This must be public so that other peer classes can access this when hydrating from JOIN
	 * queries.
	 * @var        array ".$this->getObjectClassname()."[]
	 */
	public static \$instances = array();

";

		// apply behaviors
		$this->applyBehaviorModifier('staticConstants', $script, "	");
		$this->applyBehaviorModifier('staticAttributes', $script, "	");

		$this->addFieldNamesAttribute($script);
		$this->addFieldKeysAttribute($script);

		if ($this->getTable()->hasEnumColumns()) {
			$this->addEnumColumnAttributes($script);
		}
	}

	/**
	 * Adds the COLUMN_NAME contants to the class definition.
	 */
	protected function addColumnNameConstants(&$script)
	{
		foreach ($this->getTable()->getColumns() as $col) {
			$script .= "
	/** the column name for the " . strtoupper($col->getName()) ." field */
	const ".$this->getColumnName($col) ." = '" . $this->getTable()->getName() . ".".strtoupper($col->getName())."';
";
		}
	}

	/**
	 * Adds the valueSet constants for ENUM columns.
	 */
	protected function addEnumColumnConstants(&$script)
	{
		foreach ($this->getTable()->getColumns() as $col) {
			if ($col->isEnumType()) {
				$script .= "
	/** The enumerated values for the " . strtoupper($col->getName()) . " field */";
				foreach ($col->getValueSet() as $value) {
					$script .= "
	const " . $this->getColumnName($col) . '_' . $this->getEnumValueConstant($value) . " = '" . $value . "';";
				}
				$script .= "
";
			}
		}
	}

	protected function getEnumValueConstant($value)
	{
		return strtoupper(preg_replace('/[^a-zA-Z0-9_\x7f-\xff]/', '_', $value));
	}

	protected function addFieldNamesAttribute(&$script)
	{
		$table = $this->getTable();
		$tableColumns = $table->getColumns();

		$script .= "
	/**
	 * holds an array of fieldnames
	 *
	 * first dimension keys are the type constants
	 * e.g. self::\$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
	 */
	protected static \$fieldNames = array (
		BasePeer::TYPE_PHPNAME => array (";
		foreach ($tableColumns as $col) {
			$script .= "'".$col->getPhpName()."', ";
		}
		$script .= "),
		BasePeer::TYPE_STUDLYPHPNAME => array (";
		foreach ($tableColumns as $col) {
			$script .= "'".$col->getStudlyPhpName()."', ";
		}
		$script .= "),
		BasePeer::TYPE_COLNAME => array (";
		foreach ($tableColumns as $col) {
			$script .= $this->getColumnConstant($col, 'self').", ";
		}
		$script .= "),
		BasePeer::TYPE_RAW_COLNAME => array (";
		foreach ($tableColumns as $col) {
			$script .= "'" . $col->getConstantColumnName() . "', ";
		}
		$script .= "),
		BasePeer::TYPE_FIELDNAME => array (";
		foreach ($tableColumns as $col) {
			$script .= "'".$col->getName()."', ";
		}
		$script .= "),
		BasePeer::TYPE_NUM => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "$num, ";
		}
		$script .= ")
	);
";
	}

	protected function addFieldKeysAttribute(&$script)
	{
		$table = $this->getTable();
		$tableColumns = $table->getColumns();

		$script .= "
	/**
	 * holds an array of keys for quick access to the fieldnames array
	 *
	 * first dimension keys are the type constants
	 * e.g. self::\$fieldNames[BasePeer::TYPE_PHPNAME]['Id'] = 0
	 */
	protected static \$fieldKeys = array (
		BasePeer::TYPE_PHPNAME => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "'".$col->getPhpName()."' => $num, ";
		}
		$script .= "),
		BasePeer::TYPE_STUDLYPHPNAME => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "'".$col->getStudlyPhpName()."' => $num, ";
		}
		$script .= "),
		BasePeer::TYPE_COLNAME => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= $this->getColumnConstant($col, 'self')." => $num, ";
		}
		$script .= "),
		BasePeer::TYPE_RAW_COLNAME => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "'" . $col->getConstantColumnName() . "' => $num, ";
		}
		$script .= "),
		BasePeer::TYPE_FIELDNAME => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "'".$col->getName()."' => $num, ";
		}
		$script .= "),
		BasePeer::TYPE_NUM => array (";
		foreach ($tableColumns as $num => $col) {
			$script .= "$num, ";
		}
		$script .= ")
	);
";
	}

	/**
	 * Adds the valueSet attributes for ENUM columns.
	 */
	protected function addEnumColumnAttributes(&$script)
	{
		$script .= "
	/** The enumerated values for this table */
	protected static \$enumValueSets = array(";
		foreach ($this->getTable()->getColumns() as $col) {
			if ($col->isEnumType()) {
				$script .= "
		self::" . $this->getColumnName($col) ." => array(
";
				foreach ($col->getValueSet() as $value) {
					$script .= "			" . $this->getStubPeerBuilder()->getClassname() . '::' . $this->getColumnName($col) . '_' . $this->getEnumValueConstant($value) . ",
";
				}
				$script .= "		),";
			}
		}
		$script .= "
	);
";
	}

	protected function addGetFieldNames(&$script)
	{
		$script .= "    /**
     * Returns an array of field names.
     *
     * @param      string \$type The type of fieldnames to return:
     *                      One of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME
     *                      BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM
     * @return     array A list of field names
     */
    static public function getFieldNames(string \$type = BasePeer::TYPE_PHPNAME)
	{
		if (!array_key_exists(\$type, self::\$fieldNames)) {
			throw new PropelException('Method getFieldNames() expects the parameter \$type to be one of the class constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME, BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM. ' . \$type . ' was given.');
		}
		return self::\$fieldNames[\$type];
	}
";
	}

	/**
	 * Adds the getValueSets() method.
	 */
	protected function addGetValueSets(&$script)
	{
		$this->declareClassFromBuilder($this->getTableMapBuilder());
		$callingClass = $this->getStubPeerBuilder()->getClassname();
		$script .= "
	/**
	 * Gets the list of values for all ENUM columns
	 * @return array
	 */
	public static function getValueSets()
	{
	  return {$callingClass}::\$enumValueSets;
	}
";
	}

	/**
	 * Adds the getValueSet() method.
	 */
	protected function addGetValueSet(&$script)
	{
		$this->declareClassFromBuilder($this->getTableMapBuilder());
		$script .= "
	/**
	 * Gets the list of values for an ENUM column
	 * @return array list of possible values for the column
	 */
	public static function getValueSet(\$colname)
	{
		\$valueSets = self::getValueSets();
		return \$valueSets[\$colname];
	}
";
	}

	/**
	 * Adds the alias() utility method.
	 */
	protected function addAlias(&$script)
	{
		$script .= "
	/**
	 * Convenience method which changes table.column to alias.column.
	 *
	 * Using this method you can maintain SQL abstraction while using column aliases.
	 * <code>
	 *		\$c->addAlias(\"alias1\", TablePeer::TABLE_NAME);
	 *		\$c->addJoin(TablePeer::alias(\"alias1\", TablePeer::PRIMARY_KEY_COLUMN), TablePeer::PRIMARY_KEY_COLUMN);
	 * </code>
	 * @param      string \$alias The alias for the current table.
	 * @param      string \$column The column name for current table. (i.e. ".$this->getPeerClassname()."::COLUMN_NAME).
	 * @return     string
	 */
	public static function alias(\$alias, \$column)
	{
		return str_replace(".$this->getPeerClassname()."::TABLE_NAME.'.', \$alias.'.', \$column);
	}
";
	}

	/**
	 * Adds the buildTableMap() method.
	 */
	protected function addBuildTableMap(&$script)
	{
		$this->declareClassFromBuilder($this->getTableMapBuilder());
		$script .= "
	/**
	 * Add a TableMap instance to the database for this peer class.
	 */
	public static function buildTableMap()
	{
	  \$dbMap = Propel::getDatabaseMap(".$this->getClassname()."::DATABASE_NAME);
	  if (!\$dbMap->hasTable(".$this->getClassname()."::TABLE_NAME))
	  {
	    \$dbMap->addTableObject(new ".$this->getTableMapClass()."());
	  }
	}
";
	}

	/**
	 * Adds the getTableMap() method.
	 */
	protected function addGetTableMap(&$script)
	{
		$script .= "
	/**
	 * Returns the TableMap related to this peer.
	 * This method is not needed for general use but a specific application could have a need.
	 * @return     \Propulsion\Map\TableMap
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function getTableMap()
	{
		return Propel::getDatabaseMap(self::DATABASE_NAME)->getTable(self::TABLE_NAME);
	}
";
	}

	public function getTableMapClass(): string
	{
		return $this->getTablePhpName() . 'TableMap';
	}

	public function getTablePhpName(): string
	{
		return ($this->getTable()->isAbstract() ? '' : $this->getStubObjectBuilder()->getClassname());
	}

	/**
	 * Adds the CLASSKEY_* and CLASSNAME_* constants used for inheritance.
	 */
	public function addInheritanceColumnConstants(string &$script): void
	{
		if ($this->getTable()->getChildrenColumn()) {
			$col = $this->getTable()->getChildrenColumn();
			$cfc = $col->getPhpName();

			if ($col->isEnumeratedClasses()) {
				foreach ($col->getChildren() as $child) {
					$childBuilder = $this->getMultiExtendObjectBuilder();
					$childBuilder->setChild($child);
					$script .= "
	/** A key representing a particular subclass */
	const CLASSKEY_".strtoupper($child->getKey())." = '" . $child->getKey() . "';
";

					if (strtoupper($child->getClassname()) != strtoupper($child->getKey())) {
						$script .= "
	/** A key representing a particular subclass */
	const CLASSKEY_".strtoupper($child->getClassname())." = '" . $child->getKey() . "';
";
					}

					$script .= "
	/** A class that can be returned by this peer. */
	const CLASSNAME_".strtoupper($child->getKey())." = '". $childBuilder->getClasspath() . "';
";
				}
			}
		}
	}

	/**
	 * Adds doInsert method - matches PHP5PeerBuilder signature
	 */
	protected function addDoInsert(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Performs an INSERT on the database, given a ".$this->getObjectClassname()." or Criteria object.
	 *
	 * @param      mixed \$values Criteria or ".$this->getObjectClassname()." object containing data that is used to create the INSERT statement.
	 * @param      PropelPDO \$con the PropelPDO connection to use
	 * @return     mixed The new primary key.
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function doInsert(\$values, ?PropelPDO \$con = null)
	{
		if (\$con === null) {
			\$con = Propel::getConnection(self::DATABASE_NAME, Propel::CONNECTION_WRITE);
		}

		if (\$values instanceof Criteria) {
			\$criteria = clone \$values; // rename for clarity
		} else {
			\$criteria = \$values->buildCriteria(); // build Criteria from ".$this->getObjectClassname()." object
		}
";

		foreach ($table->getColumns() as $col) {
			$cfc = $col->getPhpName();
			if ($col->isPrimaryKey() && $col->isAutoIncrement() && $table->getIdMethod() != "none" && !$table->isAllowPkInsert()) {
				$script .= "
		if (\$criteria->containsKey(".$this->getColumnConstant($col).") && \$criteria->keyContainsValue(" . $this->getColumnConstant($col) . ") ) {
			throw new PropelException('Cannot insert a value for auto-increment primary key ('.".$this->getColumnConstant($col).".')');
		}
";
				if (!$this->getPlatform()->supportsInsertNullPk()) {
					$script .= "
		// remove pkey col since this table uses auto-increment and passing a null value for it is not valid
		\$criteria->remove(".$this->getColumnConstant($col).");
";
				}
			} elseif ($col->isPrimaryKey() && $col->isAutoIncrement() && $table->getIdMethod() != "none" && $table->isAllowPkInsert() && !$this->getPlatform()->supportsInsertNullPk()) {
				$script .= "
		// remove pkey col if it is null since this table does not accept that
		if (\$criteria->containsKey(".$this->getColumnConstant($col).") && !\$criteria->keyContainsValue(" . $this->getColumnConstant($col) . ") ) {
			\$criteria->remove(".$this->getColumnConstant($col).");
		}
";
			}
		}
		$script .= "

		// Set the correct dbName
		\$criteria->setDbName(self::DATABASE_NAME);

		try {
			// use transaction because \$criteria could contain info
			// for more than one table (I guess, conceivably)
			\$con->beginTransaction();
			\$pk = ".$this->basePeerClassname."::doInsert(\$criteria, \$con);
			\$con->commit();
		} catch(PropelException \$e) {
			\$con->rollBack();
			throw \$e;
		}

		return \$pk;
	}
";
	}

	protected function addTranslateFieldName(&$script)
	{
		$script .= "    /**
     * Translates a fieldname to another type
     *
     * @param      string \$name field name
     * @param      string \$fromType One of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME
     *                         BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM
     * @param      string \$toType   One of the class type constants
     * @return     string translated name of the field.
     * @throws     PropelException - if the specified name could not be found in the fieldname mappings.
     */
    static public function translateFieldName(string \$name, string \$fromType, string \$toType)
	{
		\$toNames = self::getFieldNames(\$toType);
		\$key = isset(self::\$fieldKeys[\$fromType][\$name]) ? self::\$fieldKeys[\$fromType][\$name] : null;
		if (\$key === null) {
			throw new PropelException(\"'\$name' could not be found in the field names of type '\$fromType'. These are: \" . print_r(self::\$fieldKeys[\$fromType], true));
		}
		return \$toNames[\$key];
	}
";
	}

	/**
	 * Adds doUpdateThis method - PHP 8.4 LSP compliant version of doUpdate
	 */
	protected function addDoUpdate(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Performs an UPDATE on the database, given a ".$this->getObjectClassname()." or Criteria object.
	 * This method is renamed from doUpdate to avoid LSP violations in PHP 8.4.
	 *
	 * @param      mixed \$values Criteria or ".$this->getObjectClassname()." object containing data that is used to create the UPDATE statement.
	 * @param      PropelPDO \$con The connection to use (specify PropelPDO connection object to exert more control over transactions).
	 * @return     int The number of affected rows (if supported by underlying database driver).
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function doUpdateThis(\$values, ?PropelPDO \$con = null)
	{
		if (\$con === null) {
			\$con = Propel::getConnection(self::DATABASE_NAME, Propel::CONNECTION_WRITE);
		}

		\$selectCriteria = new Criteria(self::DATABASE_NAME);

		if (\$values instanceof Criteria) {
			\$criteria = clone \$values; // rename for clarity
";
		foreach ($table->getColumns() as $col) {
			if ($col->isPrimaryKey()) {
				$script .= "
			\$comparison = \$criteria->getComparison(".$this->getColumnConstant($col).");
			\$value = \$criteria->remove(".$this->getColumnConstant($col).");
			if (\$value) {
				\$selectCriteria->add(".$this->getColumnConstant($col).", \$value, \$comparison);
			} else {
				\$selectCriteria->setPrimaryTableName(self::TABLE_NAME);
			}";
			}
		}

		$script .= "
		} else { // \$values is ".$this->getObjectClassname()." object
			\$criteria = \$values->buildCriteria(); // gets full criteria
			\$selectCriteria = \$values->buildPkeyCriteria(); // gets criteria w/ primary key(s)
		}

		// set the correct dbName
		\$criteria->setDbName(self::DATABASE_NAME);

		return {$this->basePeerClassname}::doUpdate(\$selectCriteria, \$criteria, \$con);
	}
";
	}

	/**
	 * Adds doSelect method - matches PHP5PeerBuilder signature
	 */
	protected function addDoSelect(&$script)
	{
		$script .= "
	/**
	 * Selects several row from the DB.
	 *
	 * @param      Criteria \$criteria The Criteria object used to build the SELECT statement.
	 * @param      PropelPDO \$con
	 * @return     array Array of selected Objects
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function doSelect(Criteria \$criteria, ?PropelPDO \$con = null)
	{
		return ".$this->getPeerClassname()."::populateObjects(".$this->getPeerClassname()."::doSelectStmt(\$criteria, \$con));
	}";
	}

	/**
	 * Adds doSelectOne method - matches PHP5PeerBuilder signature
	 */
	protected function addDoSelectOne(&$script)
	{
		$script .= "
	/**
	 * Selects one object from the DB.
	 *
	 * @param      Criteria \$criteria object used to create the SELECT statement.
	 * @param      PropelPDO \$con
	 * @return     ".$this->getObjectClassname()."
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function doSelectOne(Criteria \$criteria, ?PropelPDO \$con = null)
	{
		\$critcopy = clone \$criteria;
		\$critcopy->setLimit(1);
		\$objects = ".$this->getPeerClassname()."::doSelect(\$critcopy, \$con);
		if (\$objects) {
			return \$objects[0];
		}
		return null;
	}";
	}

	/**
	 * Adds doSelectStmt method - matches PHP5PeerBuilder signature
	 */
	protected function addDoSelectStmt(&$script)
	{
		$script .= "
	/**
	 * Prepares the Criteria object and uses the parent doSelect() method to execute a PDOStatement.
	 *
	 * Use this method directly if you want to work with an executed statement durirectly (for example
	 * to perform your own object hydration).
	 *
	 * @param      Criteria \$criteria The Criteria object used to build the SELECT statement.
	 * @param      PropelPDO \$con The connection to use
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 * @return     PDOStatement The executed PDOStatement object.
	 * @see        ".$this->basePeerClassname."::doSelect()
	 */
	public static function doSelectStmt(Criteria \$criteria, ?PropelPDO \$con = null)
	{
		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_READ);
		}

		if (!\$criteria->hasSelectClause()) {
			\$criteria = clone \$criteria;
			".$this->getPeerClassname()."::addSelectColumns(\$criteria);
		}

		// Set the correct dbName
		\$criteria->setDbName(self::DATABASE_NAME);";

		if ($this->hasBehaviorModifier('preSelect')) {
			$this->applyBehaviorModifier('preSelect', $script);
		}

		$script .= "

		// BasePeer returns a PDOStatement
		return ".$this->basePeerClassname."::doSelect(\$criteria, \$con);
	}";
	}

	/**
	 * Adds doCount method - wrapper pattern for PHP 8.4 compatibility
	 */
	protected function addDoCount(&$script)
	{
		$script .= "
	/**
	 * Returns the number of rows matching criteria.
	 *
	 * @param      Criteria \$criteria
	 * @param      boolean \$distinct Whether to select only distinct columns; deprecated: use Criteria->setDistinct() instead.
	 * @param      PropelPDO \$con
	 * @return     int Number of matching rows.
	 */
	public static function doCount(Criteria \$criteria, \$distinct = false, ?PropelPDO \$con = null)
	{
		return self::doCountThis(\$criteria, \$distinct, \$con);
	}

	/**
	 * Returns the number of rows matching criteria - PHP 8.4 LSP compliant version.
	 *
	 * @param      Criteria \$criteria
	 * @param      bool \$distinct Whether to select only distinct columns; deprecated: use Criteria->setDistinct() instead.
	 * @param      PropelPDO|null \$con
	 * @return     int Number of matching rows.
	 */
	public static function doCountThis(Criteria \$criteria, bool \$distinct = false, ?PropelPDO \$con = null)
	{
		// we may modify criteria, so copy it first
		\$criteria = clone \$criteria;

		// We need to set the primary table name, since in the case that there are no WHERE columns
		// it will be impossible for the BasePeer::createSelectSql() method to determine which
		// tables go into the FROM clause.
		\$criteria->setPrimaryTableName(".$this->getPeerClassname()."::TABLE_NAME);

		if (\$distinct && !in_array(Criteria::DISTINCT, \$criteria->getSelectModifiers())) {
			\$criteria->setDistinct();
		}

		if (!\$criteria->hasSelectClause()) {
			".$this->getPeerClassname()."::addSelectColumns(\$criteria);
		}

		\$criteria->clearOrderByColumns(); // ORDER BY won't ever affect the count
		\$criteria->setDbName(self::DATABASE_NAME); // Set the correct dbName

		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_READ);
		}";

		$this->applyBehaviorModifier('preSelect', $script);

		$script .= "
		// BasePeer returns a PDOStatement
		\$stmt = ".$this->basePeerClassname."::doCount(\$criteria, \$con);

		if (\$row = \$stmt->fetch(PDO::FETCH_NUM)) {
			\$count = (int) \$row[0];
		} else {
			\$count = 0; // no rows returned; we infer that means 0 matches.
		}
		\$stmt->closeCursor();
		return \$count;
	}";
	}

	/**
	 * Adds populateObjects method - matches PHP5PeerBuilder
	 */
	protected function addPopulateObjects(&$script)
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * The returned array will contain objects of the default type or
	 * objects that inherit from the default.
	 *
	 * @param \\PDOStatement \$stmt
	 * @return array
	 * @throws PropelException Any exceptions caught during processing will be rethrown wrapped into a PropelException.
	 */
	public static function populateObjects(\\PDOStatement \$stmt): array
	{
		\$results = array();";

		if (!$table->getChildrenColumn()) {
			$script .= "
		// set the class once to avoid overhead in the loop
		\$cls = self::getOMClass();";
		}

		$script .= "
		// populate the object(s)
		while (\$row = \$stmt->fetch(PDO::FETCH_NUM)) {
			\$key = self::getPrimaryKeyHashFromRow(\$row, 0);
			if (null !== (\$obj = self::getInstanceFromPool(\$key))) {
				// We no longer rehydrate the object, since this can cause data loss.
				// See http://www.propelorm.org/ticket/509
				// \$obj->hydrate(\$row, 0, true); // rehydrate
				\$results[] = \$obj;
			} else {";

		if ($table->getChildrenColumn()) {
			$script .= "
				// class must be set each time from the record row
				\$cls = self::getOMClass(\$row, 0);
				\$cls = substr('.'.\$cls, strrpos('.'.\$cls, '.') + 1);
				" . $this->buildObjectInstanceCreationCode('$obj', '$cls') . "
				\$obj->hydrate(\$row);
				\$results[] = \$obj;
				self::addInstanceToPool(\$obj, \$key);";
		} else {
			$script .= "
				" . $this->buildObjectInstanceCreationCode('$obj', '$cls') . "
				\$obj->hydrate(\$row);
				\$results[] = \$obj;
				self::addInstanceToPool(\$obj, \$key);";
		}

		$script .= "
			} // if key exists
		}
		\$stmt->closeCursor();
		return \$results;
	}";
	}

	/**
	 * Adds addSelectColumns method
	 */
	protected function addAddSelectColumns(&$script)
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Add all the columns needed to create a new object.
	 *
	 * Note: any columns that were marked with lazyLoad=\"true\" in the
	 * XML schema will not be added to the select list and only loaded
	 * on demand.
	 *
	 * @param Criteria \$criteria object containing the columns to add.
	 * @param ?string \$alias optional table alias
	 * @throws PropelException Any exceptions caught during processing will be rethrown wrapped into a PropelException.
	 */
	public static function addSelectColumns(Criteria \$criteria, ?string \$alias = null): void
	{
		if (null === \$alias) {";

		foreach ($table->getColumns() as $col) {
			if (!$col->isLazyLoad()) {
				$script .= "
			\$criteria->addSelectColumn(self::" . $this->getColumnName($col) . ");";
			}
		}

		$script .= "
		} else {";

		foreach ($table->getColumns() as $col) {
			if (!$col->isLazyLoad()) {
				$script .= "
			\$criteria->addSelectColumn(\$alias . '." . $col->getConstantColumnName() . "');";
			}
		}

		$script .= "
		}
	}";
	}

	/**
	 * Adds instance pool methods
	 */
	protected function addInstancePoolMethods(&$script)
	{
		$this->addAddInstanceToPool($script);
		$this->addRemoveInstanceFromPool($script);
		$this->addClearInstancePool($script);
		$this->addGetInstanceFromPool($script);
		$this->addGetPrimaryKeyHash($script);
	}

	/**
	 * Adds addInstanceToPool method
	 */
	protected function addAddInstanceToPool(&$script)
	{
		$table = $this->getTable();
		$objectClass = $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		$script .= "

	/**
	 * Adds an object to the instance pool.
	 *
	 * @param \\" . $objectClass . " \$obj A \\" . $objectClass . " object.
	 * @param ?string \$key optional key to use for instance map (for performance boost if key was already calculated externally).
	 */
	public static function addInstanceToPool(object \$obj, ?string \$key = null): void
	{
		if (Propel::isInstancePoolingEnabled()) {
			if (\$key === null) {";

		$pks = $table->getPrimaryKey();
		$php = array();
		foreach ($pks as $pk) {
			$php[] = '$obj->get' . $pk->getPhpName() . '()';
		}

		$script .= "
				\$key = " . $this->getInstancePoolKeySnippet($php) . ";";
		$script .= "
			} // if key === null
			self::\$instances[\$key] = \$obj;
		}
	}";
	}

	/**
	 * Adds removeInstanceFromPool method
	 */
	protected function addRemoveInstanceFromPool(&$script)
	{
		$table = $this->getTable();
		$objectClass = $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		$script .= "

	/**
	 * Removes an object from the instance pool.
	 *
	 * @param mixed \$value A \\" . $objectClass . " object or a primary key value.
	 */
	public static function removeInstanceFromPool(mixed \$value): void
	{
		if (Propel::isInstancePoolingEnabled() && \$value !== null) {";

		$pks = $table->getPrimaryKey();
		$script .= "
			if (is_object(\$value) && \$value instanceof \\" . $objectClass . ") {";

		$php = array();
		foreach ($pks as $pk) {
			$php[] = '$value->get' . $pk->getPhpName() . '()';
		}
		$script .= "
				\$key = " . $this->getInstancePoolKeySnippet($php) . ";";

		$script .= "
			} elseif (" . (count($pks) > 1 ? "is_array(\$value) && count(\$value) === " . count($pks) : "is_scalar(\$value)") . ") {
				// assume we've been passed a primary key";

		if (count($pks) > 1) {
			$php = array();
			for ($i = 0; $i < count($pks); $i++) {
				$php[] = "\$value[$i]";
			}
		} else {
			$php = '$value';
		}

		$script .= "
				\$key = " . $this->getInstancePoolKeySnippet($php) . ";
			} else {
				\$e = new PropelException(\"Invalid value passed to removeInstanceFromPool().  Expected primary key or " . $objectClass . " object; got \" . (is_object(\$value) ? get_class(\$value) . ' object.' : var_export(\$value,true)));
				throw \$e;
			}

			unset(self::\$instances[\$key]);
		}
	}";
	}

	/**
	 * Adds clearInstancePool method
	 */
	protected function addClearInstancePool(&$script)
	{
		$script .= "

	/**
	 * Clear the instance pool.
	 */
	public static function clearInstancePool(): void
	{
		self::\$instances = array();
	}";
	}

	/**
	 * Adds getInstanceFromPool method
	 */
	protected function addGetInstanceFromPool(&$script)
	{
		$objectClass = $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		$script .= "

	/**
	 * Retrieves an object from the instance pool.
	 *
	 * @param ?string \$key The key for this instance (nullable when LEFT JOIN yields no match).
	 * @return ?\\" . $objectClass . " Found object or null if 1) no instance exists for specified key or 2) instance pooling has been disabled.
	 */
	public static function getInstanceFromPool(?string \$key): ?object
	{
		if (\$key === null) { return null; }
		if (Propel::isInstancePoolingEnabled() && isset(self::\$instances[\$key])) {
			return self::\$instances[\$key];
		}
		return null; // explicit
	}";
	}

	/**
	 * Adds getPrimaryKeyHashFromRow method
	 */
	protected function addGetPrimaryKeyHash(&$script)
	{
		$script .= "

	/**
	 * Retrieves a string version of the primary key from the DB resultset row that can be used to uniquely identify a row in this table.
	 *
	 * @param array \$row PropelPDO resultset row.
	 * @param int \$startcol The 0-based offset for reading from the resultset row.
	 * @return ?string A string version of PK or null if the components of primary key in result array are all null.
	 */
	public static function getPrimaryKeyHashFromRow(array \$row, int \$startcol = 0): ?string
	{";

		// We have to iterate through all the columns so that we know the offset of the primary
		// key columns.
		$n = 0;
		$pk = array();
		$cond = array();
		foreach ($this->getTable()->getColumns() as $col) {
			if (!$col->isLazyLoad()) {
				if ($col->isPrimaryKey()) {
					$part = $n ? "\$row[\$startcol + $n]" : "\$row[\$startcol]";
					$cond[] = $part . " === null";
					$pk[] = $part;
				}
				$n++;
			}
		}

		$script .= "
		// If the PK cannot be derived from the row, return null.
		if (" . implode(' && ', $cond) . ") {
			return null;
		}
		return " . $this->getInstancePoolKeySnippet($pk) . ";
	}";
	}

	/**
	 * Helper method to get instance pool key snippet
	 */
	public function getInstancePoolKeySnippet(mixed $pkphp): string
	{
		$pkphp = (array) $pkphp; // make it an array if it is not.
		$script = "";
		if (count($pkphp) > 1) {
			$script .= "serialize(array(";
			$i = 0;
			foreach ($pkphp as $pkvar) {
				$script .= ($i++ ? ', ' : '') . "(string) $pkvar";
			}
			$script .= "))";
		} else {
			$script .= "(string) " . $pkphp[0];
		}
		return $script;
	}

	/**
	 * Helper method to get first primary key column
	 */
	protected function getFirstPrimaryKeyColumn()
	{
		$pks = $this->getTable()->getPrimaryKey();
		return $pks[0] ?? null;
	}

	/**
	 * Helper method to build object instance creation code
	 */
	public function buildObjectInstanceCreationCode($objName, $clsName): string
	{
		return "$objName = new $clsName();";
	}

	/**
	 * Adds retrieveByPK method with enhanced type safety
	 */
	protected function addRetrieveByPK(&$script)
	{
		$table = $this->getTable();
		$primaryKeys = $table->getPrimaryKey();
		$objectClass = $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		if (count($primaryKeys) === 1) {
			$pk = $primaryKeys[0];
			$pkType = $this->getPhp84TypeHint($pk);

			$script .= "

	/**
	 * Retrieves an object by primary key.
	 *
	 * @param $pkType \$pk Primary key value
	 * @param ?PropelPDO \$con Database connection
	 * @return \\" . $objectClass . " The object or null if not found
	 * @throws PropelException
	 */
	public static function retrieveByPK($pkType \$pk, ?PropelPDO \$con = null): \\" . $objectClass . "|null
	{
		if (\$con === null) {
			\$con = Propel::getConnection(self::getDatabaseName(), Propel::CONNECTION_READ);
		}

		\$criteria = new Criteria(self::getDatabaseName());
		\$criteria->add(self::" . strtoupper($pk->getName()) . ", \$pk);

		\$v = self::doSelect(\$criteria, \$con);

		return \$v[0] ?? null;
	}";
		}
	}

	/**
	 * Helper method to get PHP 8.4 type hint for a column
	 */
	protected function getPhp84TypeHint(Column $col): string
	{
		$phpType = $col->getPhpType();
		$isNullable = !$col->isNotNull();
		
		switch ($phpType) {
			case 'int':
				return $isNullable ? '?int' : 'int';
			case 'string':
				return $isNullable ? '?string' : 'string';
			case 'float':
			case 'double':
				return $isNullable ? '?float' : 'float';
			case 'boolean':
				return $isNullable ? '?bool' : 'bool';
			case 'array':
				return 'array';
			case 'DateTime':
				$this->declareClass('\\DateTimeInterface');
				return $isNullable ? '?DateTimeInterface' : 'DateTimeInterface';
			default:
				if ($col->isTemporalType()) {
					$this->declareClass('\\DateTimeInterface');
					return $isNullable ? '?DateTimeInterface' : 'DateTimeInterface';
				}
				return $isNullable ? '?string' : 'string';
		}
	}

	/**
	 * Adds remaining peer methods with modern PHP patterns
	 */
	protected function addRetrieveByPKs(&$script)
	{
		$firstPk = $this->getFirstPrimaryKeyColumn();
		
		$script .= "

	/**
	 * Retrieves multiple objects by primary keys.
	 *
	 * @param array \$pks Array of primary key values
	 * @param ?PropelPDO \$con Database connection
	 * @return array Array of objects
	 */
	public static function retrieveByPKs(array \$pks, ?PropelPDO \$con = null): array
	{
		if (empty(\$pks)) {
			return [];
		}

		if (\$con === null) {
			\$con = Propel::getConnection(self::getDatabaseName(), Propel::CONNECTION_READ);
		}

		\$criteria = new Criteria(self::getDatabaseName());
		\$criteria->add(self::" . strtoupper($firstPk->getName()) . ", \$pks, Criteria::IN);

		return self::doSelect(\$criteria, \$con);
	}";
	}

	

	/**
	 * Adds doValidateThis method - PHP 8.4 LSP compliant version of doValidate
	 */
	protected function addDoValidate(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Validates all modified columns of given ".$this->getObjectClassname()." object.
	 * If parameter \$columns is either a single column name or an array of column names
	 * than only those columns are validated.
	 * This method is renamed from doValidate to avoid LSP violations in PHP 8.4.
	 *
	 * NOTICE: This does not apply to primary or foreign keys for now.
	 *
	 * @param      ".$this->getObjectClassname()." \$obj The object to validate.
	 * @param      mixed \$cols Column name or array of column names.
	 *
	 * @return     mixed TRUE if all columns are valid or the error message of the first invalid column.
	 */
	public static function doValidateThis(" . $this->getObjectClassname() . " \$obj, mixed \$cols = null)
	{
		\$columns = array();

		if (\$cols) {
			\$dbMap = Propel::getDatabaseMap(self::DATABASE_NAME);
			\$tableMap = \$dbMap->getTable(self::TABLE_NAME);

			if (!\is_array(\$cols)) {
				\$cols = array(\$cols);
			}

			foreach (\$cols as \$colName) {
				if (\$tableMap->containsColumn(\$colName)) {
					\$get = 'get' . \$tableMap->getColumn(\$colName)->getPhpName();
					\$columns[\$colName] = \$obj->\$get();
				}
			}
		} else {
";
		foreach ($table->getValidators() as $val) {
			$col = $val->getColumn();
			if (!$col->isAutoIncrement()) {
				$script .= "
		if (\$obj->isNew() || \$obj->isColumnModified(".$this->getColumnConstant($col)."))
			\$columns[".$this->getColumnConstant($col)."] = \$obj->get".$col->getPhpName()."();
";
			}
		}

		$script .= "
		}

		return {$this->basePeerClassname}::doValidate(".$this->getPeerClassname()."::DATABASE_NAME, ".$this->getPeerClassname()."::TABLE_NAME, \$columns);
	}

	/**
	 * Validates all modified columns using the BasePeer signature for PHP 8.4 LSP compliance.
	 * This method delegates to doValidateThis for the actual validation logic.
	 *
	 * @param      string \$dbName The name of the database
	 * @param      string \$tableName The name of the table  
	 * @param      array \$columns Array of column names as key and column values as value.
	 *
	 * @return     mixed TRUE if all columns are valid or the error message of the first invalid column.
	 */
	public static function doValidate(\$dbName, \$tableName, \$columns)
	{
		return {$this->basePeerClassname}::doValidate(\$dbName, \$tableName, \$columns);
	}
";
	}

	/**
	 * Adds doDelete method - matches PHP5PeerBuilder signature
	 */
	protected function addDoDelete(&$script)
	{
		$table = $this->getTable();
		$emulateCascade = $this->isDeleteCascadeEmulationNeeded() || $this->isDeleteSetNullEmulationNeeded();
		$script .= "
	/**
	 * Performs a DELETE on the database, given a ".$this->getObjectClassname()." or Criteria object OR a primary key value.
	 *
	 * @param      mixed \$values Criteria or ".$this->getObjectClassname()." object or primary key or array of primary keys
	 *              which is used to create the DELETE statement
	 * @param      PropelPDO \$con the connection to use
	 * @return     int 	The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
	 *				if supported by native driver or if emulated using Propel.
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	 public static function doDelete(\$values, ?PropelPDO \$con = null)
	 {
		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_WRITE);
		}

		if (\$values instanceof Criteria) {";
		if (!$emulateCascade) {
			$script .= "
			// invalidate the cache for all objects of this type, since we have no
			// way of knowing (without running a query) what objects should be invalidated
			// from the cache based on this Criteria.
			".$this->getPeerClassname()."::clearInstancePool();";
		}
		$script .= "
			// rename for clarity
			\$criteria = clone \$values;
		} elseif (\$values instanceof ".$this->getObjectClassname().") { // it's a model object";
		if (!$emulateCascade) {
			$script .= "
			// invalidate the cache for this single object
			".$this->getPeerClassname()."::removeInstanceFromPool(\$values);";
		}
		if (count($table->getPrimaryKey()) > 0) {
			$script .= "
			// create criteria based on pk values
			\$criteria = \$values->buildPkeyCriteria();";
		} else {
			$script .= "
			// create criteria based on pk value
			\$criteria = \$values->buildCriteria();";
		}

		$script .= "
		} else { // it's a primary key, or an array of pks";
		$script .= "
			\$criteria = new Criteria(self::DATABASE_NAME);";

		if (count($table->getPrimaryKey()) === 1) {
			$pkey = $table->getPrimaryKey();
			$col = array_shift($pkey);
			$script .= "
			\$criteria->add(".$this->getColumnConstant($col).", (array) \$values, Criteria::IN);";
			if (!$emulateCascade) {
				$script .= "
			// invalidate the cache for this object(s)
			foreach ((array) \$values as \$singleval) {
				".$this->getPeerClassname()."::removeInstanceFromPool(\$singleval);
			}";
			}
		} else {
			$script .= "
			// primary key is composite; we therefore, expect
			// the primary key passed to be an array of pkey values
			if (count(\$values) == count(\$values, COUNT_RECURSIVE)) {
				// array is not multi-dimensional
				\$values = array(\$values);
			}
			foreach (\$values as \$value) {";
			$i=0;
			foreach ($table->getPrimaryKey() as $col) {
				if ($i == 0) {
					$script .= "
				\$criterion = \$criteria->getNewCriterion(".$this->getColumnConstant($col).", \$value[$i]);";
				} else {
					$script .= "
				\$criterion->addAnd(\$criteria->getNewCriterion(".$this->getColumnConstant($col).", \$value[$i]));";
				}
				$i++;
			}
			$script .= "
				\$criteria->addOr(\$criterion);";
			if (!$emulateCascade) {
				$script .= "
				// we can invalidate the cache for this single PK
				".$this->getPeerClassname()."::removeInstanceFromPool(\$value);";
			}
			$script .= "
			}";
		} /* if count(table->getPrimaryKeys()) */

		$script .= "
		}

		// Set the correct dbName
		\$criteria->setDbName(self::DATABASE_NAME);

		\$affectedRows = 0; // initialize var to track total num of affected rows

		try {
			// use transaction because \$criteria could contain info
			// for more than one table or we could emulating ON DELETE CASCADE, etc.
			\$con->beginTransaction();
			";

		if ($this->isDeleteCascadeEmulationNeeded()) {
			$script .= "
			// cloning the Criteria in case it's modified by doSelect() or doSelectStmt()
			\$c = clone \$criteria;
			\$affectedRows += ".$this->getPeerClassname()."::doOnDeleteCascade(\$c, \$con);
			";
		}
		if ($this->isDeleteSetNullEmulationNeeded()) {
			$script .= "
			// cloning the Criteria in case it's modified by doSelect() or doSelectStmt()
			\$c = clone \$criteria;
			" . $this->getPeerClassname() . "::doOnDeleteSetNull(\$c, \$con);
			";
		}

		if ($emulateCascade) {
			$script .= "
			// Because this db requires some delete cascade/set null emulation, we have to
			// clear the cached instance *after* the emulation has happened (since
			// instances get re-added by the select statement contained therein).
			if (\$values instanceof Criteria) {
				".$this->getPeerClassname()."::clearInstancePool();
			} elseif (\$values instanceof ".$this->getObjectClassname().") { // it's a model object
				".$this->getPeerClassname()."::removeInstanceFromPool(\$values);
			} else { // it's a primary key, or an array of pks
				foreach ((array) \$values as \$singleval) {
					".$this->getPeerClassname()."::removeInstanceFromPool(\$singleval);
				}
			}
			";
		}

		$script .= "
			\$affectedRows += {$this->basePeerClassname}::doDelete(\$criteria, \$con);
			".$this->getPeerClassname()."::clearRelatedInstancePool();
			\$con->commit();
			return \$affectedRows;
		} catch (PropelException \$e) {
			\$con->rollBack();
			throw \$e;
		}
	}
";
	}

	/**
	 * Closes class.
	 */
	protected function addClassClose(&$script)
	{
		// apply behaviors
		$this->applyBehaviorModifier('staticMethods', $script, "	");

		$script .= "
} // " . $this->getClassname() . "

// This is the static code needed to register the TableMap for this table with the main Propel class.
//
".$this->getClassname()."::buildTableMap();
";
		$this->applyBehaviorModifier('peerFilter', $script, "");
	}

	/**
	 * Helper method to get lazy load columns
	 */
	protected function getLazyLoadColumns()
	{
		$lazyColumns = [];
		foreach ($this->getTable()->getColumns() as $col) {
			if ($col->isLazyLoad()) {
				$lazyColumns[] = $col;
			}
		}
		return $lazyColumns;
	}

	/**
	 * Adds method to clear the instance pool of related tables.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addClearRelatedInstancePool(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Method to invalidate the instance pool of all tables related to " . $table->getName() . "
	 * by a foreign key with ON DELETE CASCADE
	 */
	public static function clearRelatedInstancePool()
	{";
		// Handle ON DELETE CASCADE for updating instance pool

		foreach ($table->getReferrers() as $fk) {

			// $fk is the foreign key in the other table, so localTableName will
			// actually be the table name of other table
			$tblFK = $fk->getTable();

			$joinedTablePeerBuilder = $this->getNewStubPeerBuilder($tblFK);
			$this->declareClassFromBuilder($joinedTablePeerBuilder);
			$tblFKPackage = $joinedTablePeerBuilder->getStubPeerBuilder()->getPackage();

			if (!$tblFK->isForReferenceOnly()) {
				// we can't perform operations on tables that are
				// not within the schema (i.e. that we have no map for, etc.)

				if ($fk->getOnDelete() == ForeignKey::CASCADE || $fk->getOnDelete() == ForeignKey::SETNULL) {
					$script .= "
		// Invalidate objects in ".$joinedTablePeerBuilder->getClassname()." instance pool,
		// since one or more of them may be deleted by ON DELETE CASCADE/SETNULL rule.
		".$joinedTablePeerBuilder->getClassname()."::clearInstancePool();";
				} // if fk is on delete cascade
			} // if (! for ref only)
		} // foreach
		$script .= "
	}
";
	}

	/**
		 * Adds method to get the primary key from a row
		 * @param      string &$script The script will be modified in this method.
		 */
		protected function addGetPrimaryKeyFromRow(&$script)
		{
			$script .= "
	/**
	 * Retrieves the primary key from the DB resultset row
	 * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
	 * a multi-column primary key, an array of the primary key columns will be returned.
	 *
	 * @param      array \$row PropelPDO resultset row.
	 * @param      int \$startcol The 0-based offset for reading from the resultset row.
	 * @return     mixed The primary key of the row
	 */
	public static function getPrimaryKeyFromRow(\$row, \$startcol = 0)
	{";

			// We have to iterate through all the columns so that we know the offset of the primary
			// key columns.
			$table = $this->getTable();
			$n = 0;
			$pks = array();
			foreach ($table->getColumns() as $col) {
				if (!$col->isLazyLoad()) {
					if ($col->isPrimaryKey()) {
						$pk = '(' . $col->getPhpType() . ') ' . ($n ? "\$row[\$startcol + $n]" : "\$row[\$startcol]");
						if ($table->hasCompositePrimaryKey()) {
							$pks[] = $pk;
						}
					}
					$n++;
				}
			}
			if ($table->hasCompositePrimaryKey()) {
				$script .= "
		return array(" . implode(',', $pks). ");";
			} else {
				$script .= "
		return " . $pk . ";";
			}
			$script .= "
	}
	";
	}
	 
	/**
	 * Adds the populateObject() method.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addPopulateObject(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Populates an object of the default type or an object that inherit from the default.
	 *
	 * @param      array \$row PropelPDO resultset row.
	 * @param      int \$startcol The 0-based offset for reading from the resultset row.
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 * @return     array (" . $this->getStubObjectBuilder()->getClassName(). " object, last column rank)
	 */
	public static function populateObject(array \$row, int \$startcol = 0)
	{
		\$key = ".$this->getPeerClassname()."::getPrimaryKeyHashFromRow(\$row, \$startcol);
		if (null !== (\$obj = ".$this->getPeerClassname()."::getInstanceFromPool(\$key))) {
			// We no longer rehydrate the object, since this can cause data loss.
			// See http://www.propelorm.org/ticket/509
			// \$obj->hydrate(\$row, \$startcol, true); // rehydrate
			\$col = \$startcol + " . $this->getPeerClassname() . "::NUM_HYDRATE_COLUMNS;";
		if ($table->isAbstract()) {
			$script .= "
		} elseif (null == \$key) {
			// empty resultset, probably from a left join
			// since this table is abstract, we can't hydrate an empty object
			\$obj = null;
			\$col = \$startcol + " . $this->getPeerClassname() . "::NUM_HYDRATE_COLUMNS;";
		}
		$script .= "
		} else {";
		if (!$table->getChildrenColumn()) {
			$script .= "
			\$cls = ".$this->getPeerClassname()."::OM_CLASS;";
		} else {
			$script .= "
			\$cls = ".$this->getPeerClassname()."::getOMClass(\$row, \$startcol, false);";
		}
		$script .= "
			\$obj = new \$cls();
			\$col = \$obj->hydrate(\$row, \$startcol);
			" . $this->getPeerClassname() . "::addInstanceToPool(\$obj, \$key);
		}
		return array(\$obj, \$col);
	}
";
	}

	/**
	 * Adds a getOMClass() for tables that use single-table inheritance.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addGetOMClass_Inheritance(&$script)
	{
		$col = $this->getTable()->getChildrenColumn();
		$script .= "
	/**
	 * The returned Class will contain objects of the default type or
	 * objects that inherit from the default.
	 *
	 * @param      array \$row PropelPDO result row.
	 * @param      int \$colnum Column to examine for OM class information (first is 0).
	 * @param      boolean \$withPrefix Whether or not to return the path with the class name
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function getOMClass(\$row, \$colnum, bool \$withPrefix = true)
	{
		try {
";
		if ($col->isEnumeratedClasses()) {
			$script .= "
			\$omClass = null;
			\$classKey = \$row[\$colnum + " . ($col->getPosition() - 1) . "];

			switch(\$classKey) {
";
			foreach ($col->getChildren() as $child) {
				$script .= "
				case self::CLASSKEY_".strtoupper($child->getKey()).":
					\$omClass = self::CLASSNAME_".strtoupper($child->getKey()).";
					break;
";
			} /* foreach */
			$script .= "
				default:
					\$omClass = self::CLASS_DEFAULT;
";
			$script .= "
			} // switch
			if (!\$withPrefix) {
				\$omClass = substr('.'.\$omClass, strrpos('.'.\$omClass, '.') + 1);
			}
";
		} else { /* if not enumerated */
			$script .= "
			\$omClass = \$row[\$colnum + ".($col->getPosition()-1)."];
			\$omClass = substr('.'.\$omClass, strrpos('.'.\$omClass, '.') + 1);
";
		}
		$script .= "
		} catch (Exception \$e) {
			throw new PropelException('Unable to get OM class.', \$e);
		}
		return \$omClass;
	}
";
	}

	/**
	 * Adds a getOMClass() for non-abstract tables that do note use inheritance.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addGetOMClass_NoInheritance(&$script)
	{
		$script .= "
	/**
	 * The class that the Peer will make instances of.
	 *
	 * If \$withPrefix is true, the returned path
	 * uses a dot-path notation which is tranalted into a path
	 * relative to a location on the PHP include_path.
	 * (e.g. path.to.MyClass -> 'path/to/MyClass.php')
	 *
	 * @param      boolean \$withPrefix Whether or not to return the path with the class name
	 * @return     string path.to.ClassName
	 */
	public static function getOMClass(bool \$withPrefix = true)
	{
		return \$withPrefix ? ".$this->getPeerClassname()."::CLASS_DEFAULT : ".$this->getPeerClassname()."::OM_CLASS;
	}
";
	}

	/**
	 * Adds a getOMClass() signature for abstract tables that do not have inheritance.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addGetOMClass_NoInheritance_Abstract(&$script)
	{
		$script .= "
	/**
	 * The class that the Peer will make instances of.
	 *
	 * This method must be overridden by the stub subclass, because
	 * ".$this->getObjectClassname()." is declared abstract in the schema.
	 */
	abstract public static function getOMClass(bool \$withPrefix = true);
";
	}

	/**
	 * Adds the doDeleteAll() method.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addDoDeleteAll(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Deletes all rows from the ".$table->getName()." table.
	 *
	 * @param      PropelPDO \$con the connection to use
	 * @return     int The number of affected rows (if supported by underlying database driver).
	 */
	public static function doDeleteAll(?string \$tableName = null, ?PropelPDO \$con = null, ?string \$dbName = null)
	{
		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_WRITE);
		}
		\$affectedRows = 0; // initialize var to track total num of affected rows
		try {
			// use transaction because \$criteria could contain info
			// for more than one table or we could emulating ON DELETE CASCADE, etc.
			\$con->beginTransaction();
			";
		if ($this->isDeleteCascadeEmulationNeeded()) {
			$script .="\$affectedRows += ".$this->getPeerClassname()."::doOnDeleteCascade(new Criteria(".$this->getPeerClassname()."::DATABASE_NAME), \$con);
			";
		}
		if ($this->isDeleteSetNullEmulationNeeded()) {
			$script .= $this->getPeerClassname() . "::doOnDeleteSetNull(new Criteria(".$this->getPeerClassname() . "::DATABASE_NAME), \$con);
			";
		}
		$script .= "\$affectedRows += {$this->basePeerClassname}::doDeleteAll(".$this->getPeerClassname()."::TABLE_NAME, \$con, ".$this->getPeerClassname()."::DATABASE_NAME);
			// Because this db requires some delete cascade/set null emulation, we have to
			// clear the cached instance *after* the emulation has happened (since
			// instances get re-added by the select statement contained therein).
			".$this->getPeerClassname()."::clearInstancePool();
			".$this->getPeerClassname()."::clearRelatedInstancePool();
			\$con->commit();
			return \$affectedRows;
		} catch (PropelException \$e) {
			\$con->rollBack();
			throw \$e;
		}
	}
";
	}

	/**
	 * Adds the retrieveByPK method for tables with single-column primary key.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addRetrieveByPK_SinglePK(&$script)
	{
		$table = $this->getTable();
		$pks = $table->getPrimaryKey();
		$col = $pks[0];

		$script .= "
	/**
	 * Retrieve a single object by pkey.
	 *
	 * @param      ".$col->getPhpType()." \$pk the primary key.
	 * @param      ?PropelPDO \$con the connection to use
	 * @return     " .$this->getObjectClassname(). "
	 */
	public static function ".$this->getRetrieveMethodName()."(".$col->getPhpType(). " \$pk, ?PropelPDO \$con = null)
	{

		if (null !== (\$obj = ".$this->getPeerClassname()."::getInstanceFromPool(".$this->getInstancePoolKeySnippet('$pk')."))) {
			return \$obj;
		}

		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_READ);
		}

		\$criteria = new Criteria(".$this->getPeerClassname()."::DATABASE_NAME);
		\$criteria->add(".$this->getColumnConstant($col, $this->getPeerClassname()).", \$pk);

		\$v = ".$this->getPeerClassname()."::doSelect(\$criteria, \$con);

		return !empty(\$v) > 0 ? \$v[0] : null;
	}
";
	}

	/**
	 * Adds the retrieveByPKs method for tables with single-column primary key.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addRetrieveByPKs_SinglePK(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Retrieve multiple objects by pkey.
	 *
	 * @param      array \$pks List of primary keys
	 * @param      ?PropelPDO \$con the connection to use
	 * @throws     PropelException Any exceptions caught during processing will be
	 *		 rethrown wrapped into a PropelException.
	 */
	public static function ".$this->getRetrieveMethodName()."s(\$pks, ?PropelPDO \$con = null)
	{
		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_READ);
		}

		\$objs = null;
		if (empty(\$pks)) {
			\$objs = array();
		} else {
			\$criteria = new Criteria(".$this->getPeerClassname()."::DATABASE_NAME);";
		$k1 = $table->getPrimaryKey();
		$script .= "
			\$criteria->add(".$this->getColumnConstant($k1[0]).", \$pks, Criteria::IN);";
		$script .= "
			\$objs = ".$this->getPeerClassname()."::doSelect(\$criteria, \$con);
		}
		return \$objs;
	}
";
	}

	/**
	 * Adds the retrieveByPK method for tables with multi-column primary key.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addRetrieveByPK_MultiPK(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * Retrieve object using using composite pkey values.";
		foreach ($table->getPrimaryKey() as $col) {
			$clo = strtolower($col->getName());
			$cptype = $col->getPhpType();
			$script .= "
	 * @param      $cptype $".$clo;
		}
		$script .= "
	 * @param      PropelPDO \$con
	 * @return     ".$this->getObjectClassname()."
	 */
	public static function ".$this->getRetrieveMethodName()."(";

		$php = array();
		$vars = array(); // For getInstancePoolKeySnippet()
		foreach ($table->getPrimaryKey() as $col) {
			$clo = strtolower($col->getName());
			$cptype = $col->getPhpType();
			$php[] = $cptype . ' $' . $clo;
			$vars[] = '$' . $clo;
		} /* foreach */

		$script .= implode(', ', $php);

		$script .= ", ?PropelPDO \$con = null) {
		\$_instancePoolKey = ".$this->getInstancePoolKeySnippet($vars).";";
 		$script .= "
 		if (null !== (\$obj = ".$this->getPeerClassname()."::getInstanceFromPool(\$_instancePoolKey))) {
 			return \$obj;
		}

		if (\$con === null) {
			\$con = Propel::getConnection(".$this->getPeerClassname()."::DATABASE_NAME, Propel::CONNECTION_READ);
		}
		\$criteria = new Criteria(".$this->getPeerClassname()."::DATABASE_NAME);";
		foreach ($table->getPrimaryKey() as $col) {
			$clo = strtolower($col->getName());
			$script .= "
		\$criteria->add(".$this->getColumnConstant($col).", $".$clo.");";
		}
		$script .= "
		\$v = ".$this->getPeerClassname()."::doSelect(\$criteria, \$con);

		return !empty(\$v) ? \$v[0] : null;
	}";
	}

	/**
	 * Adds the doOnDeleteCascade() method, which provides ON DELETE CASCADE emulation.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addDoOnDeleteCascade(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * This is a method for emulating ON DELETE CASCADE for DBs that don't support this
	 * feature (like MySQL or SQLite).
	 *
	 * This method is not very speedy because it must perform a query first to get
	 * the implicated records and then perform the deletes by calling those Peer classes.
	 *
	 * This method should be used within a transaction if possible.
	 *
	 * @param      Criteria \$criteria
	 * @param      ?PropelPDO \$con
	 * @return     int The number of affected rows (if supported by underlying database driver).
	 */
	protected static function doOnDeleteCascade(Criteria \$criteria, ?PropelPDO \$con)
	{
		// initialize var to track total num of affected rows
		\$affectedRows = 0;

		// first find the objects that are implicated by the \$criteria
		\$objects = ".$this->getPeerClassname()."::doSelect(\$criteria, \$con);
		foreach (\$objects as \$obj) {
";

		foreach ($table->getReferrers() as $fk) {

			// $fk is the foreign key in the other table, so localTableName will
			// actually be the table name of other table
			$tblFK = $fk->getTable();

			$joinedTablePeerBuilder = $this->getNewPeerBuilder($tblFK);
			$tblFKPackage = $joinedTablePeerBuilder->getStubPeerBuilder()->getPackage();

			if (!$tblFK->isForReferenceOnly()) {
				// we can't perform operations on tables that are
				// not within the schema (i.e. that we have no map for, etc.)

				$fkClassName = $joinedTablePeerBuilder->getObjectClassname();

				if ($fk->getOnDelete() == ForeignKey::CASCADE) {

					// backwards on purpose
					$columnNamesF = $fk->getLocalColumns();
					$columnNamesL = $fk->getForeignColumns();

					$script .= "

			// delete related $fkClassName objects
			\$criteria = new Criteria(".$joinedTablePeerBuilder->getPeerClassname()."::DATABASE_NAME);
			";
					for ($x=0,$xlen=count($columnNamesF); $x < $xlen; $x++) {
						$columnFK = $tblFK->getColumn($columnNamesF[$x]);
						$columnL = $table->getColumn($columnNamesL[$x]);

						$script .= "
			\$criteria->add(".$joinedTablePeerBuilder->getColumnConstant($columnFK) .", \$obj->get".$columnL->getPhpName()."());";
					}

					$script .= "
			\$affectedRows += ".$joinedTablePeerBuilder->getPeerClassname()."::doDelete(\$criteria, \$con);";

				} // if cascade && fkey table name != curr table name

			} // if (! for ref only)
		} // foreach foreign keys
		$script .= "
		}
		return \$affectedRows;
	}
";
	} // end addDoOnDeleteCascade

	/**
	 * Adds the doOnDeleteSetNull() method, which provides ON DELETE SET NULL emulation.
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addDoOnDeleteSetNull(&$script)
	{
		$table = $this->getTable();
		$script .= "
	/**
	 * This is a method for emulating ON DELETE SET NULL DBs that don't support this
	 * feature (like MySQL or SQLite).
	 *
	 * This method is not very speedy because it must perform a query first to get
	 * the implicated records and then perform the deletes by calling those Peer classes.
	 *
	 * This method should be used within a transaction if possible.
	 *
	 * @param      Criteria \$criteria
	 * @param      ?PropelPDO \$con
	 * @return     void
	 */
	protected static function doOnDeleteSetNull(Criteria \$criteria, ?PropelPDO \$con)
	{

		// first find the objects that are implicated by the \$criteria
		\$objects = ".$this->getPeerClassname()."::doSelect(\$criteria, \$con);
		foreach (\$objects as \$obj) {
";

		// This logic is almost exactly the same as that in doOnDeleteCascade()
		// it may make sense to refactor this, provided that thigns don't
		// get too complicated.

		foreach ($table->getReferrers() as $fk) {

			// $fk is the foreign key in the other table, so localTableName will
			// actually be the table name of other table
			$tblFK = $fk->getTable();
			$refTablePeerBuilder = $this->getNewPeerBuilder($tblFK);

			if (!$tblFK->isForReferenceOnly()) {
				// we can't perform operations on tables that are
				// not within the schema (i.e. that we have no map for, etc.)

				$fkClassName = $refTablePeerBuilder->getObjectClassname();

				if ($fk->getOnDelete() == ForeignKey::SETNULL) {

					// backwards on purpose
					$columnNamesF = $fk->getLocalColumns();
					$columnNamesL = $fk->getForeignColumns(); // should be same num as foreign
					$script .= "
			// set fkey col in related $fkClassName rows to NULL
			\$selectCriteria = new Criteria(".$this->getPeerClassname()."::DATABASE_NAME);
			\$updateValues = new Criteria(".$this->getPeerClassname()."::DATABASE_NAME);";

					for ($x=0,$xlen=count($columnNamesF); $x < $xlen; $x++) {
						$columnFK = $tblFK->getColumn($columnNamesF[$x]);
						$columnL = $table->getColumn($columnNamesL[$x]);
						$script .= "
			\$selectCriteria->add(".$refTablePeerBuilder->getColumnConstant($columnFK).", \$obj->get".$columnL->getPhpName()."());
			\$updateValues->add(".$refTablePeerBuilder->getColumnConstant($columnFK).", null);
";
					}

					$script .= "
			{$this->basePeerClassname}::doUpdate(\$selectCriteria, \$updateValues, \$con); // use BasePeer because generated Peer doUpdate() methods only update using pkey
";
				} // if setnull && fkey table name != curr table name
			} // if not for ref only
		} // foreach foreign keys

		$script .= "
		}
	}
";
	}
}