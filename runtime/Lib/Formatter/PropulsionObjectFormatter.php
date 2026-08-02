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

 use Propulsion\Collection\PropulsionCollection;
 use Propulsion\Exception\PropulsionException;
 use Propulsion\OM\BaseObject;
 use Propulsion\Util\StatementRows;
 use PDOStatement;
 use PDO;
class PropulsionObjectFormatter extends PropulsionFormatter
{
	/** @var class-string<PropulsionCollection> */
	protected string $collectionName = 'Propulsion\\Collection\\PropulsionObjectCollection';

	public function format(PDOStatement $stmt): mixed
	{
		$this->checkInit($stmt);

		return $this->formatRows(StatementRows::iterate($stmt));
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	public function formatFromRows(iterable $rows): mixed
	{
		$this->checkInit();

		return $this->formatRows($rows);
	}

	public function formatOne(PDOStatement $stmt): ?BaseObject
	{
		$this->checkInit($stmt);

		return $this->formatOneRow(StatementRows::iterate($stmt));
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	public function formatOneFromRows(iterable $rows): mixed
	{
		$this->checkInit();

		return $this->formatOneRow($rows);
	}

	public function supportsRowCaching(): bool
	{
		return true;
	}

	/**
	 * The single per-row body behind both {@see format()} and
	 * {@see formatFromRows()}; the row source (a live statement or a cached
	 * array) is the only difference between them.
	 *
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	protected function formatRows(iterable $rows): mixed
	{
		if($class = $this->collectionName) {
			$collectionObj = new $class();
			$collectionObj->setModel($this->requireClass());
			$collectionObj->setFormatter($this);
			$collection = $collectionObj;
		} else {
			$collection = array();
		}
		if ($this->isWithOneToMany()) {
			if ($this->hasLimit) {
				throw new PropulsionException('Cannot use limit() in conjunction with with() on a one-to-many relationship. Please remove the with() call, or the limit() call.');
			}
			$pks = array();
			foreach ($rows as $row) {
				$object = $this->getAllObjectsFromRow($row);
				$pk = $object->getPrimaryKey();
				// Strict: a primary key is compared against keys already seen,
				// and loose comparison of composite (array) keys compares their
				// elements loosely too, so e.g. ['1'] and [1] -- or [0] and
				// [null] -- would collapse into one row.
				if (!in_array($pk, $pks, true)) {
					$collection[] = $object;
					$pks[] = $pk;
				}
			}
		} else {
			// only many-to-one relationships
			foreach ($rows as $row) {
				$collection[] =  $this->getAllObjectsFromRow($row);
			}
		}

		return $collection;
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	protected function formatOneRow(iterable $rows): ?BaseObject
	{
		$result = null;
		foreach ($rows as $row) {
			$result = $this->getAllObjectsFromRow($row);
		}

		return $result;
	}

	public function isObjectFormatter(): bool
	{
		return true;
	}

	/**
	 * Calls the generated Peer::populateObject($row, $startCol) static method
	 * dynamically. Its return type, `array{0: ?BaseObject, 1: int}`, can't be
	 * confirmed by PHPStan since $peerClass isn't a literal class-string, so
	 * this validates the shape at the one place it's produced instead of at
	 * every call site.
	 *
	 * @param     array<int, mixed>  $row
	 * @return    array{0: ?BaseObject, 1: int}
	 */
	private function callPopulateObject(string $peerClass, array $row, int $startCol = 0): array
	{
		$result = $peerClass::populateObject($row, $startCol);
		if (
			!is_array($result)
			|| !array_key_exists(0, $result)
			|| !array_key_exists(1, $result)
			|| ($result[0] !== null && !$result[0] instanceof BaseObject)
			|| !is_int($result[1])
		) {
			throw new PropulsionException($peerClass . '::populateObject() must return array{0: ?BaseObject, 1: int}');
		}
		return array($result[0], $result[1]);
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
		$peer = $this->peer;
		if ($peer === null) {
			throw new PropulsionException('You must initialize a formatter object before calling format() or formatOne()');
		}

		// main object
		list($obj, $col) = $this->callPopulateObject($peer, $row);
		if ($obj === null) {
			throw new PropulsionException('The main object could not be hydrated from the current row');
		}

		/** @var array<string, BaseObject> $hydrationChain */
		$hydrationChain = array();

		// related objects added using with()
		foreach ($this->getWith() as $modelWith) {
			$peerClass = $modelWith->getModelPeerName();
			list($endObject, $col) = $this->callPopulateObject($peerClass, $row, $col);

			$leftPhpName = $modelWith->getLeftPhpName();
			if (null !== $leftPhpName && !isset($hydrationChain[$leftPhpName])) {
				continue;
			}

			if ($modelWith->isPrimary()) {
				$startObject = $obj;
			} elseif ($leftPhpName !== null) {
				$startObject = $hydrationChain[$leftPhpName];
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
			$hydrationChain[$modelWith->getRightPhpName() ?? ''] = $endObject;

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
