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
 * A validator for validating the (PHP) type of the value submitted.
 *
 * <code>
 *   <column name="some_int" type="INTEGER" required="true"/>
 *
 *   <validator column="some_int">
 *     <rule name="type" value="integer" message="Please specify an integer value for some_int column." />
 *   </validator>
 * </code>
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */

use Propulsion\Map\ValidatorMap;
use Propulsion\Exception\PropulsionException;

class TypeValidator implements BasicValidator
{
	/**
	 * @see       BasicValidator::isValid()
	 *
	 * @param     ValidatorMap  $map
	 * @param     mixed         $value
	 *
	 * @return    boolean
	 */
	public function isValid(ValidatorMap $map, $value)
	{
		switch ($map->getValue()) {
			case 'array':
				return is_array($value);
			case 'bool':
			case 'boolean':
				return is_bool($value);
			case 'float':
				return is_float($value);
			case 'int':
			case 'integer':
				return is_int($value);
			case 'numeric':
				return is_numeric($value);
			case 'object':
				return is_object($value);
			case 'resource':
				return is_resource($value);
			case 'scalar':
				return is_scalar($value);
			case 'string':
				return is_string($value);
			case 'function':
				return function_exists($value);
			default:
				throw new PropulsionException('Unkonwn type ' . $map->getValue());
		}
	}
}
