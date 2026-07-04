<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM;

/**
 * Generates a PHP 8.4 base Object class
 *
 * This class produces the base object class (e.g. BaseMyTable) which contains all
 * the custom-built accessor and setter methods with modern PHP 8.4 features:
 * - Typed properties
 * - Constructor property promotion  
 * - Attributes
 * - Readonly properties where appropriate
 * - Null coalescing and arrow functions
 * - Proper return types and parameter types
 *
 * @author     GitHub Copilot
 * @package    propel.generator.builder.om
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\PropulsionTypes;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\IDMethod;
use Propulsion\Generator\Platform\MysqlPlatform;

class ObjectBuilder extends AbstractObjectBuilder
{

	/**
	 * Gets the package for the [base] object classes.
	 * @return     string
	 */
	public function getPackage()
	{
		return parent::getPackage() . ".om";
	}

	public function getNamespace()
	{
		if ($namespace = parent::getNamespace()) {
			if ($this->getGeneratorConfig() && $omns = $this->getGeneratorConfig()->getBuildProperty('namespaceOm')) {
				return $namespace . '\\' . $omns;
			} else {
				return $namespace;
			}
		}
	}

	/**
	 * Returns the name of the current class being built.
	 * @return     string
	 */
	public function getUnprefixedClassname()
	{
		return $this->getBuildProperty('basePrefix') . $this->getStubObjectBuilder()->getUnprefixedClassname();
	}

	/**
	 * Validates the current table to make sure that it won't
	 * result in generated code that will not parse.
	 *
	 * This method may emit warnings for code which may cause problems
	 * and will throw exceptions for errors that will definitely cause
	 * problems.
	 */
	protected function validateModel()
	{
		parent::validateModel();

		$table = $this->getTable();

		// Check to see whether any generated foreign key names
		// will conflict with column names.

		$colPhpNames = array();
		$fkPhpNames = array();

		foreach ($table->getColumns() as $col) {
			$colPhpNames[] = $col->getPhpName();
		}

		foreach ($table->getForeignKeys() as $fk) {
			$fkPhpNames[] = $this->getFKPhpNameAffix($fk, $plural = false);
		}

		$intersect = array_intersect($colPhpNames, $fkPhpNames);
		if (!empty($intersect)) {
			throw new EngineException("One or more of your column names for [" . $table->getName() . "] table conflict with foreign key names (" . implode(", ", $intersect) . ")");
		}

		// Check foreign keys to see if there are any foreign keys that
		// are also matched with an inversed referencing foreign key
		// (this is currently unsupported behavior)

		foreach ($table->getForeignKeys() as $fk) {
			if ($fk->isMatchedByInverseFK()) {
				throw new EngineException("The 1:1 relationship expressed by foreign key " . $fk->getName() . " is defined in both directions; Propulsion does not currently support this (if you must have both foreign key constraints, consider adding this constraint with a custom SQL file.)" );
			}
		}
	}

	/**
	 * Returns the appropriate formatter (from platform) for a date/time column.
	 * @param      Column $col
	 * @return     string
	 */
	protected function getTemporalFormatter(Column $col)
	{
		$fmt = null;
		if ($col->getType() === PropulsionTypes::DATE) {
			$fmt = $this->getPlatform()->getDateFormatter();
		} elseif ($col->getType() === PropulsionTypes::TIME) {
			$fmt = $this->getPlatform()->getTimeFormatter();
		} elseif ($col->getType() === PropulsionTypes::TIMESTAMP) {
			$fmt = $this->getPlatform()->getTimestampFormatter();
		}
		return $fmt;
	}

	/**
	 * Returns the type-casted and stringified default value for the specified Column.
	 * This only works for scalar default values currently.
	 * @return     string The default value or 'NULL' if there is none.
	 */
	protected function getDefaultValueString(Column $col)
	{
		$defaultValue = var_export(null, true);
		$def = $col->getDefaultValue();
		if ($def !== null && $def->isExpression()) {
			return $defaultValue; // Cannot get PHP value for expressions
		}
		$val = $col->getPhpDefaultValue();
		if ($val === null) {
			return $defaultValue;
		}
		if ($col->isTemporalType()) {
			$fmt = $this->getTemporalFormatter($col);
			try {
				if (!($this->getPlatform() instanceof MysqlPlatform &&
				($val === '0000-00-00 00:00:00' || $val === '0000-00-00'))) {
					// while technically this is not a default value of NULL,
					// this seems to be closest in meaning.
					$defDt = new \DateTime($val);
					$defaultValue = var_export($defDt->format($fmt), true);
				}
			} catch (\Exception $x) {
				// prevent endless loop when timezone is undefined
				date_default_timezone_set('America/Los_Angeles');
				throw new EngineException(sprintf('Unable to parse default temporal value "%s" for column "%s"', $col->getDefaultValueString(), $col->getFullyQualifiedName()), $x);
			}
		} elseif ($col->isEnumType()) {
			$valueSet = $col->getValueSet();
			if (!in_array($val, $valueSet)) {
				throw new EngineException(sprintf('Default Value "%s" is not among the enumerated values', $val));
			}
			// Return the enumerated index as a PHP literal
			$defaultValue = var_export(array_search($val, $valueSet), true);
		} else if ($col->isPhpPrimitiveType()) {
			// Prefer using the underlying Propulsion type for reliable casting
			$propelType = $col->getType();
			if ($propelType === PropulsionTypes::BIGINT || $propelType === PropulsionTypes::INTEGER ||
			    $propelType === PropulsionTypes::SMALLINT || $propelType === PropulsionTypes::TINYINT) {
				$defaultValue = var_export((int) $val, true);
			} elseif ($col->isBooleanType()) {
				$defaultValue = var_export((bool) $val, true);
			} elseif ($col->getPhpType() === 'double' || $col->getPhpType() === 'float') {
				$defaultValue = var_export((float) $val, true);
			} elseif ($col->getPhpType() === 'string' || $col->isTextType()) {
				$defaultValue = var_export((string) $val, true);
			} elseif ($col->getPhpType() === 'array') {
				$defaultValue = var_export((array) $val, true);
			} else {
				// Fallback: attempt to coerce to the declared php type
				settype($val, $col->getPhpType());
				$defaultValue = var_export($val, true);
			}
		} elseif ($col->isPhpObjectType()) {
			$defaultValue = 'new '.$col->getPhpType().'(' . var_export($val, true) . ')';
		} else {
			throw new EngineException("Cannot get default value string for " . $col->getFullyQualifiedName());
		}
		return $defaultValue;
	}

	/**
	 * Gets the appropriate PHP 8.4 type hint for a column
	 * @param Column $col
	 * @return string
	 */
	protected function getPhp84TypeHint(Column $col): string
	{
		// Always use nullable types for getters and setters to support clearing relationships
		// and object state, regardless of database constraints

		// LOB columns (BLOB/VARBINARY/LONGVARBINARY) are stored internally as a PHP
		// stream resource, matching PHP5ObjectBuilder's addLobMutator() -- callers can
		// pass either a resource or a raw string (normalized to a resource by the
		// mutator, see addColumnMutator()). "resource" isn't a legal PHP type
		// declaration, so `mixed` is used for the property/getter/setter signatures.
		if ($col->isLobType()) {
			return 'mixed';
		}

		// Check for temporal types first, before relying on getPhpType()
		if ($col->isTemporalType()) {
			$this->declareClass('\\DateTimeInterface');
			return '?DateTimeInterface';
		}
		
		// Check the actual Propulsion type for better type hints
		$propelType = $col->getType();
		
		// Map Propulsion types to PHP 8.4 types
		// For BIGINT, use int since we're on 64-bit PHP 8.4 (handles up to 2^63-1)
		if ($propelType === PropulsionTypes::BIGINT || $propelType === PropulsionTypes::INTEGER || 
		    $propelType === PropulsionTypes::SMALLINT || $propelType === PropulsionTypes::TINYINT) {
			return '?int';
		}
		
		// Fall back to the PHP type mapping
		$phpType = $col->getPhpType();
		
		switch ($phpType) {
			case 'int':
				return '?int';
			case 'string':
				return '?string';
			case 'float':
			case 'double':
				return '?float';
			case 'boolean':
				return '?bool';
			case 'array':
				return '?array';
			case 'DateTime':
				$this->declareClass('\\DateTimeInterface');
				return '?DateTimeInterface';
			default:
				return '?string';
		}
	}

	/**
	 * Gets the appropriate PHP 8.4 property type for a column
	 * @param Column $col
	 * @return string
	 */
	protected function getPhp84PropertyType(Column $col): string
	{
		// All properties need to be nullable in PHP to support object clearing/resetting
		// Unlike strongly typed languages like C#, PHP objects need to be clearable

		// See getPhp84TypeHint() -- LOB columns store a stream resource internally.
		if ($col->isLobType()) {
			return 'mixed';
		}

		// Check for temporal types first, before relying on getPhpType()
		if ($col->isTemporalType()) {
			$this->declareClass('\\DateTimeInterface');
			return '?DateTimeInterface';
		}
		
		// Check the actual Propulsion type for better type hints
		// For BIGINT, use int since we're on 64-bit PHP 8.4 (handles up to 2^63-1)
		$propelType = $col->getType();
		
		if ($propelType === PropulsionTypes::BIGINT || $propelType === PropulsionTypes::INTEGER || 
		    $propelType === PropulsionTypes::SMALLINT || $propelType === PropulsionTypes::TINYINT) {
			return '?int';
		}
		
		// Fall back to the PHP type mapping
		$phpType = $col->getPhpType();
		
		switch ($phpType) {
			case 'int':
				return '?int';
			case 'string':
				return '?string';
			case 'float':
			case 'double':
				return '?float';
			case 'boolean':
				return '?bool';
			case 'array':
				return '?array'; // Arrays can be nullable in PHP 8.4
			case 'DateTime':
				$this->declareClass('\\DateTimeInterface');
				return '?DateTimeInterface';
			default:
				return '?string';
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
	 * Adds use statements for commonly used Propulsion classes
	 * @param      string &$script The script will be modified in this method.
	 */
	protected function addUseStatements(&$script)
	{
		$script .= "
";
	}

	/**
	 * Override getUseStatements to provide PHP 8.4 compatible use statements with
	 * deduplication. See QueryBuilder::getUseStatements()/PeerBuilder::getUseStatements()
	 * for the full rationale (identical logic, copied here because this builder needed the
	 * same fix): without this, addClassBody()'s FQCN declareClass() calls for core
	 * Propulsion\* classes (BaseObject, PropulsionException, Criteria, ...) either get a
	 * redundant `use` emitted for a flat/non-namespaced class (fatal -- "name is already in
	 * use" -- when PropulsionQuickBuilder concatenates many such classes into one eval()'d
	 * script with no namespace block between them), or, for a genuinely namespaced class,
	 * would need one but the default (OMBuilder::getUseStatements()) doesn't map legacy
	 * bare declares to their real FQCN.
	 */
	public function getUseStatements($ignoredNamespace = null)
	{
		$script = '';
		$declaredClasses = $this->declaredClasses;
		unset($declaredClasses[$ignoredNamespace]);

		$classMap = [];
		$preferredNamespaces = [
			'PropulsionException' => 'Propulsion\\Exception\\PropulsionException',
			'BasePeer' => 'Propulsion\\Util\\BasePeer',
			'Criteria' => 'Propulsion\\Query\\Criteria',
			'ModelCriteria' => 'Propulsion\\Query\\ModelCriteria',
			'ModelJoin' => 'Propulsion\\Query\\ModelJoin',
			'PropulsionPDO' => 'Propulsion\\Connection\\PropulsionPDO',
			'PropulsionCollection' => 'Propulsion\\Collection\\PropulsionCollection',
			'PropulsionObjectCollection' => 'Propulsion\\Collection\\PropulsionObjectCollection',
			'Propulsion' => 'Propulsion\\Propulsion',
			'BaseObject' => 'Propulsion\\OM\\BaseObject',
			'Persistent' => 'Propulsion\\OM\\Persistent'
		];
		$isFlat = !$this->getNamespace();

		foreach ($declaredClasses as $namespace => $classes) {
			foreach ($classes as $class) {
				$fullName = $namespace ? $namespace . '\\' . $class : $class;

				if ($isFlat && isset($preferredNamespaces[$class])
					&& ($namespace === '' || $fullName === $preferredNamespaces[$class])) {
					// Flat target referencing a globally-aliased core class: no import needed.
					continue;
				}

				if (!$isFlat && $namespace === '' && isset($preferredNamespaces[$class])) {
					// Namespaced target with a legacy bare declare of a core class: import its
					// real FQCN, since a bare reference here would resolve relative to this
					// class's own namespace instead.
					$fullName = $preferredNamespaces[$class];
				}

				$classMap[$class] = $fullName;
			}
		}

		asort($classMap);

		foreach ($classMap as $className => $fullName) {
			$script .= sprintf("use %s;\n", $fullName);
		}

		return $script;
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
		$interface = $this->getInterface();
		$parentClass = $this->getBehaviorContent('parentClass');
		$parentClass = (null !== $parentClass) ? $parentClass : ClassTools::classname($this->getBaseClass());
		$implements = $interface == "propel.om.Persistent" ? " implements Persistent" : "";

		$script .= "
/**
 * Base class that represents a row from the '$tableName' table.
 *
 * $tableDesc
 *
 * This class uses PHP 8.4 features including:
 * - Typed properties
 * - Constructor property promotion
 * - Readonly properties where appropriate
 * - Modern type hints and return types
 *";
		if ($this->getBuildProperty('addTimeStamp')) {
			$now = date('c');
			$script .= "
 * @generated on $now";
		}
		
		$objectFQCN = '\\' . $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		$script .= "
 *
 * @method     $objectFQCN fromXML(string \$data) Populate the object from an XML string
 * @method     $objectFQCN fromYAML(string \$data) Populate the object from a YAML string
 * @method     $objectFQCN fromJSON(string \$data) Populate the object from a JSON string
 * @method     $objectFQCN fromCSV(string \$data) Populate the object from a CSV string
 * @method     string toXML(bool \$includeLazyLoadColumns = true) Export the object to an XML string
 * @method     string toYAML(bool \$includeLazyLoadColumns = true) Export the object to a YAML string
 * @method     string toJSON(bool \$includeLazyLoadColumns = true) Export the object to a JSON string
 * @method     string toCSV(bool \$includeLazyLoadColumns = true) Export the object to a CSV string
 *
 * @package    " . $this->getPackage() . "
 */";

		// Add use statements for Propulsion classes
		$this->addUseStatements($script);

		$script .= "
abstract class " . $this->getClassname() . " extends $parentClass$implements
{";
	}

	/**
	 * Specifies the methods that are added as part of the basic OM class.
	 * This can be overridden by subclasses that wish to add more methods.
	 * @see        AbstractObjectBuilder::addClassBody()
	 */
	protected function addClassBody(&$script)
	{
		// Declare essential classes for Base object classes
		$this->declareClass('Propulsion\\OM\\BaseObject');
		$this->declareClass('Propulsion\\OM\\Persistent');
		$this->declareClass('Propulsion\\Exception\\PropulsionException');
		$this->declareClass('Propulsion\\Util\\BasePeer');
		$this->declareClass('\\DateTime');
		$this->declareClass('\\DateTimeInterface');
		$this->declareClass('\\Exception');
		$this->declareClass('Propulsion\\Propulsion');
		$this->declareClass('Propulsion\\Query\\Criteria');
		$this->declareClass('Propulsion\\Collection\\PropulsionCollection');
		$this->declareClass('Propulsion\\Collection\\PropulsionObjectCollection');
		
		// Declare related builders for type hints and relationships
		$this->declareClassFromBuilder($this->getStubPeerBuilder());
		$this->declareClassFromBuilder($this->getStubQueryBuilder());

		$this->addConstants($script);
		$this->addProperties($script);
		
		// Always add constructor and applyDefaultValues for PHP 8.4 to ensure typed properties are initialized
		$this->addApplyDefaultValues($script);
		$this->addConstructor($script);
		
		$this->addColumnAccessorMethods($script);
		$this->addColumnMutatorMethods($script);
		$this->addHasOnlyDefaultValues($script);
		$this->addHydrate($script);
		$this->addEnsureConsistency($script);
		$this->addBuildCriteria($script);
		$this->addBuildPkeyCriteria($script);
		$this->addGetPrimaryKey($script);
		$this->addSetPrimaryKey($script);
		$this->addIsPrimaryKeyNull($script);
		$this->addCopy($script);
		$this->addGetPeer($script);
		// Add manipulation methods (save, delete, reload)
		$table = $this->getTable();
		if (!$table->isReadOnly()) {
			$this->addManipulationMethods($script);
		}

		// Add validation methods if enabled
		if ($this->isAddValidateMethod()) {
			$this->addValidationMethods($script);
		}

		// Add generic accessors/mutators if enabled
		if ($this->isAddGenericAccessors()) {
			$this->addGetByName($script);
			$this->addGetByPosition($script);
			$this->addToArray($script);
		}

		if ($this->isAddGenericMutators()) {
			$this->addSetByName($script);
			$this->addSetByPosition($script);
			$this->addFromArray($script);
		}

		$this->addFKMethods($script);
		$this->addRefFKMethods($script);
		$this->addCrossFKMethods($script);
		$this->addClear($script);
		$this->addClearAllReferences($script);
		$this->addPrimaryString($script);

		// Lets behaviors append arbitrary custom methods (e.g. the nested_set behavior's
		// getLeftValue()/setLeftValue()/isRoot()/etc.). See addProperties() for why this
		// hook, like the others added alongside it, was entirely missing before.
		$this->applyBehaviorModifier('objectMethods', $script, "	");

		$this->addMagicCall($script);
	}

	/**
	 * Adds typed properties to the class
	 */
	protected function addProperties(&$script)
	{
		$table = $this->getTable();

		$script .= "
	// Column properties with PHP 8.4 typed properties";

		foreach ($table->getColumns() as $col) {
			$colname = $col->getName();
			$phpname = $col->getPhpName();
			$type = $this->getPhp84PropertyType($col);
			// Temporal (DateTimeInterface-typed) columns can have a non-null default, but
			// PHP property declarations only accept constant/scalar expressions as defaults
			// -- `new DateTime(...)` isn't legal here (unlike in a constructor-promoted
			// parameter). Enum columns are similar for a different reason:
			// getDefaultValueString() returns the enum's *index* (an int) for its stored
			// representation, but the property itself is typed ?string -- a property
			// declaration default must match the declared type exactly (no int-to-string
			// coercion is allowed there, unlike a real assignment statement). Always default
			// both to null in the property declaration; the real default is applied via
			// applyDefaultValues(), called from the constructor (see
			// addApplyDefaultValues()), where a plain assignment IS allowed to coerce.
			$defaultVal = ($col->isTemporalType() || $col->isEnumType()) ? 'NULL' : $this->getDefaultValueString($col);
			// Array-typed columns default to an empty array, not null, absent an explicit
			// schema default -- PHP5ObjectBuilder's array getter lazily coerced a null
			// internal value to array() on read; this builder's getter is a plain property
			// return, so the property itself needs the empty-array default instead. `array()`
			// is a legal constant expression for a property declaration default (unlike the
			// DateTime/enum cases above), so this can be set directly here rather than
			// deferred to applyDefaultValues() -- which, for tables with no column that has
			// an explicit default at all, is never even generated (see hasDefaultValues()).
			if (($defaultVal === 'NULL' || $defaultVal === 'null') && $col->getType() === PropulsionTypes::PHP_ARRAY) {
				$defaultVal = 'array()';
			}

			// Add property documentation
			$script .= "

	/**
	 * The value for the $colname field.
	 * " . ($col->getDescription() ? $col->getDescription() : '') . "
	 */";
		// Add the typed property. PHP5ObjectBuilder always declared column properties
		// `protected` (see archaeology/php5-builders/PHP5ObjectBuilder.php,
		// addColumnAttributeDeclaration()); several tests across the suite rely on that
		// contract via a "declare a public subclass property with the same name to gain
		// white-box access" trick (e.g. GeneratedObjectEnumColumnTypeTest), which only
		// works if the parent's property is protected -- a same-named `private` property
		// in the parent is a genuinely separate storage slot, not an overridable one, so
		// the trick silently gains no access at all. Match PHP5's visibility here rather
		// than `private` for non-lazy columns.
		$visibility = 'protected';

		if ($defaultVal !== 'NULL' && $defaultVal !== 'null') {
			$script .= "
	$visibility $type \$$phpname = $defaultVal;";
		} else {
			// Always initialize nullable properties with explicit null to prevent PHP 8.4 uninitialized property errors
			$script .= "
	$visibility $type \$$phpname = null;";
		}

		if ($col->isLazyLoad()) {
			$clo = strtolower($col->getName());
			$script .= "
	protected bool \${$clo}_isLoaded = false;";
		}
		}

		// Add foreign key object properties (single object references)
		foreach ($table->getForeignKeys() as $fk) {
			$varName = $this->getFKVarName($fk);
			$foreignTable = $fk->getForeignTable();
			$foreignClassName = $foreignTable->getPhpName();
			
			// Declare the foreign class for proper type hinting
			$this->declareClassFromBuilder($this->getNewStubObjectBuilder($foreignTable));
			
			$script .= "

	/**
	 * @var ?$foreignClassName
	 */
	protected ?$foreignClassName \$$varName = null;";
		}

		// Add referrer foreign key collection properties (arrays of objects)
		foreach ($table->getReferrers() as $refFK) {
			$refTableName = $refFK->getTable()->getPhpName();
			$this->declareClassFromBuilder($this->getNewStubObjectBuilder($refFK->getTable()));
			
			if ($refFK->isLocalPrimaryKey()) {
				$varName = $this->getPKRefFKVarName($refFK);
				$script .= "

	/**
	 * @var ?$refTableName
	 */
	protected ?$refTableName \$$varName = null;";
			} else {
				$varName = $this->getRefFKCollVarName($refFK);
				$script .= "

	/**
	 * @var PropulsionObjectCollection|null
	 */
	protected PropulsionObjectCollection|null \$$varName = null;";
			}
		}

		// Add cross foreign key collection properties
		foreach ($table->getCrossFks() as $fkList) {
			list($refFK, $crossFK) = $fkList;
			$varName = $this->getCrossFKVarName($crossFK);
			$crossTableName = $crossFK->getForeignTable()->getPhpName();
			$this->declareClassFromBuilder($this->getNewStubObjectBuilder($crossFK->getForeignTable()));
			
			$script .= "

	/**
	 * @var PropulsionObjectCollection|null
	 */
	protected PropulsionObjectCollection|null \$$varName = null;";
		}

		$script .= "

	/**
	 * Flag to prevent endless save loop, if this object is referenced by another object which falls in this transaction.
	 */
	protected bool \$alreadyInSave = false;

	/**
	 * Flag to prevent endless validation loop, if this object is referenced by another object which falls in this transaction.
	 */  
	protected bool \$alreadyInValidation = false;

	/**
	 * Stores whether the object is new/modified/deleted
	 */
	protected bool \$new = true;
	protected bool \$deleted = false;
	protected array \$modifiedColumns = [];";

		// Lets behaviors add extra properties (e.g. the nested_set behavior's left/right/
		// level columns' in-memory shadow, if any). This hook was entirely missing from
		// this builder until now -- every behavior that hooks object-level codegen
		// silently got none of its generated code injected, see KNOWN_ISSUES.md.
		$this->applyBehaviorModifier('objectAttributes', $script, "	");
	}

	/**
	 * Adds the constructor with PHP 8.4 constructor property promotion where appropriate
	 */
	protected function addConstructor(&$script)
	{
		$table = $this->getTable();
		
		$script .= "

	/**
	 * Initializes internal state of {$this->getClassname()} object.
	 * @see parent::__construct()
	 */
	public function __construct()
	{
		parent::__construct();
		\$this->applyDefaultValues();
	}";
	}

	/**
	 * Override parent method to only return true for non-expression default values.
	 * Expression-based defaults (like NOW()) should be handled by the database at insert time,
	 * not during object instantiation.
	 */
	protected function hasDefaultValues()
	{
		foreach ($this->getTable()->getColumns() as $col) {
			$def = $col->getDefaultValue();
			if ($def !== null && !$def->isExpression()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds the applyDefaultValues() method, which is called from the constructor.
	 * For PHP 8.4, this ensures all typed properties are properly initialized.
	 */
	protected function addApplyDefaultValues(&$script)
	{
		$table = $this->getTable();

		$script .= "

	/**
	 * Applies default values to this object.
	 * This method should be called from the object's constructor (or
	 * equivalent initialization method).
	 * @see        __construct()
	 */
	public function applyDefaultValues(): void
	{";

		// For PHP 8.4, we need to initialize all typed properties, not just those with explicit defaults
		foreach ($table->getColumns() as $col) {
			$phpname = $col->getPhpName();
			$def = $col->getDefaultValue();
			
			if ($def !== null && !$def->isExpression()) {
				// Use the explicit default value. Temporal columns need an actual
				// DateTime instance assigned here (a typed ?DateTimeInterface property
				// can't be assigned the raw formatted string getDefaultValueString()
				// returns for them -- see addProperties() for why it can't just be the
				// property's compile-time default either).
				$defaultValue = $this->getDefaultValueString($col);
				if ($col->isTemporalType() && $defaultValue !== 'NULL' && $defaultValue !== 'null') {
					$this->declareClass('\\DateTime');
					$script .= "
		\$this->$phpname = new \\DateTime($defaultValue);";
				} else {
					$script .= "
		\$this->$phpname = $defaultValue;";
				}
			} elseif ($col->getType() === PropulsionTypes::PHP_ARRAY) {
				// See addProperties() -- array columns default to an empty array, not null.
				$script .= "
		\$this->$phpname = array();";
			} else {
				// For typed properties without explicit defaults, initialize to null if nullable
				$returnType = $this->getPhp84TypeHint($col);
				if (strpos($returnType, '?') === 0 || strpos($returnType, 'null') !== false) {
					$script .= "
		\$this->$phpname = null;";
				}
			}
		}

		$script .= "
	}";
	}

	/**
	 * Adds getter methods with proper PHP 8.4 return types
	 */
	protected function addColumnAccessorMethods(&$script)
	{
		$table = $this->getTable();

		foreach ($table->getColumns() as $col) {
			// Check if this is an enum column and use special accessor
			if ($col->isEnumType()) {
				$this->addEnumAccessor($script, $col);
			} else {
				$this->addColumnAccessor($script, $col);
			}
			// Array-typed columns with a plural name (e.g. "tags") additionally get
			// has<Singular>()/add<Singular>()/remove<Singular>() convenience methods --
			// ported from PHP5ObjectBuilder::addHasArrayElement()/addAddArrayElement()/
			// addRemoveArrayElement(), which were entirely missing from the promoted
			// builder (see KNOWN_ISSUES.md, Phase 3.5).
			if ($col->getType() === PropulsionTypes::PHP_ARRAY && $col->isNamePlural()) {
				$this->addHasArrayElement($script, $col);
				$this->addAddArrayElement($script, $col);
				$this->addRemoveArrayElement($script, $col);
			}
		}
	}

	/**
	 * Adds a has<Singular>() tester method for a plural-named array column.
	 */
	protected function addHasArrayElement(&$script, Column $col)
	{
		$cfc = $col->getPhpName();
		$singularPhpName = rtrim($cfc, 's');
		$script .= "

	/**
	 * Test the presence of a value in the [" . $col->getName() . "] array column value.
	 * @param      mixed \$value
	 * @return     bool
	 */
	public function has$singularPhpName(mixed \$value): bool
	{
		return in_array(\$value, \$this->get$cfc());
	}";
	}

	/**
	 * Adds an add<Singular>() method for a plural-named array column.
	 */
	protected function addAddArrayElement(&$script, Column $col)
	{
		$cfc = $col->getPhpName();
		$singularPhpName = rtrim($cfc, 's');
		$returnType = $this->getClassname();
		$script .= "

	/**
	 * Adds a value to the [" . $col->getName() . "] array column value.
	 * @param      mixed \$value
	 * @return     $returnType The current object (for fluent API support)
	 */
	public function add$singularPhpName(mixed \$value): $returnType
	{
		\$currentArray = \$this->get$cfc();
		\$currentArray[] = \$value;
		\$this->set$cfc(\$currentArray);

		return \$this;
	}";
	}

	/**
	 * Adds a remove<Singular>() method for a plural-named array column.
	 */
	protected function addRemoveArrayElement(&$script, Column $col)
	{
		$cfc = $col->getPhpName();
		$singularPhpName = rtrim($cfc, 's');
		$returnType = $this->getClassname();
		$script .= "

	/**
	 * Removes a value from the [" . $col->getName() . "] array column value.
	 * @param      mixed \$value
	 * @return     $returnType The current object (for fluent API support)
	 */
	public function remove$singularPhpName(mixed \$value): $returnType
	{
		\$targetArray = array();
		foreach (\$this->get$cfc() as \$element) {
			if (\$element != \$value) {
				\$targetArray[] = \$element;
			}
		}
		\$this->set$cfc(\$targetArray);

		return \$this;
	}";
	}

	/**
	 * Adds a getter method for a column with PHP 8.4 type hints
	 */
	protected function addColumnAccessor(&$script, Column $col)
	{
		$colname = $col->getName();
		$phpname = $col->getPhpName();
		$description = $col->getDescription() ? $col->getDescription() : "Get the value of [$colname] column.";

		if ($col->isTemporalType()) {
			$returnType = $this->getPhp84TypeHint($col);
			$script .= "

	/**
	 * $description
	 * When \$format is provided, returns the value as a formatted string instead.
	 *
	 * @param string|null \$format Optional date/time format string for backwards compatibility.
	 * @return \\DateTimeInterface|string|null
	 */
	public function get$phpname(?string \$format = null): \\DateTimeInterface|string|null
	{
		if (\$format !== null) {
			return \$this->{$phpname}?->format(\$format);
		}
		return \$this->$phpname;
	}

	/**
	 * Get the [$colname] column value formatted as a string.
	 *
	 * @param string \$format The date/time format string
	 * @return ?string Formatted date/time value or null if the value is null.
	 */
	public function get{$phpname}Formatted(string \$format = 'Y-m-d H:i:s'): ?string
	{
		return \$this->{$phpname}?->format(\$format);
	}";
		} else {
			$returnType = $this->getPhp84TypeHint($col);
			$clo = strtolower($col->getName());
			// Lazy-loaded columns (e.g. BLOB/CLOB) aren't populated by hydrate() -- see
			// addHydrate()'s isLazyLoad() skip -- so the getter has to trigger a
			// dedicated one-column query the first time it's accessed. Ported from
			// PHP5ObjectBuilder::addLazyLoader(); the promoted builder previously had no
			// lazy-load mechanism at all (the ${clo}_isLoaded flag was declared and reset
			// on reload()/clear(), but nothing ever read it or loaded anything).
			$loadCall = $col->isLazyLoad() ? "
		if (!\$this->{$clo}_isLoaded) {
			\$this->load$phpname();
		}
" : '';
			$script .= "

	/**
	 * $description
	 *
	 * @return $returnType
	 */
	public function get$phpname(): $returnType
	{{$loadCall}
		return \$this->$phpname;
	}";
			if ($col->isLazyLoad()) {
				$this->addLazyLoader($script, $col);
			}
		}
	}

	/**
	 * Adds the load<Phpname>() lazy-loader method for a lazy-loaded column.
	 *
	 * Ported from PHP5ObjectBuilder::addLazyLoader(), simplified: this builder only
	 * needs to support the LOB (resource) and plain-scalar cases actually exercised by
	 * the fixtures (see GeneratedObjectLobTest); PHP5's Oracle/SQL Server-specific
	 * branches for CLOB streaming and PDO::PARAM_LOB column binding aren't ported since
	 * nothing in this codebase's supported platforms needs them.
	 */
	protected function addLazyLoader(&$script, Column $col)
	{
		$this->declareClass('Propulsion\Connection\PropulsionPDO');
		$this->declareClass('Propulsion\Exception\PropulsionException');
		$this->declareClass('\PDO');
		$this->declareClass('\Exception');

		$phpname = $col->getPhpName();
		$clo = strtolower($col->getName());
		$const = $this->getColumnConstant($col);

		$script .= "

	/**
	 * Load the value for the lazy-loaded [" . $col->getName() . "] column.
	 *
	 * This method performs an additional query to return the value for
	 * the [" . $col->getName() . "] column, since it is not populated by
	 * the hydrate() method.
	 *
	 * @param ?PropulsionPDO \$con The connection to use.
	 * @return void
	 * @throws PropulsionException - any underlying error will be wrapped and re-thrown.
	 */
	protected function load$phpname(?PropulsionPDO \$con = null): void
	{
		\$c = \$this->buildPkeyCriteria();
		\$c->addSelectColumn($const);
		try {
			\$stmt = " . $this->getPeerClassname() . "::doSelectStmt(\$c, \$con);
			\$row = \$stmt->fetch(PDO::FETCH_NUM);
			\$stmt->closeCursor();
";
		if ($col->isLobType()) {
			$script .= "
			if (\$row !== false && \$row[0] !== null) {
				if (is_resource(\$row[0])) {
					// Some PDO drivers (e.g. pgsql, for bytea columns) already return a
					// stream for a LOB column; only string results need wrapping.
					\$this->$phpname = \$row[0];
				} else {
					\$this->$phpname = fopen('php://memory', 'r+');
					fwrite(\$this->$phpname, \$row[0]);
				}
				rewind(\$this->$phpname);
			} else {
				\$this->$phpname = null;
			}
";
		} else {
			$phpType = $col->getPhpType();
			$castType = match($phpType) { 'double' => 'float', 'integer' => 'int', 'boolean' => 'bool', default => $phpType };
			$script .= "
			\$this->$phpname = (\$row !== false && \$row[0] !== null) ? ($castType) \$row[0] : null;
";
		}
		$script .= "
			\$this->{$clo}_isLoaded = true;
		} catch (Exception \$e) {
			throw new PropulsionException(\"Error loading value for [$clo] column\", \$e);
		}
	}";
	}

	/**
	 * Adds setter methods with proper PHP 8.4 parameter types
	 */
	protected function addColumnMutatorMethods(&$script)
	{
		$table = $this->getTable();

		foreach ($table->getColumns() as $col) {
			// Always generate setters for all columns, including primary keys
			// Primary key setters are needed for setting generated IDs after insertion
			// Check if this is an enum column and use special mutator
			if ($col->isEnumType()) {
				$this->addEnumMutator($script, $col);
			} else {
				$this->addColumnMutator($script, $col);
			}
		}
	}

	/**
	 * Adds a setter method for a column with PHP 8.4 type hints
	 */
	protected function addColumnMutator(&$script, Column $col)
	{
		$colname = $col->getName();
		$phpname = $col->getPhpName();
		$paramType = $this->getPhp84TypeHint($col);
		$returnType = $this->getClassname();

		$description = $col->getDescription() ? $col->getDescription() : "Set the value of [$colname] column.";

		// Always add = null default value to support clearing relationships and object state
		$defaultValue = ' = null';

		if ($col->isLobType()) {
			// Ported from PHP5ObjectBuilder::addLobMutator(). Because BLOB columns are
			// streams in PDO, we have to assume that they are always modified when a new
			// value is passed in -- the contents of a stream may have changed externally
			// even if the resource identity hasn't. A raw string is wrapped in a fresh
			// php://memory stream (rewound to the start) so callers can pass either form.
			$script .= "

	/**
	 * $description
	 *
	 * @param mixed \$value New value: a stream resource, or a raw string that will be
	 *              wrapped in a new stream.
	 * @return $returnType The current object (for fluent API support)
	 */
	public function set$phpname(mixed \$value$defaultValue): $returnType
	{
		if (\$value !== null && !is_resource(\$value)) {
			\$fp = fopen('php://memory', 'r+');
			fwrite(\$fp, (string) \$value);
			rewind(\$fp);
			\$value = \$fp;
		}
		\$this->$phpname = \$value;
		\$this->modifiedColumns[] = " . $this->getColumnConstant($col) . ";

		return \$this;
	}";
			return;
		}

		if ($col->isBooleanType()) {
			// The property/getter are strictly typed ?bool, but Propulsion has always
			// accepted common string/int representations here too and normalized them --
			// see PHP5ObjectBuilder::addBooleanMutator() in archaeology/php5-builders/ --
			// so e.g. setActive('no') is a real, previously-supported calling convention,
			// not a caller bug. Under the too-strict promoted signature, a non-empty string
			// like 'false' or 'off' would just cast truthy to `true`, silently inverting
			// the caller's intent instead of throwing (bool is weakly-typed enough to
			// accept a string without a TypeError, so this bug was silent, not fatal).
			$script .= "

	/**
	 * $description
	 * Non-boolean arguments are converted using the following rules:
	 *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
	 *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
	 * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
	 *
	 * @param bool|int|string|null \$value New value
	 * @return $returnType The current object (for fluent API support)
	 */
	public function set$phpname(bool|int|string|null \$value$defaultValue): $returnType
	{
		if (\$value !== null) {
			\$value = is_string(\$value)
				? !in_array(strtolower(\$value), ['false', 'off', '-', 'no', 'n', '0', ''], true)
				: (bool) \$value;
		}
		if (\$this->$phpname !== \$value) {
			\$this->$phpname = \$value;
			\$this->modifiedColumns[] = " . $this->getColumnConstant($col) . ";
		}

		return \$this;
	}";
		} elseif ($col->isTemporalType()) {
			// The property/getter are strictly typed ?DateTimeInterface, but Propulsion has
			// always accepted a Unix timestamp (int) or a parseable date string here too
			// (PHP5ObjectBuilder's addTemporalMutator used PropulsionDateTime::newInstance()
			// to normalize any of those) -- callers passing an int/string, a common and
			// previously-supported pattern (e.g. TimestampableBehavior/SoftDeleteBehavior
			// call setX($someUnixTimestamp)), got a hard TypeError against the too-strict
			// ?DateTimeInterface-only param type this builder used for every column type
			// uniformly. Accept the wider mixed input and normalize to a real
			// DateTimeInterface instance (unlike PHP5, which normalized to a formatted
			// string -- this builder's properties are real DateTimeInterface objects, so
			// normalizing to an object here keeps that consistent) before comparing/storing.
			$script .= "

	/**
	 * $description
	 *
	 * @param DateTimeInterface|string|int|null \$value New value: a DateTimeInterface, a
	 *              Unix timestamp (int), a parseable date/time string, or null.
	 * @return $returnType The current object (for fluent API support)
	 */
	public function set$phpname(DateTimeInterface|string|int|null \$value$defaultValue): $returnType
	{
		if (\$value !== null && !(\$value instanceof DateTimeInterface)) {
			\$value = is_int(\$value) ? (new DateTime())->setTimestamp(\$value) : new DateTime((string) \$value);
		}
		if (\$this->$phpname != \$value) {
			\$this->$phpname = \$value;
			\$this->modifiedColumns[] = " . $this->getColumnConstant($col) . ";
		}

		return \$this;
	}";
		} else {
			$script .= "

	/**
	 * $description
	 *
	 * @param $paramType \$value New value
	 * @return $returnType The current object (for fluent API support)
	 */
	public function set$phpname($paramType \$value$defaultValue): $returnType
	{
		if (\$this->$phpname !== \$value) {
			\$this->$phpname = \$value;
			\$this->modifiedColumns[] = " . $this->getColumnConstant($col) . ";
		}

		return \$this;
	}";
		}

		// Add temporal specific mutator if needed
		if ($col->isTemporalType()) {
			$script .= "

	/**
	 * Sets the [$colname] column to the current time.
	 *
	 * @return $returnType The current object (for fluent API support)
	 */
	public function set{$phpname}ToNow(): $returnType
	{
		return \$this->set$phpname(new DateTime());
	}";
		}
	}

	/**
	 * Adds an enum accessor method (getter that returns the enum label, not the key)
	 */
	protected function addEnumAccessor(&$script, Column $col)
	{
		$colname = $col->getName();
		$phpname = $col->getPhpName();
		$description = $col->getDescription() ? $col->getDescription() : "Get the [$colname] column value.";
		$peerClass = $this->getPeerClassname();
		$columnConstant = $this->getColumnConstant($col);

		$script .= "

	/**
	 * $description
	 * Returns the enum label (not the key).
	 *
	 * @return ?string
	 */
	public function get$phpname(): ?string
	{
		if (null === \$this->$phpname) {
			return null;
		}
		\$valueSet = {$peerClass}::getValueSet($columnConstant);
		if (!isset(\$valueSet[\$this->$phpname])) {
			throw new PropulsionException('Unknown stored enum key: ' . \$this->$phpname);
		}
		return \$valueSet[\$this->$phpname];
	}";
	}

	/**
	 * Adds an enum mutator method (setter that accepts the enum label and stores the key)
	 */
	protected function addEnumMutator(&$script, Column $col)
	{
		$colname = $col->getName();
		$phpname = $col->getPhpName();
		$peerClass = $this->getPeerClassname();
		$columnConstant = $this->getColumnConstant($col);

		$script .= "

	/**
	 * Set the value of [$colname] column.
	 * Accepts the enum label (not the key).
	 *
	 * @param ?string \$v New value
	 * @return static The current object (for fluent API support)
	 */
	public function set$phpname(?string \$v): static
	{
		if (\$v !== null) {
			\$valueSet = {$peerClass}::getValueSet($columnConstant);
			if (!in_array(\$v, \$valueSet)) {
				throw new PropulsionException(sprintf('Value \"%s\" is not accepted in this enumerated column', \$v));
			}
			\$v = array_search(\$v, \$valueSet);
		}

		if (\$this->$phpname !== \$v) {
			\$this->$phpname = \$v;
			\$this->modifiedColumns[] = $columnConstant;
		}

		return \$this;
	}";
	}

	/**
	 * Adds the rest of the class body with modern PHP 8.4 patterns
	 */
	protected function addHasOnlyDefaultValues(&$script)
	{
		$table = $this->getTable();
		$colsWithDefaults = array();
		foreach ($table->getColumns() as $col) {
			$def = $col->getDefaultValue();
			if ($def !== null && !$def->isExpression()) {
				$colsWithDefaults[] = $col;
			}
		}

		$script .= "

	/**
	 * Checks whether the object read from the database has only default values.
	 *
	 * This is used internally by Propulsion to determine whether to make the
	 * object dirty (set as modified) when it is first loaded from the database.
	 *
	 * @return bool True if the object has only default values
	 */
	public function hasOnlyDefaultValues(): bool
	{";

		if (empty($colsWithDefaults)) {
			$script .= "
		// No columns have default values, so return true
		return true;";
		} else {
			foreach ($colsWithDefaults as $col) {
				$phpname = $col->getPhpName();
				$defaultVal = $this->getDefaultValueString($col);
				if ($col->isTemporalType() && $defaultVal !== 'NULL' && $defaultVal !== 'null') {
					// $this->$phpname is a DateTimeInterface object here (or null), not the
					// raw formatted string $defaultVal holds -- compare formatted values.
					$fmt = $this->getTemporalFormatter($col);
					// Curly-brace-delimited {$phpname} below is required, not stylistic: plain
					// "$this->$phpname->format(...)" makes PHP's simple string-interpolation
					// syntax parse "$phpname->format" as a (bogus, build-time) property access
					// on the $phpname *string* itself -- silently emitting corrupted generated
					// code (and a "Attempt to read property on string" warning at build time).
					$script .= "
		if (\$this->{$phpname} === null || \$this->{$phpname}->format('$fmt') !== $defaultVal) {
			return false;
		}";
				} elseif ($col->isEnumType()) {
					// $this->$phpname was coerced to the property's declared ?string type when
					// applyDefaultValues() assigned it the int index $defaultVal holds -- a
					// strict !== would always be true (differing types), so compare loosely.
					$script .= "
		if (\$this->$phpname != $defaultVal) {
			return false;
		}";
				} else {
					$script .= "
		if (\$this->$phpname !== $defaultVal) {
			return false;
		}";
				}
			}
			$script .= "

		// All default values are matching
		return true;";
		}

		$script .= "
	}";
	}

	/**
	 * Adds foreign key accessor methods with proper PHP 8.4 syntax
	 */
	protected function addFKMethods(&$script)
	{
		foreach ($this->getTable()->getForeignKeys() as $fk) {
			$this->declareClassFromBuilder($this->getNewStubObjectBuilder($fk->getForeignTable()));
			$this->declareClassFromBuilder($this->getNewStubQueryBuilder($fk->getForeignTable()));
			$this->addFKMutator($script, $fk);
			$this->addFKAccessor($script, $fk);
		}
	}

	/**
	 * Adds a foreign key accessor method with PHP 8.4 type hints and proper property names
	 */
	protected function addFKAccessor(&$script, ForeignKey $fk)
	{
		$table = $this->getTable();
		$varName = $this->getFKVarName($fk);
		
		$fkQueryBuilder = $this->getNewStubQueryBuilder($fk->getForeignTable());
		$fkObjectBuilder = $this->getNewObjectBuilder($fk->getForeignTable())->getStubObjectBuilder();
		$className = $fkObjectBuilder->getClassname();
		
		$and = "";
		$conditional = "";
		$localColumns = array();
		
		// If the related columns are a primary key on the foreign table
		// then use retrieveByPk() instead of doSelect() to take advantage
		// of instance pooling
		$useRetrieveByPk = $fk->isForeignPrimaryKey();
		
		foreach ($fk->getLocalColumns() as $columnName) {
			$lfmap = $fk->getLocalForeignMapping();
			
			$localColumn = $table->getColumn($columnName);
			$foreignColumn = $fk->getForeignTable()->getColumn($lfmap[$columnName]);
			
			$column = $table->getColumn($columnName);
			$cptype = $column->getPhpType();
			$phpname = $column->getPhpName(); // Use PHP name instead of lowercase
			$localColumns[$foreignColumn->getPosition()] = '$this->'.$phpname;
			
			if ($cptype == "integer" || $cptype == "float" || $cptype == "double") {
				$conditional .= $and . "\$this->". $phpname ." != 0";
			} elseif ($cptype == "string") {
				$conditional .= $and . "(\$this->" . $phpname ." !== \"\" && \$this->".$phpname." !== null)";
			} else {
				$conditional .= $and . "\$this->" . $phpname ." !== null";
			}
			
			$and = " && ";
		}
		
		ksort($localColumns);
		$localColumns = count($localColumns) > 1 ?
				('array('.implode(', ', $localColumns).')') : reset($localColumns);
		
		$script .= "

	/**
	 * Get the associated $className object
	 *
	 * @param PropulsionPDO|null \$con Optional Connection object.
	 * @return ?$className The associated $className object.
	 * @throws PropulsionException
	 */
	public function get".$this->getFKPhpNameAffix($fk, $plural = false)."(?PropulsionPDO \$con = null): ?$className
	{";
		$script .= "
		if (\$this->{$varName} === null && ($conditional)) {";
		if ($useRetrieveByPk) {
			$script .= "
			\$this->{$varName} = ".$fkQueryBuilder->getClassname()."::create()->findPk($localColumns, \$con);";
		} else {
			$script .= "
			\$this->{$varName} = ".$fkQueryBuilder->getClassname()."::create()
				->filterBy" . $this->getRefFKPhpNameAffix($fk, $plural = false) . "(\$this)
				->findOne(\$con);";
		}
		if ($fk->isLocalPrimaryKey()) {
			$script .= "
			// Because this foreign key represents a one-to-one relationship, we will create a bi-directional association.
			\$this->{$varName}->set".$this->getRefFKPhpNameAffix($fk, $plural = false)."(\$this);";
		}
		$script .= "
		}
		return \$this->{$varName};
	}";
	}

	/**
	 * Adds a foreign key mutator method with PHP 8.4 type hints and proper property names
	 */
	protected function addFKMutator(&$script, ForeignKey $fk)
	{
		$this->declareClassFromBuilder($this->getStubObjectBuilder());
		
		$table = $this->getTable();
		$tblFK = $fk->getTable();
		$joinedTableObjectBuilder = $this->getNewObjectBuilder($fk->getForeignTable());
		$className = $joinedTableObjectBuilder->getObjectClassname();
		
		$varName = $this->getFKVarName($fk);
		
		$script .= "

	/**
	 * Declares an association between this object and a $className object.
	 *
	 * @param ?$className \$v
	 * @return static The current object (for fluent API support)
	 * @throws PropulsionException
	 */
	public function set".$this->getFKPhpNameAffix($fk, $plural = false)."(?$className \$v = null): static
	{";

		// Now create the code for the setter
		foreach ($fk->getLocalColumns() as $columnName) {
			$lfmap = $fk->getLocalForeignMapping();
			$localColumn = $table->getColumn($columnName);
			$foreignColumn = $fk->getForeignTable()->getColumn($lfmap[$columnName]);
			
			$phpname = $localColumn->getPhpName(); // Use PHP name instead of lowercase
			$foreignPhpname = $foreignColumn->getPhpName();
			
			$script .= "
		if (\$v === null) {
			\$this->set$phpname(" . $this->getDefaultValueString($localColumn) . ");
		} else {
			\$this->set$phpname(\$v->get$foreignPhpname());
		}
";
		}

		$script .= "
		\$this->{$varName} = \$v;
";

		// Now create the code to create a bi-directional relationship
		if ($fk->isLocalPrimaryKey()) {
			$script .= "
		// Add binding for other direction of this 1:1 relationship.
		if (\$v !== null) {
			\$v->set".$this->getRefFKPhpNameAffix($fk, $plural = false)."(\$this);
		}
";
		} else {
			$script .= "
		// Add binding for other direction of this n:1 relationship.
		// If this object has already been added to the $className object, it is removed first
		if (\$v !== null) {
			\$v->add".$this->getRefFKPhpNameAffix($fk, $plural = false)."(\$this);
		}
";
		}

		$script .= "
		return \$this;
	}";
	}

	/**
	 * Adds hydration method with typed parameters
	 */
	protected function addHydrate(&$script)
	{
		$script .= "

	/**
	 * Hydrates (populates) the object variables with values from the database resultset.
	 *
	 * @param array \$row Database result row
	 * @param int \$startcol The 0-based offset for reading from the resultset row.
	 * @param bool \$rehydrate Whether this object is being re-hydrated from the database.
	 * @return int Next column offset
	 * @throws PropulsionException - Any caught Exception will be rewrapped as a PropulsionException.
	 */
	public function hydrate(array \$row, int \$startcol = 0, bool \$rehydrate = false): int
	{";

		$table = $this->getTable();
		$n = 0;
		foreach ($table->getColumns() as $col) {
			// Skip lazy-loaded columns - they are not included in the hydrate query
			if ($col->isLazyLoad()) {
				continue;
			}
			$phpname = $col->getPhpName();
			$script .= "
		\$this->$phpname = (\$row[\$startcol + " . $n . "] !== null) ? ";
			
			if ($col->isTemporalType()) {
				$script .= "new DateTime(\$row[\$startcol + " . $n . "])";
			} elseif ($col->getType() === PropulsionTypes::BOOLEAN) {
				$script .= "(bool) \$row[\$startcol + " . $n . "]";
			} elseif ($col->getType() === PropulsionTypes::PHP_ARRAY) {
				$script .= "(\$row[\$startcol + " . $n . "] === '' ? array() : (preg_match('/^ \\| (.*) \\| $/s', \$row[\$startcol + " . $n . "], \$matches) ? explode(' | ', \$matches[1]) : explode(' | ', \$row[\$startcol + " . $n . "])))";
			} elseif ($col->isNumericType()) {
				$phpType = $col->getPhpType();
				// Use canonical cast names (PHP 8.5 deprecates non-canonical forms)
				$castType = match($phpType) { 'double' => 'float', 'integer' => 'int', default => $phpType };
				$script .= "($castType) \$row[\$startcol + " . $n . "]";
			} else {
				$script .= "(string) \$row[\$startcol + " . $n . "]";
			}
			$script .= " : null;";
			$n++;
		}

		$script .= "
		\$this->resetModified();
		\$this->setNew(false);

		if (\$rehydrate) {
			\$this->ensureConsistency();
		}

		return \$startcol + " . $n . ";
	}";
	}

	// More methods would follow similar patterns...

	/**
	 * Closes class definition
	 */
	protected function addClassClose(&$script)
	{
		$script .= "
}";

		// Filter hooks rewrite the whole accumulated $script by reference, so this must
		// run last, after the closing brace above. See addProperties() for background.
		$this->applyBehaviorModifier('objectFilter', $script, "");
	}

	/**
	 * Helper methods for compatibility with existing AbstractObjectBuilder interface
	 */
	
	/**
	 * Constructs variable name for collection of objects which reference current table by specified foreign key.
	 * @param ForeignKey $fk
	 * @return string
	 */
	public function getRefFKCollVarName(ForeignKey $fk): string
	{
		return 'coll' . $this->getRefFKPhpNameAffix($fk, true);
	}

	/**
	 * Constructs variable name for single object which references current table by specified foreign key
	 * which is ALSO a primary key (hence one-to-one relationship).
	 * @param ForeignKey $fk
	 * @return string
	 */
	public function getPKRefFKVarName(ForeignKey $fk): string
	{
		return 'single' . $this->getRefFKPhpNameAffix($fk, false);
	}

	/**
	 * Constructs variable name for cross foreign key collection.
	 * @param ForeignKey $crossFK
	 * @return string
	 */
	protected function getCrossFKVarName(ForeignKey $crossFK): string
	{
		return 'coll' . $this->getFKPhpNameAffix($crossFK, true);
	}

	protected function addConstants(&$script)
	{
		// Add table constants for PHP 8.4
		$script .= "
	/**
	 * Peer class name
	 */
	public const PEER = '" . addslashes($this->getStubPeerBuilder()->getFullyQualifiedClassname()) . "';
";
	}

	/**
	 * Constructs variable name for fkey-related objects.
	 * @param      ForeignKey $fk
	 * @return     string
	 */
	public function getFKVarName(ForeignKey $fk)
	{
		return 'a' . $this->getFKPhpNameAffix($fk, $plural = false);
	}

	protected function addEnsureConsistency(&$script)
	{
		// Implementation would be similar to parent but with typed parameters
		$script .= "

	/**
	 * Ensures that all required foreign key objects are loaded.
	 * This method is used internally by Propulsion to hydrate the object.
	 */
	protected function ensureConsistency(): void
	{
		// Implementation for ensuring referential consistency
	}";
	}

	protected function addGetByName(&$script)
	{
		$script .= "

	/**
	 * Retrieves a field from the object by name passed in as a string.
	 *
	 * @param string \$name name
	 * @param string \$type The type of fieldname the \$name is of:
	 *                     one of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME
	 *                     BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM
	 * @return mixed Value of field.
	 */
	public function getByName(string \$name, string \$type = BasePeer::TYPE_PHPNAME): mixed
	{
		\$pos = ".$this->getPeerClassname()."::translateFieldName(\$name, \$type, BasePeer::TYPE_NUM);
		return \$this->getByPosition(\$pos);
	}";
	}

	/**
	 * Adds manipulation methods (save, delete, reload) with PHP 8.4 types
	 */
	protected function addManipulationMethods(&$script)
	{
		$this->addReload($script);
		$this->addDelete($script);
		$this->addSave($script);
		$this->addDoSave($script);
	}

	/**
	 * Adds validation methods
	 */
	protected function addValidationMethods(&$script)
	{
		$this->addValidationFailuresAttribute($script);
		$this->addGetValidationFailures($script);
		$this->addValidate($script);
		$this->addDoValidate($script);
	}

	/**
	 * Adds the delete method with PHP 8.4 type hints
	 */
	protected function addDelete(&$script)
	{
		$this->declareClass('Propulsion\Connection\PropulsionPDO');
		$this->declareClass('Propulsion\Exception\PropulsionException');
		
		$script .= "

	/**
	 * Removes this object from datastore and sets delete attribute.
	 *
	 * @param PropulsionPDO|null \$con Database connection
	 * @return void
	 * @throws PropulsionException
	 */
	public function delete(?PropulsionPDO \$con = null): void
	{
		if (\$this->isDeleted()) {
			throw new PropulsionException('This object has already been deleted.');
		}

		if (\$con === null) {
			\$con = Propulsion::getWriteConnection(" . $this->getPeerClassname() . "::DATABASE_NAME);
		}

		\$con->beginTransaction();
		try {
			\$deleteQuery = " . $this->getQueryClassname() . "::create()
				->filterByPrimaryKey(\$this->getPrimaryKey());";
		// preDelete()/postDelete() are user-overridable hook methods (see
		// runtime/Lib/OM/BaseObject.php) -- preDelete() returning false vetoes the delete.
		// This whole addHooks-gated block, and the applyBehaviorModifier() calls inside it,
		// were entirely missing before: neither the virtual hook methods nor any behavior's
		// preDelete/postDelete modifier ever ran. See KNOWN_ISSUES.md.
		if ($this->getGeneratorConfig()->getBuildProperty('addHooks')) {
			$script .= "
			\$ret = \$this->preDelete(\$con);";
			$this->applyBehaviorModifier('preDelete', $script, "			");
			$script .= "
			if (\$ret) {
				\$deleteQuery->delete(\$con);
				\$this->postDelete(\$con);";
			$this->applyBehaviorModifier('postDelete', $script, "				");
			$script .= "
				\$con->commit();
				\$this->setDeleted(true);
			} else {
				\$con->commit();
			}";
		} else {
			$this->applyBehaviorModifier('preDelete', $script, "			");
			$script .= "
			\$deleteQuery->delete(\$con);";
			$this->applyBehaviorModifier('postDelete', $script, "			");
			$script .= "
			\$con->commit();
			\$this->setDeleted(true);";
		}
		$script .= "
		} catch (PropulsionException \$e) {
			\$con->rollBack();
			throw \$e;
		}
	}";
	}

	/**
	 * Adds the save method with PHP 8.4 type hints
	 */
	protected function addSave(&$script)
	{
		$this->declareClass('Propulsion\Connection\PropulsionPDO');
		$this->declareClass('Propulsion\Exception\PropulsionException');
		
		$table = $this->getTable();
		$reloadOnUpdate = $table->isReloadOnUpdate();
		$reloadOnInsert = $table->isReloadOnInsert();
		
		$script .= "

	/**
	 * Persists this object to the database.
	 *
	 * If the object is new, it inserts it; otherwise an update is performed.
	 * All modified related objects will also be persisted in the doSave()
	 * method. This method wraps all precipitate database operations in a
	 * single transaction.
	 *
	 * @param PropulsionPDO|null \$con Database connection";
		if ($reloadOnUpdate || $reloadOnInsert) {
			$script .= "
	 * @param bool \$skipReload Whether to skip the reload for this object from database.";
		}
		$script .= "
	 * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
	 * @throws PropulsionException
	 */
	public function save(?PropulsionPDO \$con = null" . ($reloadOnUpdate || $reloadOnInsert ? ", bool \$skipReload = false" : "") . "): int
	{
		if (\$this->isDeleted()) {
			throw new PropulsionException('You cannot save an object that has been deleted.');
		}

		if (\$con === null) {
			\$con = Propulsion::getWriteConnection(" . $this->getPeerClassname() . "::DATABASE_NAME);
		}

		\$con->beginTransaction();
		\$isInsert = \$this->isNew();
		try {";
		// preSave()/postSave()/preInsert()/postInsert()/preUpdate()/postUpdate() are
		// user-overridable hook methods (see runtime/Lib/OM/BaseObject.php) --
		// preSave()/preInsert()/preUpdate() returning false vetoes the save. This whole
		// addHooks-gated block, and the applyBehaviorModifier() calls inside it, were
		// entirely missing before: neither the virtual hook methods nor any behavior's
		// save-lifecycle modifier ever ran. See KNOWN_ISSUES.md.
		if ($this->getGeneratorConfig()->getBuildProperty('addHooks')) {
			$script .= "
			\$ret = \$this->preSave(\$con);";
			$this->applyBehaviorModifier('preSave', $script, "			");
			$script .= "
			if (\$isInsert) {
				\$ret = \$ret && \$this->preInsert(\$con);";
			$this->applyBehaviorModifier('preInsert', $script, "				");
			$script .= "
			} else {
				\$ret = \$ret && \$this->preUpdate(\$con);";
			$this->applyBehaviorModifier('preUpdate', $script, "				");
			$script .= "
			}
			if (\$ret) {
				\$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ", \$skipReload" : "") . ");
				if (\$isInsert) {
					\$this->postInsert(\$con);";
			$this->applyBehaviorModifier('postInsert', $script, "					");
			$script .= "
				} else {
					\$this->postUpdate(\$con);";
			$this->applyBehaviorModifier('postUpdate', $script, "					");
			$script .= "
				}
				\$this->postSave(\$con);";
			$this->applyBehaviorModifier('postSave', $script, "				");
			$script .= "
				" . $this->getPeerClassname() . "::addInstanceToPool(\$this);
			} else {
				\$affectedRows = 0;
			}
			\$con->commit();
			return \$affectedRows;";
		} else {
			$this->applyBehaviorModifier('preSave', $script, "			");
			$script .= "
			\$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ", \$skipReload" : "") . ");";
			$this->applyBehaviorModifier('postSave', $script, "			");
			$script .= "
			" . $this->getPeerClassname() . "::addInstanceToPool(\$this);
			\$con->commit();
			return \$affectedRows;";
		}
		$script .= "
		} catch (PropulsionException \$e) {
			\$con->rollBack();
			throw \$e;
		}
	}";
	}

	/**
	 * Adds the doSave method with PHP 8.4 type hints
	 */
	protected function addDoSave(&$script)
	{
		$this->declareClass('Propulsion\Connection\PropulsionPDO');
		$this->declareClass('Propulsion\Exception\PropulsionException');
		
		$table = $this->getTable();
		$reloadOnUpdate = $table->isReloadOnUpdate();
		$reloadOnInsert = $table->isReloadOnInsert();
		
		$script .= "

	/**
	 * Performs the work of inserting or updating the row in the database.
	 *
	 * If the object is new, it inserts it; otherwise an update is performed.
	 * All related objects are also updated in this method.
	 *
	 * @param PropulsionPDO \$con Database connection";
		if ($reloadOnUpdate || $reloadOnInsert) {
			$script .= "
	 * @param bool \$skipReload Whether to skip the reload for this object from database.";
		}
		$script .= "
	 * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
	 * @throws PropulsionException
	 */
	protected function doSave(PropulsionPDO \$con" . ($reloadOnUpdate || $reloadOnInsert ? ", bool \$skipReload = false" : "") . "): int
	{
		\$affectedRows = 0;
		if (!\$this->alreadyInSave) {
			\$this->alreadyInSave = true;";
		
		if (count($table->getForeignKeys())) {
			$script .= "

			// We call the save method on the following object(s) if they
			// were passed to this object by their corresponding set
			// method. This object relates to these object(s) by a
			// foreign key reference.";
			
			foreach ($table->getForeignKeys() as $fk) {
				$aVarName = $this->getFKVarName($fk);
				$script .= "
			if (\$this->$aVarName !== null) {
				if (\$this->{$aVarName}->isModified() || \$this->{$aVarName}->isNew()) {
					\$affectedRows += \$this->{$aVarName}->save(\$con);
				}
				\$this->set" . $this->getFKPhpNameAffix($fk, false) . "(\$this->$aVarName);
			}";
			}
		}
		
		if ($table->hasAutoIncrementPrimaryKey()) {
			$script .= "
			if (\$this->isNew()) {
				\$this->modifiedColumns[] = " . $this->getColumnConstant($table->getAutoIncrementPrimaryKey()) . ";
			}";
		}
		
		$script .= "

			// If this object has been modified, then save it to the database.
			if (\$this->isModified()) {
				if (\$this->isNew()) {
					\$criteria = \$this->buildCriteria();";
					
			// Handle auto-increment primary keys
			foreach ($table->getColumns() as $col) {
				$colConst = $this->getColumnConstant($col);
				if ($col->isPrimaryKey() && $col->isAutoIncrement() && $table->getIdMethod() != "none" && !$table->isAllowPkInsert()) {
					$script .= "
					if (\$criteria->keyContainsValue($colConst)) {
						throw new PropulsionException('Cannot insert a value for auto-increment primary key ($colConst)');
					}";
					if (!$this->getPlatform()->supportsInsertNullPk()) {
						$script .= "
					// remove pkey col since this table uses auto-increment and passing a null value for it is not valid
					\$criteria->remove($colConst);";
					}
				}
			}
			
			$script .= "
					\$pk = " . $this->getNewPeerBuilder($table)->getBasePeerClassname() . "::doInsert(\$criteria, \$con);
					\$affectedRows += 1;";
					
			if ($table->getIdMethod() != IDMethod::NO_ID_METHOD) {
				if (count($pks = $table->getPrimaryKey())) {
					foreach ($pks as $pk) {
						if ($pk->isAutoIncrement()) {
							$script .= "
					\$this->set" . $pk->getPhpName() . "(\$pk);";
						}
					}
				}
			}
			
			$script .= "
					\$this->setNew(false);
				} else {
					\$affectedRows += " . $this->getPeerClassname() . "::doUpdateThis(\$this, \$con);
				}
				\$this->resetModified();
			}
";

		// PDO does not rewind LOB stream resources after using them to bind an
		// insert/update parameter, which otherwise leaves the very resource the caller
		// just handed us (or read back via the getter) positioned at EOF -- ported from
		// PHP5ObjectBuilder::addSaveBody()'s equivalent post-doSave rewind loop.
		foreach ($table->getColumns() as $col) {
			if ($col->isLobType()) {
				$phpname = $col->getPhpName();
				$script .= "
			if (\$this->$phpname !== null && is_resource(\$this->$phpname)) {
				rewind(\$this->$phpname);
			}
";
			}
		}

		// Add referrers save logic (many-to-many collections)
		foreach ($table->getReferrers() as $refFK) {
			if ($refFK->isLocalPrimaryKey()) {
				$varName = $this->getPKRefFKVarName($refFK);
				$script .= "
			if (\$this->$varName !== null) {
				if (!\$this->{$varName}->isDeleted()) {
					\$affectedRows += \$this->{$varName}->save(\$con);
				}
			}
";
			} else {
				$collName = $this->getRefFKCollVarName($refFK);
				$script .= "
			if (\$this->$collName !== null) {
				foreach (\$this->$collName as \$referrerFK) {
					if (!\$referrerFK->isDeleted()) {
						\$affectedRows += \$referrerFK->save(\$con);
					}
				}
			}
";
			}
		}

		$script .= "
			\$this->alreadyInSave = false;
		}
		return \$affectedRows;
	}";
	}

	/**
	 * Adds the reload method with PHP 8.4 type hints
	 */
	protected function addReload(&$script)
	{
		$this->declareClass('Propulsion\Connection\PropulsionPDO');
		$this->declareClass('Propulsion\Exception\PropulsionException');
		$this->declareClass('\PDO');
		
		$script .= "

	/**
	 * Reloads this object from datastore based on primary key and (optionally) resets all associated objects.
	 *
	 * This will only work if the object has been saved and has a valid primary key set.
	 *
	 * @param bool \$deep Whether to also de-associate any related objects.
	 * @param PropulsionPDO|null \$con The database connection to use.
	 * @return void
	 * @throws PropulsionException If this object is deleted, unsaved or doesn't have pk match in db
	 */
	public function reload(bool \$deep = false, ?PropulsionPDO \$con = null): void
	{
		if (\$this->isDeleted()) {
			throw new PropulsionException('Cannot reload a deleted object.');
		}

		if (\$this->isNew()) {
			throw new PropulsionException('Cannot reload an unsaved object.');
		}

		if (\$con === null) {
			\$con = Propulsion::getReadConnection(" . $this->getPeerClassname() . "::DATABASE_NAME);
		}

		\$stmt = " . $this->getPeerClassname() . "::doSelectStmt(\$this->buildPkeyCriteria(), \$con);
		\$row = \$stmt->fetch(PDO::FETCH_NUM);
		\$stmt->closeCursor();
		if (!\$row) {
			throw new PropulsionException('Cannot find matching row in the database to reload object values.');
		}
		\$this->hydrate(\$row, 0, true); // rehydrate";
		
		// Support for lazy load columns: force the next getter call to re-query,
		// rather than returning a value (or, for LOB columns, a stream resource) that
		// may be stale or -- for a resource the caller has since fclose()'d -- invalid.
		$table = $this->getTable();
		foreach ($table->getColumns() as $col) {
			if ($col->isLazyLoad()) {
				$clo = strtolower($col->getName());
				$phpname = $col->getPhpName();
				$script .= "
		\$this->{$clo}_isLoaded = false;
		\$this->$phpname = null;";
			}
		}

		$script .= "

		if (\$deep) {
			\$this->clearAllReferences(\$deep);
		}
	}";
	}

	// Additional method implementations
	protected function addGetByPosition(&$script) 
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Retrieves a field from the object by Position as specified in the xml schema.
	 * Zero-based.
	 *
	 * @param int \$pos position in xml schema
	 * @return mixed Value of field at \$pos
	 */
	public function getByPosition(int \$pos): mixed
	{
		return match(\$pos) {";
		$i = 0;
		foreach ($table->getColumns() as $col) {
			$cfc = $col->getPhpName();
			$script .= "
			$i => \$this->get$cfc(),";
			$i++;
		}
		$script .= "
			default => null
		};
	}
";
	}
	
	protected function addToArray(&$script) 
	{
		$fks = $this->getTable()->getForeignKeys();
		$referrers = $this->getTable()->getReferrers();
		$hasFks = count($fks) > 0 || count($referrers) > 0;
		$objectClassName = $this->getObjectClassname();
		$pkGetter = $this->getTable()->hasCompositePrimaryKey() ? 'serialize($this->getPrimaryKey())' : '$this->getPrimaryKey()';
		$script .= "

	/**
	 * Exports the object as an array.
	 *
	 * You can specify the key type of the array by passing one of the class
	 * type constants.
	 *
	 * @param string \$keyType (optional) One of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME,
	 *                    BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM.
	 *                    Defaults to BasePeer::TYPE_PHPNAME.
	 * @param bool \$includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
	 * @param array \$alreadyDumpedObjects List of objects to skip to avoid recursion";
		if ($hasFks) {
			$script .= "
	 * @param bool \$includeForeignObjects (optional) Whether to include hydrated related objects. Default to FALSE.";
		}
		$script .= "
	 *
	 * @return array an associative array containing the field names (as keys) and field values
	 */
	public function toArray(string \$keyType = BasePeer::TYPE_PHPNAME, ?bool \$includeLazyLoadColumns = true, array \$alreadyDumpedObjects = array()" . ($hasFks ? ", bool \$includeForeignObjects = false" : '') . "): array|string
	{
		if (isset(\$alreadyDumpedObjects['$objectClassName'][$pkGetter])) {
			return '*RECURSION*';
		}
		\$alreadyDumpedObjects['$objectClassName'][$pkGetter] = true;
		\$keys = ".$this->getPeerClassname()."::getFieldNames(\$keyType);
		\$result = array(";
		foreach ($this->getTable()->getColumns() as $num => $col) {
			if ($col->isLazyLoad()) {
				$script .= "
			\$keys[$num] => (\$includeLazyLoadColumns) ? \$this->get".$col->getPhpName()."() : null,";
			} else {
				$script .= "
			\$keys[$num] => \$this->get".$col->getPhpName()."(),";
			}
		}
		$script .= "
		);";
		if ($hasFks) {
			$script .= "
		if (\$includeForeignObjects) {";
			foreach ($fks as $fk) {
				$script .= "
			if (null !== \$this->" . $this->getFKVarName($fk) . ") {
				\$result['" . $this->getFKPhpNameAffix($fk, $plural = false) . "'] = \$this->" . $this->getFKVarName($fk) . "->toArray(\$keyType, \$includeLazyLoadColumns,  \$alreadyDumpedObjects, true);
			}";
			}
			foreach ($referrers as $fk) {
				if ($fk->isLocalPrimaryKey()) {
					$script .= "
			if (null !== \$this->" . $this->getPKRefFKVarName($fk) . ") {
				\$result['" . $this->getRefFKPhpNameAffix($fk, $plural = false) . "'] = \$this->" . $this->getPKRefFKVarName($fk) . "->toArray(\$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects, true);
			}";
				} else {
					$script .= "
			if (null !== \$this->" . $this->getRefFKCollVarName($fk) . ") {
				\$result['" . $this->getRefFKPhpNameAffix($fk, $plural = true) . "'] = \$this->" . $this->getRefFKCollVarName($fk) . "->toArray(null, true, \$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects, true);
			}";
				}
			}
			$script .= "
		}";
		}
		$script .= "
		return \$result;
	}
";
	}
	
	protected function addSetByName(&$script) 
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Sets a field from the object by name passed in as a string.
	 *
	 * @param string \$name peer name
	 * @param mixed \$value field value
	 * @param string \$type The type of fieldname the \$name is of:
	 *                     one of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME
	 *                     BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM
	 * @return void
	 */
	public function setByName(string \$name, mixed \$value, string \$type = BasePeer::TYPE_PHPNAME): void
	{
		\$pos = ".$this->getPeerClassname()."::translateFieldName(\$name, \$type, BasePeer::TYPE_NUM);
		\$this->setByPosition(\$pos, \$value);
	}
";
	}
	
	protected function addSetByPosition(&$script) 
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Sets a field from the object by Position as specified in the xml schema.
	 * Zero-based.
	 *
	 * @param int \$pos position in xml schema
	 * @param mixed \$value field value
	 * @return void
	 */
	public function setByPosition(int \$pos, mixed \$value): void
	{
		match(\$pos) {";
		$i = 0;
		foreach ($table->getColumns() as $col) {
			$cfc = $col->getPhpName();
			$script .= "
			$i => \$this->set$cfc(\$value),";
			$i++;
		}
		$script .= "
			default => null
		};
	}
";
	}
	
	protected function addFromArray(&$script) 
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Populates the object using an array.
	 *
	 * This is particularly useful when populating an object from one of the
	 * request arrays (e.g. \$_POST).  This method goes through the column
	 * names, checking to see whether a matching key exists in populated
	 * array. If so the setByName() method is called for that column.
	 *
	 * You can specify the key type of the array by additionally passing one
	 * of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME,
	 * BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM.
	 * The default key type is the column's phpname (e.g. 'AuthorId')
	 *
	 * @param array \$arr An array to populate the object from.
	 * @param string \$keyType The type of keys the array uses.
	 * @return void
	 */
	public function fromArray(array \$arr, string \$keyType = BasePeer::TYPE_PHPNAME): void
	{
		\$keys = ".$this->getPeerClassname()."::getFieldNames(\$keyType);";
		foreach ($table->getColumns() as $num => $col) {
			$cfc = $col->getPhpName();
			$script .= "
		if (array_key_exists(\$keys[$num], \$arr)) \$this->set$cfc(\$arr[\$keys[$num]]);";
		}
		$script .= "
	}
";
	}
	
	protected function addBuildCriteria(&$script): void
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Build a Criteria object containing the values of all modified columns in this object.
	 *
	 * @return Criteria The Criteria object containing all modified values.
	 */
	public function buildCriteria(): Criteria
	{
		\$criteria = new Criteria(" . $this->getPeerClassname() . "::DATABASE_NAME);
";
		foreach ($table->getColumns() as $col) {
			$cptype = $col->getPhpType();
			$phpname = $col->getPhpName();
			$const = $this->getColumnConstant($col);
			if ($col->getType() === PropulsionTypes::PHP_ARRAY) {
				$script .= "
		if (\$this->isColumnModified($const)) \$criteria->add($const, \$this->$phpname ? ' | ' . implode(' | ', \$this->$phpname) . ' | ' : '');";
			} else {
				$script .= "
		if (\$this->isColumnModified($const)) \$criteria->add($const, \$this->$phpname);";
			}
		}
		$script .= "

		return \$criteria;
	}";
	}
	
	protected function addBuildPkeyCriteria(&$script): void
	{
		$table = $this->getTable();
		$pks = $table->getPrimaryKey();
		$script .= "

	/**
	 * Builds a Criteria object containing the primary key for this object.
	 * Unlike buildCriteria() this method includes the primary key values regardless
	 * of whether they have been modified.
	 *
	 * @return Criteria The Criteria object containing value(s) for primary key(s).
	 */
	public function buildPkeyCriteria(): Criteria
	{
		\$criteria = new Criteria(" . $this->getPeerClassname() . "::DATABASE_NAME);";

		foreach ($pks as $pk) {
			$phpname = $pk->getPhpName();
			$const = $this->getColumnConstant($pk);
			if ($pk->getType() === PropulsionTypes::PHP_ARRAY) {
				$script .= "
		\$criteria->add($const, \$this->$phpname ? ' | ' . implode(' | ', \$this->$phpname) . ' | ' : '');";
			} else {
				$script .= "
		\$criteria->add($const, \$this->$phpname);";
			}
		}
		$script .= "

		return \$criteria;
	}";
	}
	
	protected function addGetPrimaryKey(&$script): void
	{
		$table = $this->getTable();
		$pks = $table->getPrimaryKey();
		
		if (count($pks) == 1) {
			$phpname = $pks[0]->getPhpName();
			$type = $this->getPhp84PropertyType($pks[0]);
			$script .= "

	/**
	 * Returns the primary key for this object (row).
	 * @return $type
	 */
	public function getPrimaryKey(): $type
	{
		return \$this->get$phpname();
	}";
		} else {
			$script .= "

	/**
	 * Returns the composite primary key for this object.
	 * The array elements will be in same order as the primary key columns in the table schema.
	 * @return array
	 */
	public function getPrimaryKey(): array
	{
		\$pks = [];";
			foreach ($pks as $pk) {
				$phpname = $pk->getPhpName();
				$script .= "
		\$pks[] = \$this->get$phpname();";
			}
			$script .= "
		return \$pks;
	}";
		}
	}
	
	protected function addSetPrimaryKey(&$script): void
	{
		$table = $this->getTable();
		$pks = $table->getPrimaryKey();
		
		if (count($pks) == 1) {
			$phpname = $pks[0]->getPhpName();
			$script .= "

	/**
	 * Generic method to set the primary key ($phpname column).
	 *
	 * @param mixed \$key Primary key.
	 * @return void
	 */
	public function setPrimaryKey(mixed \$key): void
	{
		\$this->set$phpname(\$key);
	}";
		} else {
			$script .= "

	/**
	 * Set the composite primary key.
	 *
	 * @param mixed \$keys The primary key columns as array.
	 * @return void
	 */
	public function setPrimaryKey(mixed \$keys): void
	{";
			$i = 0;
			foreach ($pks as $pk) {
				$phpname = $pk->getPhpName();
				$script .= "
		\$this->set$phpname(\$keys[$i]);";
				$i++;
			}
			$script .= "
	}";
		}
	}
	
	protected function addIsPrimaryKeyNull(&$script): void
	{
		$table = $this->getTable();
		$pks = $table->getPrimaryKey();
		$script .= "

	/**
	 * Returns true if the primary key for this object is null.
	 * @return bool
	 */
	public function isPrimaryKeyNull(): bool
	{";
		
		if (count($pks) == 1) {
			$phpname = $pks[0]->getPhpName();
			$script .= "
		return null === \$this->$phpname;";
		} else {
			$tests = [];
			foreach ($pks as $pk) {
				$phpname = $pk->getPhpName();
				$tests[] = "(null === \$this->$phpname)";
			}
			$script .= "
		return " . implode(' && ', $tests) . ";";
		}
		$script .= "
	}";
	}
	
	protected function addCopy(&$script): void
	{
		$table = $this->getTable();
		$className = '\\' . $this->getStubObjectBuilder()->getFullyQualifiedClassname();
		
		$script .= "

	/**
	 * Sets contents of passed object to values from current object.
	 *
	 * If desired, this method can also make copies of all associated (fkey referrers)
	 * objects.
	 *
	 * @param object \$copyObj An object of $className (or compatible) type.
	 * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
	 * @param bool \$makeNew Whether to reset autoincrement PKs and make the object new.
	 * @throws PropulsionException
	 */
	public function copyInto(object \$copyObj, bool \$deepCopy = false, bool \$makeNew = true): void
	{";
		
		foreach ($table->getColumns() as $col) {
			// Skip auto-increment columns in copyInto
			if ($col->isAutoIncrement()) {
				continue;
			}
			$phpname = $col->getPhpName();
			if ($col->isEnumType()) {
				// Enum columns store the raw index internally ($this->$phpname), but
				// their generated setter (addEnumMutator()) validates and accepts the
				// enum *label*, not the index -- passing the raw index straight through
				// throws "Value ... is not accepted in this enumerated column" the moment
				// the index isn't itself a valid label (e.g. index 2 for a 2-value enum).
				// Use the getter, which resolves the index back to its label, instead.
				$script .= "
		\$copyObj->set$phpname(\$this->get$phpname());";
			} else {
				$script .= "
		\$copyObj->set$phpname(\$this->$phpname);";
			}
		}

		// Add deep copy for foreign key references and collections
		$referrers = $table->getReferrers();
		if (!empty($referrers)) {
			$script .= "

		if (\$deepCopy) {
			// important: temporarily setNew(false) because this affects the behavior of
			// the getter/setter methods for fkey referrer objects.
			\$copyObj->setNew(false);
";
			foreach ($table->getReferrers() as $refFK) {
				if ($refFK->isLocalPrimaryKey()) {
					continue; // 1:1 relationships don't get deep copied
				}
				$refFKPhpNameAffix = $this->getRefFKPhpNameAffix($refFK, true);
				$script .= "
			foreach (\$this->get$refFKPhpNameAffix() as \$relObj) {
				if (\$relObj !== \$this) {  // ensure that we don't try to copy a reference to ourselves
					\$copyObj->add" . $this->getRefFKPhpNameAffix($refFK, false) . "(\$relObj->copy(\$deepCopy));
				}
			}
";
			}
			$script .= "
		} // if (\$deepCopy)";
		}

		$script .= "

		if (\$makeNew) {
			\$copyObj->setNew(true);";
		
		// Reset auto-increment columns to null
		foreach ($table->getColumns() as $col) {
			if ($col->isAutoIncrement()) {
				$phpname = $col->getPhpName();
				$script .= "
			\$copyObj->set$phpname(null); // this is a auto-increment column, so set to default value";
			}
		}
		$script .= "
		}
	}

	/**
	 * Makes a copy of this object that will be inserted as a new row in table when saved.
	 * It creates a new object filling in the simple attributes, but skipping any primary
	 * keys that are defined for the table.
	 *
	 * If desired, this method can also make copies of all associated (fkey referrers)
	 * objects.
	 *
	 * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
	 * @return $className Clone of current object.
	 * @throws PropulsionException
	 */
	public function copy(bool \$deepCopy = false): $className
	{
		// we use get_class(), because this might be a subclass
		\$clazz = get_class(\$this);
		\$copyObj = new \$clazz();
		\$this->copyInto(\$copyObj, \$deepCopy);
		return \$copyObj;
	}";
	}
	
	protected function addGetPeer(&$script): void
	{
		$peerClassName = $this->getPeerClassname();
		$script .= "

	/**
	 * Returns the Peer class name.
	 * Propulsion uses the Peer classes to retrieve and save data from the database.
	 * @return string
	 */
	public function getPeer(): string
	{
		return $peerClassName::class;
	}";
	}
	
	protected function addRefFKMethods(&$script): void
	{
		if (!$referrers = $this->getTable()->getReferrers()) {
			return;
		}
		$this->addInitRelations($script, $referrers);
		foreach ($referrers as $refFK) {
			$this->declareClassFromBuilder($this->getNewStubObjectBuilder($refFK->getTable()));
			$this->declareClassFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));
			if ($refFK->isLocalPrimaryKey()) {
				$this->addPKRefFKGet($script, $refFK);
				$this->addPKRefFKSet($script, $refFK);
			} else {
				$this->addRefFKClear($script, $refFK);
				$this->addRefFKInit($script, $refFK);
				$this->addRefFKGet($script, $refFK);
				$this->addRefFKCount($script, $refFK);
				$this->addRefFKAdd($script, $refFK);
				$this->addRefFKGetJoinMethods($script, $refFK);
			}
		}
	}

	/**
	 * Initializes a collection based on the name of a relation.
	 */
	protected function addInitRelations(&$script, $referrers): void
	{
		$script .= "

	/**
	 * Initializes a collection based on the name of a relation.
	 * Avoids crafting an 'init[\$relationName]s' method name
	 * that wouldn't work when StandardEnglishPluralizer is used.
	 *
	 * @param string \$relationName The name of the relation to initialize
	 * @return void
	 */
	public function initRelation(string \$relationName): void
	{";
		foreach ($referrers as $refFK) {
			if (!$refFK->isLocalPrimaryKey()) {
				$relationName = $this->getRefFKPhpNameAffix($refFK);
				$relCol = $this->getRefFKPhpNameAffix($refFK, true);
				$script .= "
		if ('$relationName' == \$relationName) {
			\$this->init$relCol();
			return;
		}";
			}
		}
		$script .= "
	}";
	}

	/**
	 * Adds the method that clears the referrer fkey collection.
	 */
	protected function addRefFKClear(&$script, ForeignKey $refFK): void
	{
		$relCol = $this->getRefFKPhpNameAffix($refFK, true);
		$collName = $this->getRefFKCollVarName($refFK);

		$script .= "

	/**
	 * Clears out the $collName collection
	 *
	 * This does not modify the database; however, it will remove any associated objects, causing
	 * them to be refetched by subsequent calls to accessor method.
	 *
	 * @return void
	 */
	public function clear$relCol(): void
	{
		\$this->{$collName} = null; // important to set this to null since that means it is uninitialized
	}";
	}

	/**
	 * Adds the method that initializes the referrer fkey collection.
	 */
	protected function addRefFKInit(&$script, ForeignKey $refFK): void
	{
		$relCol = $this->getRefFKPhpNameAffix($refFK, true);
		$collName = $this->getRefFKCollVarName($refFK);
		// NOTE: Use fully-qualified class name here (instead of short class) so that
		// PropulsionObjectCollection::save() method_exists(<model>, 'save') succeeds.
		// Short names caused runtime 'Cannot save objects on a read-only model' when
		// the non-namespaced short class was not autoloadable.
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getFullyQualifiedClassname();

		$script .= "

	/**
	 * Initializes the $collName collection.
	 *
	 * @param bool \$overrideExisting If set to true, the method call initializes
	 *                                        the collection even if it is not empty
	 *
	 * @return void
	 */
	public function init$relCol(bool \$overrideExisting = true): void
	{
		if (null !== \$this->{$collName} && !\$overrideExisting) {
			return;
		}
		\$this->{$collName} = new PropulsionObjectCollection();
		\$this->{$collName}->setModel('$relatedObjectClassName');
	}";
	}

	/**
	 * Adds the method that gets the referrer fkey collection.
	 */
	protected function addRefFKGet(&$script, ForeignKey $refFK): void
	{
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getClassname();
		$relCol = $this->getRefFKPhpNameAffix($refFK, true);
		$collName = $this->getRefFKCollVarName($refFK);

		$script .= "

	/**
	 * Gets a collection of $relatedObjectClassName objects which contain a foreign key that references this object.
	 *
	 * @param ?Criteria \$criteria optional Criteria object to narrow the query
	 * @param ?PropulsionPDO \$con optional connection object
	 * @return PropulsionObjectCollection<$relatedObjectClassName> List of $relatedObjectClassName objects
	 * @throws PropulsionException
	 */
	public function get$relCol(?Criteria \$criteria = null, ?PropulsionPDO \$con = null): PropulsionObjectCollection
	{
		if (null === \$this->{$collName} || null !== \$criteria) {
			if (\$this->isNew() && null === \$this->{$collName}) {
				// return empty collection
				\$this->init$relCol();
			} else {
				\$query = " . $this->getNewStubQueryBuilder($refFK->getTable())->getClassname() . "::create(null, \$criteria);
				
				\$this->{$collName} = \$query
					->filterBy" . $this->getFKPhpNameAffix($refFK, false) . "(\$this)
					->find(\$con);
			}
		}

		return \$this->{$collName} ?? new PropulsionObjectCollection();
	}";
	}

	/**
	 * Adds the method that gets the count of referrer fkey collection.
	 */
	protected function addRefFKCount(&$script, ForeignKey $refFK): void
	{
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getClassname();
		$relCol = $this->getRefFKPhpNameAffix($refFK, true);

		$script .= "

	/**
	 * Returns the number of related $relatedObjectClassName objects.
	 *
	 * @param ?Criteria \$criteria
	 * @param bool \$distinct
	 * @param ?PropulsionPDO \$con
	 * @return int Count of related $relatedObjectClassName objects.
	 * @throws PropulsionException
	 */
	public function count$relCol(?Criteria \$criteria = null, bool \$distinct = false, ?PropulsionPDO \$con = null): int
	{
		if (\$this->isNew()) {
			return 0;
		}

		\$query = " . $this->getNewStubQueryBuilder($refFK->getTable())->getClassname() . "::create(null, \$criteria);
		if (\$distinct) {
			\$query->distinct();
		}

		return \$query
			->filterBy" . $this->getFKPhpNameAffix($refFK, false) . "(\$this)
			->count(\$con);
	}";
	}

	/**
	 * Adds the method that adds an object to the referrer fkey collection.
	 */
	protected function addRefFKAdd(&$script, ForeignKey $refFK): void
	{
		$this->declareClassFromBuilder($this->getStubObjectBuilder());
		
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getClassname();
		$relCol = $this->getRefFKPhpNameAffix($refFK, true);
		$collName = $this->getRefFKCollVarName($refFK);

		$script .= "

	/**
	 * Method called to associate a $relatedObjectClassName object to this object
	 * through the $relatedObjectClassName foreign key attribute.
	 *
	 * @param $relatedObjectClassName \$l $relatedObjectClassName
	 * @return static The current object (for fluent API support)
	 */
	public function add" . $this->getRefFKPhpNameAffix($refFK, false) . "($relatedObjectClassName \$l): static
	{
		if (\$this->{$collName} === null) {
			\$this->init$relCol();
		}

		if (!\$this->{$collName}->contains(\$l)) { // only add it if the **same** object is not already associated
			\$this->{$collName}[] = \$l;
			\$l->set" . $this->getFKPhpNameAffix($refFK, false) . "(\$this);
		}

		return \$this;
	}";
	}

	/**
	 * Adds getters for join methods
	 */
	protected function addRefFKGetJoinMethods(&$script, ForeignKey $refFK): void
	{
		$table = $this->getTable();
		$tblFK = $refFK->getTable();
		$join_behavior = $this->getGeneratorConfig()->getBuildProperty('useLeftJoinsInDoJoinMethods') ? 'Criteria::LEFT_JOIN' : 'Criteria::INNER_JOIN';

		$peerClassname = $this->getStubPeerBuilder()->getClassname();
		$fkQueryClassname = $this->getNewStubQueryBuilder($refFK->getTable())->getClassname();
		$relCol = $this->getRefFKPhpNameAffix($refFK, $plural=true);
		$collName = $this->getRefFKCollVarName($refFK);

		$fkPeerBuilder = $this->getNewPeerBuilder($tblFK);
		$className = $fkPeerBuilder->getObjectClassname();

		$lastTable = "";
		foreach ($tblFK->getForeignKeys() as $fk2) {

			$tblFK2 = $fk2->getForeignTable();
			$doJoinGet = !$tblFK2->isForReferenceOnly();

			// it doesn't make sense to join in rows from the curent table, since we are fetching
			// objects related to *this* table (i.e. the joined rows will all be the same row as current object)
			if ($this->getTable()->getPhpName() == $tblFK2->getPhpName()) {
				$doJoinGet = false;
			}

			$relCol2 = $this->getFKPhpNameAffix($fk2, $plural = false);

			if ( $this->getRelatedBySuffix($refFK) != "" &&
			($this->getRelatedBySuffix($refFK) == $this->getRelatedBySuffix($fk2))) {
				$doJoinGet = false;
			}

			if ($doJoinGet) {
				$script .= "

	/**
	 * If this collection has already been initialized with
	 * an identical criteria, it returns the collection.
	 * Otherwise if this ".$table->getPhpName()." is new, it will return
	 * an empty collection; or if this ".$table->getPhpName()." has previously
	 * been saved, it will retrieve related $relCol from storage.
	 *
	 * This method is protected by default in order to keep the public
	 * api reasonable.  You can provide public methods for those you
	 * actually need in ".$table->getPhpName().".
	 *
	 * @param ?Criteria \$criteria optional Criteria object to narrow the query
	 * @param ?PropulsionPDO \$con optional connection object
	 * @param string \$join_behavior optional join type to use (defaults to $join_behavior)
	 * @return PropulsionCollection|array List of {$className} objects
	 */
	public function get".$relCol."Join".$relCol2."(?Criteria \$criteria = null, ?PropulsionPDO \$con = null, string \$join_behavior = $join_behavior): PropulsionCollection|array
	{
		\$query = $fkQueryClassname::create(null, \$criteria);
		\$query->joinWith('" . $this->getFKPhpNameAffix($fk2, $plural=false) . "', \$join_behavior);

		return \$this->get". $relCol . "(\$query, \$con);
	}";
			}
		}
	}

	/**
	 * Adds primary key referrer FK accessor
	 */
	protected function addPKRefFKGet(&$script, ForeignKey $refFK): void
	{
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getClassname();
		$varName = $this->getPKRefFKVarName($refFK);
		$relatedByName = $this->getRefFKPhpNameAffix($refFK, false);

		$script .= "

	/**
	 * Get the associated $relatedObjectClassName object (1:1 relationship).
	 *
	 * @param ?PropulsionPDO \$con optional connection object
	 * @return ?$relatedObjectClassName The associated $relatedObjectClassName object.
	 * @throws PropulsionException
	 */
	public function get$relatedByName(?PropulsionPDO \$con = null): ?$relatedObjectClassName
	{
		if (\$this->$varName === null && !\$this->isNew()) {
			\$this->$varName = " . $this->getNewStubQueryBuilder($refFK->getTable())->getClassname() . "::create()->findPk(\$this->getPrimaryKey(), \$con);
		}

		return \$this->$varName;
	}";
	}

	/**
	 * Adds primary key referrer FK mutator
	 */
	protected function addPKRefFKSet(&$script, ForeignKey $refFK): void
	{
		$this->declareClassFromBuilder($this->getStubObjectBuilder());
		
		$relatedObjectClassName = $this->getNewStubObjectBuilder($refFK->getTable())->getClassname();
		$varName = $this->getPKRefFKVarName($refFK);
		$relatedByName = $this->getRefFKPhpNameAffix($refFK, false);

		$script .= "

	/**
	 * Set the associated $relatedObjectClassName object (1:1 relationship).
	 *
	 * @param ?$relatedObjectClassName \$v The $relatedObjectClassName object.
	 * @return static The current object (for fluent API support)
	 * @throws PropulsionException
	 */
	public function set$relatedByName(?$relatedObjectClassName \$v = null): static
	{
		\$this->$varName = \$v;

		// Add binding for other direction of this 1:1 relationship. Guarded (unlike a
		// naive unconditional call) on the other side not already pointing back here --
		// without this check, \$v->set...(\$this) below calls back into this same setter
		// forever, overflowing the stack (a real, previously-undetected bug: this method
		// was never exercised as the default 1:1-relationship setter before the PHP5
		// builders were removed, see KNOWN_ISSUES.md).
		if (\$v !== null && \$v->get" . $this->getFKPhpNameAffix($refFK, false) . "() === null) {
			\$v->set" . $this->getFKPhpNameAffix($refFK, false) . "(\$this);
		}

		return \$this;
	}";
	}
	
	protected function addCrossFKMethods(&$script): void
	{
		$table = $this->getTable();
		foreach ($table->getCrossFks() as $crossFKs) {
			$this->addCrossFKAccessors($script, $crossFKs);
		}
	}

	/**
	 * Adds cross foreign key accessors for many-to-many relationships
	 */
	protected function addCrossFKAccessors(&$script, array $crossFKs): void
	{
		list($refFK, $crossFK) = $crossFKs;
		
		$this->declareClassFromBuilder($this->getNewStubObjectBuilder($crossFK->getForeignTable()));
		$this->declareClassFromBuilder($this->getNewStubQueryBuilder($crossFK->getForeignTable()));

		$this->addCrossFKClear($script, $crossFK);
		$this->addCrossFKInit($script, $crossFK);
		$this->addCrossFKGet($script, $refFK, $crossFK);
		$this->addCrossFKCount($script, $refFK, $crossFK);
		$this->addCrossFKAdd($script, $refFK, $crossFK);
	}

	/**
	 * Adds the method that clears the cross-FK collection (PHP 8.4 version)
	 */
	protected function addCrossFKClear(&$script, ForeignKey $crossFK): void
	{
		$relCol = $this->getFKPhpNameAffix($crossFK, true);
		$collName = $this->getCrossFKVarName($crossFK);

		$script .= "
	/**
	 * Clears out the $collName collection
	 *
	 * This does not modify the database; however, it will remove any associated objects, causing
	 * them to be refetched by subsequent calls to accessor method.
	 *
	 * @return void
	 */
	public function clear$relCol(): void
	{
		\$this->$collName = null; // important to set this to null since that means it is uninitialized
	}
";
	}

	/**
	 * Adds the method that initializes the cross-FK collection (PHP 8.4 version)
	 */
	protected function addCrossFKInit(&$script, ForeignKey $crossFK): void
	{
		$relCol = $this->getFKPhpNameAffix($crossFK, true);
		$collName = $this->getCrossFKVarName($crossFK);
		// NOTE: Same rationale as addRefFKInit(): ensure collection model stores FQCN
		// to keep subsequent method_exists(<FQCN>, 'save') checks valid.
		$relatedObjectClassName = $this->getNewStubObjectBuilder($crossFK->getForeignTable())->getFullyQualifiedClassname();

		$script .= "
	/**
	 * Initializes the $collName collection.
	 *
	 * @param bool \$overrideExisting If set to true, the method call initializes
	 *                                        the collection even if it is not empty
	 *
	 * @return void
	 */
	public function init$relCol(bool \$overrideExisting = true): void
	{
		if (null !== \$this->{$collName} && !\$overrideExisting) {
			return;
		}
		\$this->{$collName} = new PropulsionObjectCollection();
		\$this->{$collName}->setModel('$relatedObjectClassName');
	}
";
	}

	/**
	 * Adds the getter method for cross-FK relationships (PHP 8.4 version)
	 */
	protected function addCrossFKGet(&$script, ForeignKey $refFK, ForeignKey $crossFK): void
	{
		$relatedName = $this->getFKPhpNameAffix($crossFK, true);
		$relatedObjectClassName = $this->getNewStubObjectBuilder($crossFK->getForeignTable())->getClassname();
		$selfRelationName = $this->getFKPhpNameAffix($refFK, false);
		$relatedQueryClassName = $this->getNewStubQueryBuilder($crossFK->getForeignTable())->getClassname();
		$crossRefTableName = $crossFK->getTableName();
		$collName = $this->getCrossFKVarName($crossFK);

		$script .= "
	/**
	 * Gets a collection of $relatedObjectClassName objects related by a many-to-many relationship
	 * to the current object by way of the $crossRefTableName cross-reference table.
	 *
	 * @param ?Criteria \$criteria optional Criteria object to narrow the query
	 * @param ?PropulsionPDO \$con optional connection object
	 * @return PropulsionObjectCollection<$relatedObjectClassName> List of $relatedObjectClassName objects
	 * @throws PropulsionException
	 */
	public function get$relatedName(?Criteria \$criteria = null, ?PropulsionPDO \$con = null): PropulsionObjectCollection
	{
		if (null === \$this->$collName || null !== \$criteria) {
			if (\$this->isNew() && null === \$this->$collName) {
				// return empty collection
				\$this->init$relatedName();
			} else {
				// Create query with proper table context for criteria
				if (\$criteria !== null) {
					\$query = $relatedQueryClassName::create(null, \$criteria);
					\$query->filterBy{$selfRelationName}ViaCrossReference(\$this);
					return \$query->find(\$con);
				} else {
					\$query = $relatedQueryClassName::create()
						->filterBy{$selfRelationName}ViaCrossReference(\$this);
					\$this->$collName = \$query->find(\$con);
				}
			}
		}

		return \$this->$collName ?? new PropulsionObjectCollection();
	}
";
	}

	/**
	 * Adds the count method for cross-FK relationships (PHP 8.4 version)
	 */
	protected function addCrossFKCount(&$script, ForeignKey $refFK, ForeignKey $crossFK): void
	{
		$relatedName = $this->getFKPhpNameAffix($crossFK, true);
		$relatedObjectClassName = $this->getNewStubObjectBuilder($crossFK->getForeignTable())->getClassname();
		$selfRelationName = $this->getFKPhpNameAffix($refFK, false);
		$relatedQueryClassName = $this->getNewStubQueryBuilder($crossFK->getForeignTable())->getClassname();
		$crossRefTableName = $refFK->getTableName();

		$script .= "
	/**
	 * Gets the number of $relatedObjectClassName objects related by a many-to-many relationship
	 * to the current object by way of the $crossRefTableName cross-reference table.
	 *
	 * @param ?Criteria \$criteria
	 * @param bool \$distinct
	 * @param ?PropulsionPDO \$con
	 * @return int Count of related $relatedObjectClassName objects.
	 * @throws PropulsionException
	 */
	public function count$relatedName(?Criteria \$criteria = null, bool \$distinct = false, ?PropulsionPDO \$con = null): int
	{
		if (\$this->isNew()) {
			return 0;
		}

		\$query = $relatedQueryClassName::create(null, \$criteria);
		if (\$distinct) {
			\$query->distinct();
		}

		return \$query
			->filterBy{$selfRelationName}ViaCrossReference(\$this)
			->count(\$con);
	}
";
	}

	/**
	 * Adds the add method for cross-FK relationships (PHP 8.4 version)
	 */
	protected function addCrossFKAdd(&$script, ForeignKey $refFK, ForeignKey $crossFK): void
	{
		$relCol = $this->getFKPhpNameAffix($crossFK, true);
		$relColSingular = $this->getFKPhpNameAffix($crossFK, false);
		$collName = $this->getCrossFKVarName($crossFK);

		$tblFK = $refFK->getTable();
		$crossTableBuilder = $this->getNewObjectBuilder($crossFK->getForeignTable());
		$crossObjectClassName = $crossTableBuilder->getObjectClassname();
		$refTableBuilder = $this->getNewObjectBuilder($refFK->getTable());
		$refClassName = $refTableBuilder->getObjectClassname();

		$crossObjectName = '$' . lcfirst($crossFK->getForeignTable()->getPhpName());
		$refObjectName = '$' . lcfirst($tblFK->getPhpName());

		$script .= "
	/**
	 * Associate a $crossObjectClassName object to this object
	 * through the {$tblFK->getName()} cross reference table.
	 *
	 * @param $crossObjectClassName $crossObjectName The $refClassName object to relate
	 * @return static The current object (for fluent API support)
	 */
	public function add$relColSingular($crossObjectClassName $crossObjectName): static
	{
		if (\$this->$collName === null) {
			\$this->init$relCol();
		}

		if (!\$this->{$collName}->contains($crossObjectName)) { // only add it if the **same** object is not already associated
			\$refObjectName = new $refClassName();
			\$refObjectName->set$relColSingular($crossObjectName);
			\$this->add" . $this->getRefFKPhpNameAffix($refFK, false) . "(\$refObjectName);

			\$this->{$collName}[] = $crossObjectName;
		}

		return \$this;
	}
";
	}
	
	protected function addClear(&$script): void
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Clears the current object and sets all attributes to their default values
	 */
	public function clear(): void
	{";
		foreach ($table->getColumns() as $col) {
			$phpname = $col->getPhpName();
			$script .= "
		\$this->$phpname = null;";
			if ($col->isLazyLoad()) {
				$clo = strtolower($col->getName());
				$script .= "
		\$this->{$clo}_isLoaded = false;";
			}
		}

		$script .= "
		\$this->alreadyInSave = false;
		\$this->alreadyInValidation = false;
		\$this->clearAllReferences();";

		if ($this->hasDefaultValues()) {
			$script .= "
		\$this->applyDefaultValues();";
		}

		$script .= "
		\$this->resetModified();
		\$this->setNew(true);
		\$this->setDeleted(false);
	}";
	}
	
	protected function addClearAllReferences(&$script): void
	{
		$table = $this->getTable();
		$script .= "

	/**
	 * Resets all references to other model objects or collections of model objects.
	 *
	 * This method is a user-space workaround for PHP's inability to garbage collect
	 * objects with circular references (even in PHP 5.3). This is currently necessary
	 * when using Propulsion in certain daemon or large-volume/high-memory operations.
	 *
	 * @param bool \$deep Whether to also clear the references on all referrer objects.
	 */
	public function clearAllReferences(bool \$deep = false): void
	{";
		
		if ($deep = true) {
			foreach ($table->getReferrers() as $refFK) {
				if ($refFK->isLocalPrimaryKey()) {
					$varName = $this->getPKRefFKVarName($refFK);
					$script .= "
		if (\$deep && \$this->{$varName}) {
			\$this->{$varName}->clearAllReferences(\$deep);
		}";
				} else {
					$varName = $this->getRefFKCollVarName($refFK);
					$script .= "
		if (\$deep && \$this->{$varName}) {
			if (is_array(\$this->{$varName})) {
				foreach (\$this->{$varName} as \$o) {
					if (method_exists(\$o, 'clearAllReferences')) {
						\$o->clearAllReferences(\$deep);
					}
				}
			}
		}";
				}
			}
		}

		// Lets behaviors clear their own extra references here too (e.g. a behavior that
		// caches a related collection). See addProperties() for background on why this
		// hook, like the others added alongside it, was entirely missing before.
		$this->applyBehaviorModifier('objectClearReferences', $script, "		");

		// Clear foreign key references
		foreach ($table->getForeignKeys() as $fk) {
			$varName = $this->getFKVarName($fk);
			$script .= "
		\$this->{$varName} = null;";
		}
		
		// Clear referrer collections
		foreach ($table->getReferrers() as $refFK) {
			if ($refFK->isLocalPrimaryKey()) {
				$varName = $this->getPKRefFKVarName($refFK);
				$script .= "
		\$this->{$varName} = null;";
			} else {
				$varName = $this->getRefFKCollVarName($refFK);
				$script .= "
		\$this->{$varName} = null;";
			}
		}
		
		// Clear cross-FK collections (many-to-many relationships)
		foreach ($table->getCrossFks() as $fkList) {
			list($refFK, $crossFK) = $fkList;
			$varName = $this->getCrossFKVarName($crossFK);
			$script .= "
		\$this->{$varName} = null;";
		}
		
		$script .= "
	}";
	}
	
	protected function addPrimaryString(&$script): void
	{
		// Ported from PHP5ObjectBuilder::addPrimaryString(): if a column is marked
		// primaryString="true" in the schema, __toString() returns that column's value;
		// otherwise it falls back to the object's default YAML/etc. export format
		// (Peer::DEFAULT_STRING_FORMAT). The previous implementation ignored both of
		// these and always stringified the primary key instead -- wrong for every table
		// (whether or not it declares a primaryString column), and the dependency several
		// tests/behaviors (e.g. SluggableBehavior's default slug source) have on this
		// method's real contract.
		foreach ($this->getTable()->getColumns() as $column) {
			if ($column->isPrimaryString()) {
				$phpname = $column->getPhpName();
				$script .= "

	/**
	 * Return the string representation of this object
	 *
	 * @return string The value of the '" . $column->getName() . "' column
	 */
	public function __toString(): string
	{
		return (string) \$this->get$phpname();
	}";
				return;
			}
		}
		$script .= "

	/**
	 * Return the string representation of this object
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		return (string) \$this->exportTo(" . $this->getPeerClassname() . "::DEFAULT_STRING_FORMAT);
	}";
	}
	
	protected function addMagicCall(&$script): void
	{
		$script .= "

	/**
	 * Catches calls to virtual methods
	 */
	public function __call(string \$name, array \$params): mixed
	{
		";
		
		// Add behavior content for delegate behavior
		$behaviorCallContent = $this->getBehaviorContent('objectCall');
		if ($behaviorCallContent) {
			$script .= $behaviorCallContent;
		}
		
		$script .= "
		return parent::__call(\$name, \$params);
	}";
	}
	
	protected function addValidationFailuresAttribute(&$script) 
	{
		$script .= "

	/**
	 * Array to store validation failures.
	 */
	protected array \$validationFailures = [];";
	}
	
	protected function addGetValidationFailures(&$script) 
	{
		$script .= "

	/**
	 * Gets any ValidationFailed objects that resulted from last call to validate().
	 *
	 * @return array Array of ValidationFailed objects
	 */
	public function getValidationFailures(): array
	{
		return \$this->validationFailures;
	}";
	}
	
	protected function addValidate(&$script) 
	{
		$script .= "

	/**
	 * Validates the object and returns true if it's valid or false if it's not.
	 * If \$columns is specified, only those columns are validated.
	 *
	 * @param array|string \$columns Column name or array of column names to validate
	 * @return bool True if valid, false otherwise
	 */
	public function validate(\$columns = null)
	{
		\$res = \$this->doValidate(\$columns);
		if (\$res === true) {
			\$this->validationFailures = array();
			return true;
		} else {
			\$this->validationFailures = \$res;
			return false;
		}
	}";
	}
	
	protected function addDoValidate(&$script) 
	{
		$script .= "

	/**
	 * This function performs the validation work for complex object models.
	 *
	 * In addition to checking the current object, all related objects will
	 * also be validated.  If all pass then <code>true</code> is returned; otherwise
	 * an aggreagated array of ValidationFailed objects will be returned.
	 *
	 * @param      array \$columns Array of column names to validate.
	 * @return     mixed <code>true</code> if all validations pass; array of <code>ValidationFailed</code> objets otherwise.
	 */
	protected function doValidate(\$columns = null)
	{
		\$failureMap = array();
		if (!\$this->alreadyInValidation) {
			\$this->alreadyInValidation = true;
			\$retval = null;


";

		// Add foreign key validation
		foreach ($this->getTable()->getForeignKeys() as $fk) {
			$fkVarName = $this->getFKVarName($fk);
			$script .= "
			// We call the validate method on the following object(s) if they
			// were passed to this object by their coresponding set
			// method.  This object relates to these object(s) by a
			// foreign key reference.

			if (\$this->" . $fkVarName . " !== null) {
				if (!\$this->" . $fkVarName . "->validate(\$columns)) {
					\$failureMap = array_merge(\$failureMap, \$this->" . $fkVarName . "->getValidationFailures());
				}
			}
";
		}

		$script .= "

			if ((\$retval = " . $this->getPeerClassname() . "::doValidateThis(\$this, \$columns)) !== true) {
				\$failureMap = array_merge(\$failureMap, \$retval);
			}

";

		// Add referrer (child) validation
		foreach ($this->getTable()->getReferrers() as $refFK) {
			if ($refFK->isLocalPrimaryKey()) {
				continue;
			}
			
			$varName = $this->getRefFKCollVarName($refFK);
			$script .= "
				if (\$this->" . $varName . " !== null) {
					foreach (\$this->" . $varName . " as \$referrerFK) {
						if (!\$referrerFK->validate(\$columns)) {
							\$failureMap = array_merge(\$failureMap, \$referrerFK->getValidationFailures());
						}
					}
				}
";
		}

		$script .= "

			\$this->alreadyInValidation = false;
		}

		return (!\$failureMap ? true : \$failureMap);
	}";
	}
}
