<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Model\Behavior;

require_once __DIR__ . '/AddClassBehaviorBuilder.php';

class AddClassBehavior extends Behavior
{
	protected $additionalBuilders = array('AddClassBehaviorBuilder');
}