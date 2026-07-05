<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Formatter;

/**
 * Array formatter for Propulsion query
 * format() returns a PropulsionArrayCollection of associative arrays
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */

 use \PDO;
 use \PDOStatement;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
class PropulsionArrayFormatter extends PropulsionFormatter
{
	protected $collectionName = 'Propulsion\\Collection\\PropulsionArrayCollection';
	protected $alreadyHydratedObjects = array();
	protected $emptyVariable;

	public function format(PDOStatement $stmt)
	{
		$this->checkInit();
		if ($this->isWithOneToMany() && $this->hasLimit) {
			throw new PropulsionException('Cannot use limit() in conjunction with with() on a one-to-many relationship. Please remove the with() call, or the limit() call.');
		}
		// Rows are always accumulated into a plain PHP array first, by reference (not a
		// PropulsionArrayCollection, even if that's the final container -- PHP has no
		// by-reference form of ArrayAccess::offsetSet(), so "$collection[] = &$object"
		// fatals once $collection is an object). For a one-to-many with(), a later row for
		// the same main object mutates $this->alreadyHydratedObjects[...] in place
		// (appending to the nested "Reviews"-style array) rather than returning a new array
		// -- a plain copy here would freeze the first row's snapshot, silently dropping
		// every related row after the first one. Object hydration
		// (PropulsionObjectFormatter) doesn't have this problem since PHP objects are
		// always handle/reference types; a plain PHP array is not.
		$rows = array();
		while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
			if ($object = &$this->getStructuredArrayFromRow($row)) {
				$rows[] = &$object;
			}
			unset($object);
		}
		if ($class = $this->collectionName) {
			$collection = new $class();
			$collection->setModel($this->class);
			$collection->setFormatter($this);
			foreach ($rows as $row) {
				$collection[] = $row;
			}
		} else {
			$collection = $rows;
		}
		$this->currentObjects = array();
		$this->alreadyHydratedObjects = array();
		$stmt->closeCursor();

		return $collection;
	}

	public function formatOne(PDOStatement $stmt)
	{
		$this->checkInit();
		$result = null;
		while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
			if ($object = &$this->getStructuredArrayFromRow($row)) {
				$result = &$object;
			}
		}
		$this->currentObjects = array();
		$this->alreadyHydratedObjects = array();
		$stmt->closeCursor();
		return $result;
	}

	/**
	 * Formats an ActiveRecord object
	 *
	 * @param BaseObject $record the object to format
	 *
	 * @return array The original record turned into an array
	 */
	public function formatRecord($record = null)
	{
		return $record ? $record->toArray() : array();
	}

	public function isObjectFormatter()
	{
		return false;
	}

	/**
	 * Hydrates a series of objects from a result row
	 * The first object to hydrate is the model of the Criteria
	 * The following objects (the ones added by way of ModelCriteria::with()) are linked to the first one
	 *
	 *  @param    array  $row associative array indexed by column number,
	 *                   as returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 *
	 * @return    array|null
	 */
	public function &getStructuredArrayFromRow($row)
	{
		$emptyVariable = null;
		$col = 0;

		// hydrate main object or take it from registry
		$mainObjectIsNew = false;
		$mainKey = call_user_func(array($this->peer, 'getPrimaryKeyHashFromRow'), $row);
		// we hydrate the main object even in case of a one-to-many relationship
		// in order to get the $col variable increased anyway
		$obj = $this->getSingleObjectFromRow($row, $this->class, $col);
		if (!isset($this->alreadyHydratedObjects[$this->class][$mainKey])) {
			$this->alreadyHydratedObjects[$this->class][$mainKey] = $obj->toArray();
			$mainObjectIsNew = true;
		}

		$hydrationChain = array();

		// related objects added using with()
		foreach ($this->getWith() as $relAlias => $modelWith) {

			// determine class to use
			if ($modelWith->isSingleTableInheritance()) {
				$class = call_user_func(array($modelWith->getModelPeerName(), 'getOMClass'), $row, $col, false);
				$refl = new \ReflectionClass($class);
				if ($refl->isAbstract()) {
					$col += constant($class . 'Peer::NUM_COLUMNS');
					continue;
				}
			} else {
				$class = $modelWith->getModelName();
			}

			// hydrate related object or take it from registry
			$key = call_user_func(array($modelWith->getModelPeerName(), 'getPrimaryKeyHashFromRow'), $row, $col) ?? '';
			// we hydrate the main object even in case of a one-to-many relationship
			// in order to get the $col variable increased anyway
			$secondaryObject = $this->getSingleObjectFromRow($row, $class, $col);
			if (!isset($this->alreadyHydratedObjects[$relAlias][$key])) {

				if ($secondaryObject->isPrimaryKeyNull()) {
					$this->alreadyHydratedObjects[$relAlias][$key] = array();
				} else {
					$this->alreadyHydratedObjects[$relAlias][$key] = $secondaryObject->toArray();
				}
			}

			if ($modelWith->isPrimary()) {
				$arrayToAugment = &$this->alreadyHydratedObjects[$this->class][$mainKey];
			} else {
				$arrayToAugment = &$hydrationChain[$modelWith->getLeftPhpName()];
			}

			if ($modelWith->isAdd()) {
				if (!isset($arrayToAugment[$modelWith->getRelationName()]) || !in_array($this->alreadyHydratedObjects[$relAlias][$key], $arrayToAugment[$modelWith->getRelationName()])) {
					$arrayToAugment[$modelWith->getRelationName()][] = &$this->alreadyHydratedObjects[$relAlias][$key];
				}
			} else {
				$arrayToAugment[$modelWith->getRelationName()] = &$this->alreadyHydratedObjects[$relAlias][$key];
			}

			$hydrationChain[$modelWith->getRightPhpName()] = &$this->alreadyHydratedObjects[$relAlias][$key];
		}

		// columns added using withColumn()
		foreach ($this->getAsColumns() as $alias => $clause) {
			$this->alreadyHydratedObjects[$this->class][$mainKey][$alias] = $row[$col];
			$col++;
		}

		if ($mainObjectIsNew) {
			return $this->alreadyHydratedObjects[$this->class][$mainKey];
		} else {
			// we still need to return a reference to something to avoid a warning
			return $emptyVariable;
		}
	}

}