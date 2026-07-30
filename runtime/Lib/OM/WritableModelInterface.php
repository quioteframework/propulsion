<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\OM;

use Propulsion\Util\BasePeer;

/**
 * Marker interface for generated model classes that have the generic
 * "by name"/"by position"/array mutators (setByName(), setByPosition(),
 * fromArray()) -- ObjectBuilder only emits those three methods together, all
 * gated by the same AbstractObjectBuilder::isAddGenericMutators() check
 * (false for read-only/view-backed tables, alias tables, or when a schema
 * opts out via the `propulsion.addGenericMutators` build property).
 *
 * Lets code that dynamically instantiates a generated model class (e.g.
 * ModelCriteria::findOneOrCreate(), which only has the model's class name as
 * a runtime string) check `instanceof WritableModelInterface` before calling
 * setByName() -- narrowing to BaseObject instead doesn't work: not every
 * BaseObject subclass has these methods (a read-only, VIEW-backed generated
 * model has none of them), so BaseObject itself can't declare them.
 *
 * @author     Markus Lervik <markus.lervik@thejakamo.com>
 */
interface WritableModelInterface
{
	/**
	 * Sets a field from the object by name passed in as a string.
	 *
	 * @param string $name peer name
	 * @param mixed $value field value
	 * @param string $type The type of fieldname the $name is of:
	 *                     one of the class type constants BasePeer::TYPE_PHPNAME, BasePeer::TYPE_STUDLYPHPNAME
	 *                     BasePeer::TYPE_COLNAME, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_NUM
	 * @return void
	 */
	public function setByName(string $name, mixed $value, string $type = BasePeer::TYPE_PHPNAME): void;

	/**
	 * Sets a field from the object by Position as specified in the xml schema.
	 * Zero-based.
	 *
	 * @param int $pos position in xml schema
	 * @param mixed $value field value
	 * @return void
	 */
	public function setByPosition(int $pos, mixed $value): void;

	/**
	 * Populates the object using an array.
	 *
	 * @param array<string|int,mixed> $arr An array to populate the object from.
	 * @param string $keyType The type of keys the array uses.
	 * @return void
	 */
	public function fromArray(array $arr, string $keyType = BasePeer::TYPE_PHPNAME): void;
}
