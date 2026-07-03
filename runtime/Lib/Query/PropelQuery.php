<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Query;

/**
 * Factory for model queries
 *
 * @author     François Zaninotto
 * @version    $Revision$
 * @package    propel.runtime.query
 */
use Propulsion\Exception\PropelException;
class PropelQuery
{
	public static function from($queryClassAndAlias)
	{
		list($class, $alias) = ModelCriteria::getClassAndAlias($queryClassAndAlias);
		$queryClass = $class . 'Query';
		if (!class_exists($queryClass)) {
			throw new PropelException('Cannot find a query class for ' . $class);
		}
		$query = new $queryClass();
		if ($alias !== null) {
			$query->setModelAlias($alias);
		}
		return $query;
	}
}
