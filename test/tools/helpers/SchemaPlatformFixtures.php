<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Platform\DefaultPlatform;

/**
 * Minimal platform test doubles used by DatabaseTest/TableTest to exercise
 * Database::hasTable()'s schema-qualification behavior without depending on
 * a specific real platform's supportsSchemas() value.
 */
class SchemaPlatform extends DefaultPlatform
{
	public function supportsSchemas()
	{
		return true;
	}
}

class NoSchemaPlatform extends DefaultPlatform
{
	public function supportsSchemas()
	{
		return false;
	}
}
