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
 use Propulsion\Collection\PropulsionCollection;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
class PropulsionArrayFormatter extends PropulsionFormatter
{
	/** @var class-string<PropulsionCollection> */
	protected string $collectionName = 'Propulsion\\Collection\\PropulsionArrayCollection';

	/** @var array<string, array<int|string, array<array-key, mixed>>> */
	protected $alreadyHydratedObjects = array();

	protected mixed $emptyVariable = null;

	public function format(PDOStatement $stmt): mixed
	{
		$this->checkInit($stmt);
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
		while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
			if (!is_array($row) || !array_is_list($row)) {
				continue;
			}
			if ($object = &$this->getStructuredArrayFromRow($row)) {
				$rows[] = &$object;
			}
			unset($object);
		}
		if ($class = $this->collectionName) {
			$collectionObj = new $class();
			$collectionObj->setModel($this->requireClass());
			$collectionObj->setFormatter($this);
			foreach ($rows as $row) {
				$collectionObj[] = $row;
			}
			$collection = $collectionObj;
		} else {
			$collection = $rows;
		}
		$this->currentObjects = array();
		$this->alreadyHydratedObjects = array();
		$stmt->closeCursor();

		return $collection;
	}

	public function formatOne(PDOStatement $stmt): mixed
	{
		$this->checkInit($stmt);
		$result = null;
		while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
			if (!is_array($row) || !array_is_list($row)) {
				continue;
			}
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
	 * @return array<int|string, mixed>|string The original record turned into an array
	 *         (toArray()'s own return type also allows a string: the literal sentinel
	 *         '*RECURSION*', returned instead of an array when $includeForeignObjects
	 *         recursion revisits an object it's already dumping).
	 */
	public function formatRecord(?BaseObject $record = null): mixed
	{
		return $record ? $this->toRecordArray($record) : array();
	}

	public function isObjectFormatter(): bool
	{
		return false;
	}

	/**
	 * Turns a hydrated object into a plain array, guarding against the
	 * array<string,mixed>|string union BaseObject::toArray() carries for
	 * tables where the generic-accessor overload isn't generated.
	 *
	 * @return array<array-key, mixed>
	 */
	private function toRecordArray(BaseObject $obj): array
	{
		$result = $obj->toArray();
		if (!is_array($result)) {
			throw new PropulsionException(get_class($obj) . '::toArray() must return an array');
		}
		return $result;
	}

	/**
	 * Hydrates a series of objects from a result row
	 * The first object to hydrate is the model of the Criteria
	 * The following objects (the ones added by way of ModelCriteria::with()) are linked to the first one
	 *
	 *  @param    array<int, mixed>  $row associative array indexed by column number,
	 *                   as returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 *
	 * @return    array<int|string, mixed>|null
	 */
	public function &getStructuredArrayFromRow(array $row): ?array
	{
		$emptyVariable = null;
		$col = 0;

		$peer = $this->peer;
		$class = $this->class;
		if ($peer === null || $class === null) {
			throw new PropulsionException('You must initialize a formatter object before calling format() or formatOne()');
		}

		// hydrate main object or take it from registry
		$mainObjectIsNew = false;
		$mainKey = $peer::getPrimaryKeyHashFromRow($row) ?? '';
		if (!is_string($mainKey)) {
			$mainKey = '';
		}
		// we hydrate the main object even in case of a one-to-many relationship
		// in order to get the $col variable increased anyway
		$obj = $this->getSingleObjectFromRow($row, $class, $col);
		if (!isset($this->alreadyHydratedObjects[$class][$mainKey])) {
			$this->alreadyHydratedObjects[$class][$mainKey] = $this->toRecordArray($obj);
			$mainObjectIsNew = true;
		}

		/** @var array<string, array<array-key, mixed>> $hydrationChain */
		$hydrationChain = array();

		// related objects added using with()
		foreach ($this->getWith() as $relAlias => $modelWith) {

			// determine class to use
			if ($modelWith->isSingleTableInheritance()) {
				$peerClass = $modelWith->getModelPeerName();
				$relatedClass = $peerClass::getOMClass($row, $col, false);
				if (!is_string($relatedClass) || !class_exists($relatedClass)) {
					throw new PropulsionException($peerClass . '::getOMClass() must return a class name');
				}
				$refl = new \ReflectionClass($relatedClass);
				if ($refl->isAbstract()) {
					$numColumns = constant($relatedClass . 'Peer::NUM_COLUMNS');
					if (!is_int($numColumns)) {
						throw new PropulsionException($relatedClass . 'Peer::NUM_COLUMNS must be an int');
					}
					$col += $numColumns;
					continue;
				}
			} else {
				$relatedClass = $modelWith->getModelName();
			}

			// hydrate related object or take it from registry
			$peerClass = $modelWith->getModelPeerName();
			$key = $peerClass::getPrimaryKeyHashFromRow($row, $col) ?? '';
			if (!is_string($key)) {
				$key = '';
			}
			// we hydrate the main object even in case of a one-to-many relationship
			// in order to get the $col variable increased anyway
			$secondaryObject = $this->getSingleObjectFromRow($row, $relatedClass, $col);
			if (!isset($this->alreadyHydratedObjects[$relAlias][$key])) {

				if ($secondaryObject->isPrimaryKeyNull()) {
					$this->alreadyHydratedObjects[$relAlias][$key] = array();
				} else {
					$this->alreadyHydratedObjects[$relAlias][$key] = $this->toRecordArray($secondaryObject);
				}
			}

			if ($modelWith->isPrimary()) {
				$arrayToAugment = &$this->alreadyHydratedObjects[$class][$mainKey];
			} else {
				$arrayToAugment = &$hydrationChain[$modelWith->getLeftPhpName() ?? ''];
			}

			if ($modelWith->isAdd()) {
				$existing = $arrayToAugment[$modelWith->getRelationName()] ?? null;
				if (!is_array($existing)) {
					$existing = array();
				}
				if (!in_array($this->alreadyHydratedObjects[$relAlias][$key], $existing)) {
					$existing[] = &$this->alreadyHydratedObjects[$relAlias][$key];
					$arrayToAugment[$modelWith->getRelationName()] = $existing;
				}
			} else {
				$arrayToAugment[$modelWith->getRelationName()] = &$this->alreadyHydratedObjects[$relAlias][$key];
			}

			$hydrationChain[$modelWith->getRightPhpName() ?? ''] = &$this->alreadyHydratedObjects[$relAlias][$key];
		}

		// columns added using withColumn()
		foreach ($this->getAsColumns() as $alias => $clause) {
			$this->alreadyHydratedObjects[$class][$mainKey][$alias] = $row[$col];
			$col++;
		}

		if ($mainObjectIsNew) {
			return $this->alreadyHydratedObjects[$class][$mainKey];
		} else {
			// we still need to return a reference to something to avoid a warning
			return $emptyVariable;
		}
	}

}