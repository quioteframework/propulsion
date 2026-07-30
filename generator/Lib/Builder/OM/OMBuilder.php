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
 * Baseclass for OM-building classes.
 *
 * OM-building classes are those that build a PHP (or other) class to service
 * a single table.  This includes Peer classes, Entity classes, Map classes,
 * Node classes, Nested Set classes, etc.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Builder\DataModelBuilder;
use \Exception;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Model\ForeignKey;
abstract class OMBuilder extends DataModelBuilder
{
	/**
	 * Table::getDatabase(), ForeignKey::getTable()/getForeignTable(),
	 * Table::getColumn(), and various ::getName()-style accessors are
	 * nullable in the Model layer because those objects can be read while a
	 * schema is still being parsed (construct-then-attach). By the time any
	 * OM builder runs, every table/column/foreign-key/database in the data
	 * model is guaranteed to be fully wired up. These small local guards
	 * centralize that invariant (mirroring the Model layer's own require*()
	 * naming convention) instead of widening types or adding new
	 * Model-layer accessors.
	 */
	protected static function requireDatabase(Table $table): Database
	{
		return $table->getDatabase() ?? throw new EngineException(sprintf(
			"Table '%s' is not attached to a database.",
			$table->getName() ?? '(unnamed table)'
		));
	}

	/**
	 * @see self::requireDatabase()
	 */
	protected static function requireFkLocalTable(ForeignKey $fk): Table
	{
		return $fk->getTable() ?? throw new EngineException(sprintf(
			"Foreign key '%s' is not attached to a local table.",
			$fk->getName() ?? '(unnamed)'
		));
	}

	/**
	 * @see self::requireDatabase()
	 */
	protected static function requireFkForeignTable(ForeignKey $fk): Table
	{
		$foreignTableName = self::requireNotNull($fk->getForeignTableName(), sprintf("Foreign key '%s' foreign table name", $fk->getName() ?? '(unnamed)'));
		return self::requireNotNull(
			self::requireDatabase(self::requireFkLocalTable($fk))->getTable($foreignTableName),
			sprintf("Foreign table '%s'", $foreignTableName)
		);
	}

	/**
	 * @template T
	 * @param T|null $value
	 * @return T
	 */
	protected static function requireNotNull(mixed $value, string $context): mixed
	{
		return $value ?? throw new EngineException("$context is not set.");
	}

	/**
	 * getBuildProperty()/getGeneratorConfig()->getBuildProperty() are
	 * untyped (build properties come from parsed .properties/XML config and
	 * are read generically), but every call site in these builders treats
	 * the result as a plain string to splice into generated source. Scalars
	 * (string/int/float/bool) have an unambiguous string form; anything else
	 * (unset, array, object) is a real configuration problem, not something
	 * to paper over.
	 */
	protected function getStringBuildProperty(string $name): string
	{
		$value = $this->getBuildProperty($name);
		if (is_string($value)) {
			return $value;
		}
		if (is_scalar($value)) {
			return (string) $value;
		}
		return '';
	}
	/**
	 * Declared fully qualified classnames, to build the 'namespace' statements
   * according to this table's namespace.
   * @var array<string, list<string>>
	 */
	protected array $declaredClasses = array();

	protected ?string $columnName = null;

	/**
	 * @param string &$includes The script will be modified in this method.
	 * @return void
	 */
	abstract protected function addIncludes(string &$includes = '');
	/**
	 * @param string &$scrript The script will be modified in this method.
	 * @return void
	 */
	abstract protected function addClassOpen(string &$scrript);
	/**
	 * @param string &$script The script will be modified in this method.
	 * @return void
	 */
	abstract protected function addClassBody(string &$script);
	/**
	 * @param string &$script The script will be modified in this method.
	 * @return void
	 */
	abstract protected function addClassClose(string &$script);

	/**
	 * Creates the IntlDateFormatter used to render the "generated on <date>"
	 * comment in class headers. datefmt_create()/IntlDateFormatter::create()
	 * is declared as nullable because arbitrary caller-supplied locale/format
	 * strings can fail to resolve, but the arguments used here are fixed and
	 * valid, so failure would only indicate a broken intl environment --
	 * a real (if unlikely) error worth surfacing rather than silently
	 * swallowing it.
	 */
	protected function createGeneratedTimestampFormatter(): \IntlDateFormatter
	{
		return datefmt_create(
			'en_US',
			\IntlDateFormatter::FULL,
			\IntlDateFormatter::FULL,
			'Europe/Helsinki',
			\IntlDateFormatter::GREGORIAN,
			"yyyy-MM-dd HH:mm:ss"
		) ?? throw new EngineException("Failed to create the IntlDateFormatter used for generated-code timestamps.");
	}

