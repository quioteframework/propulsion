<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Validator;

/**
 * A validator for required fields.
 *
 * Below is an example usage for your Propulsion xml schema file.
 *
 * <code>
 *   <column name="username" type="VARCHAR" size="25" required="true" />
 *
 *   <validator column="username">
 *     <rule name="required" message="Username is required." />
 *   </validator>
 * </code>
 *
 * @author     Michael Aichler <aichler@mediacluster.de>
 * @version    $Revision$
 */

use Propulsion\Map\ValidatorMap;
class RequiredValidator implements BasicValidator
{
	/**
	 * @see       BasicValidator::isValid()
	 *
	 * @param     ValidatorMap  $map
	 * @param     string        $str
	 *
	 * @return    boolean
	 */
	public function isValid (ValidatorMap $map, $str)
	{
		return ($str !== null && $str !== "");
	}
}
