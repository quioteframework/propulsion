<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Builder;
/**
 * This is the base class for any builder class that is using the data model.
 *
 * This could be extended by classes that build SQL DDL, PHP classes, configuration
 * files, input forms, etc.
 *
 * The GeneratorConfig needs to be set on this class in order for the builders
 * to be able to access the propel generator build properties.  You should be
 * safe if you always use the GeneratorConfig to get a configured builder class
 * anyway.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @package    propel.generator.builder
 */

use Propulsion\Generator\Builder\OM\AbstractObjectBuilder;
use Propulsion\Generator\Builder\OM\AbstractPeerBuilder;
use Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder;
use Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder;
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Builder\OM\QueryBuilder;
use Propulsion\Generator\Builder\OM\QueryInheritanceBuilder;
use Propulsion\Generator\Builder\OM\TableMapBuilder;
use Propulsion\Generator\Builder\SQL\DataSQLBuilder;
use Propulsion\Generator\Builder\Util\Pluralizer;
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Config\GeneratorConfigInterface;
use Propulsion\Generator\Platform\PropulsionPlatformInterface;
abstract class DataModelBuilder
{

	/**
	 * The current table.
	 * @var        Table
	 */
	private $table;

	/**
	 * The generator config object holding build properties, etc.
	 *
	 * @var        GeneratorConfigInterface
	 */
	private $generatorConfig;

	/**
	 * An array of warning messages that can be retrieved for display (e.g. as part of phing build process).
	 * @var        array string[]
	 */
	private $warnings = array();

	/**
	 * Peer builder class for current table.
	 * @var        AbstractPeerBuilder
	 */
	private $peerBuilder;

	/**
	 * Stub Peer builder class for current table.
	 * @var        AbstractPeerBuilder
	 */
	private $stubPeerBuilder;

	/**
	 * Object builder class for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $objectBuilder;

	/**
	 * Stub Object builder class for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $stubObjectBuilder;

	/**
	 * Query builder class for current table.
	 * @var        QueryBuilder
	 */
	private $queryBuilder;

	/**
	 * Stub Query builder class for current table.
	 * @var        OMBuilder
	 */
	private $stubQueryBuilder;

	/**
	 * TableMap builder class for current table.
	 * @var        TableMapBuilder
	 */
	protected $tablemapBuilder;

	/**
	 * Stub Interface builder class for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $interfaceBuilder;

	/**
	 * Stub child object for current table.
	 * @var        MultiExtendObjectBuilder
	 */
	private $multiExtendObjectBuilder;

	/**
	 * Node object builder for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $nodeBuilder;

	/**
	 * Node peer builder for current table.
	 * @var        AbstractPeerBuilder
	 */
	private $nodePeerBuilder;

	/**
	 * Stub node object builder for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $stubNodeBuilder;

	/**
	 * Stub node peer builder for current table.
	 * @var        AbstractPeerBuilder
	 */
	private $stubNodePeerBuilder;

	/**
	 * NestedSet object builder for current table.
	 * @var        AbstractObjectBuilder
	 */
	private $nestedSetBuilder;

	/**
	 * NestedSet peer builder for current table.
	 * @var        AbstractPeerBuilder
	 */
	private $nestedSetPeerBuilder;

	/**
	 * The Data-SQL builder for current table.
	 * @var        DataSQLBuilder
	 */
	private $dataSqlBuilder;

	/**
	 * The Pluralizer class to use.
	 * @var        Pluralizer
	 */
	private $pluralizer;

	/**
	 * The platform class
	 * @var 			PropulsionPlatformInterface
	 */
	protected $platform;

	/**
	 * Creates new instance of DataModelBuilder subclass.
	 * @param      Table $table The Table which we are using to build [OM, DDL, etc.].
	 */
	public function __construct(Table $table)
	{
		$this->table = $table;
	}

	/**
	 * Gets a configured builder for the given table/type, asserting that it is an
	 * instance of $expectedClass. GeneratorConfig::getConfiguredBuilder() resolves
	 * its return type dynamically (from build.properties config), so its static
	 * return type is only ever the common base class; every getXxxBuilder()
	 * accessor below knows the concrete subclass its own $type always resolves to,
	 * and uses this to narrow it back for callers that need e.g. setChild().
	 *
	 * @template T of DataModelBuilder
	 * @param      Table $table
	 * @param      string $type
	 * @param      class-string<T> $expectedClass
	 * @return     T
	 */
	private function configureBuilder(Table $table, string $type, string $expectedClass): DataModelBuilder
	{
		$builder = $this->getGeneratorConfig()->getConfiguredBuilder($table, $type);
		if (!$builder instanceof $expectedClass) {
			throw new EngineException(sprintf(
				"Configured '%s' builder class (%s) does not extend %s.",
				$type,
				get_class($builder),
				$expectedClass
			));
		}
		return $builder;
	}

