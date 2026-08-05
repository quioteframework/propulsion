<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propulsion\Generator\Util;

use Propulsion\Generator\Config\GeneratorConfigInterface;
use Propulsion\Generator\Exception\EngineException;
use \PDO;
use PDOStatement;
use Propulsion\Adapter\DBAdapter;
use Propulsion\Adapter\DBSQLite;
use \Propulsion\Connection\PropulsionPDO;
use Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder;
use Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder;
use Propulsion\Generator\Builder\OM\OMBuilder;
use Propulsion\Generator\Builder\OM\QueryInheritanceBuilder;
use Propulsion\Generator\Builder\Util\XmlToAppData;
use Propulsion\Generator\Config\QuickGeneratorConfig;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\Inheritance;
use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Platform\DefaultPlatform;
use Propulsion\Generator\Platform\PropulsionPlatformInterface;
use Propulsion\Generator\Platform\SqlitePlatform;
use \Propulsion\Propulsion;

class PropulsionQuickBuilder
{
	protected ?string $schema = null;
	protected ?PropulsionPlatformInterface $platform = null;
	protected ?GeneratorConfigInterface $config = null;
	protected ?Database $database = null;

	public function setSchema(string $schema): void
	{
		$this->schema = $schema;
	}

	/**
	 * Setter for the platform property
	 *
	 * @param PropulsionPlatformInterface $platform
	 */
	public function setPlatform(PropulsionPlatformInterface $platform): void
	{
		$this->platform = $platform;
	}

	/**
	 * Getter for the platform property
	 *
	 * @return PropulsionPlatformInterface
	 */
	public function getPlatform(): PropulsionPlatformInterface
	{
		if (null === $this->platform) {
			$this->platform = new SqlitePlatform();
		}
		return $this->platform;
	}

	/**
	 * Setter for the config property
	 *
	 * @param GeneratorConfigInterface $config
	 */
	public function setConfig(GeneratorConfigInterface $config): void
	{
		$this->config = $config;
	}

	/**
	 * Getter for the config property
	 *
	 * @return GeneratorConfigInterface
	 */
	public function getConfig()
	{
		if (null === $this->config) {
			$this->config = new QuickGeneratorConfig();
		}
		return $this->config;
	}

	public static function buildSchema(string $schema, ?string $dsn = null, ?string $user = null, ?string $pass = null, ?DBAdapter $adapter = null): PropulsionPDO
	{
		$builder = new self;
		$builder->setSchema($schema);
		return $builder->build($dsn, $user, $pass, $adapter);
	}

	public function build(?string $dsn = null, ?string $user = null, ?string $pass = null, ?DBAdapter $adapter = null): PropulsionPDO
	{
		if (null === $dsn) {
			$dsn = 'sqlite::memory:';
		}
		if (null === $adapter) {
			$adapter = new DBSQLite();
		}
		$pdoClass = $adapter->getDefaultPdoClass();
		$con = new $pdoClass($dsn, $user, $pass);
		$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
		$this->buildSQL($con);
		$this->buildClasses();
		$name = $this->requireDatabase()->getName();
		// Only when nothing has configured Propulsion yet: setConfiguration()
		// replaces the configuration wholesale and drops everything derived from
		// the one it replaces -- including every adapter registered with setDB().
		// Calling it on each build would therefore unregister the adapters of the
		// databases built before this one, and the next query against any of them
		// would fail with "Unable to find adapter for datasource". Building
		// several schemas in one process is ordinary here (a test class per
		// schema, several schemas per suite), and the later builds have nothing
		// to add anyway: this sets a *default* datasource name, while generated
		// code reaches its own datasource by Peer::DATABASE_NAME.
		if (!Propulsion::isInit() && Propulsion::getConfigurationArray() === array()) {
			Propulsion::setConfiguration(array('datasources' => array('default' => $name)));
		}
		Propulsion::setDB($name, $adapter);
		Propulsion::setConnection($name, $con, Propulsion::CONNECTION_READ);
		Propulsion::setConnection($name, $con, Propulsion::CONNECTION_WRITE);
		return $con;
	}

	public function getDatabase(): ?Database
	{
		if (null === $this->database) {
			if (null === $this->schema) {
				return null;
			}
			$xtad = new XmlToAppData($this->getPlatform());
			$appData = $xtad->parseString($this->schema);
			$this->database = $appData->getDatabase(); // does final initialization
		}
		return $this->database;
	}

	/**
	 * Same as getDatabase(), but throws instead of returning null -- for call
	 * sites that only make sense once a schema has actually been parsed into
	 * a Database (i.e. setSchema() was called before this method).
	 */
	private function requireDatabase(): Database
	{
		$database = $this->getDatabase();
		if (null === $database) {
			throw new \RuntimeException('No schema has been set on this PropulsionQuickBuilder; call setSchema() first.');
		}
		return $database;
	}

	public function buildSQL(PropulsionPDO $con): int
	{
		$statements = PropulsionSQLParser::parseString($this->getSQL());
		foreach ($statements as $statement) {
			if (strpos($statement, 'DROP') === 0) {
				// drop statements cause errors since the table doesn't exist
				continue;
			}
			$stmt = $con->prepare($statement);
			if ($stmt instanceof PDOStatement) {
				// only execute if has no error
				$stmt->execute();
			}
		}
		return count($statements);
	}

	public function getSQL(): string
	{
		$platform = $this->getPlatform();
		return $platform instanceof DefaultPlatform ? $platform->getAddTablesDDL($this->requireDatabase()) : '';
	}