	/**
	 * Builds the PHP source for current class and returns it as a string.
	 *
	 * This is the main entry point and defines a basic structure that classes should follow.
	 * In most cases this method will not need to be overridden by subclasses.  This method
	 * does assume that the output language is PHP code, so it will need to be overridden if
	 * this is not the case.
	 *
	 * @return     string The resulting PHP sourcecode.
	 */
	public function build()
	{
		$this->validateModel();

		$script = '';
		if ($this->isAddIncludes()) {
			$this->addIncludes($script);
		}
		$this->addClassOpen($script);
		$this->addClassBody($script);
		$this->addClassClose($script);

		if($useStatements = $this->getUseStatements($ignoredNamespace = $this->getNamespace())) {
			$script = $useStatements . $script;
		}
		if($namespaceStatement = $this->getNamespaceStatement()) {
			$script = $namespaceStatement . $script;
		}
		//if($this->getTable()->getName() == 'book_club_list') die($ignoredNamespace);

		return "<" . "?php

" . $script;
	}

	/**
	 * Validates the current table to make sure that it won't
	 * result in generated code that will not parse.
	 *
	 * This method may emit warnings for code which may cause problems
	 * and will throw exceptions for errors that will definitely cause
	 * problems.
	 *
	 * @return void
	 */
	protected function validateModel()
	{
		// Validation is currently only implemented in the subclasses.
	}

	/**
	 * Creates a $obj = new Book(); code snippet. Can be used by frameworks, for instance, to
	 * extend this behavior, e.g. initialize the object after creating the instance or so.
	 *
	 * @return     string Some code
	 */
	public function buildObjectInstanceCreationCode(string $objName, string $clsName): string
	{
		return "$objName = new $clsName();";
	}

	/**
	 * Returns the qualified (prefixed) classname that is being built by the current class.
	 * This method must be implemented by child classes.
	 * @return     string
	 */
	abstract public function getUnprefixedClassname();

	/**
	 * Returns the prefixed classname that is being built by the current class.
	 * @return     string
	 * @see        DataModelBuilder#prefixClassname()
	 */
	public function getClassname()
	{
		return $this->prefixClassname($this->getUnprefixedClassname());
	}

	/**
	 * Returns the namespaced classname if there is a namespace, and the raw classname otherwise
	 * @return     string
	 */
	public function getFullyQualifiedClassname()
	{
		if ($namespace = $this->getNamespace()) {
			return $namespace . '\\' . $this->getClassname();
		} else {
			return $this->getClassname();
		}
	}

	/**
	 * Gets the dot-path representation of current class being built.
	 * @return     string
	 */
	public function getClasspath()
	{
		if ($this->getPackage()) {
			$path = $this->getPackage() . '.' . $this->getClassname();
		} else {
			$path = $this->getClassname();
		}
		return $path;
	}

	/**
	 * Gets the full path to the file for the current class.
	 * @return     string
	 */
	public function getClassFilePath()
	{
		return ClassTools::createFilePath($this->getPackagePath(), $this->getClassname());
	}

	/**
	 * Gets package name for this table.
	 * This is overridden by child classes that have different packages.
	 * @return     string
	 */
	public function getPackage(): string
	{
		$table = $this->getTable();
		$pkg = $table->getPackage() ?: self::requireDatabase($table)->getPackage();
		if (!$pkg) {
			$pkg = $this->getBuildProperty('targetPackage');
		}
		return is_string($pkg) ? $pkg : '';
	}

	/**
	 * Returns filesystem path for current package.
	 * @return     string
	 */
	public function getPackagePath(): string
	{
		$pkg = $this->getPackage();

		if (strpos($pkg, '/') !== false) {
			return preg_replace('#\.(map|om)$#i', '/\1', $pkg) ?? $pkg;
		}

		return strtr($pkg, '.', '/');
	}

	/**
	 * Returns the package suffix used for OM classes.
	 */
	protected function getOmPackageSegment(): string
	{
		$segment = $this->getGeneratorConfig()->getBuildProperty('namespaceOm');
		return is_string($segment) && $segment !== '' ? $segment : 'OM';
	}

	/**
	 * Returns the package suffix used for map classes.
	 */
	protected function getMapPackageSegment(): string
	{
		$segment = $this->getGeneratorConfig()->getBuildProperty('namespaceMap');
		return is_string($segment) && $segment !== '' ? $segment : 'Map';
	}