	/**
	 * Returns new or existing Peer builder class for this table.
	 * @return     AbstractPeerBuilder
	 */
	public function getPeerBuilder()
	{
		if (!isset($this->peerBuilder)) {
			$this->peerBuilder = $this->configureBuilder($this->getTable(), 'peer', AbstractPeerBuilder::class);
		}
		return $this->peerBuilder;
	}

	/**
	 * Returns new or existing Pluralizer class.
	 * @return     Pluralizer
	 */
	public function getPluralizer()
	{
		if (!isset($this->pluralizer)) {
			$this->pluralizer = $this->getGeneratorConfig()->getConfiguredPluralizer();
		}
		return $this->pluralizer;
	}

	/**
	 * Returns new or existing stub Peer builder class for this table.
	 * @return     AbstractPeerBuilder
	 */
	public function getStubPeerBuilder()
	{
		if (!isset($this->stubPeerBuilder)) {
			$this->stubPeerBuilder = $this->configureBuilder($this->getTable(), 'peerstub', AbstractPeerBuilder::class);
		}
		return $this->stubPeerBuilder;
	}

	/**
	 * Returns new or existing Object builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getObjectBuilder()
	{
		if (!isset($this->objectBuilder)) {
			$this->objectBuilder = $this->configureBuilder($this->getTable(), 'object', AbstractObjectBuilder::class);
		}
		return $this->objectBuilder;
	}

	/**
	 * Returns new or existing stub Object builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getStubObjectBuilder()
	{
		if (!isset($this->stubObjectBuilder)) {
			$this->stubObjectBuilder = $this->configureBuilder($this->getTable(), 'objectstub', AbstractObjectBuilder::class);
		}
		return $this->stubObjectBuilder;
	}

	/**
	 * Returns new or existing Query builder class for this table.
	 * @return     QueryBuilder
	 */
	public function getQueryBuilder()
	{
		if (!isset($this->queryBuilder)) {
			$this->queryBuilder = $this->configureBuilder($this->getTable(), 'query', QueryBuilder::class);
		}
		return $this->queryBuilder;
	}

	/**
	 * Returns new or existing stub Query builder class for this table.
	 * @return     OMBuilder
	 */
	public function getStubQueryBuilder()
	{
		if (!isset($this->stubQueryBuilder)) {
			$this->stubQueryBuilder = $this->configureBuilder($this->getTable(), 'querystub', OMBuilder::class);
		}
		return $this->stubQueryBuilder;
	}

	/**
	 * Returns new or existing Object builder class for this table.
	 * @return     TableMapBuilder
	 */
	public function getTableMapBuilder()
	{
		if (!isset($this->tablemapBuilder)) {
			$this->tablemapBuilder = $this->configureBuilder($this->getTable(), 'tablemap', TableMapBuilder::class);
		}
		return $this->tablemapBuilder;
	}

	/**
	 * Returns new or existing stub Interface builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getInterfaceBuilder()
	{
		if (!isset($this->interfaceBuilder)) {
			$this->interfaceBuilder = $this->configureBuilder($this->getTable(), 'interface', AbstractObjectBuilder::class);
		}
		return $this->interfaceBuilder;
	}

	/**
	 * Returns new or existing stub child object builder class for this table.
	 * @return     MultiExtendObjectBuilder
	 */
	public function getMultiExtendObjectBuilder()
	{
		if (!isset($this->multiExtendObjectBuilder)) {
			$this->multiExtendObjectBuilder = $this->configureBuilder($this->getTable(), 'objectmultiextend', MultiExtendObjectBuilder::class);
		}
		return $this->multiExtendObjectBuilder;
	}

