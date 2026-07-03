<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Config;

// Phing dependencies

/**
 * A class that holds build properties and provide a class loading mechanism for the generator.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @package    propel.generator.config
 * @method string getLocation()
 */

 use Phing\Exception\BuildException;
use Phing\Phing;
use PDO;
use Propulsion\Generator\Platform\PropelPlatformInterface;
use Propulsion\Generator\Reverse\SchemaParser;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\DataModelBuilder;

class GeneratorConfig implements GeneratorConfigInterface
{

	/**
	 * The build properties.
	 *
	 * @var        array
	 */
	private $buildProperties = array();

	protected $buildConnections = null;
	protected $defaultBuildConnection = null;

	/**
	 * Construct a new GeneratorConfig.
	 * @param      mixed $props Array or Iterator
	 */
	public function __construct($props = null)
	{
		if ($props) $this->setBuildProperties($props);
	}

	/**
	 * Gets the build properties.
	 * @return     array
	 */
	public function getBuildProperties()
	{
		return $this->buildProperties;
	}

	/**
	 * Parses the passed-in properties, renaming and saving eligible properties in this object.
	 *
	 * Renames the propel.xxx properties to just xxx and renames any xxx.yyy properties
	 * to xxxYyy as PHP doesn't like the xxx.yyy syntax.
	 *
	 * @param      mixed $props Array or Iterator
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
					$newKey =  substr($newKey, 0, $j) . ucfirst(substr($newKey, $j + 1));
					$j = strpos($newKey, '.');
				}
				$this->setBuildProperty($newKey, $propValue);
			}
		}
	}

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
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

	/**
	 * Resolves and returns the class name based on the specified property value.
	 *
	 * @param      string $propname The name of the property that holds the class path (dot-path notation).
	 * @return     string The class name.
	 * @throws     BuildException If the classname cannot be determined or class cannot be loaded.
	 */
	public function getClassname($propname)
	{
		$classpath = $this->getBuildProperty($propname);
		if (null === $classpath) {
			throw new BuildException("Unable to find class path for '$propname' property.");
		}

		// This is a slight hack to workaround camel case inconsistencies for the DataSQL classes.
		// Basically, we want to turn ?.?.?.sqliteDataSQLBuilder into ?.?.?.SqliteDataSQLBuilder
		$lastdotpos = strrpos($classpath, '.');
		if ($lastdotpos !== false) {
			$classpath[$lastdotpos+1] = strtoupper($classpath[$lastdotpos+1]);
		} else {
			// Allows to configure full classname instead of a dot-path notation
			if (class_exists($classpath)) {
				return $classpath;
			}
			$classpath = ucfirst($classpath);
		}

		if (empty($classpath)) {
			throw new BuildException("Unable to find class path for '$propname' property.");
		}

		// If it's a PSR-4 namespaced class name, prefer that (most modern usages)
		if (strpos($classpath, '\\') !== false) {
			if (class_exists($classpath)) {
				return $classpath;
			}
			throw new BuildException("Class '$classpath' not found for property '$propname'.");
		}

		// If $classpath already refers to a real class (no namespace), return it
		if (class_exists($classpath)) {
			return $classpath;
		}

		// Try mapping dot-notation to a PSR-4 namespaced class under Propulsion\Generator\
		// e.g. 'platform.mysql.MysqlPlatform' -> 'Propulsion\Generator\platform\mysql\MysqlPlatform'
		$nsCandidate = 'Propulsion\\Generator\\' . str_replace('.', '\\', $classpath);
		if (class_exists($nsCandidate)) {
			return $nsCandidate;
		}

		// Also try uppercasing the final segment (common class-name capitalization)
		$parts = explode('.', $classpath);
		$last = array_pop($parts);
		$parts[] = ucfirst($last);
		$nsCandidateUc = 'Propulsion\\Generator\\' . str_replace('.', '\\', implode('.', $parts));
		if (class_exists($nsCandidateUc)) {
			return $nsCandidateUc;
		}

		// Legacy dot-notation path, use Phing::import as a last resort for backward compatibility
		$clazz = Phing::import($classpath);

		return $clazz;
	}

	/**
	 * Resolves and returns the builder class name.
	 *
	 * @param      string $type
	 * @return     string The class name.
	 */
	public function getBuilderClassname($type)
	{
		$platform = $this->getBuildProperty('targetPlatform');
		
		// Check for platform-specific builder first
		if ($platform && $platform !== 'php5') {
			$platformPropname = 'builder' . ucfirst(strtolower($type)) . ucfirst($platform) . 'Class';
			if ($this->getBuildProperty($platformPropname)) {
				return $this->getClassname($platformPropname);
			}
		}
		
		// Fall back to default builder
		$propname = 'builder' . ucfirst(strtolower($type)) . 'Class';
		return $this->getClassname($propname);
	}

	/**
	 * Creates and configures a new Platform class.
	 *
	 * @param      PDO $con
	 * @return     PropelPlatformInterface
	 */
	public function getConfiguredPlatform(?\PDO $con = null, $database = null)
	{
		$buildConnection = $this->getBuildConnection($database);
		if (null !== $buildConnection['adapter']) {
			$clazz = 'Propulsion\\Generator\\Platform\\' . ucfirst($buildConnection['adapter']) . 'Platform';
		} elseif ($this->getBuildProperty('platformClass')) {
			// propel.platform.class = platform.${propel.database}Platform by default
			$clazz = $this->getClassname('platformClass');
		} else {
			return null;
		}
		$platform = new $clazz();

		if (!$platform instanceof PropelPlatformInterface) {
			throw new BuildException("Specified platform class ($clazz) does not implement teh PropelPlatformInterface interface.");
		}

		$platform->setConnection($con);
		$platform->setGeneratorConfig($this);
		return $platform;
	}

