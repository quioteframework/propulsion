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
 * A validator for unique column names.
 *
 * <code>
 *   <column name="username" type="VARCHAR" size="25" required="true" />
 *
 *   <validator column="username">
 *     <rule name="unique" message="Username already exists !" />
 *   </validator>
 * </code>
 *
 * @author     Michael Aichler <aichler@mediacluster.de>
 * @version    $Revision$
 */

use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ValidatorMap;
use Propulsion\Query\Criteria;

class UniqueValidator implements BasicValidator
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
		$column = $map->getColumn();

		$c = new Criteria();
		$c->add($column->getFullyQualifiedName(), $str, Criteria::EQUAL);

		$table = $column->getTable()->getClassName();

		$peerClass = $table . 'Peer';
		$count = $peerClass::doCount($c);
		if (!is_int($count)) {
			throw new PropulsionException("{$peerClass}::doCount() was expected to return an int");
		}

		return $count === 0;
	}
}