	/**
	 * Return the user-defined namespace for this table,
	 * or the database namespace otherwise.
	 *
	 * @return    string|null
	 */
	public function getNamespace()
	{
		return $this->getTable()->getNamespace();
	}

	public function declareClassNamespace(string $class, ?string $namespace = ''): void
	{
		$namespace ??= '';
		if (isset($this->declaredClasses[$namespace])
		 && in_array($class, $this->declaredClasses[$namespace])) {
			return;
		}
		$this->declaredClasses[$namespace][] = $class;
	}

	public function declareClass(string $fullyQualifiedClassName): void
	{
		$fullyQualifiedClassName = trim($fullyQualifiedClassName, '\\');
		if (($pos = strrpos($fullyQualifiedClassName, '\\')) !== false) {
			$this->declareClassNamespace(substr($fullyQualifiedClassName, $pos + 1), substr($fullyQualifiedClassName, 0, $pos));
		} else {
			// root namespace
			$this->declareClassNamespace($fullyQualifiedClassName);
		}
	}

	public function declareClassFromBuilder(OMBuilder $builder): void
	{
		$this->declareClassNamespace($builder->getClassname(), $builder->getNamespace());
	}

	/**
	 * Declares multiple classes at once (variadic via func_get_args()).
	 */
	public function declareClasses(string ...$classes): void
	{
		foreach ($classes as $class) {
			$this->declareClass($class);
		}
	}

	/**
	 * @return array<string, list<string>>|list<string>
	 */
	public function getDeclaredClasses(?string $namespace = null): array
	{
		if (null !== $namespace && isset($this->declaredClasses[$namespace])) {
			return $this->declaredClasses[$namespace];
		} else {
			return $this->declaredClasses;
		}
	}

