<?php

/**
 * This file is part of the Propulsion package.
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
 */

use Propulsion\Generator\Exception\EngineException;
use PDO;
use Propulsion\Generator\Platform\PropulsionPlatformInterface;
use Propulsion\Generator\Reverse\SchemaParser;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\DataModelBuilder;
use Propulsion\Generator\Builder\Util\Pluralizer;

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
	 * Builds a GeneratorConfig from the generator's default.properties file, one or
	 * more optional user-supplied properties files (Phing/Ant-style `key = value`
	 * lines, one per line), and an array of ad-hoc overrides -- without requiring Phing.
	 *
	 * @param      string $defaultPropertiesFile Path to generator/default.properties.
	 * @param      string|string[]|null $overridePropertiesFiles One or more override files,
	 *             applied in order (later files win on conflicting keys).
	 * @param      array<string,mixed> $overrides Raw `propel.*`-prefixed overrides, e.g. ['propel.targetPlatform' => 'php84'].
	 */
	public static function createFromPropertiesFile(string $defaultPropertiesFile, string|array|null $overridePropertiesFiles = null, array $overrides = []): self
	{
		$props = self::parsePropertiesFile($defaultPropertiesFile);

		foreach ((array) $overridePropertiesFiles as $overrideFile) {
			if ($overrideFile !== null) {
				$props = array_merge($props, self::parsePropertiesFile($overrideFile));
			}
		}

		$props = array_merge($props, $overrides);
		$props = self::resolvePlaceholders($props);

		return new self($props);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function parsePropertiesFile(string $filepath): array
	{
		$properties = array();
		$lines = @file($filepath);
		if ($lines === false) {
			throw new EngineException("Unable to parse contents of $filepath");
		}
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || $line[0] === ';') {
				continue;
			}
			$pos = strpos($line, '=');
			if ($pos === false) {
				continue;
			}
			$property = trim(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1));
			$properties[$property] = $value;
		}
		return $properties;
	}

	/**
	 * Resolves Ant/Phing-style `${propel.some.key}` placeholders against the
	 * properties themselves, innermost-first, so e.g.
	 * `propel.platform.class = ${propel.platform.${propel.database}.class}`
	 * resolves in two passes once `propel.database` is set.
	 *
	 * @param      array<string,mixed> $props
	 * @return     array<string,mixed>
	 */
	private static function resolvePlaceholders(array $props): array
	{
		for ($i = 0; $i < 10; $i++) {
			$changed = false;
			foreach ($props as $key => $value) {
				if (!is_string($value)) {
					continue;
				}
				$resolved = preg_replace_callback('/\$\{([^{}]+)\}/', function ($m) use ($props) {
					return $props[$m[1]] ?? $m[0];
				}, $value);
				if ($resolved !== $value) {
					$props[$key] = $resolved;
					$changed = true;
				}
			}
			if (!$changed) {
				break;
			}
		}
		return $props;
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

		$renamedPropulsionProps = array();
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
	 * @throws     EngineException If the classname cannot be determined or class cannot be loaded.
	 */
	public function getClassname($propname)
	{
		$classpath = $this->getBuildProperty($propname);
		if (null === $classpath) {
			throw new EngineException("Unable to find class path for '$propname' property.");
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
			throw new EngineException("Unable to find class path for '$propname' property.");
		}

		// If it's a PSR-4 namespaced class name, prefer that (most modern usages)
		if (strpos($classpath, '\\') !== false) {
			if (class_exists($classpath)) {
				return $classpath;
			}
			throw new EngineException("Class '$classpath' not found for property '$propname'.");
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

		// Legacy dot-notation path (e.g. 'test.tools.helpers.bookstore.behavior.AddClassBehavior'),
		// resolved relative to the current working directory. Phing::import() can't be used for
		// this in Phing 3.x: it only converts '_' and '\' to directory separators, not '.', so it
		// never supported this notation to begin with.
		$file = str_replace('.', DIRECTORY_SEPARATOR, $classpath) . '.php';
		$className = substr($classpath, strrpos($classpath, '.') + 1);

		if (is_file($file)) {
			require_once $file;
		}

		if (class_exists($className)) {
			return $className;
		}

		throw new EngineException("Class '$className' not found for property '$propname' (tried file '$file').");
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

		// Check for platform-specific builder first. 'php5' used to be special-cased
		// here to always skip straight to the unsuffixed default -- back when the
		// unsuffixed propel.builder.*.class keys *were* the PHP5 builders. Since
		// Phase 3 (see KNOWN_ISSUES.md) promoted the modern (formerly PHP84) builders
		// to the unsuffixed defaults, 'php5' now needs the same platform-specific
		// override lookup as any other explicit targetPlatform value, so that
		// propel.builder.*.php5.class overrides remain reachable.
		if ($platform) {
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
	 * @return     PropulsionPlatformInterface
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

		if (!$platform instanceof PropulsionPlatformInterface) {
			throw new EngineException("Specified platform class ($clazz) does not implement the PropulsionPlatformInterface interface.");
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
			throw new EngineException("Specified platform class ($clazz) does not implement the SchemaParser interface.");
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
		} catch (EngineException $e) {
			// class path not configured
			$ret = false;
		}
		return $ret;
	}

	public function setBuildConnections($buildConnections)
	{
		$this->buildConnections = $buildConnections;
	}

	/**
	 * Looks up build-time database connection info (adapter/dsn/user/password,
	 * keyed by datasource id, with one marked as default) from, in order:
	 *
	 *  - a `propel.buildtimeConfigArray` build property: a plain PHP array,
	 *    already in the shape this method returns (see
	 *    {@see applyBuildConnectionsArray()}), e.g. set programmatically or
	 *    via an ad-hoc `--config` override. This is the recommended path --
	 *    see KNOWN_ISSUES.md.
	 *  - a `propel.buildtimeConfFile` build property naming either a plain
	 *    PHP file (recommended -- returns the same array shape as above,
	 *    loaded via `require`) or a legacy `buildtime-conf.xml` file (kept
	 *    for backward compatibility with existing project configs -- see
	 *    {@see parseBuildConnections()}), tried at a direct path, CWD, or a
	 *    repository `build/propel/` directory.
	 *  - a `propel.buildtimeConf` base64-encoded XML string build property
	 *    (legacy; command-line-friendly since it avoids whitespace).
	 *
	 * @return array<string,array{adapter:?string,dsn:?string,user:?string,password:?string}>
	 */
	public function getBuildConnections()
	{
		if (null === $this->buildConnections) {
			if (is_array($buildTimeConfigArray = $this->getBuildProperty('buildtimeConfigArray'))) {
				// A PHP array passed directly, in the same shape a
				// buildtime-config.php file returns.
				$this->applyBuildConnectionsArray($buildTimeConfigArray);
			} else {
				$buildTimeConfFileName = $this->getBuildProperty('buildtimeConfFile');
				$buildTimeConfigPath = $buildTimeConfFileName ? $this->getBuildProperty('projectDir') . DIRECTORY_SEPARATOR . $buildTimeConfFileName : null;

				if ($buildTimeConfigString = $this->getBuildProperty('buildtimeConf')) {
					// configuration passed as propel.buildtimeConf string
					// probably using the command line, which doesn't accept whitespace
					// therefore base64 encoded
					$this->parseBuildConnections(base64_decode($buildTimeConfigString));
				} elseif ($buildTimeConfFileName) {
					// Try, in order: the resolved projectDir-relative path, the
					// filename as given directly (e.g. -Dpropel.buildtime.conf.file=/path/to/file),
					// then a few alternative locations (CWD, repository build/propel directory).
					$candidates = array_filter([
						$buildTimeConfigPath,
						$buildTimeConfFileName,
						getcwd() . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
						getcwd() . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
						dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
					]);
					foreach ($candidates as $candidate) {
						if ($this->loadBuildConnectionsFile($candidate)) {
							break;
						}
					}
				}
			}

			if (null === $this->buildConnections) {
				$this->buildConnections = array();
			}
		}
		return $this->buildConnections;
	}

	/**
	 * Loads build connections from a file if it exists, dispatching to the
	 * plain-PHP-array format (a `.php` file returning the
	 * `getBuildConnections()` array shape) or the legacy XML format based on
	 * the file extension.
	 *
	 * @return bool true if the file existed and was loaded.
	 */
	private function loadBuildConnectionsFile(string $path): bool
	{
		if (!file_exists($path)) {
			return false;
		}
		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
			$config = require $path;
			$this->applyBuildConnectionsArray(is_array($config) ? $config : []);
		} else {
			$this->parseBuildConnections(file_get_contents($path));
		}
		return true;
	}

	/**
	 * Applies a plain-PHP build connections array, e.g.:
	 * ```php
	 * return [
	 *     'default' => 'bookstore',
	 *     'datasources' => [
	 *         'bookstore' => ['adapter' => 'pgsql', 'dsn' => 'pgsql:host=localhost;dbname=mydb', 'user' => 'me', 'password' => 'secret'],
	 *     ],
	 * ];
	 * ```
	 *
	 * @param array<string,mixed> $config
	 */
	private function applyBuildConnectionsArray(array $config): void
	{
		$this->defaultBuildConnection = $config['default'] ?? null;
		$this->buildConnections = $config['datasources'] ?? [];
	}

	/**
	 * Parses the legacy `buildtime-conf.xml` format. Kept for backward
	 * compatibility with existing project configs -- see
	 * {@see getBuildConnections()} and KNOWN_ISSUES.md for why the plain-PHP
	 * array format (a `.php` file, or `propel.buildtimeConfigArray`) is
	 * recommended for new configs instead.
	 */
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
		$databaseName ??= '';
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
