<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Reverse;

/**
 * Base class for reverse engineering a database schema.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 * @method     void setMigrationTable(string $migrationTable)
 */
use Propulsion\Generator\Config\GeneratorConfig;
use Propulsion\Generator\Config\GeneratorConfigInterface;
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\VendorInfo;

abstract class BaseSchemaParser implements SchemaParser
{

	/**
	 * The database connection.
	 * @var        \PDO|null
	 */
	protected $dbh;

	/**
	 * Stack of warnings.
	 *
	 * @var        array string[]
	 */
	protected $warnings = array();

	/**
	 * GeneratorConfig object holding build properties.
	 *
	 * @var        GeneratorConfigInterface|null
	 */
	private $generatorConfig;

	/**
	 * Map native DB types to Propulsion types.
	 * (Override in subclasses.)
	 * @var        array
	 */
	protected $nativeToPropulsionTypeMap;

	/**
	 * Map to hold reverse type mapping (initialized on-demand).
	 *
	 * @var        array
	 */
	protected $reverseTypeMap;

	/**
	 * Name of the propel migration table - to be ignored in reverse
	 *
	 * @var string
	 */
	protected $migrationTable = 'propulsion_migration';

	protected $platform;

	/**
	 * @param      \PDO $dbh Optional database connection
	 */
	public function __construct(?\PDO $dbh = null)
	{
		if ($dbh) $this->setConnection($dbh);
	}

	/**
	 * Sets the database connection.
	 *
	 * @param      \PDO|null $dbh
	 */
	public function setConnection(?\PDO $dbh)
	{
		$this->dbh = $dbh;
	}

	/**
	 * Gets the database connection.
	 * @return     \PDO|null
	 */
	public function getConnection()
	{
		return $this->dbh;
	}

	/**
	 * Setter for the migrationTable property
	 *
	 * @param string $migrationTable
	 */
	public function setMigrationTable($migrationTable)
	{
		$this->migrationTable = $migrationTable;
	}

	/**
	 * Getter for the migrationTable property
	 *
	 * @return string
	 */
	public function getMigrationTable()
	{
		return $this->migrationTable;
	}

	/**
	 * Pushes a message onto the stack of warnings.
	 *
	 * @param      string $msg The warning message.
	 */
	protected function warn($msg)
	{
		$this->warnings[] = $msg;
	}

	/**
	 * Gets array of warning messages.
	 *
	 * @return     array string[]
	 */
	public function getWarnings()
	{
		return $this->warnings;
	}

	/**
	 * Sets the GeneratorConfig to use in the parsing.
	 *
	 * @param      GeneratorConfigInterface $config
	 */
	public function setGeneratorConfig(GeneratorConfigInterface $config)
	{
		$this->generatorConfig = $config;
	}

	/**
	 * Gets the GeneratorConfig option.
	 *
	 * @return     GeneratorConfigInterface|null
	 */
	public function getGeneratorConfig()
	{
		return $this->generatorConfig;
	}

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
	 */
	public function getBuildProperty($name)
	{
		if ($this->generatorConfig !== null) {
			return $this->generatorConfig->getBuildProperty($name);
		}
		return null;
	}

	/**
	 * Gets a type mapping from native type to Propulsion type.
	 *
	 * @return     array The mapped Propulsion type.
	 */
	abstract protected function getTypeMapping();

	/**
	 * Gets a mapped Propulsion type for specified native type.
	 *
	 * @param      string $nativeType
	 * @return     string|null The mapped Propulsion type.
	 */
	protected function getMappedPropulsionType($nativeType)
	{
		if ($this->nativeToPropulsionTypeMap === null) {
			$this->nativeToPropulsionTypeMap = $this->getTypeMapping();
		}
		if (isset($this->nativeToPropulsionTypeMap[$nativeType])) {
			return $this->nativeToPropulsionTypeMap[$nativeType];
		}
		return null;
	}

	/**
	 * Give a best guess at the native type.
	 *
	 * @param      string $propelType
	 * @return     string The native SQL type that best matches the specified Propulsion type.
	 */
	protected function getMappedNativeType($propelType)
	{
		if ($this->reverseTypeMap === null) {
			$this->reverseTypeMap = array_flip($this->getTypeMapping());
		}
		return isset($this->reverseTypeMap[$propelType]) ? $this->reverseTypeMap[$propelType] : null;
	}

	/**
	 * Gets a new VendorInfo object for this platform with specified params.
	 *
	 * @param      array $params
	 */
	protected function getNewVendorInfoObject(array $params)
	{
		$type = $this->getPlatform()->getDatabaseType();
		$vi = new VendorInfo($type);
		$vi->setParameters($params);
		return $vi;
	}

	public function setPlatform($platform)
	{
	  $this->platform = $platform;
	}

	public function getPlatform()
	{
	  if (null === $this->platform)
	  {
	    $generatorConfig = $this->getGeneratorConfig();
	    if (!$generatorConfig instanceof GeneratorConfig) {
	      throw new EngineException(sprintf(
	        "Cannot auto-configure the platform: the configured GeneratorConfig (%s) does not support getConfiguredPlatform(). Call setPlatform() explicitly instead.",
	        $generatorConfig === null ? 'none' : get_class($generatorConfig)
	      ));
	    }
	    $this->platform = $generatorConfig->getConfiguredPlatform();
	  }
	  return $this->platform;
	}
}
