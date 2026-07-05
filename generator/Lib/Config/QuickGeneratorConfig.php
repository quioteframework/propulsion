<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license		 MIT License
 */
namespace Propulsion\Generator\Config;

 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Builder\DataModelBuilder;
 use Propulsion\Generator\Builder\Util\Pluralizer;
 use Propulsion\Generator\Builder\Util\DefaultEnglishPluralizer;
 use Propulsion\Generator\Builder\OM\PeerBuilder;
 use Propulsion\Generator\Builder\OM\ObjectBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionObjectBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionPeerBuilder;
 use Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder;
 use Propulsion\Generator\Builder\OM\TableMapBuilder;
 use Propulsion\Generator\Builder\OM\QueryBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionQueryBuilder;
 use Propulsion\Generator\Builder\OM\QueryInheritanceBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder;
 use Propulsion\Generator\Builder\OM\InterfaceBuilder;
 use Propulsion\Generator\Builder\OM\NodeBuilder;
 use Propulsion\Generator\Builder\OM\NodePeerBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionNodeBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionNodePeerBuilder;
 use Propulsion\Generator\Builder\OM\NestedSetBuilder;
 use Propulsion\Generator\Builder\OM\NestedSetPeerBuilder;

class QuickGeneratorConfig implements GeneratorConfigInterface
{
	// These used to hardcode the PHP5* builders (independently of
	// generator/default.php's propulsion.builder.*.class keys -- this class is used by
	// PropulsionQuickBuilder, the ad-hoc-schema builder most behavior unit tests use, and
	// has its own separate builder registry). Since the PHP5 builders were removed
	// entirely (see archaeology/php5-builders/, KNOWN_ISSUES.md), these now point at the
	// same promoted builders default.php uses.
	protected $builders = array(
		'peer'					=> PeerBuilder::class,
		'object'				=> ObjectBuilder::class,
		'objectstub'		=> ExtensionObjectBuilder::class,
		'peerstub'			=> ExtensionPeerBuilder::class,
		'objectmultiextend' => MultiExtendObjectBuilder::class,
		'tablemap'			=> TableMapBuilder::class,
		'query'					=> QueryBuilder::class,
		'querystub'			=> ExtensionQueryBuilder::class,
		'queryinheritance' => QueryInheritanceBuilder::class,
		'queryinheritancestub' => ExtensionQueryInheritanceBuilder::class,
		'interface'			=> InterfaceBuilder::class,
		'node'					=> NodeBuilder::class,
		'nodepeer'			=> NodePeerBuilder::class,
		'nodestub'			=> ExtensionNodeBuilder::class,
		'nodepeerstub'	=> ExtensionNodePeerBuilder::class,
		'nestedset'			=> NestedSetBuilder::class,
		'nestedsetpeer' => NestedSetPeerBuilder::class,
	);

	protected $buildProperties = array();

	public function __construct()
	{
		$this->setBuildProperties(require dirname(__FILE__) . '/../../default.php');
	}

	/**
	 * Gets a configured data model builder class for specified table and based on type.
	 *
	 * @param			 Table $table
	 * @param			 string $type The type of builder ('ddl', 'sql', etc.)
	 * @return		 DataModelBuilder
	 */
	public function getConfiguredBuilder($table, $type)
	{
		$class = $this->builders[$type];
		$builder = new $class($table);
		$builder->setGeneratorConfig($this);
		return $builder;
	}

	/**
	* Gets a configured Pluralizer class.
	*
	* @return     Pluralizer
	*/
	public function getConfiguredPluralizer()
	{
		return new DefaultEnglishPluralizer();
	}

	/**
	 * Parses the passed-in properties, renaming and saving eligible properties in this object.
	 *
	 * Renames the propulsion.xxx properties to just xxx and renames any xxx.yyy properties
	 * to xxxYyy as PHP doesn't like the xxx.yyy syntax.
	 *
	 * @param			 mixed $props Array or Iterator
	 */
	public function setBuildProperties($props)
	{
		$this->buildProperties = array();

		$renamedPropulsionProps = array();
		foreach ($props as $key => $propValue) {
			if (strpos($key, "propulsion.") === 0) {
				$newKey = substr($key, strlen("propulsion."));
				$j = strpos($newKey, '.');
				while ($j !== false) {
					$newKey =	 substr($newKey, 0, $j) . ucfirst(substr($newKey, $j + 1));
					$j = strpos($newKey, '.');
				}
				$this->setBuildProperty($newKey, $propValue);
			}
		}
	}

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param			 string $name
	 * @return		 mixed
	 */
	public function getBuildProperty($name)
	{
		return isset($this->buildProperties[$name]) ? $this->buildProperties[$name] : null;
	}

	/**
	 * Sets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @param      mixed $value
	 */
	public function setBuildProperty($name, $value)
	{
		if ($value === 'true') {
			$value = true;
		} elseif ($value === 'false') {
			$value = false;
		}
		$this->buildProperties[$name] = $value;
	}

}