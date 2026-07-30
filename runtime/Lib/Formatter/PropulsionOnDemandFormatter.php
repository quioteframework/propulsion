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

 use Propulsion\Query\ModelCriteria;
 use PDOStatement;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
 use Propulsion\Collection\PropulsionOnDemandCollection;
 use ReflectionClass;

class PropulsionOnDemandFormatter extends PropulsionObjectFormatter
{
	/** @var class-string<PropulsionOnDemandCollection> */
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
		$collectionClass = $this->collectionName;
		$collection = new $collectionClass();
		$collection->setModel($this->class);
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
		// main object
		$peer = $this->peer;
		$class = $this->isSingleTableInheritance ? $peer::getOMClass($row, $col, false) : $this->class;
		$obj = $this->getSingleObjectFromRow($row, $class, $col);
		// related objects using 'with'
		foreach ($this->getWith() as $modelWith) {
			if ($modelWith->isSingleTableInheritance()) {
				$modelWithPeer = $modelWith->getModelPeerName();
				$class = $modelWithPeer::getOMClass($row, $col, false);
				$refl = new ReflectionClass($class);
				if ($refl->isAbstract()) {
					$col += constant($class . 'Peer::NUM_COLUMNS');
					continue;
				}
			} else {
				$class = $modelWith->getModelName();
			}
			$endObject = $this->getSingleObjectFromRow($row, $class, $col);
			if ($modelWith->isPrimary()) {
				$startObject = $obj;
			} elseif (isset($hydrationChain)) {
				$startObject = $hydrationChain[$modelWith->getLeftPhpName()];
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
			if (isset($hydrationChain)) {
				$hydrationChain[$modelWith->getRightPhpName()] = $endObject;
			} else {
				$hydrationChain = array($modelWith->getRightPhpName() => $endObject);
			}
			$startObject->{$modelWith->getRelationMethod()}($endObject);
		}
		foreach ($this->getAsColumns() as $alias => $clause) {
			$obj->setVirtualColumn($alias, $row[$col]);
			$col++;
		}
		return $obj;
	}

}