	/**
	 * Builds the script for a configured builder, if it is a real OM builder
	 * (i.e. actually has a build() method).
	 */
	private function buildScriptFor(Table $table, string $target): string
	{
		$builder = $this->getConfig()->getConfiguredBuilder($table, $target);

		return $builder instanceof OMBuilder ? self::wrapInNamespaceBlock($builder->build()) : '';
	}

	/**
	 * Re-express one generated class as a *braced* namespace block, so that many
	 * of them can be concatenated into the single script {@see getClasses()}
	 * returns and {@see buildClasses()} eval()s.
	 *
	 * Each braced block has its own import scope. That is what makes the
	 * concatenation legal now that generated classes carry real `use` statements
	 * for the runtime classes they reference (see
	 * {@see \Propulsion\Generator\Builder\OM\OMBuilder::getUseStatements()}):
	 * repeating `use Propulsion\Query\Criteria;` once per class in a *single*
	 * scope is a fatal error -- "Cannot use Propulsion\Query\Criteria as Criteria
	 * because the name is already in use" -- and PHP raises it even when the two
	 * imports are identical. Previously flat generated classes emitted no imports
	 * at all and leaned on the global aliases instead, which is exactly the
	 * dependency this removes.
	 *
	 * Every block is braced, including the global-namespace one (`namespace { }`),
	 * because PHP refuses to mix braced and unbraced namespace declarations in one
	 * script -- so a schema with namespaced tables cannot be handled by wrapping
	 * only the flat ones. Classes declared inside `namespace { }` still land in the
	 * global namespace, exactly as before.
	 */
	private static function wrapInNamespaceBlock(string $script): string
	{
		if (trim($script) === '') {
			return '';
		}

		// The concatenated result is eval()'d, which is already in PHP mode.
		$script = preg_replace('/^\s*<\?php\s*/', '', $script, 1) ?? $script;

		if (preg_match('/^\s*namespace\s+([^;{]+);\s*/', $script, $matches) === 1) {
			return sprintf("namespace %s {\n%s\n}\n", trim($matches[1]), substr($script, strlen($matches[0])));
		}

		return sprintf("namespace {\n%s\n}\n", $script);
	}

	/**
	 * Same as buildScriptFor(), but also assigns the given inheritance child
	 * to the builder before building, for builders that support it.
	 */
	private function buildScriptForChild(Table $table, string $target, Inheritance $child): string
	{
		$builder = $this->getConfig()->getConfiguredBuilder($table, $target);
		if ($builder instanceof QueryInheritanceBuilder
			|| $builder instanceof ExtensionQueryInheritanceBuilder
			|| $builder instanceof MultiExtendObjectBuilder
		) {
			$builder->setChild($child);
		}
		return $builder instanceof OMBuilder ? self::wrapInNamespaceBlock($builder->build()) : '';
	}

	public function buildClasses(): void
	{
		eval($this->getClasses());
	}

	public function getClasses(): string
	{
		$script = '';
		foreach ($this->requireDatabase()->getTables() as $table) {
			$script .= $this->getClassesForTable($table);
		}
		return $script;
	}

	public function getClassesForTable(Table $table): string
	{
		$script = '';

		foreach (array('tablemap', 'peer', 'object', 'query', 'peerstub', 'objectstub', 'querystub') as $target) {
			$script .= $this->buildScriptFor($table, $target);
		}

		if ($col = $table->getChildrenColumn()) {
			if ($col->isEnumeratedClasses()) {
				foreach ($col->getChildren() ?? array() as $child) {
					// The root inheritance entry (no "extends") describes the table's own
					// base class, which the ordinary object/query/*stub targets above
					// already build -- generating objectmultiextend/queryinheritancestub
					// for it too would redeclare that same class a second time.
					if ($child->getAncestor()) {
						$script .= $this->buildScriptForChild($table, 'queryinheritance', $child);
						foreach (array('objectmultiextend', 'queryinheritancestub') as $target) {
							$script .= $this->buildScriptForChild($table, $target, $child);
						}
					}
				}
			}
		}

		if ($table->getInterface()) {
			$script .= $this->buildScriptFor($table, 'interface');
		}

		if ($table->treeMode()) {
			switch($table->treeMode()) {
				case 'NestedSet':
					foreach (array('nestedsetpeer', 'nestedset') as $target) {
						$script .= $this->buildScriptFor($table, $target);
					}
				break;
				case 'MaterializedPath':
					foreach (array('nodepeer', 'node') as $target) {
						$script .= $this->buildScriptFor($table, $target);
					}
					foreach (array('nodepeerstub', 'nodestub') as $target) {
						$script .= $this->buildScriptFor($table, $target);
					}
				break;
				case 'AdjacencyList':
					// No implementation for this yet.
				default:
				break;
			}
		}

		if ($table->hasAdditionalBuilders()) {
			foreach ($table->getAdditionalBuilders() as $builderClass) {
				$builder = new $builderClass($table);
				if (!$builder instanceof OMBuilder) {
					throw new EngineException(sprintf(
						"Additional builder class (%s) does not extend %s.",
						get_class($builder),
						OMBuilder::class
					));
				}
				$script .= self::wrapInNamespaceBlock($builder->build());
			}
		}

		// Belt and braces: wrapInNamespaceBlock() already strips the opening tag
		// from every block above, so this is a no-op now rather than the thing
		// that made the concatenation valid.
		$script = str_replace('<?php', '', $script);
		return $script;
	}

	public static function debugClassesForTable(string $schema, string $tableName): void
	{
		$builder = new self;
		$builder->setSchema($schema);
		foreach ($builder->requireDatabase()->getTables() as $table) {
			if ($table->getName() == $tableName) {
				echo $builder->getClassesForTable($table);
			}
		}
	}
}