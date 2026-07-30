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
	 * @var        array<string,mixed>
	 */
	private $buildProperties = array();

	/**
	 * @var array<string,array{adapter:?string,dsn:?string,user:?string,password:?string}>|null
	 */
	protected $buildConnections = null;

	/**
	 * @var string|null
	 */
	protected $defaultBuildConnection = null;

	/**
	 * Construct a new GeneratorConfig.
	 * @param      iterable<string,mixed>|null $props Array or Iterator
	 */
	public function __construct(?iterable $props = null)
	{
		if ($props) $this->setBuildProperties($props);
	}

	/**
	 * Builds a GeneratorConfig from the generator's default config file, one or
	 * more optional user-supplied override files, and an array of ad-hoc
	 * overrides -- without requiring Phing. Each file is either a plain PHP
	 * file returning a flat `['propulsion.foo' => ..., ...]` array (recommended,
	 * dispatched by a `.php` extension -- see generator/default.php), or a
	 * legacy Phing/Ant-style `.properties` text file (`key = value` lines,
	 * one per line).
	 *
	 * @param      string $defaultPropertiesFile Path to generator/default.php.
	 * @param      string|string[]|null $overridePropertiesFiles One or more override files,
	 *             applied in order (later files win on conflicting keys).
	 * @param      array<string,mixed> $overrides Raw `propulsion.*`-prefixed overrides, e.g. ['propulsion.targetPlatform' => 'php84'].
	 */
	public static function createFromPropertiesFile(string $defaultPropertiesFile, string|array|null $overridePropertiesFiles = null, array $overrides = []): self
	{
		$props = self::parsePropertiesFile($defaultPropertiesFile);

		foreach ((array) $overridePropertiesFiles as $overrideFile) {
			$props = array_merge($props, self::parsePropertiesFile($overrideFile));
		}

		$props = array_merge($props, $overrides);
		$props = self::resolvePlaceholders($props);

		return new self($props);
	}

	/**
	 * Loads a build-properties file, dispatched by extension: a `.php` file is
	 * `require`d and expected to `return` a flat `['propulsion.foo' => ..., ...]`
	 * array directly (recommended -- see NOTICE.md/KNOWN_ISSUES.md), while
	 * anything else falls back to the legacy Ant/Phing `.properties` text
	 * format: these files are user-authored content that may live in a
	 * consuming project's own repo, not code inside this one, so dropping the
	 * legacy format outright isn't provably safe from here alone.
	 *
	 * @return array<string,mixed>
	 */
	private static function parsePropertiesFile(string $filepath): array
	{
		if (strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) === 'php') {
			$props = require $filepath;
			if (!is_array($props)) {
				throw new EngineException("Expected $filepath to return an array of properties.");
			}
			return self::requireStringKeys($props, "the array returned by $filepath");
		}

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
	 * Validates (and re-types) an array of unknown key type as string-keyed,
	 * e.g. after loading a file or property whose runtime shape is trusted
	 * but not statically known.
	 *
	 * @param      array<mixed,mixed> $arr
	 * @param      string $context Human-readable description used in the exception message.
	 * @return     array<string,mixed>
	 */
	private static function requireStringKeys(array $arr, string $context): array
	{
		$result = array();
		foreach ($arr as $key => $value) {
			if (!is_string($key)) {
				throw new EngineException("Expected $context to be an array keyed by string.");
			}
			$result[$key] = $value;
		}
		return $result;
	}

	/**
	 * Resolves Ant/Phing-style `${propulsion.some.key}` placeholders against the
	 * properties themselves, innermost-first, so e.g.
	 * `propulsion.platform.class = ${propulsion.platform.${propulsion.database}.class}`
	 * resolves in two passes once `propulsion.database` is set.
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
				$resolved = preg_replace_callback('/\$\{([^{}]+)\}/', function (array $m) use ($props): string {
					$replacement = $props[$m[1]] ?? $m[0];
					return is_string($replacement) ? $replacement : $m[0];
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
	 * @return     array<string,mixed>
	 */
	public function getBuildProperties()
	{
		return $this->buildProperties;
	}

	/**
	 * Parses the passed-in properties, renaming and saving eligible properties in this object.
	 *
	 * Renames the propulsion.xxx properties to just xxx and renames any xxx.yyy properties
	 * to xxxYyy as PHP doesn't like the xxx.yyy syntax.
	 *
	 * @param      iterable<string,mixed> $props Array or Iterator
	 */
	public function setBuildProperties(iterable $props): void
	{
		$this->buildProperties = array();

		$renamedPropulsionProps = array();
		foreach ($props as $key => $propValue) {
			if (strpos($key, "propulsion.") === 0) {
				$newKey = substr($key, strlen("propulsion."));
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
	public function setBuildProperty($name, $value): void
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
		if (!is_string($classpath)) {
			throw new EngineException("Expected class path for '$propname' property to be a string.");
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

		// Check for a platform-specific builder override first (e.g.
		// propulsion.builder.*.php84.class), falling back to the unsuffixed
		// default below when targetPlatform is unset or has no matching
		// override.
		if (is_string($platform) && $platform !== '') {
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
	 * @return     PropulsionPlatformInterface|null
	 */
	public function getConfiguredPlatform(?\PDO $con = null, ?string $database = null)
	{
		$buildConnection = $this->getBuildConnection($database);
		if (null !== $buildConnection['adapter']) {
			$clazz = 'Propulsion\\Generator\\Platform\\' . ucfirst($buildConnection['adapter']) . 'Platform';
		} elseif ($this->getBuildProperty('platformClass')) {
			// propulsion.platform.class = platform.${propulsion.database}Platform by default
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
	public function getConfiguredSchemaParser(\PDO $con)
	{
		$clazz = $this->getClassname("reverseParserClass");
		$parser = new $clazz();
		if (!$parser instanceof SchemaParser) {
			throw new EngineException("Specified platform class ($clazz) does not implement the SchemaParser interface.");
		}
		$parser->setConnection($con);
		$migrationTable = $this->getBuildProperty('migrationTable');
		if (is_string($migrationTable) && $migrationTable !== '') {
			$parser->setMigrationTable($migrationTable);
		}
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
	public function getConfiguredBuilder(mixed $table, $type, bool $cache = true)
	{
		$classname = $this->getBuilderClassname($type);
		$builder = new $classname($table);
		if (!$builder instanceof DataModelBuilder) {
			throw new EngineException("Specified builder class ($classname) does not extend DataModelBuilder.");
		}
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
		if (!$pluralizer instanceof Pluralizer) {
			throw new EngineException("Specified pluralizer class ($classname) does not implement the Pluralizer interface.");
		}
		return $pluralizer;
	}

	/**
	 * Gets a configured behavior class
	 *
	 * @param string $name a behavior name
	 * @return string|null a behavior class name, or null if not configured
	 */
	public function getConfiguredBehavior($name): ?string
	{
		$propname = 'behavior' . ucfirst(strtolower($name)) . 'Class';
		try {
			$ret = $this->getClassname($propname);
		} catch (EngineException $e) {
			// class path not configured
			$ret = null;
		}
		return $ret;
	}

	/**
	 * @param array<string,array{adapter:?string,dsn:?string,user:?string,password:?string}> $buildConnections
	 */
	public function setBuildConnections(array $buildConnections): void
	{
		$this->buildConnections = $buildConnections;
	}

	/**
	 * Looks up build-time database connection info (adapter/dsn/user/password,
	 * keyed by datasource id, with one marked as default) from, in order:
	 *
	 *  - a `propulsion.buildtimeConfigArray` build property: a plain PHP array,
	 *    already in the shape this method returns (see
	 *    {@see applyBuildConnectionsArray()}), e.g. set programmatically or
	 *    via an ad-hoc `--config` override.
	 *  - a `propulsion.buildtimeConfFile` build property naming a plain PHP
	 *    file returning the same array shape as above, loaded via `require`
	 *    and tried at a direct path, CWD, or a repository `build/propel/`
	 *    directory.
	 *
	 * @return array<string,array{adapter:?string,dsn:?string,user:?string,password:?string}>
	 */
	public function getBuildConnections()
	{
		if (null === $this->buildConnections) {
			$buildTimeConfigArray = $this->getBuildProperty('buildtimeConfigArray');
			if (is_array($buildTimeConfigArray)) {
				// A PHP array passed directly, in the same shape a
				// buildtime-config.php file returns.
				$this->applyBuildConnectionsArray(self::requireStringKeys($buildTimeConfigArray, "the 'buildtimeConfigArray' build property"));
			} else {
				$buildTimeConfFileName = $this->getBuildProperty('buildtimeConfFile');

				if (is_string($buildTimeConfFileName) && $buildTimeConfFileName !== '') {
					$projectDir = $this->getBuildProperty('projectDir');
					$projectDir = is_string($projectDir) ? $projectDir : '';
					$cwd = getcwd();
					$cwd = $cwd !== false ? $cwd : '';

					// Try, in order: the resolved projectDir-relative path, the
					// filename as given directly (e.g. -Dpropel.buildtime.conf.file=/path/to/file),
					// then a few alternative locations (CWD, repository build/propel directory).
					$candidates = [
						$projectDir . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
						$buildTimeConfFileName,
						$cwd . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
						$cwd . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
						dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'propel' . DIRECTORY_SEPARATOR . $buildTimeConfFileName,
					];
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
	 * Loads build connections from a `.php` file if it exists (a file
	 * returning the `getBuildConnections()` array shape, loaded via `require`).
	 *
	 * @return bool true if the file existed and was loaded.
	 */
	private function loadBuildConnectionsFile(string $path): bool
	{
		if (!file_exists($path)) {
			return false;
		}
		$config = require $path;
		$this->applyBuildConnectionsArray(is_array($config) ? self::requireStringKeys($config, "the build connections file '$path'") : []);
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
		$default = $config['default'] ?? null;
		$this->defaultBuildConnection = is_string($default) ? $default : null;

		$datasources = $config['datasources'] ?? [];
		if (!is_array($datasources)) {
			throw new EngineException("Expected 'datasources' in build connections config to be an array.");
		}

		$buildConnections = array();
		foreach ($datasources as $name => $connection) {
			if (!is_string($name) || !is_array($connection)) {
				throw new EngineException("Expected 'datasources' in build connections config to be an array of string-keyed connection-info arrays.");
			}
			$buildConnections[$name] = array(
				'adapter'  => isset($connection['adapter']) && is_string($connection['adapter']) ? $connection['adapter'] : null,
				'dsn'      => isset($connection['dsn']) && is_string($connection['dsn']) ? $connection['dsn'] : null,
				'user'     => isset($connection['user']) && is_string($connection['user']) ? $connection['user'] : null,
				'password' => isset($connection['password']) && is_string($connection['password']) ? $connection['password'] : null,
			);
		}
		$this->buildConnections = $buildConnections;
	}

	/**
	 * @return array{adapter:?string,dsn:?string,user:?string,password:?string}
	 */
	public function getBuildConnection(?string $databaseName = null): array
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
			$adapter = $this->getBuildProperty('databaseAdapter');
			$dsn = $this->getBuildProperty('databaseUrl');
			$user = $this->getBuildProperty('databaseUser');
			$password = $this->getBuildProperty('databasePassword');
			return array(
				'adapter'  => is_string($adapter) ? $adapter : null,
				'dsn'      => is_string($dsn) ? $dsn : null,
				'user'     => is_string($user) ? $user : null,
				'password' => is_string($password) ? $password : null,
			);
		}
	}

	public function getBuildPDO(string $database): PDO
	{
		$buildConnection = $this->getBuildConnection($database);
		if (null === $buildConnection['dsn']) {
			throw new EngineException("No DSN configured for build connection '$database'.");
		}
		$dsn = str_replace("@DB@", $database, $buildConnection['dsn']);

		// Set user + password to null if they are empty strings or missing
		$username = isset($buildConnection['user']) && $buildConnection['user'] ? $buildConnection['user'] : null;
		$password = isset($buildConnection['password']) && $buildConnection['password'] ? $buildConnection['password'] : null;

		$pdo = new PDO($dsn, $username, $password);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		return $pdo;
	}
}