	/**
	 * Returns new or existing node Object builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getNodeBuilder()
	{
		if (!isset($this->nodeBuilder)) {
			$this->nodeBuilder = $this->configureBuilder($this->getTable(), 'node', AbstractObjectBuilder::class);
		}
		return $this->nodeBuilder;
	}

	/**
	 * Returns new or existing node Peer builder class for this table.
	 * @return     AbstractPeerBuilder
	 */
	public function getNodePeerBuilder()
	{
		if (!isset($this->nodePeerBuilder)) {
			$this->nodePeerBuilder = $this->configureBuilder($this->getTable(), 'nodepeer', AbstractPeerBuilder::class);
		}
		return $this->nodePeerBuilder;
	}

	/**
	 * Returns new or existing stub node Object builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getStubNodeBuilder()
	{
		if (!isset($this->stubNodeBuilder)) {
			$this->stubNodeBuilder = $this->configureBuilder($this->getTable(), 'nodestub', AbstractObjectBuilder::class);
		}
		return $this->stubNodeBuilder;
	}

	/**
	 * Returns new or existing stub node Peer builder class for this table.
	 * @return     AbstractPeerBuilder
	 */
	public function getStubNodePeerBuilder()
	{
		if (!isset($this->stubNodePeerBuilder)) {
			$this->stubNodePeerBuilder = $this->configureBuilder($this->getTable(), 'nodepeerstub', AbstractPeerBuilder::class);
		}
		return $this->stubNodePeerBuilder;
	}

	/**
	 * Returns new or existing nested set object builder class for this table.
	 * @return     AbstractObjectBuilder
	 */
	public function getNestedSetBuilder()
	{
		if (!isset($this->nestedSetBuilder)) {
			$this->nestedSetBuilder = $this->configureBuilder($this->getTable(), 'nestedset', AbstractObjectBuilder::class);
		}
		return $this->nestedSetBuilder;
	}

	/**
	 * Returns new or existing nested set Peer builder class for this table.
	 * @return     AbstractPeerBuilder
	 */
	public function getNestedSetPeerBuilder()
	{
		if (!isset($this->nestedSetPeerBuilder)) {
			$this->nestedSetPeerBuilder = $this->configureBuilder($this->getTable(), 'nestedsetpeer', AbstractPeerBuilder::class);
		}
		return $this->nestedSetPeerBuilder;
	}

	/**
	 * Returns new or existing data sql builder class for this table.
	 * @return     DataSQLBuilder
	 */
	public function getDataSQLBuilder()
	{
		if (!isset($this->dataSqlBuilder)) {
			$this->dataSqlBuilder = $this->configureBuilder($this->getTable(), 'datasql', DataSQLBuilder::class);
		}
		return $this->dataSqlBuilder;
	}

 /**
	* Gets a new data model builder class for specified table and classname.
	*
	* @param      Table $table
	* @param      string $classname The class of builder
	* @return     DataModelBuilder
	*/
	public function getNewBuilder(Table $table, $classname)
	{
		$builder = new $classname($table);
		$builder->setGeneratorConfig($this);
		return $builder;
	}

