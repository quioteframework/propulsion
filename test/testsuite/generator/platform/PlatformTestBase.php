<?php


use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Base class for all Platform tests
 * @package    generator.platform
 */
abstract class PlatformTestBase extends TestCase
{

	abstract protected static function getPlatform();

	protected static function getDatabaseFromSchema($schema)
	{
		$xtad = new XmlToAppData(static::getPlatform());
		$appData = $xtad->parseString($schema);
		return $appData->getDatabase();
	}

	protected static function getTableFromSchema($schema, $tableName = 'foo')
	{
		return static::getDatabaseFromSchema($schema)->getTable($tableName);
	}

}
