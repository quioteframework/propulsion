<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests for the StandardEnglishPluralizer class
 *
 * @version    $Revision$
 */
class DefaultEnglishPluralizerTest extends TestCase
{
	public static function getPluralFormDataProvider()
	{
		return array(
			array('', 's'),
			array('user', 'users'),
			array('users', 'userss'),
			array('User', 'Users'),
			array('sheep', 'sheeps'),
			array('Sheep', 'Sheeps'),
			array('wife', 'wifes'),
			array('Wife', 'Wifes'),
			array('country', 'countrys'),
			array('Country', 'Countrys'),
		);
	}

	/**
	 * @dataProvider getPluralFormDataProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getPluralFormDataProvider')]
	public function testgetPluralForm($input, $output)
	{
		$pluralizer = new DefaultEnglishPluralizer();
		$this->assertEquals($output, $pluralizer->getPluralForm($input));
	}
}
