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
 * format() returns a PropulsionOnDemandCollection that hydrates objects as the use iterates on the collection
 * This formatter consumes less memory than the PropulsionObjectFormatter, but doesn't use Instance Pool
 *
 * @author     Francois Zaninotto
 * @version    $Revision$
 */

 use Propulsion\Collection\PropulsionOnDemandCollection;
 use Propulsion\Query\ModelCriteria;
 use PDOStatement;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
 use ReflectionClass;
 
class PropulsionOnDemandFormatter extends PropulsionObjectFormatter
{
	protected string $collectionName = 'Propulsion\\Collection\\PropulsionOnDemandCollection';
	protected bool $isSingleTableInheritance = false;

	public function init(ModelCriteria $criteria): static
	{
		parent::init($criteria);
		$this->isSingleTableInheritance = $criteria->getTableMap()->isSingleTableInheritance();

		return $this;
	}

	public function format(PDOStatement $stmt): mixed
	{
		$this->checkInit($stmt);
		if ($this->isWithOneToMany()) {
			// $stmt was already executed by the caller before format() ever runs
			// -- since it's never getting wrapped in a PropulsionOnDemandIterator
			// (the only thing that would otherwise closeCursor() it), it must be
			// closed explicitly here. Left open, FreeTDS/pdo_dblib (MSSQL, no
			// MARS support) can fail a later, unrelated statement on the same
			// connection with "Attempt to initiate a new Adaptive Server
			// operation with results pending" before PHP's GC gets around to
			// destructing it.
			$stmt->closeCursor();
			throw new PropulsionException('PropulsionOnDemandFormatter cannot hydrate related objects using a one-to-many relationship. Try removing with() from your query.');
		}
		$class = $this->collectionName;
		$collection = new $class();
		if (!$collection instanceof PropulsionOnDemandCollection) {
			throw new PropulsionException($class . ' must be a subclass of ' . PropulsionOnDemandCollection::class);
		}
		$collection->setModel($this->class ?? '');
		$collection->initIterator($this, $stmt);

		return $collection;
	}

	/**
	 * Hydrates a series of objects from a result row
	 * The first object to hydrate is the model of the Criteria
	 * The following objects (the ones added by way of ModelCriteria::with()) are linked to the first one
	 *
	 *  @param    array<int, mixed>  $row associative array indexed by column number,
	 *                   as returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 *
	 * @return    BaseObject
	 */
	public function getAllObjectsFromRow(array $row): BaseObject
	{
		$col = 0;
		$peer = $this->peer;
		$mainClass = $this->class;
		if ($peer === null || $mainClass === null) {
			throw new PropulsionException('You must initialize a formatter object before calling format() or formatOne()');
		}

		// main object
		if ($this->isSingleTableInheritance) {
			$omClass = $peer::getOMClass($row, $col, false);
			if (!is_string($omClass)) {
				throw new PropulsionException($peer . '::getOMClass() must return a string');
			}
			$mainClass = $omClass;
		}
		$obj = $this->getSingleObjectFromRow($row, $mainClass, $col);

		/** @var array<string, BaseObject> $hydrationChain */
		$hydrationChain = array();

		// related objects using 'with'
		foreach ($this->getWith() as $modelWith) {
			if ($modelWith->isSingleTableInheritance()) {
				$peerClass = $modelWith->getModelPeerName();
				$class = $peerClass::getOMClass($row, $col, false);
				if (!is_string($class) || !class_exists($class)) {
					throw new PropulsionException($peerClass . '::getOMClass() must return a class name');
				}
				$refl = new ReflectionClass($class);
				if ($refl->isAbstract()) {
					$numColumns = constant($class . 'Peer::NUM_COLUMNS');
					if (!is_int($numColumns)) {
						throw new PropulsionException($class . 'Peer::NUM_COLUMNS must be an int');
					}
					$col += $numColumns;
					continue;
				}
			} else {
				$class = $modelWith->getModelName();
			}
			$endObject = $this->getSingleObjectFromRow($row, $class, $col);
			$leftPhpName = $modelWith->getLeftPhpName();
			if ($modelWith->isPrimary()) {
				$startObject = $obj;
			} elseif ($leftPhpName !== null && isset($hydrationChain[$leftPhpName])) {
				$startObject = $hydrationChain[$leftPhpName];
			} else {
				continue;
			}
			// as we may be in a left join, the endObject may be empty
			// in which case it should not be related to the previous object
			if ($endObject->isPrimaryKeyNull()) {
				if ($modelWith->isAdd()) {
					$startObject->{$modelWith->getInitMethod()}(false);
				}
				continue;
			}
			$hydrationChain[$modelWith->getRightPhpName() ?? ''] = $endObject;
			$startObject->{$modelWith->getRelationMethod()}($endObject);
		}
		foreach ($this->getAsColumns() as $alias => $clause) {
			$obj->setVirtualColumn($alias, $row[$col]);
			$col++;
		}
		return $obj;
	}

}