	/**
	 * Creates and configures a new SchemaParser class for specified platform.
	 * @param      PDO $con
	 * @return     SchemaParser
	 */
	public function getConfiguredSchemaParser(?\PDO $con = null)
	{
		$clazz = $this->getClassname("reverseParserClass");
		$parser = new $clazz();
		if (!$parser instanceof SchemaParser) {
			throw new BuildException("Specified platform class ($clazz) does implement SchemaParser interface.", $this->getLocation());
		}
		$parser->setConnection($con);
		$parser->setMigrationTable($this->getBuildProperty('migrationTable'));
		$parser->setGeneratorConfig($this);
		return $parser;
	}

	/**
	 * Gets a configured data model builder class for specified table and based on type.
	 *
	 * @param      mixed $table
	 * @param      string $type The type of builder ('ddl', 'sql', etc.)
	 * @return     DataModelBuilder
	 */
	public function getConfiguredBuilder(mixed $table, $type, $cache = true)
	{
		$classname = $this->getBuilderClassname($type);
		$builder = new $classname($table);
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
		$classname = $this->getBuilderClassname('pluralizer');
		$pluralizer = new $classname();
		return $pluralizer;
	}

	/**
	 * Gets a configured behavior class
	 *
	 * @param string $name a behavior name
	 * @return string a behavior class name
	 */
	public function getConfiguredBehavior($name)
	{
		$propname = 'behavior' . ucfirst(strtolower($name)) . 'Class';
		try {
			$ret = $this->getClassname($propname);
		} catch (BuildException $e) {
			// class path not configured
			$ret = false;
		}
		return $ret;
	}

	public function setBuildConnections($buildConnections)
	{
		$this->buildConnections = $buildConnections;
	}

	public function getBuildConnections()
	{
		if (null === $this->buildConnections) {
			$buildTimeConfigPath = $this->getBuildProperty('buildtimeConfFile') ? $this->getBuildProperty('projectDir') . DIRECTORY_SEPARATOR .  $this->getBuildProperty('buildtimeConfFile') : null;
			// Allow alternative locations for buildtime conf: a direct path, CWD, or repository build/propel directory
			$buildTimeConfFileName = $this->getBuildProperty('buildtimeConfFile');
			if ($buildTimeConfigString = $this->getBuildProperty('buildtimeConf')) {
				// configuration passed as propel.buildtimeConf string
				// probably using the command line, which doesn't accept whitespace
				// therefore base64 encoded
				$this->parseBuildConnections(base64_decode($buildTimeConfigString));
			} elseif (file_exists($buildTimeConfigPath)) {
				// configuration stored in a buildtime-conf.xml file
				$this->parseBuildConnections(file_get_contents($buildTimeConfigPath));
			} elseif ($buildTimeConfFileName && file_exists($buildTimeConfFileName)) {
				// path provided directly, e.g. -Dpropel.buildtime.conf.file=/path/to/file
				$this->parseBuildConnections(file_get_contents($buildTimeConfFileName));
			} elseif ($buildTimeConfFileName) {
				// try from current working directory: ./build/propel/<file>
				$cand = getcwd() . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName;
				if (file_exists($cand)) {
					$this->parseBuildConnections(file_get_contents($cand));
				} else {
					// try CWD + filename (in case it's referenced relative to CWD)
					$cand2 = getcwd() . DIRECTORY_SEPARATOR . $buildTimeConfFileName;
					if (file_exists($cand2)) {
						$this->parseBuildConnections(file_get_contents($cand2));
					} else {
						// last resort: try repository-level build/propel path relative to this file
						$repoCand = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName;
						if (file_exists($repoCand)) {
							$this->parseBuildConnections(file_get_contents($repoCand));
						}
					}
				}
			} else {
				$this->buildConnections = array();
			}
		}
		return $this->buildConnections;
	}

	protected function parseBuildConnections($xmlString)
	{
		$conf = simplexml_load_string($xmlString);
		$this->defaultBuildConnection = (string) $conf->propel->datasources['default'];
		$buildConnections = array();
		foreach ($conf->propel->datasources->datasource as $datasource) {
			$buildConnections[(string) $datasource['id']] = array(
				'adapter'  => (string) $datasource->adapter,
				'dsn'      => (string) $datasource->connection->dsn,
				'user'     => (string) $datasource->connection->user,
				'password' => (string) $datasource->connection->password,
			);
		}
		$this->buildConnections = $buildConnections;
	}

	public function getBuildConnection($databaseName = null)
	{
		$connections = $this->getBuildConnections();
		if (null === $databaseName) {
			$databaseName = $this->defaultBuildConnection;
		}
		if (isset($connections[$databaseName])) {
			return $connections[$databaseName];
		} else {
			// fallback to the single connection from build.properties
			return array(
				'adapter'  => $this->getBuildProperty('databaseAdapter'),
				'dsn'      => $this->getBuildProperty('databaseUrl'),
				'user'     => $this->getBuildProperty('databaseUser'),
				'password' => $this->getBuildProperty('databasePassword'),
			);
		}
	}

	public function getBuildPDO($database)
	{
		$buildConnection = $this->getBuildConnection($database);
		$dsn = str_replace("@DB@", $database, $buildConnection['dsn']);

		// Set user + password to null if they are empty strings or missing
		$username = isset($buildConnection['user']) && $buildConnection['user'] ? $buildConnection['user'] : null;
		$password = isset($buildConnection['password']) && $buildConnection['password'] ? $buildConnection['password'] : null;

		$pdo = new PDO($dsn, $username, $password);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		return $pdo;
	}
}
