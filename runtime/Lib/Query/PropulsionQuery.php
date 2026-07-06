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
 */
use Propulsion\Exception\PropulsionException;
class PropulsionQuery
{
	/**
	 * @return ModelCriteria
	 */
	public static function from(string $queryClassAndAlias): ModelCriteria
	{
		list($class, $alias) = ModelCriteria::getClassAndAlias($queryClassAndAlias);
		$queryClass = $class . 'Query';
		if (!class_exists($queryClass)) {
			throw new PropulsionException('Cannot find a query class for ' . $class);
		}
		$query = new $queryClass();
		if ($alias !== null) {
			$query->setModelAlias($alias);
		}
		return $query;
	}
}
