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
 * A validator for valid values (e.g. for enum fields)
 *
 * <code>
 *   <column name="address_type" type="VARCHAR" required="true" default="delivery" />
 *
 *   <validator column="address_type">
 *     <rule name="validValues" value="account|delivery" message="Please select a valid address type." />
 *   </validator>
 * </code>
 *
 * @author     Michael Aichler <aichler@mediacluster.de>
 * @version    $Revision$
 */

use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ValidatorMap;

class ValidValuesValidator implements BasicValidator
{
	/**
	 * @see       BasicValidator::isValid()
	 *
	 * @param     ValidatorMap  $map
	 * @param     string        $str
	 *
	 * @return    boolean
	 */
	public function isValid(ValidatorMap $map, $str)
	{
		$value = $map->getValue();
		if ($value === null) {
			throw new PropulsionException('ValidValuesValidator requires a "value" attribute with the list of valid values');
		}

		$validValues = preg_split("/[|,]/", $value);
		if ($validValues === false) {
			throw new PropulsionException("Failed to parse valid values from \"$value\"");
		}

		return in_array($str, $validValues);
	}
}
