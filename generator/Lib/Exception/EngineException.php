<?php

namespace Propulsion\Generator\Exception;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * The base class of all exceptions thrown by the engine.
 *
 * This does not extend Phing\Exception\BuildException: EngineException is thrown
 * throughout the core generator code (schema/model classes, GeneratorConfig, the
 * Manager classes used by bin/propulsion), none of which should require phing/phing
 * -- a dev-only dependency -- to be installed to run.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Daniel Rall <dlr@collab.net> (Torque)
 * @author     Jason van Zyl <jvz@apache.org> (Torque)
 * @version    $Revision$
 * @package    propel.generator.exception
 */
class EngineException extends \Exception {}
