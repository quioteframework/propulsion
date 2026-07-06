<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Util;

/**
 * Proxy for conditional statements in a fluid interface.
 * This class replaces another class for wrong statements,
 * and silently catches all calls to non-conditional method calls
 *
 * @example
 * <code>
 * $c->_if(true)        // returns $c
 *     ->doStuff()      // executed
 *   ->_else()          // returns a PropulsionConditionalProxy instance
 *     ->doOtherStuff() // not executed
 *   ->_endif();        // returns $c
 * $c->_if(false)       // returns a PropulsionConditionalProxy instance
 *     ->doStuff()      // not executed
 *   ->_else()          // returns $c
 *     ->doOtherStuff() // executed
 *   ->_endif();        // returns $c
 * @see Criteria
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */
use Propulsion\Query\Criteria;

class PropulsionConditionalProxy
{
	protected Criteria $mainObject;

	public function __construct(Criteria $mainObject)
	{
		$this->mainObject = $mainObject;
	}

	/**
	 * @return PropulsionConditionalProxy|Criteria
	 */
	public function _if(bool $cond)
	{
		return $this->mainObject->_if($cond);
	}

	/**
	 * @return PropulsionConditionalProxy|Criteria
	 */
	public function _elseif(bool $cond)
	{
		return $this->mainObject->_elseif($cond);
	}

	/**
	 * @return PropulsionConditionalProxy|Criteria
	 */
	public function _else()
	{
		return $this->mainObject->_else();
	}

	public function _endif(): Criteria
	{
		return $this->mainObject->_endif();
	}

	/**
	 * @param array<mixed> $arguments
	 */
	public function __call(string $name, array $arguments): static
	{
		return $this;
	}
}