	/**
	 * Convenience method to return a NEW Peer class builder instance.
   *
	 * This is used very frequently from the peer and object builders to get
	 * a peer builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     AbstractPeerBuilder
	 */
	public function getNewPeerBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'peer', AbstractPeerBuilder::class);
	}

	/**
	 * Convenience method to return a NEW Peer stub class builder instance.
	 *
	 * This is used from the peer and object builders to get
	 * a peer builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     AbstractPeerBuilder
	 */
	public function getNewStubPeerBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'peerstub', AbstractPeerBuilder::class);
	}

	/**
	 * Convenience method to return a NEW Object class builder instance.
	 *
	 * This is used very frequently from the peer and object builders to get
	 * an object builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     AbstractObjectBuilder
	 */
	public function getNewObjectBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'object', AbstractObjectBuilder::class);
	}

	/**
	 * Convenience method to return a NEW Object stub class builder instance.
	 *
	 * This is used from the query builders to get
	 * an object builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     AbstractObjectBuilder
	 */
	public function getNewStubObjectBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'objectstub', AbstractObjectBuilder::class);
	}

	/**
	 * Convenience method to return a NEW query class builder instance.
	 *
	 * This is used from the query builders to get
	 * a query builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     QueryBuilder
	 */
	public function getNewQueryBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'query', QueryBuilder::class);
	}

	/**
	 * Convenience method to return a NEW query stub class builder instance.
	 *
	 * This is used from the query builders to get
	 * a query builder for a RELATED table.
	 *
	 * @param      Table $table
	 * @return     OMBuilder
	 */
	public function getNewStubQueryBuilder(Table $table)
	{
		return $this->configureBuilder($table, 'querystub', OMBuilder::class);
	}

	/**
	 * Returns new Query Inheritance builder class for this table.
	 * @return     OMBuilder
	 */
	public function getNewQueryInheritanceBuilder($child)
	{
		$queryInheritanceBuilder = $this->configureBuilder($this->getTable(), 'queryinheritance', OMBuilder::class);
		if ($queryInheritanceBuilder instanceof QueryInheritanceBuilder) {
			$queryInheritanceBuilder->setChild($child);
		}
		return $queryInheritanceBuilder;
	}

	/**
	 * Returns new stub Query Inheritance builder class for this table.
	 * @return     OMBuilder
	 */
	public function getNewStubQueryInheritanceBuilder($child)
	{
		$stubQueryInheritanceBuilder = $this->configureBuilder($this->getTable(), 'queryinheritancestub', OMBuilder::class);
		if ($stubQueryInheritanceBuilder instanceof ExtensionQueryInheritanceBuilder) {
			$stubQueryInheritanceBuilder->setChild($child);
		}
		return $stubQueryInheritanceBuilder;
	}

	/**
	 * Gets the GeneratorConfig object.
	 *
	 * @return     GeneratorConfigInterface
	 */
	public function getGeneratorConfig()
	{
		return $this->generatorConfig;
	}

	/**
	 * Get a specific [name transformed] build property.
	 *
	 * @param      string $name
	 * @return     string
	 */
	public function getBuildProperty($name)
	{
		if ($this->getGeneratorConfig()) {
			return $this->getGeneratorConfig()->getBuildProperty($name);
		}
		return null; // just to be explicit
	}

	/**
	 * Sets the GeneratorConfig object.
	 *
	 * @param      GeneratorConfig $v
	 */
	public function setGeneratorConfig(GeneratorConfigInterface $v)
	{
		$this->generatorConfig = $v;
	}

	/**
	 * Sets the table for this builder.
	 * @param      Table $table
	 */
	public function setTable(Table $table)
	{
		$this->table = $table;
	}

	/**
	 * Returns the current Table object.
	 * @return     Table
	 */
	public function getTable()
	{
		return $this->table;
	}

	/**
	 * Convenience method to returns the Platform class for this table (database).
	 * @return     PropulsionPlatformInterface
	 */
	public function getPlatform()
	{
		if (null === $this->platform) {
			// try to load the platform from the table
			if ($this->getTable() && $this->getTable()->getDatabase()) {
				$this->setPlatform($this->getTable()->getDatabase()->getPlatform());
			}
		}
		return $this->platform;
	}

	/**
	 * Platform setter
	 *
	 * @param PropulsionPlatformInterface $platform
	 */
	public function setPlatform(PropulsionPlatformInterface $platform)
	{
		$this->platform = $platform;
	}

	/**
	 * Convenience method to returns the database for current table.
	 * @return     Database
	 */
	public function getDatabase()
	{
		if ($this->getTable()) {
			return $this->getTable()->getDatabase();
		}
	}

	/**
	 * Pushes a message onto the stack of warnings.
	 * @param      string $msg The warning message.
	 */
	protected function warn($msg)
	{
		$this->warnings[] = $msg;
	}

	/**
	 * Gets array of warning messages.
	 * @return     array string[]
	 */
	public function getWarnings()
	{
		return $this->warnings;
	}

	/**
	 * Wraps call to Platform->quoteIdentifier() with a check to see whether quoting is enabled.
	 *
	 * All subclasses should call this quoteIdentifier() method rather than calling the Platform
	 * method directly.  This method is used by both DataSQLBuilder and DDLBuilder, and potentially
	 * in the OM builders also, which is why it is defined in this class.
	 *
	 * @param      string $text The text to quote.
	 * @return     string Quoted text.
	 */
	public function quoteIdentifier($text)
	{
		if (!$this->getBuildProperty('disableIdentifierQuoting')) {
			return $this->getPlatform()->quoteIdentifier($text);
		}
		return $text;
	}

	/**
	 * Returns the name of the current class being built, with a possible prefix.
	 * @return     string
	 * @see        OMBuilder#getClassname()
	 */
	public function prefixClassname($identifier)
	{
		return $this->getBuildProperty('classPrefix') . $identifier;
	}

}
