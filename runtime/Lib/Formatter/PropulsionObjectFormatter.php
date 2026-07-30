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
 * Object formatter for Propulsion query
 * format() returns a PropulsionObjectCollection of Propulsion model objects
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */

 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
 use Propulsion\Collection\PropulsionCollection;
 use PDOStatement;
 use PDO;
class PropulsionObjectFormatter extends PropulsionFormatter
{
	/** @var class-string<PropulsionCollection> */
	protected string $collectionName = 'Propulsion\\Collection\\PropulsionObjectCollection';

	public function format(PDOStatement $stmt): mixed
	{
		$this->checkInit($stmt);
		if($class = $this->collectionName) {
			$collection = new $class();
			$collection->setModel($this->requireClass());
			$collection->setFormatter($this);
		} else {
			$collection = array();
		}
		if ($this->isWithOneToMany()) {
			if ($this->hasLimit) {
				throw new PropulsionException('Cannot use limit() in conjunction with with() on a one-to-many relationship. Please remove the with() call, or the limit() call.');
			}
			$pks = array();
			while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
				$object = $this->getAllObjectsFromRow($row);
				$pk = $object->getPrimaryKey();
				if (!in_array($pk, $pks)) {
					$collection[] = $object;
					$pks[] = $pk;
				}
			}
		} else {
			// only many-to-one relationships
			while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
				$collection[] =  $this->getAllObjectsFromRow($row);
			}
		}
		$stmt->closeCursor();

		return $collection;
	}

	public function formatOne(PDOStatement $stmt): ?BaseObject
	{
		$this->checkInit($stmt);
		$result = null;
		while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
			$result = $this->getAllObjectsFromRow($row);
		}
		$stmt->closeCursor();

		return $result;
	}

	public function isObjectFormatter(): bool
	{
		return true;
	}

	/**
	 * Hydrates a series of objects from a result row
	 * The first object to hydrate is the model of the Criteria
	 * The following objects (the ones added by way of ModelCriteria::with()) are linked to the first one
	 *
	 *  @param    array<int, mixed>  $row associative array indexed by column number,
	 *                   as returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 *
	 * @return    \Propulsion\OM\BaseObject
	 */
	public function getAllObjectsFromRow(array $row): BaseObject
	{
		// main object
		list($obj, $col) = $this->peer::populateObject($row);

		// related objects added using with()
		foreach ($this->getWith() as $modelWith) {
			$modelWithPeer = $modelWith->getModelPeerName();
			list($endObject, $col) = $modelWithPeer::populateObject($row, $col);

			if (null !== $modelWith->getLeftPhpName() && !isset($hydrationChain[$modelWith->getLeftPhpName()])) {
				continue;
			}

			if ($modelWith->isPrimary()) {
				$startObject = $obj;
			} elseif (isset($hydrationChain)) {
				$startObject = $hydrationChain[$modelWith->getLeftPhpName()];
			} else {
				continue;
			}
			// as we may be in a left join, the endObject may be empty
			// in which case it should not be related to the previous object
			if (null === $endObject || $endObject->isPrimaryKeyNull()) {
				if ($modelWith->isAdd()) {
					$startObject->{$modelWith->getInitMethod()}(false);
				}
				continue;
			}
			if (isset($hydrationChain)) {
				$hydrationChain[$modelWith->getRightPhpName()] = $endObject;
			} else {
				$hydrationChain = array($modelWith->getRightPhpName() => $endObject);
			}

			$startObject->{$modelWith->getRelationMethod()}($endObject);
		}

		// columns added using withColumn()
		foreach ($this->getAsColumns() as $alias => $clause) {
			$obj->setVirtualColumn($alias, $row[$col]);
			$col++;
		}
		return $obj;
	}

}