	public function getNamespaceStatement(): ?string
	{
		$namespace = $this->getNamespace();
		if ($namespace != '') {
			return sprintf("namespace %s;

", $namespace);
		}
		return null;
	}

	public function getUseStatements(?string $ignoredNamespace = null): string
	{
		$script = '';
		$declaredClasses = $this->declaredClasses;
		unset($declaredClasses[$ignoredNamespace ?? '']);
		ksort($declaredClasses);
		foreach ($declaredClasses as $namespace => $classes) {
			sort($classes);
			foreach ($classes as $class) {
				$script .= sprintf("use %s\\%s;
", $namespace, $class);
			}
		}
		return $script;
	}

	/**
	 * Shortcut method to return the [stub] peer classname for current table.
	 * This is the classname that is used whenever object or peer classes want
	 * to invoke methods of the peer classes.
	 * @return     string (e.g. 'MyPeer')
	 * @see        StubPeerBuilder::getClassname()
	 */
	public function getPeerClassname() {
		return $this->getStubPeerBuilder()->getClassname();
	}

	/**
	 * Shortcut method to return the [stub] query classname for current table.
	 * This is the classname that is used whenever object or peer classes want
	 * to invoke methods of the query classes.
	 * @return     string (e.g. 'Myquery')
	 * @see        StubQueryBuilder::getClassname()
	 */
	public function getQueryClassname() {
		return $this->getStubQueryBuilder()->getClassname();
	}

	/**
	 * Returns the object classname for current table.
	 * This is the classname that is used whenever object or peer classes want
	 * to invoke methods of the object classes.
	 * @return     string (e.g. 'My')
	 * @see        StubPeerBuilder::getClassname()
	 */
	public function getObjectClassname() {
		return $this->getStubObjectBuilder()->getClassname();
	}

	/**
	 * Get the column constant name (e.g. PeerName::COLUMN_NAME).
	 *
	 * @param      Column|null $col The column we need a name for.
	 * @param      string $classname The Peer classname to use.
	 *
	 * @return     string If $classname is provided, then will return $classname::COLUMN_NAME; if not, then the peername is looked up for current table to yield $currTablePeer::COLUMN_NAME.
	 */
	public function getColumnConstant($col, $classname = null)
	{
		if ($col === null) {
			$e = new Exception("No col specified.");
			print $e;
			throw $e;
		}
		if ($classname === null) {
			$classPrefix = $this->getBuildProperty('classPrefix');
			return (is_string($classPrefix) ? $classPrefix : '') . $col->getConstantName();
		}
		// was it overridden in schema.xml ?
		if ($peerName = $col->getPeerName()) {
			$const = strtoupper($peerName);
		} else {
			$const = strtoupper(self::requireNotNull($col->getName(), sprintf("Column '%s' name", $col->getConstantName())));
		}
		return $classname.'::'.$const;
	}

	/**
	 * Gets the basePeer classname if specified for table/db.
	 * If not, will return 'BasePeer' (i.e. \Propulsion\Util\BasePeer,
	 * brought into scope by the builder's own declareClass() call).
	 * @return     string
	 */
	public function getBasePeer(Table $table) {
		$class = $table->getBasePeer();
		if ($class === null) {
			$class = "BasePeer";
		}
		return $class;
	}

	/**
	 * Convenience method to get the foreign Table object for an fkey.
	 * @deprecated use ForeignKey::getForeignTable() instead
	 * @return     Table
	 */
	protected function getForeignTable(ForeignKey $fk): Table
	{
		return self::requireFkForeignTable($fk);
	}

	/**
	 * Convenience method to get the default Join Type for a relation.
	 * If the key is required, an INNER JOIN will be returned, else a LEFT JOIN will be suggested,
	 * unless the schema is provided with the DefaultJoin attribute, which overrules the default Join Type
	 *
	 * @param ForeignKey $fk
	 * @return     string
	 */
	protected function getJoinType(ForeignKey $fk)
	{
		if ($defaultJoin = $fk->getDefaultJoin()) {
			return "'" . $defaultJoin . "'";
		}
		if ($fk->isLocalColumnsRequired()) {
			return 'Criteria::INNER_JOIN';
		}
		return 'Criteria::LEFT_JOIN';
	}

	/**
	 * Gets the PHP method name affix to be used for fkeys for the current table (not referrers to this table).
	 *
	 * The difference between this method and the getRefFKPhpNameAffix() method is that in this method the
	 * classname in the affix is the foreign table classname.
	 *
	 * @param      ForeignKey $fk The local FK that we need a name for.
	 * @param      boolean $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
	 * @return     string
	 */
	public function getFKPhpNameAffix(ForeignKey $fk, $plural = false)
	{
		if ($phpName = $fk->getPhpName()) {
			if ($plural) {
				return $this->getPluralizer()->getPluralForm($phpName);
			} else {
				return $phpName;
			}
		} else {
			$className = self::requireNotNull(self::requireFkForeignTable($fk)->getPhpName(), sprintf("Foreign table of foreign key '%s' PHP name", $fk->getName() ?? '(unnamed)'));
			if ($plural) {
				$className = $this->getPluralizer()->getPluralForm($className);
			}
			return $className . $this->getRelatedBySuffix($fk);
		}
	}

	/**
	 * Gets the "RelatedBy*" suffix (if needed) that is attached to method and variable names.
	 *
	 * The related by suffix is based on the local columns of the foreign key.  If there is more than
	 * one column in a table that points to the same foreign table, then a 'RelatedByLocalColName' suffix
	 * will be appended.
	 *
	 * @return     string
	 */
	protected static function getRelatedBySuffix(ForeignKey $fk)
	{
		$relCol = '';
		$foreignTableName = self::requireNotNull($fk->getForeignTableName(), sprintf("Foreign key '%s' foreign table name", $fk->getName() ?? '(unnamed)'));
		$localTableName = self::requireNotNull($fk->getTableName(), sprintf("Foreign key '%s' local table name", $fk->getName() ?? '(unnamed)'));
		foreach ($fk->getLocalForeignMapping() as $localColumnName => $foreignColumnName) {
			$localTable  = self::requireFkLocalTable($fk);
			$localColumn = $localTable->getColumn($localColumnName);
			if (!$localColumn) {
				throw new Exception("Could not fetch column: $localColumnName in table " . $localTable->getName());
			}
			if (count($localTable->getForeignKeysReferencingTable($foreignTableName)) > 1
			 || count(self::requireFkForeignTable($fk)->getForeignKeysReferencingTable($localTableName)) > 0
			 || $foreignTableName == $localTableName) {
				// self referential foreign key, or several foreign keys to the same table, or cross-reference fkey
				$relCol .= $localColumn->getPhpName();
			}
		}

		if ($relCol != '') {
			$relCol = 'RelatedBy' . $relCol;
		}

		return $relCol;
	}

	/**
	 * Gets the PHP method name affix to be used for referencing foreign key methods and variable names (e.g. set????(), $coll???).
	 *
	 * The difference between this method and the getFKPhpNameAffix() method is that in this method the
	 * classname in the affix is the classname of the local fkey table.
	 *
	 * @param      ForeignKey $fk The referrer FK that we need a name for.
	 * @param      boolean $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
	 * @return     string
	 */
	public function getRefFKPhpNameAffix(ForeignKey $fk, $plural = false)
	{
		if ($refPhpName = $fk->getRefPhpName()) {
			if ($plural) {
				return $this->getPluralizer()->getPluralForm($refPhpName);
			} else {
				return $refPhpName;
			}
		} else {
			$className = self::requireNotNull(self::requireFkLocalTable($fk)->getPhpName(), sprintf("Local table of foreign key '%s' PHP name", $fk->getName() ?? '(unnamed)'));
			if ($plural) {
				$className = $this->getPluralizer()->getPluralForm($className);
			}
			return $className . $this->getRefRelatedBySuffix($fk);
		}
	}

	protected static function getRefRelatedBySuffix(ForeignKey $fk): string
	{
		$relCol = '';
		$foreignTableName = self::requireNotNull($fk->getForeignTableName(), sprintf("Foreign key '%s' foreign table name", $fk->getName() ?? '(unnamed)'));
		$localTableName = self::requireNotNull($fk->getTableName(), sprintf("Foreign key '%s' local table name", $fk->getName() ?? '(unnamed)'));
		foreach ($fk->getLocalForeignMapping() as $localColumnName => $foreignColumnName) {
			$localTable = self::requireFkLocalTable($fk);
			$localColumn = $localTable->getColumn($localColumnName);
			if (!$localColumn) {
				throw new Exception("Could not fetch column: $localColumnName in table " . $localTable->getName());
			}
			$foreignKeysToForeignTable = $localTable->getForeignKeysReferencingTable($foreignTableName);
			if ($foreignTableName == $localTableName) {
				// self referential foreign key
				$foreignColumn = self::requireFkForeignTable($fk)->getColumn($foreignColumnName);
				if (!$foreignColumn) {
					throw new Exception("Could not fetch column: $foreignColumnName in table $foreignTableName");
				}
				$relCol .= $foreignColumn->getPhpName();
				if (count($foreignKeysToForeignTable) > 1) {
					// several self-referential foreign keys
					$relCol .= array_search($fk, $foreignKeysToForeignTable);
				}
			} elseif (count($foreignKeysToForeignTable) > 1 || count(self::requireFkForeignTable($fk)->getForeignKeysReferencingTable($localTableName)) > 0) {
				// several foreign keys to the same table, or symmetrical foreign key in foreign table
				$relCol .= $localColumn->getPhpName();
			}
		}

		if ($relCol != '') {
			$relCol = 'RelatedBy' . $relCol;
		}

		return $relCol;
	}

	/**
	 * Whether to add the include statements.
	 * This is based on the build property propulsion.addIncludes
	 */
	protected function isAddIncludes(): bool
	{
		return (bool) $this->getBuildProperty('addIncludes');
	}

	/**
   * Checks whether any registered behavior on that table has a modifier for a hook
   * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
   * @param string $modifier The name of the modifier object providing the method in the behavior
   * @return boolean
   */
  public function hasBehaviorModifier($hookName, $modifier)
  {
    $modifierGetter = 'get' . $modifier;
    foreach ($this->getTable()->getBehaviors() as $behavior) {
      if(method_exists($behavior->$modifierGetter(), $hookName)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Checks whether any registered behavior on that table has a modifier for a hook
   * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
   * @param string $modifier The name of the modifier object providing the method in the behavior
	 * @param string &$script The script will be modified in this method.
   * @return void
   */
  public function applyBehaviorModifierBase(string $hookName, string $modifier, string &$script, string $tab = "		"): void
  {
    $modifierGetter = 'get' . $modifier;
    foreach ($this->getTable()->getBehaviors() as $behavior) {
      $modifier = $behavior->$modifierGetter();
      if(method_exists($modifier, $hookName)) {
        if (strpos($hookName, 'Filter') !== false) {
          // filter hook: the script string will be modified by the behavior
          $modifier->$hookName($script, $this);
        } else {
          // regular hook: the behavior returns a string to append to the script string
          if (!$addedScript = $modifier->$hookName($this)) {
          	continue;
          }
          $script .= "
" . $tab . '// ' . $behavior->getName() . " behavior
";
          $script .= preg_replace('/^/m', $tab, $addedScript);
         }
      }
    }
  }

  /**
   * Checks whether any registered behavior content creator on that table exists a contentName
   * @param string $contentName The name of the content as called from one of this class methods, e.g. "parentClassname"
   * @param string $modifier The name of the modifier object providing the method in the behavior
   * @return mixed
   */
  public function getBehaviorContentBase(string $contentName, string $modifier): mixed
  {
    $modifierGetter = 'get' . $modifier;
    foreach ($this->getTable()->getBehaviors() as $behavior) {
      $modifier = $behavior->$modifierGetter();
      if(method_exists($modifier, $contentName)) {
        return $modifier->$contentName($this);
      }
    }
    return null;
  }

}
