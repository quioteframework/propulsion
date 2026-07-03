<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license		 MIT License
 */
namespace Propulsion\Generator\Config;

/**
 *
 * @package		 propel.generator.config
 */

 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Builder\Util\DefaultEnglishPluralizer;
 use Propulsion\Generator\Builder\OM\PHP5PeerBuilder;
 use Propulsion\Generator\Builder\OM\PHP5ObjectBuilder;
 use Propulsion\Generator\Builder\OM\PHP5ExtensionObjectBuilder;
 use Propulsion\Generator\Builder\OM\PHP5ExtensionPeerBuilder;
 use Propulsion\Generator\Builder\OM\PHP5MultiExtendObjectBuilder;
 use Propulsion\Generator\Builder\OM\PHP5TableMapBuilder;
 use Propulsion\Generator\Builder\OM\QueryBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionQueryBuilder;
 use Propulsion\Generator\Builder\OM\QueryInheritanceBuilder;
 use Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder;
 use Propulsion\Generator\Builder\OM\PHP5InterfaceBuilder;
 use Propulsion\Generator\Builder\OM\PHP5NodeBuilder;
 use Propulsion\Generator\Builder\OM\PHP5NodePeerBuilder;
 use Propulsion\Generator\Builder\OM\PHP5ExtensionNodeBuilder;
 use Propulsion\Generator\Builder\OM\PHP5ExtensionNodePeerBuilder;
 use Propulsion\Generator\Builder\OM\PHP5NestedSetBuilder;
 use Propulsion\Generator\Builder\OM\PHP5NestedSetPeerBuilder;

class QuickGeneratorConfig implements GeneratorConfigInterface
{
	protected $builders = array(
		'peer'					=> PHP5PeerBuilder::class,
		'object'				=> PHP5ObjectBuilder::class,
		'objectstub'		=> PHP5ExtensionObjectBuilder::class,
		'peerstub'			=> PHP5ExtensionPeerBuilder::class,
		'objectmultiextend' => PHP5MultiExtendObjectBuilder::class,
		'tablemap'			=> PHP5TableMapBuilder::class,
		'query'					=> QueryBuilder::class,
		'querystub'			=> ExtensionQueryBuilder::class,
		'queryinheritance' => QueryInheritanceBuilder::class,
		'queryinheritancestub' => ExtensionQueryInheritanceBuilder::class,
		'interface'			=> PHP5InterfaceBuilder::class,
		'node'					=> PHP5NodeBuilder::class,
		'nodepeer'			=> PHP5NodePeerBuilder::class,
		'nodestub'			=> PHP5ExtensionNodeBuilder::class,
		'nodepeerstub'	=> PHP5ExtensionNodePeerBuilder::class,
		'nestedset'			=> PHP5NestedSetBuilder::class,
		'nestedsetpeer' => PHP5NestedSetPeerBuilder::class,
	);

	protected $buildProperties = array();

	public function __construct()
	{
		$this->setBuildProperties($this->parsePseudoIniFile(dirname(__FILE__) . '/../../default.properties'));
	}

	/**
	 * Why would Phing use ini while it so fun to invent a new format? (sic)
	 * parse_ini_file() doesn't work for Phing property files
	 */
	protected function parsePseudoIniFile($filepath)
	{
		$properties = array();
		if (($lines = @file($filepath)) === false) {
			throw new \Exception("Unable to parse contents of $filepath");
		}
		foreach($lines as $line) {
				$line = trim($line);
				if ($line == "" || $line[0] == '#' || $line[0] == ';') continue;
				$pos = strpos($line, '=');
				$property = trim(substr($line, 0, $pos));
				$value = trim(substr($line, $pos + 1));
				if ($value === "true") {
					$value = true;
				} elseif ($value === "false") {
					$value = false;
				}
				$properties[$property] = $value;
		}
		return $properties;
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
	 * Renames the propel.xxx properties to just xxx and renames any xxx.yyy properties
	 * to xxxYyy as PHP doesn't like the xxx.yyy syntax.
	 *
	 * @param			 mixed $props Array or Iterator
	 */
	public function setBuildProperties($props)
	{
		$this->buildProperties = array();

		$renamedPropelProps = array();
		foreach ($props as $key => $propValue) {
			if (strpos($key, "propel.") === 0) {
				$newKey = substr($key, strlen("propel."));
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
		$this->buildProperties[$name] = $value;
	}

}