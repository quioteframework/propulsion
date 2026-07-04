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
 * Interface for reverse engineering schema parsers.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 * @package    propel.generator.reverse
 */

 use Propulsion\Generator\Config\GeneratorConfigInterface;
 use Propulsion\Generator\Model\Database;
interface SchemaParser
{

	/**
	 * Gets the database connection.
	 * @return     \PDO|null
	 */
	public function getConnection();

	/**
	 * Sets the database connection.
	 *
	 * @param      \PDO $dbh
	 */
	public function setConnection(\PDO $dbh);

	/**
	 * Sets the GeneratorConfig to use in the parsing.
	 *
	 * @param      GeneratorConfigInterface $config
	 */
	public function setGeneratorConfig(GeneratorConfigInterface $config);

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
	 */
	public function getBuildProperty($name);

	/**
	 * Gets array of warning messages.
	 * @return     array string[]
	 */
	public function getWarnings();

	/**
	 * Parse the schema and populate passed-in Database model object.
	 *
	 * @param      Database $database
	 * @param      mixed $task Optional caller-provided logging sink. Historically a
	 *             Phing\Task (behind an `if ($task) $task->log(...)` guard, only ever
	 *             used for optional verbose-level logging -- never a hard dependency);
	 *             the console commands (schema:reverse, sql:diff) always pass null.
	 *
	 * @return     int number of generated tables
	 */
	public function parse(Database $database, mixed $task = null);

	public function setMigrationTable(string $migrationTable);
}
