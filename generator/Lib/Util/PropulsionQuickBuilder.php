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
		$con = new PropulsionPDO($dsn, $user, $pass);
		$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
		$this->buildSQL($con);
		$this->buildClasses();
		$name = $this->getDatabase()->getName();
		if (!Propulsion::isInit()) {
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
			$xtad = new XmlToAppData($this->getPlatform());
			$appData = $xtad->parseString($this->schema);
			$this->database = $appData->getDatabase(); // does final initialization
		}
		return $this->database;
	}

	public function buildSQL(PDO $con): int
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
		return $platform instanceof DefaultPlatform ? $platform->getAddTablesDDL($this->getDatabase()) : '';
	}

	/**
	 * Builds the script for a configured builder, if it is a real OM builder
	 * (i.e. actually has a build() method).
	 *
	 * @param mixed $table
	 * @param string $target
	 * @return string
	 */
	private function buildScriptFor($table, $target)
	{
		$builder = $this->getConfig()->getConfiguredBuilder($table, $target);
		return $builder instanceof OMBuilder ? $builder->build() : '';
	}

	/**
	 * Same as buildScriptFor(), but also assigns the given inheritance child
	 * to the builder before building, for builders that support it.
	 *
	 * @param mixed $table
	 * @param string $target
	 * @param mixed $child
	 * @return string
	 */
	private function buildScriptForChild($table, $target, $child)
	{
		$builder = $this->getConfig()->getConfiguredBuilder($table, $target);
		if ($builder instanceof QueryInheritanceBuilder
			|| $builder instanceof ExtensionQueryInheritanceBuilder
			|| $builder instanceof MultiExtendObjectBuilder
		) {
			$builder->setChild($child);
		}
		return $builder instanceof OMBuilder ? $builder->build() : '';
	}

	public function buildClasses(): void
	{
		eval($this->getClasses());
	}

	public function getClasses(): string
	{
		$script = '';
		foreach ($this->getDatabase()->getTables() as $table) {
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
				foreach ($col->getChildren() as $child) {
					if ($child->getAncestor()) {
						$script .= $this->buildScriptForChild('queryinheritance', $target, $child);
					}
					foreach (array('objectmultiextend', 'queryinheritancestub') as $target) {
						$script .= $this->buildScriptForChild($table, $target, $child);
					}
				}
			}
		}

		if ($table->getInterface()) {
			$script .= $this->buildScriptFor('interface', $target);
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
				$script .= $builder->build();
			}
		}

		// remove extra <?php
		$script = str_replace('<?php', '', $script);
		return $script;
	}

	public static function debugClassesForTable(string $schema, string $tableName): void
	{
		$builder = new self;
		$builder->setSchema($schema);
		foreach ($builder->getDatabase()->getTables() as $table) {
			if ($table->getName() == $tableName) {
				echo $builder->getClassesForTable($table);
			}
		}
	}
}