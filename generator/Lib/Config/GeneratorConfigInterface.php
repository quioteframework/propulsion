<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license		 MIT License
 */
namespace Propulsion\Generator\Config;

/**
 *
 * @package      propel.generator.config
 */

use Propulsion\Generator\Model\Table;
use Propulsion\Generator\Builder\DataModelBuilder;
use Propulsion\Generator\Builder\Util\Pluralizer;

interface GeneratorConfigInterface
{
	/**
	 * Gets a configured data model builder class for specified table and based on type.
	 *
	 * @param      mixed $table
	 * @param      string $type The type of builder ('ddl', 'sql', etc.)
	 * @return     DataModelBuilder
	 */
	public function getConfiguredBuilder($table, $type);

	/**
	* Gets a configured Pluralizer class.
	*
	* @return     Pluralizer
	*/
	public function getConfiguredPluralizer();

	/**
	 * Gets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @return     mixed
	 */
	public function getBuildProperty($name);

	/**
	 * Sets a specific propel (renamed) property from the build.
	 *
	 * @param      string $name
	 * @param      mixed $value
	 */
	public function setBuildProperty($name, $value);

}